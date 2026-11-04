<?php

namespace App\Services\Psb;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\GenieAcsService;
use App\Services\MikrotikService;
use App\Services\OltOnuSerialResolver;
use App\Services\OltZteC300Service;
use Illuminate\Support\Facades\Schema;

/**
 * PSB WA: live correction (a resend #psb that changes a live draft). Snapshot,
 * mark-pending, preflight and the locked bodies of apply/cancel.
 *
 * Moved as-is from PsbOrchestratorService (PR 2 of the PSB refactor, no
 * behaviour change). The facade keeps applyPsbCorrection/cancelPsbCorrection
 * with their guards (status, writes switches, lock) in the original order and
 * only then calls applyLocked/cancelLocked. Username building, error shortening
 * and phone normalising are reached through the facade (closures), so an
 * override there still wins. Device calls use the facade's own MikroTik edge;
 * OLT and GenieACS stay behind app() as before.
 */
final class PsbLiveCorrection
{
    /**
     * @param  \Closure(PsbDraft): string  $buildPppoeUsername  the orchestrator's buildPppoeUsername
     * @param  \Closure(string): string  $shortError  the orchestrator's shortError
     * @param  \Closure(mixed): string  $normalizePsbPhone  the orchestrator's normalizePsbPhone
     */
    public function __construct(
        private readonly MikrotikService $mikrotik,
        private readonly \Closure $buildPppoeUsername,
        private readonly \Closure $shortError,
        private readonly \Closure $normalizePsbPhone,
    ) {}

    /** @return array<string, mixed> */
    public function psbCorrectionSnapshot(PsbDraft $draft): array
    {
        return [
            'full_name' => $draft->full_name,
            'rt' => $draft->rt,
            'rw' => $draft->rw,
            'odp' => $draft->odp,
            'daerah' => $draft->daerah,
            'phone' => $draft->phone,
            'sn' => $draft->sn,
            'router_id' => $draft->router_id,
            'olt_id' => $draft->olt_id,
            'package_id' => $draft->package_id,
            'pppoe_user' => $draft->pppoe_user,
            'pppoe_local_address' => $draft->pppoe_local_address,
            'pppoe_remote_address' => $draft->pppoe_remote_address,
            'mikrotik_profile' => $draft->mikrotik_profile,
            'customer_id' => $draft->customer_id,
            'status' => $draft->status,
        ];
    }

    /**
     * Phone is shown in the correction WA and synced on apply, but it never
     * starts a correction by itself (callers only reach this with a real diff).
     *
     * @param  array<string, array{old: string, new: string}>  $diff
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function addPhoneDiff(array &$diff, array $before, array $after): void
    {
        if (! array_key_exists('phone', $before)) {
            return;
        }
        $old = $this->normalizeCorrectionValue($before['phone'] ?? null);
        $new = $this->normalizeCorrectionValue($after['phone'] ?? null);
        if (($this->normalizePsbPhone)($old) !== ($this->normalizePsbPhone)($new)) {
            $diff['phone'] = ['old' => $old, 'new' => $new];
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $payload
     */
    public function markLiveCorrectionPendingIfNeeded(PsbDraft $draft, array $before, array $parsed, array $payload): bool
    {
        if (! $this->isLivePsbDraft($before)) {
            return false;
        }

        $oldUser = trim((string) ($before['pppoe_user'] ?? ''));
        $newUser = ($this->buildPppoeUsername)($draft);
        $after = $this->psbCorrectionSnapshot($draft);
        $after['pppoe_user'] = $newUser ?: $oldUser;

        $watch = ['full_name', 'rt', 'rw', 'odp', 'daerah', 'sn', 'router_id', 'olt_id', 'package_id'];
        $diff = [];
        foreach ($watch as $key) {
            $old = $this->normalizeCorrectionValue($before[$key] ?? null);
            $new = $this->normalizeCorrectionValue($after[$key] ?? null);
            if ($old !== $new) {
                $diff[$key] = ['old' => $old, 'new' => $new];
            }
        }
        if ($newUser !== '' && $oldUser !== '' && strcasecmp($oldUser, $newUser) !== 0) {
            $diff['pppoe_user'] = ['old' => $oldUser, 'new' => $newUser];
        }

        if ($diff === []) {
            // A phone-only change is not a provisioning change.
            return false;
        }
        $this->addPhoneDiff($diff, $before, $after);

        $meta = is_array($draft->meta) ? $draft->meta : [];
        // A pending correction already holds the unapproved values in the draft row.
        // Keep the ORIGINAL snapshot as "before" (and diff against it), so
        // `#psb batal` restores the real pre-correction values.
        $pending = is_array($meta['psb_correction'] ?? null) ? $meta['psb_correction'] : [];
        if (($before['status'] ?? null) === PsbDraft::STATUS_CORRECTION_PENDING
            && ($pending['status'] ?? null) === 'pending_review'
            && is_array($pending['before'] ?? null)) {
            $before = $pending['before'];
            $oldUser = trim((string) ($before['pppoe_user'] ?? $oldUser));
            $diff = [];
            foreach ($watch as $key) {
                $old = $this->normalizeCorrectionValue($before[$key] ?? null);
                $new = $this->normalizeCorrectionValue($after[$key] ?? null);
                if ($old !== $new) {
                    $diff[$key] = ['old' => $old, 'new' => $new];
                }
            }
            if ($newUser !== '' && $oldUser !== '' && strcasecmp($oldUser, $newUser) !== 0) {
                $diff['pppoe_user'] = ['old' => $oldUser, 'new' => $newUser];
            }
            $this->addPhoneDiff($diff, $before, $after);
        }

        $preflight = $this->psbCorrectionPreflight($draft, $oldUser, $newUser);
        $meta['psb_correction'] = [
            'status' => 'pending_review',
            'reason' => 'Resend #psb mengubah data provisioning setelah PPPoE/OLT live.',
            'before' => $before,
            'after' => $after,
            'diff' => $diff,
            'preflight' => $preflight,
            'requested_at' => now()->toIso8601String(),
            'requested_by' => $payload['sender_name'] ?? $payload['sender_jid'] ?? null,
            'raw' => $parsed['raw'] ?? null,
        ];
        $draft->meta = $meta;
        $draft->status = PsbDraft::STATUS_CORRECTION_PENDING;
        $draft->pppoe_user = $oldUser ?: $draft->pppoe_user;
        $draft->last_error = 'Koreksi provisioning menunggu approval; perangkat/customer belum diubah.';

        return true;
    }

    /** @param array<string, mixed> $before */
    public function isLivePsbDraft(array $before): bool
    {
        $status = (string) ($before['status'] ?? '');

        return in_array($status, [PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CORRECTION_PENDING], true)
            && trim((string) ($before['pppoe_user'] ?? '')) !== '';
    }

    public function normalizeCorrectionValue(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? '');
    }

    /** @return array<string, mixed> */
    public function psbCorrectionPreflight(PsbDraft $draft, string $oldUser, string $newUser): array
    {
        $out = [
            'db_customer' => $draft->customer_id ? 'linked #'.$draft->customer_id : 'draft_only',
            'mikrotik' => ['old_secret' => 'not_checked', 'new_secret' => 'not_checked', 'active' => 'not_checked'],
            'olt' => ['snapshot' => 'not_checked'],
            'genieacs' => ['snapshot' => 'not_checked'],
            'checked_at' => now()->toIso8601String(),
        ];

        if ($draft->router_id && ($router = Router::find($draft->router_id))) {
            try {
                $this->mikrotik->connect($router, ['timeout' => max(2, min(8, (int) Setting::get('psb_pppoe_search_timeout_seconds', 4))), 'attempts' => 1]);
                try {
                    $oldSecret = $oldUser !== '' ? $this->mikrotik->getPPPSecret($oldUser) : null;
                    $newSecret = $newUser !== '' ? $this->mikrotik->getPPPSecret($newUser) : null;
                    $activeRows = collect($this->mikrotik->getActiveConnections(true));
                } finally {
                    $this->mikrotik->disconnect();
                }
                $out['mikrotik'] = [
                    'router' => $router->name,
                    'old_secret' => $oldSecret ? 'live' : 'missing',
                    'new_secret' => $newSecret ? 'live' : 'missing',
                    'active' => $activeRows->first(fn ($row) => strcasecmp((string) ($row['name'] ?? ''), $oldUser) === 0 || strcasecmp((string) ($row['name'] ?? ''), $newUser) === 0)
                        ? 'online'
                        : 'offline',
                ];
            } catch (\Throwable $e) {
                $out['mikrotik'] = ['router' => $router->name, 'error' => ($this->shortError)($e->getMessage())];
            }
        }

        $sn = strtoupper(trim((string) $draft->sn));
        if ($sn !== '' && Schema::hasTable('olt_onus')) {
            // Same serial may be registered twice (stale offline duplicate):
            // pick the live row deterministically instead of the newest inventory.
            $candidates = OltOnu::query()
                ->whereRaw('UPPER(serial_number) = ?', [$sn])
                ->get(['id', 'olt_id', 'onu_ref', 'pon_port', 'onu_name', 'state', 'phase_state', 'rx_power_dbm', 'state_collected_at', 'rx_collected_at', 'inventory_collected_at']);
            $onu = app(OltOnuSerialResolver::class)->pick($candidates, $draft->olt_id ? (int) $draft->olt_id : null);
            $out['olt'] = $onu ? [
                'snapshot' => 'found',
                'olt_id' => $onu->olt_id,
                'onu_ref' => $onu->onu_ref,
                'pon_port' => $onu->pon_port,
                'onu_name' => $onu->onu_name,
                'state' => $onu->state,
                'rx' => $onu->rx_power_dbm,
                'seen_at' => $onu->inventory_collected_at?->toIso8601String(),
            ] : ['snapshot' => 'not_found'];
        }

        if ($sn !== '' && Schema::hasTable('genieacs_devices')) {
            $acs = GenieAcsDevice::query()
                ->whereRaw('UPPER(serial_number) = ?', [$sn])
                ->orderByDesc('last_inform_at')
                ->first(['device_id', 'tags', 'pppoe_user', 'genieacs_customer_name', 'last_inform_at']);
            $out['genieacs'] = $acs ? [
                'snapshot' => 'found',
                'device_id' => $acs->device_id,
                'tags' => $acs->tags,
                'pppoe_user' => $acs->pppoe_user,
                'name' => $acs->genieacs_customer_name,
                'last_inform_at' => $acs->last_inform_at?->toIso8601String(),
            ] : ['snapshot' => 'not_found'];
        }

        return $out;
    }

    /**
     * The body of applyPsbCorrection that runs while the facade holds the
     * psb-correction lock (the facade releases it).
     */
    public function applyLocked(PsbDraft $draft): PsbDraft
    {
        $draft = $draft->fresh() ?? $draft;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $corr = is_array($meta['psb_correction'] ?? null) ? $meta['psb_correction'] : [];
        $before = is_array($corr['before'] ?? null) ? $corr['before'] : [];
        $after = is_array($corr['after'] ?? null) ? $corr['after'] : [];
        $oldUser = trim((string) ($before['pppoe_user'] ?? $draft->pppoe_user ?? ''));
        $newUser = trim((string) ($after['pppoe_user'] ?? ($this->buildPppoeUsername)($draft)));
        if ($oldUser === '' || $newUser === '') {
            throw new \RuntimeException('Username lama/baru kosong, koreksi dibatalkan.');
        }

        $steps = [];
        $router = $draft->router_id ? Router::find($draft->router_id) : null;
        if ($router) {
            $this->mikrotik->connect($router, ['timeout' => 8, 'attempts' => 1]);
            try {
                $secretOld = $this->mikrotik->getPPPSecret($oldUser);
                $sourcePassword = trim((string) ($secretOld['password'] ?? ''));
                if (! $secretOld || $sourcePassword === '' || preg_match('/^\*+$/', $sourcePassword) === 1) {
                    throw new \RuntimeException('Password PPPoE source tidak terbaca di MikroTik; koreksi dibatalkan tanpa fallback database.');
                }
                $secretNew = strcasecmp($oldUser, $newUser) !== 0 ? $this->mikrotik->getPPPSecret($newUser) : $secretOld;
                if ($secretNew) {
                    $targetPassword = trim((string) ($secretNew['password'] ?? ''));
                    if ($targetPassword === '' || preg_match('/^\*+$/', $targetPassword) === 1 || ! hash_equals(hash('sha256', $sourcePassword), hash('sha256', $targetPassword))) {
                        throw new \RuntimeException('Password PPPoE target conflict; koreksi dibatalkan tanpa overwrite otomatis.');
                    }
                    $steps['mikrotik'] = 'secret baru sudah live';
                } elseif ($secretOld) {
                    $this->mikrotik->renamePPPSecret(
                        $oldUser,
                        $newUser,
                        $sourcePassword,
                        (string) ($draft->mikrotik_profile ?: ($before['mikrotik_profile'] ?? '')),
                        $draft->pppoe_local_address ?: ($before['pppoe_local_address'] ?? null),
                        $draft->pppoe_remote_address ?: ($before['pppoe_remote_address'] ?? null),
                        'correction-'.$draft->id
                    );
                    $secretNew = $this->mikrotik->getPPPSecret($newUser);
                    $targetPassword = trim((string) ($secretNew['password'] ?? ''));
                    if (! $secretNew || $targetPassword === '' || preg_match('/^\*+$/', $targetPassword) === 1 || ! hash_equals(hash('sha256', $sourcePassword), hash('sha256', $targetPassword))) {
                        throw new \RuntimeException('Password PPPoE target gagal diverifikasi setelah koreksi; database tidak diubah.');
                    }
                    $steps['mikrotik'] = 'secret renamed';
                } else {
                    throw new \RuntimeException("Secret lama `{$oldUser}` dan secret baru `{$newUser}` tidak ditemukan di MikroTik.");
                }
            } finally {
                $this->mikrotik->disconnect();
            }
        } else {
            throw new \RuntimeException('Router pelanggan tidak tersedia; password hanya boleh diambil dari MikroTik.');
        }

        if ($draft->customer_id && ($customer = Customer::find($draft->customer_id))) {
            $verifiedCustomerPassword = trim((string) ($secretNew['password'] ?? ''));
            if ($verifiedCustomerPassword === '' || preg_match('/^\*+$/', $verifiedCustomerPassword) === 1) {
                throw new \RuntimeException('Password PPPoE hasil koreksi tidak terbaca dari MikroTik; database tidak diubah.');
            }
            $customerUpdate = [
                'pppoe_user' => $newUser,
                'pppoe_password' => $verifiedCustomerPassword,
                'router_id' => $draft->router_id ?: $customer->router_id,
                'package_id' => $draft->package_id ?: $customer->package_id,
                'olt_id' => $draft->olt_id ?: $customer->olt_id,
                'olt_port_label' => $draft->pon_port ?: $customer->olt_port_label,
                'mikrotik_profile' => $draft->mikrotik_profile ?: $customer->mikrotik_profile,
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
            ];
            if (Schema::hasColumn('customers', 'odp_name')) {
                $customerUpdate['odp_name'] = $draft->odp;
            }
            if ($draft->rt || $draft->rw || $draft->odp || $draft->daerah) {
                $customerUpdate['address'] = trim('RT '.$draft->rt.' / RW '.$draft->rw.' - ODP '.$draft->odp.' - '.($draft->daerah ?? ''));
            }
            if (Schema::hasColumn('customers', 'onu_serial') && $draft->sn) {
                $customerUpdate['onu_serial'] = strtoupper(preg_replace('/\s+/', '', (string) $draft->sn) ?? (string) $draft->sn);
            }
            // Only a phone change that was shown in the approval WA.
            $phoneDiff = is_array($corr['diff'] ?? null) ? ($corr['diff']['phone'] ?? null) : null;
            if (is_array($phoneDiff) && trim((string) ($phoneDiff['new'] ?? '')) !== '') {
                $customerUpdate['phone'] = trim((string) $phoneDiff['new']);
            }
            // Only a name change that was shown in the approval WA.
            $nameDiff = is_array($corr['diff'] ?? null) ? ($corr['diff']['full_name'] ?? null) : null;
            if (is_array($nameDiff) && trim((string) ($nameDiff['new'] ?? '')) !== '') {
                $customerUpdate['name'] = trim((string) $nameDiff['new']);
            }
            $customer->forceFill(array_filter($customerUpdate, fn ($v) => $v !== null && $v !== ''))->save();
            $steps['customer'] = 'updated #'.$customer->id;
        }

        $sn = strtoupper(trim((string) $draft->sn));
        if ($sn !== '' && $draft->olt_id && Schema::hasTable('olt_onus')) {
            $onu = OltOnu::query()
                ->where('olt_id', $draft->olt_id)
                ->whereRaw('UPPER(serial_number) = ?', [$sn])
                ->orderByDesc('inventory_collected_at')
                ->first();
            if ($onu?->onu_ref && ($olt = Olt::find($draft->olt_id))) {
                try {
                    app(OltZteC300Service::class)->renameOnu($olt, (string) $onu->onu_ref, $newUser);
                    $steps['olt'] = 'onu renamed';
                } catch (\Throwable $e) {
                    $steps['olt'] = 'soft-fail: '.($this->shortError)($e->getMessage());
                }
            }
        }

        if ($sn !== '') {
            try {
                $acs = app(GenieAcsService::class)->ensureTagAfterProvision($sn, $newUser);
                $steps['genieacs'] = ! empty($acs['pushed']) ? 'tag pushed' : ('pending: '.($acs['message'] ?? 'not pushed'));
            } catch (\Throwable $e) {
                $steps['genieacs'] = 'soft-fail: '.($this->shortError)($e->getMessage());
            }
        }

        $draft->pppoe_user = $newUser;
        $draft->status = $draft->olt_at ? PsbDraft::STATUS_OLT_OK : PsbDraft::STATUS_SECRET_OK;
        $draft->last_error = null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['psb_correction']['status'] = 'applied';
        $meta['psb_correction']['applied_at'] = now()->toIso8601String();
        $meta['psb_correction']['steps'] = $steps;
        $meta['psb_correction']['rollback_hint'] = "Rename PPPoE `{$newUser}` kembali ke `{$oldUser}` dan restore customer/draft dari meta.psb_correction.before jika perlu.";
        $draft->meta = $meta;
        $draft->save();

        return $draft;
    }

    /**
     * The body of cancelPsbCorrection that runs while the facade holds the
     * psb-correction lock (the facade releases it).
     */
    public function cancelLocked(PsbDraft $draft, mixed $actor = null): PsbDraft
    {
        $draft = $draft->fresh() ?? $draft;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $corr = is_array($meta['psb_correction'] ?? null) ? $meta['psb_correction'] : [];
        $before = is_array($corr['before'] ?? null) ? $corr['before'] : [];
        $restore = fn (string $key, mixed $fallback = null) => array_key_exists($key, $before) ? $before[$key] : $fallback;

        $draft->forceFill([
            'status' => $restore('status', $draft->olt_at ? PsbDraft::STATUS_OLT_OK : PsbDraft::STATUS_SECRET_OK),
            'full_name' => $restore('full_name', $draft->full_name),
            'rt' => $restore('rt', $draft->rt),
            'rw' => $restore('rw', $draft->rw),
            'odp' => $restore('odp', $draft->odp),
            'daerah' => $restore('daerah', $draft->daerah),
            'phone' => $restore('phone', $draft->phone),
            'sn' => $restore('sn', $draft->sn),
            'router_id' => $restore('router_id', $draft->router_id),
            'olt_id' => $restore('olt_id', $draft->olt_id),
            'package_id' => $restore('package_id', $draft->package_id),
            'pppoe_user' => $restore('pppoe_user', $draft->pppoe_user),
            'pppoe_local_address' => $restore('pppoe_local_address', $draft->pppoe_local_address),
            'pppoe_remote_address' => $restore('pppoe_remote_address', $draft->pppoe_remote_address),
            'mikrotik_profile' => $restore('mikrotik_profile', $draft->mikrotik_profile),
            'last_error' => null,
        ]);

        $meta['psb_correction_cancelled'] = [
            'cancelled_at' => now()->toIso8601String(),
            'cancelled_by' => $actor,
            'restored_status' => $draft->status,
            'discarded_diff' => $corr['diff'] ?? [],
        ];
        unset($meta['psb_correction']);
        $draft->meta = $meta;
        $draft->save();

        return $draft;
    }
}
