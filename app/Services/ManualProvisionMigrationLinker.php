<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\PsbDraft;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Manual UI provision (OLT C300: ZTE provision/activate and FiberHome) must
 * complete an OPEN customer migration instead of only recording a new PSB
 * draft (#1106, #1102). DB recording only: never writes a device.
 *
 *  - exact: SN == migration.new_sn, or PPPoE == migration/customer PPPoE,
 *    consistent target router/OLT and ownership guards clear -> linked.
 *  - near: PPPoE or SN within 2 characters on the same target router/OLT ->
 *    warning only; the technician confirms explicitly (link-migration).
 */
class ManualProvisionMigrationLinker
{
    public const MAX_DISTANCE = 2;

    public function __construct(
        protected CustomerMigrationService $migrations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  olt_id, router_id, sn, port, onu_id, name
     * @return array{exact: ?CustomerMigration, matched_by: ?string, candidates: list<array<string, mixed>>}
     */
    public function match(array $payload): array
    {
        $empty = ['exact' => null, 'matched_by' => null, 'candidates' => []];
        if (! Schema::hasTable('customer_migrations')) {
            return $empty;
        }

        $sn = $this->migrations->normalizeSerialForMatch((string) ($payload['sn'] ?? ''));
        $pppoe = $this->migrations->normalizeUsernameForMatch((string) ($payload['name'] ?? $payload['pppoe_user'] ?? ''));
        $oltId = (int) ($payload['olt_id'] ?? 0);
        $routerId = (int) ($payload['router_id'] ?? 0);
        if ($sn === '' && $pppoe === '') {
            return $empty;
        }

        $typedCustomer = $pppoe !== ''
            ? Customer::query()->whereRaw('LOWER(pppoe_user) = ?', [strtolower($pppoe)])->first()
            : null;

        $exactRows = CustomerMigration::query()
            ->open()
            ->where(function ($query) use ($sn, $pppoe, $typedCustomer): void {
                if ($sn !== '') {
                    $query->orWhereRaw('UPPER(REPLACE(REPLACE(COALESCE(new_sn, ""), "-", ""), " ", "")) = ?', [$sn]);
                }
                if ($pppoe !== '') {
                    $query->orWhereRaw('LOWER(pppoe_user) = ?', [strtolower($pppoe)]);
                }
                if ($typedCustomer) {
                    $query->orWhere('customer_id', $typedCustomer->id);
                }
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $exact = [];
        $candidates = [];
        foreach ($exactRows as $row) {
            $bySn = $sn !== '' && $this->migrations->normalizeSerialForMatch((string) $row->new_sn) === $sn;
            $byPppoe = $pppoe !== '' && (
                strcasecmp((string) $row->pppoe_user, $pppoe) === 0
                || ($typedCustomer && (int) $row->customer_id === (int) $typedCustomer->id)
            );
            // Typed PPPoE is another existing customer than the migration's
            // one: never auto-link on SN alone.
            if ($bySn && ! $byPppoe && $typedCustomer && $row->customer_id && (int) $row->customer_id !== (int) $typedCustomer->id) {
                $candidates[$row->id] = $this->candidate($row, $payload, ['identity_conflict'], 0);

                continue;
            }
            if ($bySn || $byPppoe) {
                $exact[$row->id] = [$row, $bySn ? ($byPppoe ? 'exact_sn_pppoe' : 'exact_sn') : 'exact_pppoe'];
            }
        }

        $result = $empty;
        if (count($exact) === 1) {
            [$row, $matchedBy] = array_values($exact)[0];
            $probe = $this->candidate($row, $payload, [$matchedBy], 0);
            if ($probe['linkable']) {
                $result['exact'] = $row;
                $result['matched_by'] = $matchedBy;
            } else {
                $candidates[$row->id] = $probe;
            }
        } else {
            foreach ($exact as $id => [$row, $matchedBy]) {
                $candidates[$id] = $this->candidate($row, $payload, [$matchedBy, 'ambiguous'], 0);
            }
        }

        if (! $result['exact'] && ($oltId > 0 || $routerId > 0)) {
            foreach ($this->nearMatches($sn, $pppoe, $typedCustomer, $oltId, $routerId, array_keys($candidates)) as $id => $near) {
                $candidates[$id] ??= $near;
            }
        }

        $result['candidates'] = array_slice(array_values($candidates), 0, 3);

        return $result;
    }

    /**
     * Manual FiberHome provision recording (KPI + migration), by purpose:
     *  - migration: complete the open migration in place; only without one a
     *    `manual_fiberhome` migration is created (legacy path).
     *  - psb_new / psb_wa: PSB draft, linked to an exact open migration.
     *  - migration_wa: nothing recorded; the WA flow owns the migration.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> response fragment (recorded_as, record_id, migration_*)
     */
    public function recordFiberhomeProvision(array $payload, string $purpose, ?string $actor = null): array
    {
        if ($purpose === 'migration') {
            $link = $this->recordAfterProvision($payload, null, true, $actor);
            if (isset($link['migration_link'])) {
                return $link + ['recorded_as' => 'migration', 'record_id' => $link['migration_link']['migration_id']];
            }
            $migration = $this->migrations->recordManualFiberhomeProvision($payload);

            return ['recorded_as' => 'migration', 'record_id' => $migration?->id] + $link;
        }
        if ($purpose === 'migration_wa') {
            return ['recorded_as' => 'migration_existing'] + $this->recordAfterProvision($payload, null, false, $actor);
        }

        $draft = app(PsbActivationRecorder::class)->recordFiberhomeProvision($payload);
        $link = $this->recordAfterProvision($payload, $draft, true, $actor);

        return [
            'recorded_as' => isset($link['migration_link']) ? 'migration_linked' : 'psb_new',
            'record_id' => $draft?->id,
        ] + $link;
    }

    /**
     * Post-provision recording for every manual UI path. Never throws; a
     * failure only adds a warning (the ONU is already live on the OLT).
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $autoLink  false for `migration_wa` (the WA flow still owns the MikroTik step)
     * @return array<string, mixed> response fragment: migration_link, migration_candidates, migration_warning
     */
    public function recordAfterProvision(array $payload, ?PsbDraft $draft, bool $autoLink = true, ?string $actor = null): array
    {
        try {
            $match = $this->match($payload);
            $out = [];
            if ($match['exact']) {
                if (! $autoLink) {
                    $migration = $match['exact'];
                    $out['migration_open'] = $this->summary($migration);
                    $out['migration_warning'] = "Migrasi WA #{$migration->id} ({$migration->pppoe_user}) masih terbuka; lanjutkan lewat WA (#migrasi eksekusi #{$migration->id}).";

                    return $out;
                }
                $migration = $this->migrations->applyManualProvisionLink(
                    $match['exact'],
                    $payload,
                    $draft,
                    (string) $match['matched_by'],
                    $actor,
                );
                $out['migration_link'] = $this->linkSummary($migration, $draft, (string) $match['matched_by']);

                return $out;
            }
            if ($match['candidates'] !== []) {
                $out['migration_candidates'] = $match['candidates'];
                $out['migration_warning'] = (string) $match['candidates'][0]['message'];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[manual provision] migration link failed', ['error' => SensitiveDataRedactor::redact($e->getMessage())]);

            return ['migration_warning' => 'Cek migrasi terbuka gagal: '.SensitiveDataRedactor::redact($e->getMessage())];
        }
    }

    /**
     * Explicit technician confirmation of a near match. Re-runs the matcher
     * (the migration must still be an exact or near candidate) and the
     * ownership guards before linking.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, message: string, migration_link?: array<string, mixed>, blocked_reasons?: list<string>}
     */
    public function confirm(CustomerMigration $migration, array $payload, ?PsbDraft $draft, ?string $actor = null): array
    {
        if (! $migration->isOpen()) {
            return ['ok' => false, 'status' => 409, 'message' => "Migrasi #{$migration->id} sudah {$migration->status}."];
        }

        $match = $this->match($payload);
        $candidate = null;
        if ($match['exact'] && (int) $match['exact']->id === (int) $migration->id) {
            $candidate = ['linkable' => true, 'blocked_reasons' => []];
        }
        foreach ($match['candidates'] as $row) {
            if ((int) $row['migration_id'] === (int) $migration->id) {
                $candidate = $row;
            }
        }
        if (! $candidate) {
            return ['ok' => false, 'status' => 422, 'message' => "Migrasi #{$migration->id} tidak cocok dengan SN/PPPoE provision ini."];
        }
        if (! $candidate['linkable']) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => "Migrasi #{$migration->id} tidak bisa ditautkan: ".implode('; ', $candidate['blocked_reasons']),
                'blocked_reasons' => $candidate['blocked_reasons'],
            ];
        }

        try {
            $linked = $this->migrations->applyManualProvisionLink($migration, $payload, $draft, 'confirmed', $actor);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'status' => 422, 'message' => SensitiveDataRedactor::redact($e->getMessage())];
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => "Tertaut ke migrasi #{$linked->id}.",
            'migration_link' => $this->linkSummary($linked, $draft, 'confirmed'),
        ];
    }

    /**
     * @param  list<int>  $skipIds
     * @return array<int, array<string, mixed>>
     */
    protected function nearMatches(string $sn, string $pppoe, ?Customer $typedCustomer, int $oltId, int $routerId, array $skipIds): array
    {
        $typedToken = $pppoe !== '' ? $this->migrations->onuNameTokenForMatch($pppoe) : '';
        $payload = ['sn' => $sn, 'name' => $pppoe, 'olt_id' => $oltId, 'router_id' => $routerId];
        $out = [];

        CustomerMigration::query()
            ->open()
            ->with('customer:id,pppoe_user')
            ->where(function ($query) use ($oltId, $routerId): void {
                if ($oltId > 0) {
                    $query->orWhere('new_olt_id', $oltId);
                }
                if ($routerId > 0) {
                    $query->orWhere('router_id', $routerId);
                }
            })
            ->when($skipIds !== [], fn ($query) => $query->whereNotIn('id', $skipIds))
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->each(function (CustomerMigration $row) use (&$out, $sn, $pppoe, $typedToken, $typedCustomer, $payload): void {
                $reasons = [];
                $distance = PHP_INT_MAX;

                // PPPoE near only when the typed name is not some other
                // existing customer (ANDI1 vs ANDI2 are different people).
                if ($pppoe !== '' && (! $typedCustomer || (int) $typedCustomer->id === (int) $row->customer_id)) {
                    foreach (array_filter([(string) $row->pppoe_user, (string) $row->customer?->pppoe_user]) as $identity) {
                        $d = $this->nameDistance($pppoe, $typedToken, $identity);
                        if ($d !== null) {
                            $reasons['pppoe_near'] = true;
                            $distance = min($distance, $d);
                        }
                    }
                }
                $rowSn = $this->migrations->normalizeSerialForMatch((string) $row->new_sn);
                if ($sn !== '' && strlen($sn) >= 8 && $rowSn !== '' && abs(strlen($rowSn) - strlen($sn)) <= self::MAX_DISTANCE) {
                    $d = levenshtein($rowSn, $sn);
                    if ($d >= 1 && $d <= self::MAX_DISTANCE) {
                        $reasons['sn_near'] = true;
                        $distance = min($distance, $d);
                    }
                }
                if ($reasons !== []) {
                    $out[$row->id] = $this->candidate($row, $payload, array_keys($reasons), $distance);
                }
            });

        uasort($out, fn (array $a, array $b): int => [count($b['reasons']), $a['distance']] <=> [count($a['reasons']), $b['distance']]);

        return $out;
    }

    protected function nameDistance(string $typed, string $typedToken, string $identity): ?int
    {
        $identity = $this->migrations->normalizeUsernameForMatch($identity);
        if ($identity === '' || strcasecmp($identity, $typed) === 0) {
            return null;
        }
        $full = levenshtein(strtoupper($identity), strtoupper($typed));
        if ($full >= 1 && $full <= self::MAX_DISTANCE) {
            return $full;
        }
        $token = $this->migrations->onuNameTokenForMatch($identity);
        if (strlen($token) < 4 || strlen($typedToken) < 4 || substr($token, 0, 4) !== substr($typedToken, 0, 4)) {
            return null;
        }
        $d = levenshtein($token, $typedToken);

        return ($d >= 1 && $d <= self::MAX_DISTANCE) ? $d : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    protected function candidate(CustomerMigration $row, array $payload, array $reasons, int $distance): array
    {
        $customer = $row->customer_id ? Customer::query()->find($row->customer_id) : null;
        $oltId = (int) ($payload['olt_id'] ?? 0);
        $olt = $oltId > 0 ? Olt::query()->find($oltId) : null;
        $port = (string) ($payload['port'] ?? '');
        $onuId = (int) ($payload['onu_id'] ?? 0);
        $ref = ($port !== '' && $onuId > 0) ? strtolower($port).':'.$onuId : null;
        $blocked = $this->migrations->manualProvisionLinkConflicts(
            $row,
            $customer,
            (string) ($payload['sn'] ?? ''),
            $olt,
            $ref,
            (int) ($payload['router_id'] ?? 0) ?: null,
        );
        if (in_array('identity_conflict', $reasons, true)) {
            $blocked[] = 'PPPoE yang diketik milik pelanggan lain';
        }
        $blocked = array_values(array_unique($blocked));
        $pppoe = (string) ($row->pppoe_user ?: $customer?->pppoe_user ?: '-');
        $sn = (string) ($row->new_sn ?: '-');
        $linkable = $blocked === [];
        $prefix = in_array('ambiguous', $reasons, true) ? 'Lebih dari satu migrasi cocok. ' : '';

        return [
            'migration_id' => $row->id,
            'customer_id' => $row->customer_id,
            'pppoe_user' => $row->pppoe_user ?: $customer?->pppoe_user,
            'new_sn' => $row->new_sn,
            'status' => $row->status,
            'reasons' => $reasons,
            'distance' => $distance === PHP_INT_MAX ? null : $distance,
            'linkable' => $linkable,
            'blocked_reasons' => $linkable ? [] : $blocked,
            'message' => $linkable
                ? $prefix."Ada migrasi terbuka #{$row->id} untuk {$pppoe} (SN {$sn}). Tautkan?"
                : $prefix."Ada migrasi terbuka #{$row->id} untuk {$pppoe} (SN {$sn}), tapi tidak bisa ditautkan: ".implode('; ', $blocked),
        ];
    }

    /** @return array<string, mixed> */
    protected function summary(CustomerMigration $migration): array
    {
        return [
            'migration_id' => $migration->id,
            'customer_id' => $migration->customer_id,
            'pppoe_user' => $migration->pppoe_user,
            'status' => $migration->status,
            'new_sn' => $migration->new_sn,
        ];
    }

    /** @return array<string, mixed> */
    protected function linkSummary(CustomerMigration $migration, ?PsbDraft $draft, string $matchedBy): array
    {
        $mikrotikVerified = (bool) data_get($migration->meta, 'manual_provision_link.mikrotik_step_verified', false);
        $crossRouter = (bool) data_get($migration->meta, 'manual_provision_link.cross_router', false);

        return $this->summary($migration) + [
            'psb_draft_id' => $draft?->id,
            'matched_by' => $matchedBy,
            'onu_ref' => $migration->new_onu_ref,
            'message' => "Tertaut ke migrasi #{$migration->id} ({$migration->pppoe_user}) — status synced.",
            'mikrotik_note' => ($crossRouter && ! $mikrotikVerified)
                ? 'Secret PPPoE di router target belum terverifikasi oleh alur migrasi; cek manual di MikroTik.'
                : null,
            'old_onu_note' => ($migration->old_onu_ref && ! $migration->old_onu_deleted_at)
                ? "ONU lama {$migration->old_onu_ref} (OLT #{$migration->old_olt_id}) tidak dihapus otomatis; hapus manual lewat menu OLT bila sudah tidak dipakai."
                : null,
        ];
    }
}
