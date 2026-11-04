<?php

namespace App\Services;

use App\Jobs\ResolveGuidedMigrationLookupJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\GenieAcsDevice;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Models\RouterMtSnapshot;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class GuidedMigrationLookupService
{
    public const QUEUE = 'migration-lookup';

    public const FEATURE_FLAG = 'migration_guided_lookup_enabled';

    public const ALLOWED_ROLES = 'migration_guided_lookup_allowed_roles';

    public const ALLOWED_USER_IDS = 'migration_guided_lookup_allowed_user_ids';

    public const ALLOWED_SENDER_JIDS = 'migration_guided_lookup_allowed_sender_jids';

    public const ALLOWED_GROUP_JIDS = 'migration_guided_lookup_allowed_group_jids';

    /**
     * The feature remains fail-closed until the owner enables it explicitly.
     */
    public function enabled(): bool
    {
        return filter_var(Setting::get(self::FEATURE_FLAG, '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public function canPreview(mixed $user): bool
    {
        if (! $user instanceof User || ! $user->isGlobalAdmin()) {
            return false;
        }

        if (! $this->scopedAllowlistEnabled()) {
            return true;
        }

        return in_array((string) $user->role, $this->allowedRoles(), true)
            || in_array((string) $user->id, $this->allowedUserIds(), true);
    }

    /**
     * @return array{enabled: bool, allowed_roles: list<string>, allowed_user_count: int}
     */
    public function allowlistMeta(): array
    {
        return [
            'enabled' => $this->scopedAllowlistEnabled(),
            'allowed_roles' => $this->allowedRoles(),
            'allowed_user_count' => count($this->allowedUserIds()),
            'wa_enabled' => $this->allowedSenderJids() !== [] || $this->allowedGroupJids() !== [],
            'allowed_sender_count' => count($this->allowedSenderJids()),
            'allowed_group_count' => count($this->allowedGroupJids()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function waAllowlistGuard(array $payload): array
    {
        if (! $this->enabled()) {
            return ['allowed' => false, 'reasons' => ['feature_disabled']];
        }

        $senders = array_values(array_filter([
            (string) ($payload['sender_jid'] ?? ''),
            (string) ($payload['sender_jid_pn'] ?? ''),
            (string) ($payload['sender_jid_lid'] ?? ''),
            (string) ($payload['participant'] ?? ''),
        ]));
        $group = (string) ($payload['group_jid'] ?? '');
        $senderAllowed = $this->matchesAnyJid($senders, $this->allowedSenderJids());
        $groupAllowed = $this->matchesAnyJid([$group], $this->allowedGroupJids());

        if (! $senderAllowed && ! $groupAllowed) {
            return ['allowed' => false, 'reasons' => ['wa_sender_or_group_not_allowlisted']];
        }

        return ['allowed' => true, 'reasons' => []];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canUseWa(array $payload): bool
    {
        return $this->waAllowlistGuard($payload)['allowed'];
    }

    /**
     * Group-only guard for technician confirmation. Sender-only allowlisting
     * must never open device automation in another group.
     *
     * @param  array<string, mixed>  $payload
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function waGroupAllowlistGuard(array $payload): array
    {
        if (! $this->enabled()) {
            return ['allowed' => false, 'reasons' => ['feature_disabled']];
        }

        $groups = $this->allowedGroupJids();
        if ($groups === [] || ! $this->matchesAnyJid([(string) ($payload['group_jid'] ?? '')], $groups)) {
            return ['allowed' => false, 'reasons' => ['group_not_allowlisted']];
        }

        return ['allowed' => true, 'reasons' => []];
    }

    /**
     * Device execution is stricter than preview: the group must match the
     * activation allowlist and the sender must be statically allowlisted or
     * bound to the migration operation that is being executed.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $operationBoundSenders
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function waExecutionAllowlistGuard(array $payload, array $operationBoundSenders = []): array
    {
        $reasons = [];
        $groups = $this->allowedGroupJids();
        $senders = $this->allowedSenderJids();
        if (! $this->enabled()) {
            $reasons[] = 'feature_disabled';
        }
        if ($groups === [] || ! $this->matchesAnyJid([(string) ($payload['group_jid'] ?? '')], $groups)) {
            $reasons[] = 'group_not_allowlisted';
        }
        $actualSenders = array_values(array_filter([
            (string) ($payload['sender_jid'] ?? ''),
            (string) ($payload['sender_jid_pn'] ?? ''),
            (string) ($payload['sender_jid_lid'] ?? ''),
            (string) ($payload['participant'] ?? ''),
        ]));
        $senderAllowed = $senders !== [] && $this->matchesAnyJid($actualSenders, $senders);
        if (! $senderAllowed && $operationBoundSenders !== []) {
            $senderAllowed = $this->matchesAnyJid($actualSenders, $operationBoundSenders);
        }
        if (! $senderAllowed) {
            $reasons[] = 'sender_not_allowlisted';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @return array{allowed: bool, reasons: list<string>, psb_queue_size: int|null}
     */
    public function dispatchGuard(?int $psbQueueSize = null, ?bool $hostSafe = null): array
    {
        $reasons = [];
        if (! $this->enabled()) {
            $reasons[] = 'feature_disabled';
        }

        $hostSafe ??= filter_var(Setting::get('migration_guided_lookup_host_safe', '0'), FILTER_VALIDATE_BOOLEAN);
        if (! $hostSafe) {
            $reasons[] = 'host_not_acknowledged_safe';
        }

        if ($psbQueueSize === null) {
            try {
                $psbQueueSize = Queue::size('psb');
            } catch (\Throwable) {
                $reasons[] = 'psb_queue_unavailable';
            }
        }
        $threshold = max(0, (int) Setting::get('migration_guided_lookup_psb_queue_threshold', 0));
        if ($psbQueueSize !== null && $psbQueueSize > $threshold) {
            $reasons[] = 'psb_queue_busy';
        }
        if (! in_array((string) Cache::get('psb_wa:webhook_status', 'unknown'), ['OK', 'ok'], true)) {
            $reasons[] = 'psb_webhook_not_healthy';
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'psb_queue_size' => $psbQueueSize,
        ];
    }

    /**
     * No current webhook calls this method. It is the isolated queue contract for later rollout.
     *
     * @param  array<string, mixed>  $input
     */
    public function dispatch(array $input): bool
    {
        if (! $this->dispatchGuard()['allowed']) {
            return false;
        }
        if (! $this->canUseWa($input)) {
            return false;
        }

        ResolveGuidedMigrationLookupJob::dispatch($this->normalizeInput($input));

        return true;
    }

    /**
     * Pure read-model preview. It never saves a model or calls a device adapter.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $input = $this->normalizeInput($input);
        $missing = $this->missingRequirements($input);
        $base = [
            'readonly' => true,
            'feature_enabled' => $this->enabled(),
            'queue' => [
                'name' => self::QUEUE,
                'consumer_enabled' => false,
                'concurrency' => 1,
                'timeout_seconds' => 45,
            ],
            'idempotency_key' => 'migration-lookup:'.hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES)),
            'input' => array_filter([
                'name' => $input['name'],
                'new_sn' => $this->maskSerial($input['new_sn']),
                'old_sn' => $this->maskSerial($input['old_sn']),
                'pppoe_user' => $input['pppoe_user'],
                'phone' => $this->maskPhone($input['phone']),
                'customer_code' => $input['customer_code'],
                'area' => $input['area'],
                'odp' => $input['odp'],
                'rt' => $input['rt'],
                'rw' => $input['rw'],
                'onu_name' => $input['onu_name'],
                'olt' => $input['olt'],
            ], fn ($value) => $value !== ''),
            'required' => 'nama + SN baru + HP/customer code/(daerah+ODP)/(RT+RW), atau nama ONU + OLT untuk preview',
            'missing' => $missing,
            'candidates' => [],
            'device_write' => false,
            'customer_write' => false,
            'live_device_query' => false,
        ];

        if ($missing !== []) {
            return $base + [
                'status' => 'input_incomplete',
                'reasons' => $missing,
                'next_action' => 'Lengkapi data pembeda; belum ada customer yang dipilih.',
            ];
        }

        if (! Schema::hasTable('customers')) {
            return $base + [
                'status' => 'needs_review',
                'reasons' => ['customer_read_model_unavailable'],
                'next_action' => 'NOC cek read model customer.',
            ];
        }

        $candidates = $this->customerCandidates($input);
        if ($candidates === [] && $input['onu_name'] !== '') {
            $candidates = $this->onuNameCandidates($input);
        }
        $serialOwners = $this->newSerialOwners($input['new_sn']);
        foreach ($candidates as &$candidate) {
            foreach ($serialOwners['customer_ids'] as $customerId) {
                if ($customerId === ($candidate['customer_id'] ?? null)) {
                    $candidate['warnings'][] = 'new_sn_already_current_customer';
                } else {
                    $candidate['conflicts'][] = 'new_sn_already_linked_to_other_customer';
                }
            }
            foreach ($serialOwners['psb_pppoe'] as $pppoe) {
                if ($pppoe !== '' && $pppoe !== strtolower((string) $candidate['pppoe_user'])) {
                    $candidate['conflicts'][] = 'new_sn_exists_in_other_psb_history';
                }
            }
            $candidate['conflicts'] = array_values(array_unique($candidate['conflicts']));
            $candidate['warnings'] = array_values(array_unique($candidate['warnings']));
        }
        unset($candidate);

        $topScore = $candidates[0]['score'] ?? null;
        $topCount = $topScore === null
            ? 0
            : count(array_filter($candidates, fn (array $candidate) => $candidate['score'] === $topScore));
        $topHasConflict = ($candidates[0]['conflicts'] ?? []) !== [];
        $ready = ($candidates[0]['confidence'] ?? '') === 'high'
            && $topCount === 1
            && ! $topHasConflict
            && ! ($candidates[0]['requires_manual_selection'] ?? false);
        $reasons = [];
        if ($candidates === []) {
            $reasons[] = 'customer_candidate_not_found';
        } elseif (($candidates[0]['customer_id'] ?? null) === null) {
            $reasons[] = 'onu_snapshot_found_without_customer';
        }
        if ($topCount > 1) {
            $reasons[] = 'candidate_ambiguous';
        }
        if ($topHasConflict) {
            $reasons[] = 'source_conflict';
        }
        if (! $ready && $reasons === []) {
            $reasons[] = 'operator_selection_required';
        }

        return array_merge($base, [
            'status' => $ready ? 'ready_for_selection' : 'needs_review',
            'reasons' => $reasons,
            'candidates' => $candidates,
            'next_action' => $candidates === []
                ? 'Periksa ejaan nama atau tambahkan customer code/HP/area+ODP/RT+RW.'
                : '#migrasi pilih #ID <nomor kandidat>',
        ]);
    }

    /**
     * @param  array<string, string>  $input
     * @return list<array<string, mixed>>
     */
    private function customerCandidates(array $input): array
    {
        if ($input['name'] === '' && $input['pppoe_user'] === '') {
            return [];
        }

        $oldSnCustomerIds = $this->customerIdsByOldSn($input['old_sn']);
        $terms = array_values(array_filter(
            explode(' ', $this->normalizeText($input['name'])),
            fn (string $term) => mb_strlen($term) >= 2
        ));
        $query = Customer::query()
            ->with(['area', 'router', 'olt', 'genieAcsDevice'])
            ->where(function ($query) use ($input, $terms, $oldSnCustomerIds): void {
                if ($input['pppoe_user'] !== '') {
                    $query->whereRaw('LOWER(pppoe_user) = ?', [strtolower($input['pppoe_user'])]);
                }
                if ($terms !== []) {
                    $method = $input['pppoe_user'] !== '' ? 'orWhere' : 'where';
                    $query->{$method}(function ($query) use ($terms): void {
                        foreach ($terms as $term) {
                            $query->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%']);
                        }
                    });
                }
                if ($oldSnCustomerIds !== []) {
                    $query->orWhereIn('id', $oldSnCustomerIds);
                }
            });

        $candidates = $query->limit(20)->get()
            ->map(fn (Customer $customer) => $this->candidate(
                $customer,
                $input,
                in_array((int) $customer->id, $oldSnCustomerIds, true)
            ))
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take(5)
            ->values()
            ->all();

        $top = $candidates[0] ?? [];
        $needsFuzzy = $input['name'] !== '' && $oldSnCustomerIds === [] && (
            $candidates === []
            || ($top['confidence'] ?? 'low') !== 'high'
            || ($top['conflicts'] ?? []) !== []
        );
        if ($needsFuzzy) {
            $candidates = collect(array_merge($candidates, $this->fuzzyNameCandidates($input)))
                ->unique('customer_id')
                ->sortByDesc('score')
                ->take(5)
                ->values()
                ->all();
        }

        return array_map(function (array $candidate, int $index): array {
            $candidate['number'] = $index + 1;

            return $candidate;
        }, $candidates, array_keys($candidates));
    }

    /** @return list<int> */
    private function customerIdsByOldSn(string $oldSn): array
    {
        if ($oldSn === '') {
            return [];
        }

        $ids = Customer::query()
            ->whereRaw('UPPER(onu_serial) = ?', [$oldSn])
            ->limit(10)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if (Schema::hasTable('customer_migrations')) {
            $ids = $ids->merge(CustomerMigration::query()
                ->whereNotNull('customer_id')
                ->whereRaw('UPPER(old_sn) = ?', [$oldSn])
                ->latest('id')
                ->limit(10)
                ->pluck('customer_id')
                ->map(fn ($id) => (int) $id));
        }

        if (Schema::hasTable('olt_onus')) {
            $pppoeUsers = OltOnu::query()
                ->whereRaw('UPPER(serial_number) = ?', [$oldSn])
                ->whereNotNull('onu_name')
                ->limit(5)
                ->pluck('onu_name')
                ->map(fn ($value) => strtolower(trim((string) $value)))
                ->filter()
                ->values()
                ->all();
            if ($pppoeUsers !== []) {
                $ids = $ids->merge(Customer::query()
                    ->whereIn(DB::raw('LOWER(pppoe_user)'), $pppoeUsers)
                    ->limit(10)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id));
            }
        }

        return $ids->unique()->take(10)->values()->all();
    }

    /**
     * @param  array<string, string>  $input
     * @return list<array<string, mixed>>
     */
    private function onuNameCandidates(array $input): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [];
        }

        $needle = $this->normalizeIdentifier($input['onu_name']);
        if ($needle === '') {
            return [];
        }

        $rows = OltOnu::query()
            ->with('olt:id,name,code')
            ->whereNotNull('onu_name')
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(TRIM(onu_name), ' ', '_'), '-', '_')) = ?",
                [$needle]
            )
            ->when($input['olt'] !== '', function ($query) use ($input): void {
                $olt = '%'.$input['olt'].'%';
                $query->whereHas('olt', fn ($q) => $q
                    ->where('name', 'like', $olt)
                    ->orWhere('code', 'like', $olt));
            })
            ->latest('inventory_collected_at')
            ->limit(5)
            ->get();

        return $rows->values()->map(function (OltOnu $onu, int $index): array {
            return [
                'number' => $index + 1,
                'customer_id' => null,
                'customer_code' => null,
                'name' => $onu->onu_name ?: '-',
                'pppoe_user' => $onu->onu_name,
                'area' => null,
                'odp' => null,
                'rt' => '',
                'rw' => '',
                'old_sn' => $this->normalizeSerial((string) $onu->serial_number) ?: null,
                'old_onu_ref' => $onu->onu_ref,
                'old_onu_resolution' => $onu->onu_ref ? 'known_from_olt_snapshot' : 'skipped_unknown_old_onu',
                'score' => 70,
                'confidence' => 'medium',
                'match_reasons' => ['onu_name_exact', 'olt_snapshot'],
                'conflicts' => [],
                'warnings' => ['customer_not_linked'],
                'authoritative_source' => 'olt_snapshot_readonly',
                'sources' => ['olt_snapshot'],
                'freshness' => [
                    'customer' => ['at' => null, 'stale' => null],
                    'olt_snapshot' => $this->freshness(
                        $onu->inventory_collected_at ?? $onu->state_collected_at,
                        120
                    ),
                    'genieacs_snapshot' => ['at' => null, 'stale' => null],
                    'mikrotik_snapshot' => ['at' => null, 'stale' => null],
                ],
            ];
        })->all();
    }

    /**
     * @param  array<string, string>  $input
     * @return array<string, mixed>
     */
    private function candidate(Customer $customer, array $input, bool $historicalOldSnExact = false): array
    {
        [$customerRt, $customerRw] = $this->customerRtRw($customer);
        $name = $this->normalizeText((string) $customer->name);
        $area = $this->normalizeText((string) ($customer->area?->name ?? ''));
        $odp = $this->normalizeText((string) $customer->odp_name);
        $reasons = [];
        $conflicts = [];
        $warnings = [];
        $score = 0;
        $fuzzyName = false;

        if ($input['name'] !== '' && $name === $this->normalizeText($input['name'])) {
            $score += 40;
            $reasons[] = 'name_exact';
        } elseif ($input['name'] !== '' && str_contains($name, $this->normalizeText($input['name']))) {
            $score += 25;
            $reasons[] = 'name_contains';
        } elseif ($this->isFuzzyNameMatch($input['name'], (string) $customer->name)) {
            $score += 25;
            $reasons[] = 'name_typo_candidate';
            $warnings[] = 'name_typo_requires_confirmation';
            $fuzzyName = true;
        }
        if ($input['phone'] !== '' && $this->digits((string) $customer->phone) === $input['phone']) {
            $score += 50;
            $reasons[] = 'phone_exact';
        }
        if ($input['customer_code'] !== '' && strtoupper((string) $customer->code) === $input['customer_code']) {
            $score += 50;
            $reasons[] = 'customer_code_exact';
        }
        if ($input['area'] !== '' && $area === $this->normalizeText($input['area'])) {
            $score += 15;
            $reasons[] = 'area_exact';
        }
        if ($input['odp'] !== '' && $odp === $this->normalizeText($input['odp'])) {
            $score += 30;
            $reasons[] = 'odp_exact';
        }
        if ($input['rt'] !== '' && $customerRt === $input['rt']) {
            $score += 15;
            $reasons[] = 'rt_exact';
        }
        if ($input['rw'] !== '' && $customerRw === $input['rw']) {
            $score += 30;
            $reasons[] = 'rw_exact';
        }
        $pppoe = strtolower(trim((string) $customer->pppoe_user));
        if ($input['pppoe_user'] !== '') {
            if ($pppoe === strtolower($input['pppoe_user'])) {
                $score += 60;
                $reasons[] = 'pppoe_exact';
            } else {
                $conflicts[] = 'pppoe_mismatch';
            }
        }

        $acquisition = $this->acquisitionEvidence($customer);
        $currentSn = $this->normalizeSerial((string) $customer->onu_serial);
        $oldSn = $currentSn
            ?: $this->normalizeSerial((string) ($acquisition[0]['old_sn'] ?? ''));
        $oldOnu = trim((string) $customer->olt_port_label)
            ?: trim((string) ($acquisition[0]['old_onu_ref'] ?? ''));
        $oltSnapshot = $this->oltSnapshot($customer, $oldSn, $oldOnu);
        $oldOnu = $oldOnu ?: (string) ($oltSnapshot['onu_ref'] ?? '');
        $genie = $customer->genieAcsDevice ?: $this->genieSnapshot($customer);
        $routerSnapshot = $customer->router_id && Schema::hasTable('router_mt_snapshots')
            ? RouterMtSnapshot::query()->where('router_id', $customer->router_id)->latest('collected_at')->first()
            : null;
        if ($input['old_sn'] !== '') {
            if ($currentSn !== '' && $currentSn === $input['old_sn']) {
                $score += 60;
                $reasons[] = 'old_sn_exact';
            } elseif ($historicalOldSnExact) {
                $score += 45;
                $reasons[] = 'old_sn_historical_exact';
                if ($currentSn !== '' && $currentSn !== $input['old_sn']) {
                    $conflicts[] = 'old_sn_not_current_customer_sn';
                } elseif ($oldSn === '') {
                    $oldSn = $input['old_sn'];
                }
            } elseif ($oldSn !== '') {
                $conflicts[] = 'old_sn_mismatch';
            } else {
                $warnings[] = 'old_sn_not_confirmed_in_snapshot';
            }
        }
        foreach ($acquisition as $evidence) {
            $evidenceSn = $this->normalizeSerial((string) ($evidence['new_sn'] ?? ''));
            if ($evidenceSn !== '' && $evidenceSn !== $input['new_sn']) {
                if ($this->isLiveAfterCutoff($customer)) {
                    $warnings[] = 'excel_conflict_ignored_live_newer';
                } else {
                    $conflicts[] = 'excel_new_sn_conflict';
                }
            }
        }

        return [
            'number' => 0,
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'name' => $customer->name,
            'pppoe_user' => $customer->pppoe_user,
            'area' => $customer->area?->name,
            'odp' => $customer->odp_name,
            'rt' => $customerRt,
            'rw' => $customerRw,
            'old_sn' => $oldSn !== '' ? $oldSn : null,
            'old_onu_ref' => $oldOnu !== '' ? $oldOnu : null,
            'old_onu_resolution' => $oldOnu !== '' ? 'known' : 'skipped_unknown_old_onu',
            'score' => $score,
            'confidence' => $score >= 85 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'match_reasons' => $reasons,
            'conflicts' => array_values(array_unique($conflicts)),
            'warnings' => array_values(array_unique($warnings)),
            'requires_manual_selection' => $fuzzyName,
            'authoritative_source' => $this->isLiveAfterCutoff($customer)
                ? 'ebilling_live_post_cutoff'
                : 'ebilling_live',
            'sources' => array_values(array_filter([
                'customer_db',
                $acquisition !== [] ? 'starlite_akuisisi_confirmed' : null,
                $oltSnapshot ? 'olt_snapshot' : null,
                $genie ? 'genieacs_snapshot' : null,
                $routerSnapshot ? 'mikrotik_snapshot' : null,
                $this->hasPsbHistory($customer) ? 'psb_history' : null,
            ])),
            'freshness' => [
                'customer' => $this->freshness($customer->updated_at, null),
                'olt_snapshot' => $this->freshness(
                    $oltSnapshot?->inventory_collected_at ?? $oltSnapshot?->state_collected_at,
                    120
                ),
                'genieacs_snapshot' => $this->freshness($genie?->last_inform_at, 60),
                'mikrotik_snapshot' => $this->freshness(
                    $routerSnapshot?->collected_at ?? $customer->mikrotik_sync_checked_at,
                    60
                ),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $input
     * @return list<array<string, mixed>>
     */
    private function fuzzyNameCandidates(array $input): array
    {
        $firstToken = explode(' ', $this->normalizeText($input['name']))[0] ?? '';
        $prefix = substr($firstToken, 0, 2);
        if (strlen($prefix) < 2) {
            return [];
        }

        return Customer::query()
            ->with(['area', 'router', 'olt', 'genieAcsDevice'])
            ->where(function ($query) use ($prefix): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$prefix.'%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['% '.$prefix.'%']);
            })
            ->limit(100)
            ->get()
            ->map(fn (Customer $customer) => $this->candidate($customer, $input))
            ->filter(fn (array $candidate) => in_array('name_typo_candidate', $candidate['match_reasons'], true))
            ->sortByDesc('score')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function acquisitionEvidence(Customer $customer): array
    {
        if (! Schema::hasTable('customer_migrations')) {
            return [];
        }

        return CustomerMigration::query()
            ->where('source', 'excel_import')
            ->where(function ($query) use ($customer) {
                $query->whereRaw('LOWER(pppoe_user) = ?', [strtolower((string) $customer->pppoe_user)])
                    ->orWhereRaw('LOWER(full_name) = ?', [strtolower((string) $customer->name)]);
            })
            ->latest('id')
            ->limit(5)
            ->get()
            ->filter(fn (CustomerMigration $row) => strtolower((string) data_get($row->meta, 'project')) === 'starlite-akuisisi')
            ->map(fn (CustomerMigration $row) => [
                'id' => $row->id,
                'old_sn' => $row->old_sn,
                'new_sn' => $row->new_sn,
                'old_onu_ref' => $row->old_onu_ref,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function oltSnapshot(Customer $customer, string $oldSn, string $oldOnu): ?OltOnu
    {
        if (! Schema::hasTable('olt_onus') || ($oldSn === '' && $oldOnu === '')) {
            return null;
        }

        return OltOnu::query()
            ->when($customer->olt_id, fn ($query) => $query->where('olt_id', $customer->olt_id))
            ->where(function ($query) use ($oldSn, $oldOnu) {
                if ($oldSn !== '') {
                    $query->orWhereRaw('UPPER(serial_number) = ?', [$oldSn]);
                }
                if ($oldOnu !== '') {
                    $query->orWhere('onu_ref', $oldOnu);
                }
            })
            ->latest('inventory_collected_at')
            ->first();
    }

    private function genieSnapshot(Customer $customer): ?GenieAcsDevice
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return null;
        }

        return GenieAcsDevice::query()
            ->where('customer_id', $customer->id)
            ->orWhereRaw('LOWER(pppoe_user) = ?', [strtolower((string) $customer->pppoe_user)])
            ->latest('last_inform_at')
            ->first();
    }

    private function hasPsbHistory(Customer $customer): bool
    {
        $pppoe = strtolower((string) $customer->pppoe_user);
        $name = strtolower((string) $customer->name);

        return (Schema::hasTable('psb_drafts') && PsbDraft::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(pppoe_user) = ?', [$pppoe])
                ->orWhereRaw('LOWER(full_name) = ?', [$name]))
            ->exists())
            || (Schema::hasTable('psb_starlite_entries') && PsbStarliteEntry::query()
                ->where(fn ($query) => $query
                    ->whereRaw('LOWER(pppoe_user) = ?', [$pppoe])
                    ->orWhereRaw('LOWER(full_name) = ?', [$name]))
                ->exists());
    }

    /**
     * @return array{customer_ids: list<int>, psb_pppoe: list<string>}
     */
    private function newSerialOwners(string $newSn): array
    {
        // A device returned through a `received` dismantle recovery case is
        // free for reuse: its old (non-live) owner must not count as a
        // conflict, nor that owner's PSB history.
        $recovery = app(DeviceRecoveryService::class);
        $releasedIds = $recovery->releasedCustomerIdsForSerial($newSn);
        $releasedPppoe = $recovery->releasedPppoeUsersForSerial($newSn);

        $customerIds = Customer::query()
            ->whereRaw('UPPER(onu_serial) = ?', [$newSn])
            ->when($releasedIds !== [], fn ($query) => $query->whereNotIn('id', $releasedIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $psbPppoe = [];

        if (Schema::hasTable('psb_starlite_entries')) {
            $psbPppoe = PsbStarliteEntry::query()
                ->whereRaw('UPPER(sn) = ?', [$newSn])
                ->limit(5)
                ->pluck('pppoe_user')
                ->map(fn ($pppoe) => strtolower((string) $pppoe))
                ->reject(fn (string $pppoe) => in_array($pppoe, $releasedPppoe, true))
                ->values()
                ->all();
        }

        return ['customer_ids' => $customerIds, 'psb_pppoe' => $psbPppoe];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function normalizeInput(array $input): array
    {
        return [
            'name' => trim((string) ($input['name'] ?? $input['full_name'] ?? '')),
            'new_sn' => $this->normalizeSerial((string) ($input['new_sn'] ?? '')),
            'old_sn' => $this->normalizeSerial((string) ($input['old_sn'] ?? '')),
            'pppoe_user' => trim((string) ($input['pppoe_user'] ?? $input['pppoe'] ?? '')),
            'phone' => $this->digits((string) ($input['phone'] ?? '')),
            'customer_code' => strtoupper(trim((string) ($input['customer_code'] ?? ''))),
            'area' => trim((string) ($input['area'] ?? $input['daerah'] ?? '')),
            'odp' => trim((string) ($input['odp'] ?? '')),
            'rt' => ltrim($this->digits((string) ($input['rt'] ?? '')), '0') ?: '0',
            'rw' => ltrim($this->digits((string) ($input['rw'] ?? '')), '0') ?: '0',
            'onu_name' => $this->normalizeIdentifier((string) ($input['onu_name'] ?? '')),
            'olt' => trim((string) ($input['olt'] ?? $input['olt_field'] ?? '')),
        ];
    }

    /**
     * @param  array<string, string>  $input
     * @return list<string>
     */
    private function missingRequirements(array $input): array
    {
        $missing = [];
        if ($input['name'] === '' && $input['onu_name'] === '') {
            $missing[] = 'name_required';
        }
        if ($input['new_sn'] === '') {
            $missing[] = 'new_sn_required';
        }
        $hasIndependentKey = $input['phone'] !== ''
            || $input['customer_code'] !== ''
            || $input['pppoe_user'] !== ''
            || $input['old_sn'] !== ''
            || ($input['area'] !== '' && $input['odp'] !== '')
            || ($input['rt'] !== '0' && $input['rw'] !== '0')
            || ($input['onu_name'] !== '' && $input['olt'] !== '');
        if (! $hasIndependentKey) {
            $missing[] = 'independent_discriminator_required';
        }

        return $missing;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function customerRtRw(Customer $customer): array
    {
        $source = strtoupper((string) $customer->pppoe_user.' '.(string) $customer->address);
        preg_match('/(?:^|[^A-Z0-9])RT[\s_-]*0*(\d+)\b/', $source, $rt);
        preg_match('/(?:^|[^A-Z0-9])RW[\s_-]*0*(\d+)\b/', $source, $rw);

        return [$rt[1] ?? '', $rw[1] ?? ''];
    }

    /**
     * @return array{at: string|null, stale: bool|null}
     */
    private function freshness(mixed $at, ?int $maxMinutes): array
    {
        if (! $at) {
            return ['at' => null, 'stale' => null];
        }
        $time = $at instanceof Carbon ? $at : Carbon::parse($at);

        return [
            'at' => $time->toIso8601String(),
            'stale' => $maxMinutes === null ? false : $time->lt(now()->subMinutes($maxMinutes)),
        ];
    }

    private function isLiveAfterCutoff(Customer $customer): bool
    {
        return $customer->updated_at !== null
            && $customer->updated_at->gte(Carbon::parse('2026-07-25 00:00:00', 'Asia/Jakarta')->utc());
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value)) ?? '');
    }

    private function isFuzzyNameMatch(string $input, string $candidate): bool
    {
        $needles = array_values(array_filter(explode(' ', $this->normalizeText($input))));
        $haystack = array_values(array_filter(explode(' ', $this->normalizeText($candidate))));
        if ($needles === [] || $haystack === []) {
            return false;
        }

        foreach ($needles as $needle) {
            $distance = min(array_map(fn (string $word) => levenshtein($needle, $word), $haystack));
            $limit = min(2, max(1, (int) floor(strlen($needle) * 0.2)));
            if ($distance > $limit) {
                return false;
            }
        }

        return true;
    }

    private function normalizeIdentifier(string $value): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value) ?? ''), '_');
    }

    private function normalizeSerial(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private function maskPhone(string $phone): string
    {
        return $phone === '' ? '' : substr($phone, 0, 4).'***'.substr($phone, -2);
    }

    private function maskSerial(string $serial): string
    {
        return strlen($serial) < 8 ? $serial : substr($serial, 0, 4).'***'.substr($serial, -4);
    }

    private function scopedAllowlistEnabled(): bool
    {
        return $this->allowedRoles() !== [] || $this->allowedUserIds() !== [];
    }

    /**
     * @return list<string>
     */
    private function allowedRoles(): array
    {
        return $this->settingList(self::ALLOWED_ROLES);
    }

    /**
     * @return list<string>
     */
    private function allowedUserIds(): array
    {
        return $this->settingList(self::ALLOWED_USER_IDS);
    }

    /**
     * @return list<string>
     */
    private function allowedSenderJids(): array
    {
        return $this->settingList(self::ALLOWED_SENDER_JIDS);
    }

    /**
     * @return list<string>
     */
    private function allowedGroupJids(): array
    {
        return $this->settingList(self::ALLOWED_GROUP_JIDS);
    }

    /**
     * @return list<string>
     */
    private function settingList(string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                fn (string $value) => trim(strtolower($value)),
                preg_split('/[\s,;]+/', (string) Setting::get($key, ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
            ),
            fn (string $value) => $value !== ''
        )));
    }

    /**
     * @param  list<string>  $actuals
     * @param  list<string>  $allowed
     */
    private function matchesAnyJid(array $actuals, array $allowed): bool
    {
        foreach ($actuals as $actual) {
            $actual = trim(strtolower($actual));
            $actualDigits = $this->digits($actual);
            foreach ($allowed as $item) {
                if ($actual === $item) {
                    return true;
                }
                $itemDigits = $this->digits($item);
                if ($itemDigits !== '' && $actualDigits !== '' && $itemDigits === $actualDigits) {
                    return true;
                }
            }
        }

        return false;
    }
}
