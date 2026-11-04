<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\OdpOdc;
use App\Models\OltOnu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PonPppoeMismatchDetector
{
    public function __construct(
        protected CustomerConnectivityService $connectivity,
    ) {}

    public function mismatchQuery(): Builder
    {
        return Customer::query()
            ->ponPppoeMismatch()
            ->with(['area', 'olt']);
    }

    /**
     * @return array{
     *   scanned: int,
     *   active: int,
     *   upserted: int,
     *   recovered: int,
     *   pre_synced: int,
     *   skipped: int,
     *   samples: array<int, array<string, mixed>>
     * }
     */
    public function scan(bool $persist = true): array
    {
        $columns = [
            'id', 'code', 'name', 'area_id', 'olt_id', 'olt_port_label', 'onu_serial', 'pppoe_user',
            'olt_status', 'is_online', 'mikrotik_profile', 'onu_rx_power_dbm', 'olt_last_synced_at',
        ];

        $raw = $this->mismatchQuery()
            ->orderBy('code')
            ->get($columns);

        $scanned = $raw->count();
        $migrated = $this->syncMigratedFhttOnus($raw);
        $preSynced = $this->preSyncOltStatusFromOnus($raw);

        $raw = $this->mismatchQuery()
            ->orderBy('code')
            ->get($columns);

        $verified = $this->filterVerifiedMismatches($raw);

        $result = $this->scanIssue(
            issue: 'pon_pppoe_mismatch',
            customers: $verified,
            persist: $persist,
        );

        $result['scanned'] = $scanned;
        $result['pre_synced'] = $preSynced;
        $result['migrated'] = $migrated;
        $result['skipped'] = $raw->count() - $verified->count();

        return $result;
    }

    public function scanBadSignal(bool $persist = true): array
    {
        $threshold = (float) config('services.monitoring.weak_rx_dbm', -27);
        $columns = [
            'id', 'code', 'name', 'area_id', 'olt_id', 'olt_port_label', 'onu_serial', 'pppoe_user',
            'olt_status', 'is_online', 'mikrotik_profile', 'onu_rx_power_dbm', 'olt_last_synced_at',
        ];

        $raw = Customer::query()
            ->badSignalOntOnline($threshold)
            ->with(['area', 'olt'])
            ->orderBy('onu_rx_power_dbm')
            ->get($columns);

        $migrated = $this->syncMigratedFhttOnus($raw);

        $customers = Customer::query()
            ->badSignalOntOnline($threshold)
            ->with(['area', 'olt'])
            ->orderBy('onu_rx_power_dbm')
            ->get($columns)
            ->filter(fn (Customer $customer) => ! $this->isFalsePositiveAlert($customer, 'bad_signal_online'))
            ->values();

        $result = $this->scanIssue(
            issue: 'bad_signal_online',
            customers: $customers,
            persist: $persist,
        );
        $result['migrated'] = $migrated;

        return $result;
    }

    /**
     * @return array{
     *   scanned: int,
     *   active: int,
     *   upserted: int,
     *   recovered: int,
     *   samples: array<int, array<string, mixed>>
     * }
     */
    protected function scanIssue(string $issue, Collection $customers, bool $persist): array
    {
        $upserted = 0;
        $recovered = 0;

        if ($persist && Schema::hasTable('monitoring_alert_events')) {
            foreach ($customers as $customer) {
                $this->upsertEvent($customer, $issue);
                $upserted++;
            }

            $recovered = $this->recoverStaleEvents($customers->pluck('id'), $issue);
        }

        $samples = $customers->take(5)->map(fn (Customer $customer) => $this->buildPayload($customer))->values()->all();

        return [
            'scanned' => $customers->count(),
            'active' => $customers->count(),
            'upserted' => $upserted,
            'recovered' => $recovered,
            'samples' => $samples,
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    protected function filterVerifiedMismatches(Collection $candidates): Collection
    {
        return $candidates->filter(function (Customer $customer): bool {
            return $this->verifyMismatch($customer) !== null;
        })->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function verifyMismatch(Customer $customer): ?array
    {
        $migratedOnu = $this->findMigratedOnu($customer);
        if ($migratedOnu !== null) {
            $this->syncCustomerFromOnu($customer, $migratedOnu);

            return null;
        }

        $ref = $this->normalizeOnuRef($customer->olt_port_label);
        if ($ref === null || ! $customer->olt_id) {
            return null;
        }

        $onu = OltOnu::query()
            ->where('olt_id', $customer->olt_id)
            ->where('onu_ref', $ref)
            ->first();

        if (! $onu) {
            return null;
        }

        $custSerial = $this->normalizeSerial($customer->onu_serial);
        $onuSerial = $this->normalizeSerial($onu->serial_number);

        if ($custSerial && $onuSerial && $custSerial !== $onuSerial) {
            return null;
        }

        if (! $this->isOnuStateFresh($onu)) {
            return null;
        }

        if ($this->connectivity->isPonOnline($onu->state)) {
            return null;
        }

        if ($this->connectivity->isPonOnline($customer->olt_status)) {
            return null;
        }

        if (! $customer->is_online) {
            return null;
        }

        return [
            'onu_ref' => $ref,
            'onu_state' => $onu->state,
            'onu_serial' => $onu->serial_number,
        ];
    }

    protected function syncMigratedFhttOnus(Collection $candidates): int
    {
        $synced = 0;

        foreach ($candidates as $customer) {
            $onu = $this->findMigratedOnu($customer);
            if ($onu === null) {
                continue;
            }

            if ($this->syncCustomerFromOnu($customer, $onu)) {
                $synced++;
            }

            $this->recoverCustomerConnectivityEvents((int) $customer->id);
        }

        return $synced;
    }

    public function isFalsePositiveAlert(Customer $customer, string $issue): bool
    {
        $migratedOnu = $this->findMigratedOnu($customer);
        if ($migratedOnu !== null) {
            $this->syncCustomerFromOnu($customer, $migratedOnu);
            $this->recoverCustomerConnectivityEvents((int) $customer->id, $issue);

            return true;
        }

        if ($issue === 'pon_pppoe_mismatch') {
            return $this->verifyMismatch($customer) === null;
        }

        if ($this->hasStaleCustomerSerial($customer)) {
            return true;
        }

        return false;
    }

    public function recoverCustomerConnectivityEvents(int $customerId, ?string $issue = null): int
    {
        $query = MonitoringAlertEvent::query()
            ->where('source', 'connectivity_scan')
            ->where('status', 'active')
            ->where('entity_id', (string) $customerId);

        if ($issue !== null) {
            $query->where('issue', $issue);
        }

        return $query->update([
            'status' => 'recovered',
            'recovered_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    protected function findMigratedOnu(Customer $customer): ?OltOnu
    {
        if (! $customer->olt_id) {
            return null;
        }

        $byPppoeName = $this->findMigratedOnuByExactPppoeName($customer);
        if ($byPppoeName !== null) {
            return $byPppoeName;
        }

        $currentRef = $this->normalizeOnuRef($customer->olt_port_label);
        $custSerial = $this->normalizeSerial($customer->onu_serial);
        $identityKeys = $this->customerIdentityKeys($customer);

        $candidates = OltOnu::query()
            ->where('olt_id', $customer->olt_id)
            ->whereNotNull('state_collected_at')
            ->get();

        $matched = [];

        foreach ($candidates as $onu) {
            if (! $this->isOnuStateFresh($onu)) {
                continue;
            }

            if (! $this->connectivity->isPonOnline($onu->state)) {
                continue;
            }

            if (! $this->onuMatchesCustomerIdentity($onu, $identityKeys, $custSerial)) {
                continue;
            }

            $onuRef = $this->normalizeOnuRef($onu->onu_ref);
            $onuSerial = $this->normalizeSerial($onu->serial_number);

            if ($onuRef !== null && $onuRef === $currentRef && $custSerial && $onuSerial && $custSerial === $onuSerial) {
                continue;
            }

            $matched[] = $onu;
        }

        if ($matched === []) {
            return null;
        }

        usort($matched, function (OltOnu $a, OltOnu $b) use ($currentRef): int {
            $aRef = $this->normalizeOnuRef($a->onu_ref);
            $bRef = $this->normalizeOnuRef($b->onu_ref);
            $aFhtt = $a->isFhtt() ? 0 : 1;
            $bFhtt = $b->isFhtt() ? 0 : 1;

            if ($aFhtt !== $bFhtt) {
                return $aFhtt <=> $bFhtt;
            }

            if ($currentRef !== null) {
                $aSamePon = $aRef && str_starts_with((string) $aRef, preg_replace('/:\d+$/', '', $currentRef) ?: '') ? 0 : 1;
                $bSamePon = $bRef && str_starts_with((string) $bRef, preg_replace('/:\d+$/', '', $currentRef) ?: '') ? 0 : 1;
                if ($aSamePon !== $bSamePon) {
                    return $aSamePon <=> $bSamePon;
                }
            }

            return ($b->state_collected_at?->getTimestamp() ?? 0) <=> ($a->state_collected_at?->getTimestamp() ?? 0);
        });

        return $matched[0];
    }

    protected function findMigratedOnuByExactPppoeName(Customer $customer): ?OltOnu
    {
        if (! $customer->pppoe_user) {
            return null;
        }

        $pppoe = mb_strtolower(trim($customer->pppoe_user));
        $currentRef = $this->normalizeOnuRef($customer->olt_port_label);
        $custSerial = $this->normalizeSerial($customer->onu_serial);

        $matched = OltOnu::query()
            ->where('olt_id', $customer->olt_id)
            ->whereRaw('LOWER(TRIM(onu_name)) = ?', [$pppoe])
            ->get()
            ->filter(function (OltOnu $onu) use ($currentRef, $custSerial): bool {
                if (! $this->isOnuStateFresh($onu) || ! $this->connectivity->isPonOnline($onu->state)) {
                    return false;
                }

                $onuRef = $this->normalizeOnuRef($onu->onu_ref);
                $onuSerial = $this->normalizeSerial($onu->serial_number);

                return ! ($onuRef !== null && $onuRef === $currentRef && $custSerial && $onuSerial && $custSerial === $onuSerial);
            })
            ->values();

        if ($matched->isEmpty()) {
            return null;
        }

        return $matched->sortBy([
            fn (OltOnu $onu) => $onu->isFhtt() ? 0 : 1,
            fn (OltOnu $onu) => -($onu->state_collected_at?->getTimestamp() ?? 0),
        ])->first();
    }

    /**
     * @return array<int, string>
     */
    protected function customerIdentityKeys(Customer $customer): array
    {
        $keys = [];

        if ($customer->pppoe_user) {
            $keys[] = mb_strtolower(trim($customer->pppoe_user));
        }

        if ($customer->code) {
            $keys[] = mb_strtolower(trim($customer->code));
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  array<int, string>  $identityKeys
     */
    protected function onuMatchesCustomerIdentity(OltOnu $onu, array $identityKeys, ?string $custSerial): bool
    {
        $onuName = mb_strtolower(trim((string) $onu->onu_name));
        if ($onuName !== '') {
            foreach ($identityKeys as $key) {
                if ($onuName === $key || str_contains($onuName, $key) || str_contains($key, $onuName)) {
                    return true;
                }
            }
        }

        $onuSerial = $this->normalizeSerial($onu->serial_number);
        if ($custSerial && $onuSerial && $custSerial === $onuSerial) {
            return true;
        }

        return false;
    }

    protected function hasStaleCustomerSerial(Customer $customer): bool
    {
        $ref = $this->normalizeOnuRef($customer->olt_port_label);
        if ($ref === null || ! $customer->olt_id) {
            return false;
        }

        $onu = OltOnu::query()
            ->where('olt_id', $customer->olt_id)
            ->where('onu_ref', $ref)
            ->first();

        if (! $onu || ! $this->isOnuStateFresh($onu)) {
            return false;
        }

        $custSerial = $this->normalizeSerial($customer->onu_serial);
        $onuSerial = $this->normalizeSerial($onu->serial_number);

        return $custSerial && $onuSerial && $custSerial !== $onuSerial;
    }

    protected function syncCustomerFromOnu(Customer $customer, OltOnu $onu): bool
    {
        $fill = [
            'olt_id' => $onu->olt_id,
            'olt_port_label' => $onu->onu_ref,
            'onu_serial' => $onu->serial_number,
            'olt_status' => $onu->state ?? $customer->olt_status,
            'olt_last_synced_at' => $onu->state_collected_at ?? $onu->updated_at ?? now(),
        ];

        if ($this->connectivity->isPonOnline($onu->state) && $onu->rx_power_dbm !== null) {
            $fill['onu_rx_power_dbm'] = $onu->rx_power_dbm;
        } elseif (! $this->connectivity->isPonOnline($onu->state)) {
            $fill['onu_rx_power_dbm'] = null;
        }

        $customer->forceFill($fill);

        if (! $customer->isDirty(array_keys($fill))) {
            return false;
        }

        $customer->save();

        return true;
    }

    protected function preSyncOltStatusFromOnus(Collection $candidates): int
    {
        $synced = 0;

        foreach ($candidates as $customer) {
            if ($this->findMigratedOnu($customer) !== null) {
                continue;
            }

            $ref = $this->normalizeOnuRef($customer->olt_port_label);
            if ($ref === null || ! $customer->olt_id) {
                continue;
            }

            $onu = OltOnu::query()
                ->where('olt_id', $customer->olt_id)
                ->where('onu_ref', $ref)
                ->first();

            if (! $onu || ! $this->isOnuStateFresh($onu)) {
                continue;
            }

            $custSerial = $this->normalizeSerial($customer->onu_serial);
            $onuSerial = $this->normalizeSerial($onu->serial_number);

            if ($custSerial && $onuSerial && $custSerial !== $onuSerial) {
                continue;
            }

            $fill = [
                'olt_status' => $onu->state ?? $customer->olt_status,
                'olt_last_synced_at' => $onu->state_collected_at ?? $onu->updated_at ?? now(),
            ];

            if ($onu->rx_power_dbm !== null && $this->connectivity->isPonOnline($onu->state)) {
                $fill['onu_rx_power_dbm'] = $onu->rx_power_dbm;
            } elseif (! $this->connectivity->isPonOnline($onu->state)) {
                $fill['onu_rx_power_dbm'] = null;
            }

            $customer->forceFill($fill);

            if ($customer->isDirty(['olt_status', 'olt_last_synced_at', 'onu_rx_power_dbm'])) {
                $customer->save();
                $synced++;
            }
        }

        return $synced;
    }

    protected function isOnuStateFresh(OltOnu $onu): bool
    {
        $collectedAt = $onu->state_collected_at ?? $onu->updated_at;

        if ($collectedAt === null) {
            return false;
        }

        return $collectedAt->gte(now()->subHours($this->onuStateFreshHours()));
    }

    protected function onuStateFreshHours(): int
    {
        return (int) config('services.monitoring.onu_state_fresh_hours', 48);
    }

    protected function normalizeSerial(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    protected function normalizeOnuRef(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/^gpon-onu_/i', '', $value) ?? $value;

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Customer $customer): array
    {
        return [
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'area' => $customer->area?->name,
            'onu_serial' => $customer->onu_serial,
            'odp' => $this->resolveOdpName($customer),
            'olt_port_label' => $customer->olt_port_label,
            'pon_port' => $this->extractPonPort($customer->olt_port_label),
            'olt_status' => $customer->olt_status,
            'pppoe_online' => (bool) $customer->is_online,
            'onu_rx_power_dbm' => $customer->onu_rx_power_dbm,
            'olt_name' => $customer->olt?->name,
        ];
    }

    protected function upsertEvent(Customer $customer, string $issue = 'pon_pppoe_mismatch'): void
    {
        if ($this->isFalsePositiveAlert($customer, $issue)) {
            return;
        }

        $dedupeKey = 'customer:'.$customer->id.':'.$issue;
        $event = MonitoringAlertEvent::firstOrNew(['dedupe_key' => $dedupeKey]);

        $event->fill([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => trim(($customer->name ?: 'Pelanggan').' ('.($customer->code ?: $customer->id).')'),
            'issue' => $issue,
            'severity' => $issue === 'bad_signal_online' ? 'critical' : 'warning',
            'status' => 'active',
            'payload' => $this->buildPayload($customer),
            'last_seen_at' => now(),
            'recovered_at' => null,
        ]);
        $event->first_seen_at ??= now();
        $event->save();
    }

    protected function recoverStaleEvents(Collection $activeCustomerIds, string $issue): int
    {
        $query = MonitoringAlertEvent::query()
            ->where('source', 'connectivity_scan')
            ->where('issue', $issue)
            ->where('status', 'active');

        if ($activeCustomerIds->isEmpty()) {
            return $query->update([
                'status' => 'recovered',
                'recovered_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return $query
            ->whereNotIn('entity_id', $activeCustomerIds->map(fn ($id) => (string) $id)->all())
            ->update([
                'status' => 'recovered',
                'recovered_at' => now(),
                'last_seen_at' => now(),
            ]);
    }

    protected function resolveOdpName(Customer $customer): ?string
    {
        $ponPort = $this->extractPonPort($customer->olt_port_label);
        if ($ponPort === null) {
            return $customer->area?->name;
        }

        $odp = OdpOdc::query()
            ->where('olt_port', $ponPort)
            ->orderByRaw("CASE WHEN type = 'ODP' THEN 0 WHEN type = 'ODC' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->value('name');

        if ($odp) {
            return $odp;
        }

        $parentPort = preg_match('/^(\d+\/\d+)\//', $ponPort, $m) ? $m[1] : null;
        if ($parentPort) {
            $odp = OdpOdc::query()
                ->where('olt_port', $parentPort)
                ->orderBy('name')
                ->value('name');
            if ($odp) {
                return $odp;
            }
        }

        return $customer->area?->name;
    }

    protected function extractPonPort(?string $oltPortLabel): ?string
    {
        if (! $oltPortLabel) {
            return null;
        }

        if (preg_match('/(?:gpon-(?:olt|onu)_)?(\d+\/\d+\/\d+)(?::\d+)?/i', $oltPortLabel, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function logScanSummary(array $result, bool $dryRun = false): void
    {
        Log::info('PON/PPPoE mismatch scan', [
            'dry_run' => $dryRun,
            'scanned' => $result['scanned'],
            'active' => $result['active'],
            'skipped' => $result['skipped'] ?? 0,
            'pre_synced' => $result['pre_synced'] ?? 0,
            'migrated' => $result['migrated'] ?? 0,
            'upserted' => $result['upserted'],
            'recovered' => $result['recovered'],
            'samples' => $result['samples'],
        ]);
    }
}
