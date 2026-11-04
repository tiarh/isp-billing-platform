<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded, read-only view of the latest connectivity snapshots.
 * Device collectors own refreshes; this service never opens a device session.
 */
class UnifiedConnectivityReadModel
{
    private const SOURCES = ['all', 'genieacs', 'mikrotik_pppoe', 'olt_onu'];

    private const STRATEGIES = ['all', 'fiberhome_tr069', 'hioso_htb', 'pppoe_only', 'manual', 'unknown'];

    private const STATUSES = ['all', 'online', 'degraded', 'offline', 'isolated', 'maintenance', 'unknown', 'setup_required', 'not_pingable'];

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $key = 'monitoring.unified-connectivity.v1.'.sha1(json_encode($filters));

        if (! filter_var(Setting::get('monitoring_unified_read_model_v2', false), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'enabled' => false,
                'filters' => $filters,
                'rows' => [],
                'total_rows' => 0,
                'pagination' => ['page' => $filters['page'], 'per_page' => $filters['per_page'], 'last_page' => 1, 'from' => null, 'to' => null],
                'summary' => ['total' => 0, 'online' => 0, 'isolated' => 0, 'latest_snapshot' => true, 'history_note' => 'Feature flag monitoring unified read model masih OFF.'],
                'source_options' => [],
                'strategy_options' => [],
                'status_options' => [],
                'read_only' => true,
            ];
        }

        return Cache::remember($key, now()->addSeconds(20), fn () => $this->build($filters));
    }

    /** @return array<string, string> */
    public function filters(Request $request): array
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->format('Y-m');
        }

        $pick = static fn (string $value, array $allowed, string $fallback): string => in_array($value, $allowed, true) ? $value : $fallback;

        return [
            'month' => $month,
            'source' => $pick((string) $request->query('source', 'all'), self::SOURCES, 'all'),
            'strategy' => $pick((string) $request->query('strategy', 'all'), self::STRATEGIES, 'all'),
            'status' => $pick((string) $request->query('connectivity_status', 'all'), self::STATUSES, 'all'),
            'stale' => $request->boolean('stale') ? '1' : '0',
            'page' => max(1, min(400, (int) $request->query('connectivity_page', 1))),
            'per_page' => max(10, min(100, (int) $request->query('connectivity_per_page', 50))),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>|null  $oltEvidence
     */
    public function compose(Customer $customer, ?GenieAcsDevice $device = null, ?array $oltEvidence = null): array
    {
        $evaluator = app(OnlineStatusEvaluator::class);
        $device ??= $customer->relationLoaded('genieAcsDevice') ? $customer->genieAcsDevice : null;
        $status = strtolower((string) $customer->status);
        $isolated = in_array($status, CustomerStatus::isolatedOrClosed(), true);

        // Signals with their own freshness windows (services.online_freshness).
        $ppp = $evaluator->pppoeSignal($customer);
        $olt = $evaluator->oltSignals($customer, $oltEvidence);
        $useOltSnapshot = $olt['state']['source'] === 'olt_onus';
        $oltStatus = $olt['state']['value'];
        $oltOnline = $olt['state']['online'];
        $oltCheckedAt = $olt['state']['checked_at'];
        $oltFresh = $olt['state']['fresh'];
        $pppoeOnline = $ppp['online'];
        $pppoeCheckedAt = $ppp['checked_at'];
        $pppoeFresh = $ppp['fresh'];

        $oltVendor = strtolower((string) ($oltEvidence['olt_vendor'] ?? ($customer->relationLoaded('olt') ? $customer->olt?->vendor : '')));
        $strict = $evaluator->isStrictVendor($oltVendor);
        if (! $strict && $olt['rx']['fresh'] && (! $oltFresh || ! $oltOnline)
            && (! $oltCheckedAt || $olt['rx']['checked_at']?->gt($oltCheckedAt))) {
            // Lenient OLTs (no reliable state collector, e.g. HiOSO): a fresh RX
            // that is newer than the state snapshot is fresh "ONU up" evidence.
            $oltOnline = true;
            $oltFresh = true;
            $oltCheckedAt = $olt['rx']['checked_at'];
        }

        $acsFresh = $device?->last_inform_at && $device->last_inform_at->gt(now()->subMinutes(30));
        $pingFresh = $device?->last_ping_at && $device->last_ping_at->gt(now()->subMinutes(45));
        $pingOk = $pingFresh ? (bool) $device->last_ping_success : null;
        $fiberhome = $device && str_contains(strtolower((string) $device->manufacturer), 'fiber');
        $hiosoHtb = str_contains($oltVendor, 'hioso') || str_contains($oltVendor, 'htb');
        $hasOltMapping = filled($customer->olt_id) || filled($customer->onu_serial);
        $manual = ! $fiberhome && ! $hiosoHtb && ! filled($customer->pppoe_user)
            && ($customer->router_id || $customer->olt_id || filled($customer->onu_serial));
        $strategy = $hiosoHtb ? 'hioso_htb' : ($fiberhome ? 'fiberhome_tr069' : ($manual ? 'manual' : (filled($customer->pppoe_user) ? 'pppoe_only' : 'unknown')));
        $hasFreshEvidence = $oltFresh || $pppoeFresh || $acsFresh || $pingFresh;
        $confidence = 'low';
        $reasonCode = 'insufficient_evidence';

        if ($isolated) {
            $connectivityStatus = 'isolated';
            $reason = 'Isolir billing/operasional; tidak dihitung sebagai gangguan konektivitas.';
            $confidence = 'high';
            $reasonCode = 'customer_isolated';
        } elseif (! $customer->router_id || (! $customer->olt_id && ! $customer->onu_serial)) {
            $connectivityStatus = 'setup_required';
            $reason = 'Mapping router/OLT/ONU belum lengkap.';
            $confidence = 'high';
            $reasonCode = 'mapping_incomplete';
        } elseif ($strict) {
            // Strict OLT (ZTE C300 RA/Krian, Huawei Gadang, ...): ONLINE only
            // with fresh PPPoE + fresh ONU state online + fresh valid RX.
            $verdict = $evaluator->strictVerdict($ppp, $olt);
            $connectivityStatus = $verdict['status'];
            $reason = $verdict['reason'];
            $confidence = $verdict['confidence'];
            $reasonCode = $verdict['reason_code'];
        } elseif ($fiberhome && ($acsFresh || $pingOk === true) && ($oltOnline || $pppoeOnline)) {
            $connectivityStatus = 'online';
            $reason = 'FiberHome: ACS fresh/IPPing berhasil dengan bukti OLT atau PPPoE.';
            $confidence = 'high';
            $reasonCode = $pingOk === true ? 'acs_ipping_ok' : 'acs_inform_and_access_layer_ok';
        } elseif ($pppoeOnline && $pppoeFresh && $oltOnline) {
            // A stale PPPoE flag is never enough: is_online must be confirmed
            // within ONLINE_PPP_MAX_AGE_MINUTES.
            $connectivityStatus = 'online';
            $reason = 'PPPoE aktif (snapshot segar) + ONU/OLT online.';
            $confidence = $oltFresh ? 'high' : 'medium';
            $reasonCode = 'pppoe_and_olt_online';
        } elseif ($oltFresh && $pppoeFresh && ! $pppoeOnline && ! $oltOnline) {
            $connectivityStatus = 'offline';
            $reason = 'PPPoE tidak aktif dan ONU/OLT offline; kedua snapshot masih fresh.';
            $confidence = 'high';
            $reasonCode = 'pppoe_and_olt_offline';
        } elseif ($hasFreshEvidence && ($pppoeOnline || $oltOnline || $acsFresh || $pingOk === true)) {
            $connectivityStatus = 'degraded';
            $reason = 'Sebagian sumber hidup, tetapi snapshot sumber lain stale atau tidak konsisten.';
            $confidence = 'medium';
            $reasonCode = 'partial_source_evidence';
        } elseif (! $hasFreshEvidence) {
            $connectivityStatus = 'unknown';
            $reason = 'Semua bukti konektivitas stale atau belum tersedia; bukan hasil ping live.';
            $confidence = 'low';
            $reasonCode = 'all_sources_stale';
        } else {
            $connectivityStatus = 'unknown';
            $reason = 'Belum ada bukti snapshot yang cukup; bukan hasil ping live.';
            $reasonCode = 'insufficient_evidence';
        }

        $checkedAt = collect([
            $pppoeCheckedAt,
            $oltCheckedAt,
            $olt['rx']['checked_at'],
            $device?->last_inform_at,
            $device?->last_ping_at,
        ])->filter()->map(fn ($value) => $value instanceof \DateTimeInterface ? $value : Carbon::parse((string) $value))->sortDesc()->first();
        $stale = ! $checkedAt || $checkedAt->lt(now()->subHours(2)) || $reasonCode === 'stale_evidence';

        $sourceBadges = array_values(array_filter([
            $device ? 'genieacs_inform' : null,
            filled($customer->pppoe_user) ? 'mikrotik_pppoe' : null,
            $hasOltMapping ? 'olt_onu' : null,
            $fiberhome ? 'genieacs_ip_ping' : null,
        ]));

        return [
            'customer_id' => (int) $customer->id,
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'area' => $customer->relationLoaded('area') ? $customer->area?->name : null,
            'router' => $customer->relationLoaded('router') ? $customer->router?->name : null,
            'olt' => $customer->relationLoaded('olt') ? $customer->olt?->name : null,
            'strategy' => $strategy,
            'status' => $connectivityStatus,
            'confidence' => $confidence,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'source' => $fiberhome ? 'genieacs_inform' : ($pppoeOnline ? 'mikrotik_pppoe' : ($hasOltMapping ? 'olt_onu' : 'unknown')),
            'source_badges' => $sourceBadges,
            'checked_at' => $checkedAt?->copy()->timezone('Asia/Jakarta')->toISOString(),
            'stale' => $stale,
            'latency_ms' => $device?->last_ping_rtt_ms,
            'packet_loss' => null,
            'latest_snapshot' => true,
            'evidence' => [
                'pppoe' => ['online' => $pppoeOnline, 'fresh' => $pppoeFresh, 'checked_at' => $pppoeCheckedAt?->toISOString()],
                'olt' => [
                    'online' => $oltOnline,
                    'fresh' => $oltFresh,
                    'checked_at' => $oltCheckedAt?->toISOString(),
                    'source' => $useOltSnapshot ? 'olt_onus' : 'customer_projection',
                    'state' => $oltStatus,
                ],
                'rx' => [
                    'dbm' => $olt['rx']['dbm'],
                    'valid' => $olt['rx']['valid'],
                    'fresh' => $olt['rx']['fresh'],
                    'checked_at' => $olt['rx']['checked_at']?->toISOString(),
                ],
                'acs' => ['fresh' => (bool) $acsFresh, 'ping_ok' => $pingOk, 'checked_at' => $device?->last_inform_at?->toISOString()],
            ],
            'rule' => $strict ? 'strict_pppoe_onu_rx' : 'lenient',
        ];
    }

    /**
     * Compose rows for an already-loaded set of customers (e.g. the map) with
     * the same rule as the NOC table. Customers should carry the columns in
     * customerColumns() and have the `olt` relation (id, vendor) loaded.
     *
     * @param  iterable<int, Customer>  $customers
     * @return array<int, array<string, mixed>> keyed by customer id
     */
    public function composeMany(iterable $customers): array
    {
        $evidence = $this->oltEvidenceMap($customers);
        $rows = [];
        foreach ($customers as $customer) {
            $rows[(int) $customer->id] = $this->compose(
                $customer,
                $customer->relationLoaded('genieAcsDevice') ? $customer->genieAcsDevice : null,
                $evidence[(int) $customer->id] ?? null,
            );
        }

        return $rows;
    }

    /**
     * Customer columns compose() reads.
     *
     * @return list<string>
     */
    public static function customerColumns(): array
    {
        $columns = ['id', 'code', 'name', 'status', 'router_id', 'olt_id', 'onu_serial', 'olt_status', 'olt_last_synced_at', 'onu_rx_power_dbm', 'is_online', 'pppoe_user', 'mikrotik_sync_checked_at'];
        if (PppoeOnlineFlagWriter::hasCheckedAtColumn()) {
            $columns[] = 'pppoe_online_checked_at';
        }

        return $columns;
    }

    /** @param array<string, string> $filters */
    private function build(array $filters): array
    {
        $query = Customer::query()
            ->with(['area:id,name', 'router:id,name', 'olt:id,name,vendor']);

        $columns = self::customerColumns();
        $query->select($columns)->whereIn('status', CustomerStatus::monitored());

        if (Schema::hasTable('genieacs_devices')) {
            $query->with('genieAcsDevice:id,customer_id,device_id,manufacturer,last_inform_at,last_ping_at,last_ping_success,last_ping_rtt_ms');
            if ($filters['source'] === 'genieacs') {
                $query->whereHas('genieAcsDevice');
            }
        }
        if ($filters['source'] === 'mikrotik_pppoe') {
            $query->whereNotNull('pppoe_user')->where('pppoe_user', '<>', '');
        } elseif ($filters['source'] === 'olt_onu') {
            $query->where(function ($q) {
                $q->whereNotNull('onu_serial')->orWhereNotNull('olt_id');
            });
        }

        $page = (int) $filters['page'];
        $perPage = (int) $filters['per_page'];
        $firstRow = (($page - 1) * $perPage) + 1;
        $lastRow = $page * $perPage;
        $pageRows = [];
        $totalRows = 0;
        $summary = ['total' => 0, 'online' => 0, 'isolated' => 0];

        // Compose and filter in bounded chunks so the summary and table share
        // one read-only dataset without loading all customers at once.
        $query->orderBy('id')->chunkById(500, function ($customers) use (&$pageRows, &$totalRows, &$summary, $filters, $firstRow, $lastRow): void {
            $oltEvidence = $this->oltEvidenceMap($customers);
            foreach ($customers as $customer) {
                $row = $this->compose(
                    $customer,
                    $customer->genieAcsDevice,
                    $oltEvidence[(int) $customer->id] ?? null,
                );
                if (! $this->matchesFilters($row, $filters)) {
                    continue;
                }

                $totalRows++;
                $summary['total']++;
                if ($row['status'] === 'online') {
                    $summary['online']++;
                } elseif ($row['status'] === 'isolated') {
                    $summary['isolated']++;
                }

                if ($totalRows >= $firstRow && $totalRows <= $lastRow) {
                    $pageRows[] = $row;
                }
            }
        });

        return [
            'enabled' => true,
            'filters' => $filters,
            'source_options' => [
                ['value' => 'all', 'label' => 'Semua'],
                ['value' => 'genieacs', 'label' => 'GenieACS'],
                ['value' => 'mikrotik_pppoe', 'label' => 'PPPoE'],
                ['value' => 'olt_onu', 'label' => 'OLT/ONU'],
            ],
            'strategy_options' => ['all', 'fiberhome_tr069', 'hioso_htb', 'pppoe_only', 'manual', 'unknown'],
            'status_options' => ['all', 'online', 'degraded', 'offline', 'isolated', 'maintenance', 'unknown', 'setup_required', 'not_pingable'],
            'summary' => [
                'total' => $summary['total'],
                'online' => $summary['online'],
                'isolated' => $summary['isolated'],
                'latest_snapshot' => true,
                'history_note' => 'Trend/history mengikuti filter bulan; daftar ini adalah latest snapshot, bukan live device query.',
            ],
            'rows' => $pageRows,
            'total_rows' => $totalRows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
                'from' => $totalRows === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $totalRows === 0 ? null : min($page * $perPage, $totalRows),
            ],
            'truncated' => $totalRows > ($page * $perPage),
            'read_only' => true,
        ];
    }

    /**
     * Match only the current bounded customer chunk to the latest OLT ONU
     * snapshot. This is read-only and avoids rewriting customer projections.
     * When a serial matches several rows (registered twice on one OLT, or a
     * leftover row on another OLT) OltOnuSerialResolver picks one
     * deterministically: a fresh online row always beats a stale/offline
     * duplicate; otherwise the most recent evidence wins, ties keep the
     * customer's own OLT.
     *
     * @param  iterable<int, Customer>  $customers
     * @return array<int, array<string, mixed>>
     */
    private function oltEvidenceMap(iterable $customers): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [];
        }

        $customerRows = collect($customers)
            ->filter(fn (Customer $customer) => filled($customer->onu_serial))
            ->mapWithKeys(fn (Customer $customer) => [
                (int) $customer->id => [
                    'olt_id' => (int) $customer->olt_id,
                    'serial' => $this->normalizeSerial($customer->onu_serial),
                ],
            ]);
        $serials = $customerRows->pluck('serial')->filter()->unique()->values()->all();
        if ($serials === []) {
            return [];
        }

        $hasOlts = Schema::hasTable('olts');
        $query = DB::table('olt_onus')
            ->whereIn('olt_onus.serial_number', $serials)
            ->orderBy('olt_onus.id');
        $select = [
            'olt_onus.id',
            'olt_onus.olt_id',
            'olt_onus.serial_number',
            'olt_onus.state',
            'olt_onus.phase_state',
            'olt_onus.rx_power_dbm',
            'olt_onus.state_collected_at',
            'olt_onus.rx_collected_at',
        ];
        if ($hasOlts) {
            $query->leftJoin('olts', 'olts.id', '=', 'olt_onus.olt_id');
            $select[] = 'olts.vendor as olt_vendor';
        }
        $snapshotRows = $query->get($select);
        if ($snapshotRows->isEmpty()) {
            return [];
        }

        $resolver = app(OltOnuSerialResolver::class);
        $bySerial = $resolver->groupBySerial($snapshotRows);

        $result = [];
        foreach ($customerRows as $customerId => $customerRow) {
            $row = $resolver->pick($bySerial[$customerRow['serial']] ?? [], $customerRow['olt_id']);
            if ($row) {
                $row = (array) $row;
                unset($row['id']);
                $result[$customerId] = $row;
            }
        }

        return $result;
    }

    private function normalizeSerial(mixed $serial): string
    {
        return strtoupper(trim((string) $serial));
    }

    /** @param array<string, mixed> $row @param array<string, string> $filters */
    private function matchesFilters(array $row, array $filters): bool
    {
        if ($filters['source'] !== 'all') {
            $source = $filters['source'];
            $badges = $row['source_badges'] ?? [];
            $sourceMatch = match ($source) {
                'genieacs' => in_array('genieacs_inform', $badges, true) || in_array('genieacs_ip_ping', $badges, true),
                'mikrotik_pppoe' => in_array('mikrotik_pppoe', $badges, true),
                'olt_onu' => in_array('olt_onu', $badges, true),
                default => false,
            };
            if (! $sourceMatch) {
                return false;
            }
        }

        return ($filters['strategy'] === 'all' || $row['strategy'] === $filters['strategy'])
            && ($filters['status'] === 'all' || $row['status'] === $filters['status'])
            && ($filters['stale'] !== '1' || $row['stale'] === true);
    }
}
