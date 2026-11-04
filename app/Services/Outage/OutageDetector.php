<?php

namespace App\Services\Outage;

use App\Jobs\EnrichOnuCauseJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OltOperationLog;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\PsbDraft;
use App\Models\SupportTicket;
use App\Services\DeviceRecoveryService;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 2.4 PR-2: outage detector in SHADOW mode.
 *
 * Reads ONLY already-collected tables (olt_onus, olt_collect_runs, customers, tickets, ...) and writes
 * ONLY outage_onu_tracker + outage_signals. It never opens an OLT/router session, never writes to
 * customers, never creates tickets and never sends WA. A mode of "live" is treated as shadow in this
 * PR (the live path arrives with the auto-ticket PR).
 *
 * Signals here: mass_los (per OLT+PON), single_los, dying_gasp (log only), olt_wide (collector glitch guard).
 */
class OutageDetector
{
    use DetectsWeakRxAndPppoe;

    /** pppoe_down is recorded from this many minutes (confirm uses outage_pppoe_down_minutes). */
    public const PPPOE_RECORD_MINUTES = 5;

    /** Tracker RX-sample writes per OLT per run (due ONUs beyond this wait for the next 5 minute run). */
    public const WEAK_RX_MAX_WRITES_PER_RUN = 300;

    /** Raw values kept for the current WIB day (interval >= 15 min => at most 96 per day). */
    public const WEAK_RX_DAY_VALUES_MAX = 96;

    /** This many customers of one router down together => one router-level alarm instead of N. */
    public const PPPOE_ROUTER_MIN = 5;

    /** olts.vendor => rule set. Anything else (e.g. HiOSO) is skipped. */
    public const VENDOR_FAMILIES = [
        'zte_c300' => 'zte',
        'huawei_ma5608t' => 'huawei',
    ];

    public const EXPIRE_AFTER_MINUTES = 120;

    public const MASS_RECORD_MIN = 2;

    public const MASS_WHOLE_PON_RATIO = 0.8;

    public const OLT_WIDE_RATIO = 0.30;

    public const OLT_WIDE_ABSOLUTE = 40;

    public const OLT_WIDE_MIN_CHANGED = 5;

    /** Newly-offline ONUs concentrated in this many PONs or fewer are a real cut, not a glitch. */
    public const OLT_WIDE_MAX_CONCENTRATED_PONS = 3;

    /** A stale phase_state older than this is not trusted for LOS / DyingGasp. */
    public const PHASE_MAX_AGE_MINUTES = 20;

    public const DG_LONG_HOURS = 24;

    /** A ZTE offline-without-cause ONU only counts as a fresh transition when it went offline this recently. */
    public const DOWN_UNKNOWN_FRESH_MINUTES = 15;

    public const PSB_OPEN_STATUSES = [
        PsbDraft::STATUS_RECEIVED,
        PsbDraft::STATUS_WAITING_SN,
        PsbDraft::STATUS_PENDING_VLAN,
        PsbDraft::STATUS_SECRET_OK,
        PsbDraft::STATUS_OLT_OK,
        PsbDraft::STATUS_CORRECTION_PENDING,
    ];

    private const TICKET_FINAL_STATUSES = ['resolved', 'closed', 'cancelled'];

    /** @var array<string,mixed> */
    private array $summary = [];

    /** @var array<int,string>|null OLT id => name, loaded once per run for the shadow WA entries. */
    private ?array $oltNameMap = null;

    /** --dry-run: no side effects at all (DB is rolled back, the olt_wide streak cache is not written). */
    private bool $dryRun = false;

    /** @var list<int> monitored OLTs where the serial fallback is allowed (outage_serial_fallback_olt_ids) */
    private array $serialFallbackIds = [];

    /** @var array<string,array<int,true>> normalized fresh ONU serial => OLT ids it appears on */
    private array $serialOlts = [];

    /** @var array<string,list<Customer>> customers with olt_id NULL by normalized serial (built once per run) */
    private array $nullOltBySerial = [];

    /**
     * Never throws: any failure is logged (class only, no data) and returned as summary['error'].
     *
     * @return array<string,mixed>
     */
    public function runSafely(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        try {
            return $this->run($now, $dryRun);
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] detector run failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
                // never throw into the scheduler
            }

            return ['error' => $e::class, 'new_confirmed' => []] + $this->emptySummary();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $this->summary = $this->emptySummary();
        $this->oltNameMap = null;
        $this->dryRun = $dryRun;

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->detect($now);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $this->summary;
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'olts' => [],
            'opened' => 0,
            'confirmed' => 0,
            'resolved' => 0,
            'expired' => 0,
            'suppressed' => [],
            'new_confirmed' => [],
        ];
    }

    private function detect(Carbon $now): void
    {
        $blindOn = OutageSettings::dataBlindEnabled();
        $this->blindRouters = $blindOn ? $this->routerBlindMap($now) : [];
        $this->blindRouterCustomers = [];
        $this->weakPending = [];

        $ids = OutageSettings::oltIds();
        $olts = $ids === [] ? collect() : Olt::query()->whereIn('id', $ids)->get()->keyBy('id');
        $this->prepareSerialIndex($ids, $now);

        foreach ($ids as $id) {
            $olt = $olts->get($id);
            if (! $olt) {
                $this->summary['olts'][$id] = ['status' => 'missing'];

                continue;
            }

            $family = self::VENDOR_FAMILIES[(string) $olt->vendor] ?? null;
            if ($family === null) {
                // Detector-side vendor check: a mislabelled / unsupported OLT never enters automation.
                $this->summary['olts'][$id] = ['status' => 'skipped_vendor'];
                Log::warning('[Outage] OLT skipped: unsupported vendor', ['olt_id' => $id]);

                continue;
            }

            if (! $this->oltDataIsFresh($olt, $family, $now)) {
                $this->summary['olts'][$id] = ['status' => 'stale', 'family' => $family];
                // Unknown phases: nothing opens, nothing closes while the data is stale.
                OutageOnuTracker::query()
                    ->where('olt_id', $id)
                    ->where('phase', '!=', 'unknown')
                    ->update(['phase' => 'unknown', 'phase_since_at' => null, 'updated_at' => $now]);
                // A stale gap must never count as PPPoE "down" time.
                OutageOnuTracker::query()->where('olt_id', $id)->whereNotNull('pppoe_down_since')->update(['pppoe_down_since' => null]);
                // RCA-1 DATA_BLIND: a collector that is stale / failed is a blind spot, not an outage.
                if ($blindOn) {
                    $this->recordBlind('olt:'.$id.':blind', ['olt_id' => $id], $this->oltBlindPayload($olt, $family, $now), (int) OutageOnuTracker::query()->where('olt_id', $id)->count(), $now);
                }

                continue;
            }
            if ($blindOn) {
                $this->resolveBlind('olt:'.$id.':blind', $now);
            }

            // One OLT failing must not stop the others (runSafely still catches anything outside).
            try {
                $this->processOlt($olt, $family, $now);
            } catch (\Throwable $e) {
                $this->summary['olts'][$id] = ['status' => 'error', 'family' => $family, 'error' => $e::class];
                Log::warning('[Outage] OLT processing failed', ['olt_id' => $id, 'exception_class' => $e::class]);
            }
        }

        $this->finalizeWeakRx($now);
        if ($blindOn) {
            $this->syncRouterBlind($now);
        }
        $this->processPppoe($now);
        $this->expireStale($now);
    }

    // -------------------------------------------------------------- DATA_BLIND (RCA-1)

    /** @var array<int,array<string,mixed>> router id => blind payload (this run) */
    private array $blindRouters = [];

    /** @var array<int,int> router id => PPPoE customers turned "unknown" because their router is blind */
    private array $blindRouterCustomers = [];

    /**
     * Routers whose PPPoE view cannot be trusted right now (read-only): the router is marked offline, its health
     * check is long overdue, or - when the Plan C pulse is live for it - its newest good snapshot is stale.
     *
     * @return array<int,array<string,mixed>>
     */
    private function routerBlindMap(Carbon $now): array
    {
        // RCA-4: the logic moved to RouterHealthReadModel so the diagnosis correlator shares it (same result).
        return app(RouterHealthReadModel::class)->blindMap($now);
    }

    /** @return array<string,mixed> */
    private function oltBlindPayload(Olt $olt, string $family, Carbon $now): array
    {
        $tier = $family === 'huawei' ? OltCollectRun::TIER_HUAWEI_OPTICAL : OltCollectRun::TIER_STATE;
        $last = OltCollectRun::query()->where('olt_id', $olt->id)->where('tier', $tier)->orderByDesc('id')->first();
        $lastOk = OltCollectRun::query()->where('olt_id', $olt->id)->where('tier', $tier)->where('status', 'success')->orderByDesc('id')->first();
        $okAt = $lastOk?->finished_at ?? $lastOk?->started_at;

        return [
            'kind' => 'olt',
            'family' => $family,
            'reason' => $last === null ? 'no_run' : ($last->status !== 'success' ? 'collector_failed' : 'collector_stale'),
            'last_status' => $last?->status,
            'minutes_since_success' => $okAt !== null ? (int) $okAt->diffInMinutes($now) : null,
        ];
    }

    /**
     * One open data_blind episode per blind scope (olt:ID:blind / router:ID:blind), refreshed every run while the
     * data stays blind. It never confirms, never has a would_action and never counts as a would-be ticket.
     *
     * @param  array<string,mixed>  $attrs
     * @param  array<string,mixed>  $payload
     */
    private function recordBlind(string $scope, array $attrs, array $payload, int $affected, Carbon $now): void
    {
        $ep = OutageSignal::query()->open()->where('scope_key', $scope)->first();
        if ($ep === null) {
            $ep = $this->createEpisode('data_blind', $scope, $attrs + [
                'opened_at' => $now,
                'last_seen_at' => $now,
                'observations' => 1,
                'affected_count' => $affected,
                'would_action' => 'none',
                'payload' => $payload,
            ]);
            $this->summary['opened']++;
        } else {
            $ep->observations = (int) $ep->observations + 1;
        }
        $ep->state = OutageSignal::STATE_OPEN;
        $ep->payload = $payload;
        $ep->affected_count = $affected;
        $ep->last_seen_at = $now;
        $ep->would_action = 'none';
        $ep->save();
        $this->bump('data_blind', $payload['kind'] ?? 'other');
    }

    private function resolveBlind(string $scope, Carbon $now): void
    {
        $ep = OutageSignal::query()->open()->where('scope_key', $scope)->first();
        if ($ep !== null) {
            $this->resolve($ep, $now);
        }
    }

    /** Record blind routers, close episodes of routers that are readable again. */
    private function syncRouterBlind(Carbon $now): void
    {
        foreach ($this->blindRouters as $rid => $payload) {
            $this->recordBlind('router:'.$rid.':blind', [], $payload + ['pppoe_customers_unknown' => $this->blindRouterCustomers[$rid] ?? 0], $this->blindRouterCustomers[$rid] ?? 0, $now);
        }
        $open = OutageSignal::query()->open()->ofType('data_blind')->where('scope_key', 'like', 'router:%:blind')->get();
        foreach ($open as $ep) {
            if (preg_match('/^router:(\d+):blind$/', (string) $ep->scope_key, $m) === 1 && ! isset($this->blindRouters[(int) $m[1]])) {
                $this->resolve($ep, $now);
            }
        }
    }

    /**
     * Once per run: OLTs where the serial fallback is allowed, the NULL-olt customers indexed by serial
     * and the set of monitored OLTs each fresh ONU serial appears on (a serial on 2+ OLTs is ambiguous).
     *
     * @param  list<int>  $ids
     */
    private function prepareSerialIndex(array $ids, Carbon $now): void
    {
        $this->serialFallbackIds = array_values(array_intersect($ids, OutageSettings::serialFallbackOltIds()));
        $this->serialOlts = [];
        $this->nullOltBySerial = [];
        if ($this->serialFallbackIds === []) {
            return;
        }

        $fresh = $now->copy()->subMinutes(OutageSettings::stateMaxAgeMinutes());
        $rows = OltOnu::query()
            ->whereIn('olt_id', $ids)
            ->whereNotNull('state_collected_at')
            ->where('state_collected_at', '>=', $fresh)
            ->whereNotNull('serial_number')
            ->get(['olt_id', 'serial_number']);
        foreach ($rows as $row) {
            $sn = self::serialKey((string) $row->serial_number);
            if ($sn !== null) {
                $this->serialOlts[$sn][(int) $row->olt_id] = true;
            }
        }

        $nullOlt = Customer::query()
            ->whereNull('olt_id')
            ->whereNotNull('onu_serial')
            ->get(['id', 'olt_id', 'olt_port_label', 'status', 'mikrotik_profile', 'area_id', 'onu_serial', 'odp_name', 'router_id', 'is_online', 'pppoe_online_checked_at']);
        foreach ($nullOlt as $c) {
            $sn = self::serialKey((string) $c->onu_serial);
            if ($sn !== null) {
                $this->nullOltBySerial[$sn][] = $c;
            }
        }
    }

    private function oltDataIsFresh(Olt $olt, string $family, Carbon $now): bool
    {
        $tier = $family === 'huawei' ? OltCollectRun::TIER_HUAWEI_OPTICAL : OltCollectRun::TIER_STATE;
        $run = OltCollectRun::query()
            ->where('olt_id', $olt->id)
            ->where('tier', $tier)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->first();
        if (! $run) {
            return false;
        }

        $at = $run->finished_at ?? $run->started_at;

        return $at !== null && $at->gte($now->copy()->subMinutes(OutageSettings::stateMaxAgeMinutes()));
    }

    /**
     * olt_onus columns the detector reads. phase_collected_at is guarded so outage:detect does not
     * throw "unknown column" when it runs between a code deploy and `migrate` (#513 LOW-2); without
     * it the phase age falls back to inventory_collected_at.
     *
     * @return list<string>
     */
    private function onuColumns(): array
    {
        $columns = ['onu_ref', 'pon_port', 'serial_number', 'state', 'phase_state', 'state_collected_at', 'inventory_collected_at', 'distance_m', 'rx_power_dbm', 'rx_collected_at'];
        if (Schema::hasColumn('olt_onus', 'phase_collected_at')) {
            $columns[] = 'phase_collected_at';
        }

        return $columns;
    }

    private function processOlt(Olt $olt, string $family, Carbon $now): void
    {
        $maxAge = OutageSettings::stateMaxAgeMinutes();
        $fresh = $now->copy()->subMinutes($maxAge);

        $onus = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereNotNull('state_collected_at')
            ->where('state_collected_at', '>=', $fresh)
            ->get($this->onuColumns());

        // Customers of this OLT, joined to the collected ONUs by ONU ref (ZTE "1/3/3:19", Huawei
        // "0/0/3:12") first and by serial second. The serial join exists because many customers (Gadang:
        // 65 of 72 serial matches) have olt_id NULL or a label that is not a full ONU ref.
        /** @var array<string,list<Customer>> $byRef */
        $byRef = [];
        /** @var array<string,list<Customer>> $bySerial */
        $bySerial = [];
        $serialFallback = in_array((int) $olt->id, $this->serialFallbackIds, true);
        $customers = Customer::query()
            ->where('olt_id', $olt->id)
            ->where(fn ($q) => $q->whereNotNull('olt_port_label')->orWhereNotNull('onu_serial'))
            ->get(['id', 'olt_id', 'olt_port_label', 'status', 'mikrotik_profile', 'area_id', 'onu_serial', 'odp_name', 'router_id', 'is_online', 'pppoe_online_checked_at']);
        foreach ($customers as $c) {
            $ref = self::normalizeRef((string) $c->olt_port_label);
            if ($ref !== null) {
                $byRef[$ref][] = $c;
            }
            $sn = self::serialKey((string) $c->onu_serial);
            if ($sn !== null && $serialFallback) {
                $bySerial[$sn][] = $c;
            }
        }
        // Customers whose olt_id is still NULL (never assigned) join by serial only, from the index built
        // once per run (prepareSerialIndex). Customers of another OLT are never considered. Read-only.

        // Refs / serials present in this snapshot (a serial seen on two ONU rows is ambiguous).
        $onuRefs = [];
        $onuSerialCount = [];
        foreach ($onus as $onu) {
            $r = self::normalizeRef((string) $onu->onu_ref);
            if ($r !== null) {
                $onuRefs[$r] = true;
                $s = self::serialKey((string) $onu->serial_number);
                if ($s !== null) {
                    $onuSerialCount[$s] = ($onuSerialCount[$s] ?? 0) + 1;
                }
            }
        }

        $trackers = OutageOnuTracker::query()->where('olt_id', $olt->id)->get()->keyBy('onu_ref');

        // Pass 1: classify in memory (no writes yet).
        $rows = [];
        $leftOnline = 0;
        $leftPons = [];
        $ponsWithCustomers = [];
        $eligibleMapped = 0;
        $mapStats = ['by_ref' => 0, 'by_serial' => 0, 'by_serial_null_olt' => 0, 'unmapped' => 0, 'ambiguous' => 0, 'not_eligible' => 0];
        foreach ($onus as $onu) {
            $ref = self::normalizeRef((string) $onu->onu_ref);
            if ($ref === null) {
                continue;
            }
            $cands = $byRef[$ref] ?? [];
            $mappedBy = 'by_ref';
            if ($cands === []) {
                $sn = self::serialKey((string) $onu->serial_number);
                if ($sn !== null && $serialFallback) {
                    $dupSerial = ($onuSerialCount[$sn] ?? 0) > 1 || count($this->serialOlts[$sn] ?? []) > 1;
                    if ($dupSerial) {
                        // The same serial on two ONU rows / in the fresh snapshots of two OLTs: never joined.
                        $mapStats['ambiguous']++;
                        $cands = [];
                        $mappedBy = 'dup';
                    } else {
                        // Customers whose own label already points at a collected ONU keep that mapping.
                        $cands = array_values(array_filter(
                            array_merge($bySerial[$sn] ?? [], $this->nullOltBySerial[$sn] ?? []),
                            fn (Customer $c) => $c->olt_id === null
                                || ! isset($onuRefs[(string) self::normalizeRef((string) $c->olt_port_label)]),
                        ));
                        if (count($cands) > 1) {
                            // e.g. an old terminated account and the current one share a serial.
                            $eligibleCands = array_values(array_filter($cands, fn (Customer $c) => self::customerEligible($c)));
                            if (count($eligibleCands) === 1) {
                                $cands = $eligibleCands;
                            }
                        }
                        $mappedBy = 'by_serial';
                    }
                }
            }
            $customer = count($cands) === 1 ? $cands[0] : null; // 0 = unmapped, >1 = ambiguous
            if ($customer !== null && $mappedBy === 'by_serial' && $customer->olt_id === null) {
                $mappedBy = 'by_serial_null_olt';
            }
            if ($mappedBy !== 'dup') {
                $mapStats[$customer === null ? (count($cands) > 1 ? 'ambiguous' : 'unmapped') : $mappedBy]++;
            }
            $tracker = $trackers->get($ref);
            $phase = $this->classify($family, $onu, $tracker?->phase, $now);
            $prevKnown = $tracker && $tracker->phase !== 'unknown';
            $eligible = $customer !== null && self::customerEligible($customer);

            if ($eligible) {
                $eligibleMapped++;
                // Only PONs that had an eligible ONU online in the previous snapshot count as "PONs with
                // customers": a PON whose customers were all already offline cannot "drop".
                if ($prevKnown && $tracker->phase === 'online') {
                    $ponsWithCustomers[(string) self::ponOf($ref)] = true;
                }
                if ($prevKnown && $tracker->phase === 'online' && in_array($phase, ['los', 'dying_gasp', 'offline'], true)) {
                    $leftOnline++;
                    $leftPons[(string) self::ponOf($ref)] = true;
                }
            } elseif ($customer !== null) {
                $mapStats['not_eligible']++;
            }
            if (! $eligible && $customer !== null && in_array($phase, ['los', 'dying_gasp', 'offline'], true)) {
                // Isolated / non-active customers are not recorded; they are only counted.
                $this->bump('suppressed', 'isolated');
            }

            $rows[$ref] = [
                'ref' => $ref,
                'phase' => $phase,
                'prev_known' => $prevKnown,
                'tracker' => $tracker,
                'customer' => $customer,
                'ambiguous' => count($cands) > 1,
                'eligible' => $eligible,
                'pon' => self::ponOf($ref),
                'serial' => (string) $onu->serial_number,
                'distance' => $onu->distance_m !== null && (int) $onu->distance_m > 0 ? (int) $onu->distance_m : null,
                'collected' => $onu->state_collected_at,
                'rx' => $phase === 'online' ? self::rxOf($onu) : null,
                'rx_at' => $onu->rx_collected_at,
                'since' => null,
                // First sight, or first snapshot after a stale period: re-baseline only, never confirm.
                'fresh_baseline' => ! $prevKnown,
                'no_confirm' => ! $prevKnown,
            ];
        }

        // Global safety 2: collector glitch / one sick OLT. If the newly-offline ONUs sit in <= 3 PONs
        // it is a real feeder / fiber cut and is evaluated as mass_los per PON. Otherwise hold this
        // snapshot; the 2nd consecutive wide snapshot is accepted (re-baseline) so a long event
        // cannot hold the detector forever.
        $wideRaw = $eligibleMapped > 0
            && $leftOnline >= self::OLT_WIDE_MIN_CHANGED
            && ($leftOnline > self::OLT_WIDE_ABSOLUTE || ($leftOnline / $eligibleMapped) > self::OLT_WIDE_RATIO);
        // "Concentrated" = few changed PONs AND not every PON that has customers (a drop of all PONs at
        // once looks like the OLT / collector, not a cut). The hold applies whatever the olt_wide mode is;
        // the mode only controls recording.
        $concentrated = count($leftPons) <= self::OLT_WIDE_MAX_CONCENTRATED_PONS && count($leftPons) < count($ponsWithCustomers);
        $wide = $wideRaw && ! $concentrated;
        $accepted = false;
        if (! $wide) {
            $this->oltWideStreak($olt, 0, $now);
        }
        if ($wide) {
            $consecutive = $this->oltWideConsecutive($olt) + 1;
            $this->oltWideStreak($olt, $consecutive, $now);
            if ($consecutive >= 2) {
                $accepted = true;
                $wide = false;
                $this->recordOltWide($olt, $leftOnline, $eligibleMapped, $now, $consecutive);
                foreach ($rows as &$row) {
                    $row['no_confirm'] = true;
                }
                unset($row);
            }
        }

        $this->summary['olts'][$olt->id] = [
            'status' => $wide ? 'olt_wide' : ($accepted ? 'olt_wide_accepted' : 'ok'),
            'family' => $family,
            'onus' => count($rows),
            'mapped_eligible' => $eligibleMapped,
            // Mapping diagnostics (counts only): how ONUs were joined to customers.
            'mapping' => $mapStats,
        ];

        if ($wide) {
            $this->recordOltWide($olt, $leftOnline, $eligibleMapped, $now, $consecutive);

            return;
        }
        if (! $accepted) {
            $this->resolveOltWide($olt, $now);
        }

        // Pass 2: tracker writes (only changed rows).
        $this->writeTracker($olt, $rows, $now);

        $this->detectMass($olt, $family, $rows, $now);
        $this->detectCustomerSignals($olt, $family, $rows, $now);

        // PR-3 (shadow): RX trend and PPPoE state of the same snapshot.
        $this->hydrateTrackers($olt, $rows);
        $this->detectWeakRx($olt, $family, $rows, $now);
        $this->collectPppoe($olt, $family, $rows, $now);
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function writeTracker(Olt $olt, array &$rows, Carbon $now): void
    {
        $inserts = [];
        foreach ($rows as $ref => &$r) {
            /** @var OutageOnuTracker|null $t */
            $t = $r['tracker'];
            $customerId = $r['customer']?->id;

            if ($t === null) {
                // Baseline: first sight, age unknown (never counts as a fresh transition).
                $r['since'] = null;
                $inserts[] = [
                    'olt_id' => $olt->id,
                    'onu_ref' => $ref,
                    'customer_id' => $customerId,
                    'phase' => $r['phase'],
                    'phase_since_at' => null,
                    'last_state_collected_at' => $r['collected'],
                    'last_onu_distance_m' => $r['phase'] === 'online' ? $r['distance'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            $dirty = false;
            if ($t->phase !== $r['phase']) {
                // Known predecessor => real transition time; unknown predecessor => age unknown.
                $t->phase_since_at = $r['prev_known'] ? ($r['collected'] ?? $now) : null;
                $t->phase = $r['phase'];
                $t->last_state_collected_at = $r['collected'];
                $dirty = true;
            }
            if ($t->customer_id !== $customerId) {
                $t->customer_id = $customerId;
                $dirty = true;
            }
            if ($r['phase'] === 'online' && $r['distance'] !== null && (int) $t->last_onu_distance_m !== $r['distance']) {
                $t->last_onu_distance_m = $r['distance'];
                $dirty = true;
            }
            if ($dirty) {
                $t->save();
            }
            $r['since'] = $t->phase_since_at;
        }
        unset($r);

        foreach (array_chunk($inserts, 200) as $chunk) {
            OutageOnuTracker::query()->insert($chunk);
        }
    }

    // ---------------------------------------------------------------- mass_los

    /**
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function detectMass(Olt $olt, string $family, array $rows, Carbon $now): void
    {
        $mode = OutageSettings::modeFor('mass_los', $family);
        if ($mode === 'off') {
            return;
        }

        $window = max(OutageSettings::massWindowMinutes(), 10);
        $since = $now->copy()->subMinutes($window);
        $min = OutageSettings::massMinCustomers();

        /** @var array<string,list<array<string,mixed>>> $byPon */
        $byPon = [];
        foreach ($rows as $r) {
            if ($r['eligible'] && $r['pon'] !== null) {
                $byPon[$r['pon']][] = $r;
            }
        }

        $existing = OutageSignal::query()->open()->ofType('mass_los')->where('olt_id', $olt->id)->get()->keyBy('pon_port');
        $ponKeys = array_unique(array_merge(array_keys($byPon), $existing->keys()->all()));

        foreach ($ponKeys as $pon) {
            $members = $byPon[$pon] ?? [];
            $scope = 'pon:'.$olt->id.':'.$pon;
            $episode = $existing->get($pon);
            $prior = $episode ? array_map('intval', (array) $episode->affected_customer_ids) : [];

            $losRows = array_values(array_filter($members, fn ($r) => $this->isLosLike($family, $r['phase'])));
            $suppressed = $this->suppressionMap($olt, $losRows, $now, false);

            $counted = [];
            $rebaseline = false;
            foreach ($losRows as $r) {
                if ($r['no_confirm']) {
                    // First snapshot after stale / first sight / accepted olt_wide: re-baseline only.
                    $rebaseline = true;

                    continue;
                }
                $cid = $r['customer']->id;
                if (isset($suppressed[$cid])) {
                    $this->bump('suppressed', $suppressed[$cid]);

                    continue;
                }
                $isNew = $r['since'] !== null && $r['since']->gte($since);
                if ($isNew || in_array($cid, $prior, true)) {
                    $counted[] = $r;
                }
            }
            $count = count($counted);
            $ids = array_slice(array_map(fn ($r) => $r['customer']->id, $counted), 0, 300);

            if ($episode === null) {
                if ($count < self::MASS_RECORD_MIN || $members === []) {
                    continue;
                }
                $episode = $this->createEpisode('mass_los', $scope, [
                    'olt_id' => $olt->id,
                    'pon_port' => $pon,
                    'opened_at' => $now,
                    'last_seen_at' => $now,
                    'observations' => 1,
                    'affected_count' => $count,
                    'affected_customer_ids' => $ids,
                    'payload' => ['family' => $family, 'cause_unknown' => $family === 'huawei', 'low_runs' => 0],
                ]);
                $this->summary['opened']++;
            } elseif ($members !== []) {
                $payload = (array) $episode->payload;
                $episode->last_seen_at = $now;
                $episode->observations = (int) $episode->observations + 1;
                if ($count >= self::MASS_RECORD_MIN) {
                    $episode->affected_count = $count;
                    $episode->affected_customer_ids = $ids;
                    $payload['low_runs'] = 0;
                } elseif (! $rebaseline) {
                    $payload['low_runs'] = (int) ($payload['low_runs'] ?? 0) + 1;
                    if ($payload['low_runs'] >= 2) {
                        $episode->payload = $payload;
                        $this->resolve($episode, $now);

                        continue;
                    }
                }
                $episode->payload = $payload;
                $episode->save();
            } else {
                continue;
            }

            if ($episode->state === OutageSignal::STATE_OPEN && $count >= $min) {
                $payload = (array) $episode->payload;
                $payload['pon_total'] = count($members);
                $payload['whole_pon'] = count($members) > 0 && ($count / count($members)) >= self::MASS_WHOLE_PON_RATIO;
                $payload['segment_estimate_m'] = $this->segmentEstimate($rows, $pon, $counted);
                $payload['thresholds'] = ['mass_min' => $min, 'window_min' => $window];
                $afterWide = $this->recentAcceptedOltWide($olt, $now);
                if ($afterWide !== null) {
                    $payload['after_olt_wide'] = $afterWide;
                }
                $episode->payload = $payload;
                $episode->affected_count = $count;
                $this->confirm($episode, 'mass_los', $mode, 'ticket_mass', $now, $olt, $family);
            }
        }
    }

    /**
     * Fiber-cut segment estimate from the last known ONU distances: the cut lies between the farthest
     * still-working ONU that is closer than every affected ONU and the nearest affected ONU.
     *
     * @param  array<string,array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $affected
     * @return array{from_m:int,to_m:int}|null
     */
    private function segmentEstimate(array $rows, string $pon, array $affected): ?array
    {
        $affectedRefs = array_column($affected, 'ref');
        $affDist = [];
        foreach ($affected as $r) {
            $d = $r['tracker']?->last_onu_distance_m ?: $r['distance'];
            if ($d) {
                $affDist[] = (int) $d;
            }
        }
        if ($affDist === []) {
            return null;
        }
        $nearest = min($affDist);

        $from = 0;
        foreach ($rows as $r) {
            if ($r['pon'] !== $pon || $r['phase'] !== 'online' || in_array($r['ref'], $affectedRefs, true)) {
                continue;
            }
            $d = (int) ($r['distance'] ?: ($r['tracker']?->last_onu_distance_m ?? 0));
            if ($d > 0 && $d < $nearest && $d > $from) {
                $from = $d;
            }
        }

        return ['from_m' => $from, 'to_m' => $nearest];
    }

    // ------------------------------------------------------ single_los / dying_gasp

    /**
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function detectCustomerSignals(Olt $olt, string $family, array $rows, Carbon $now): void
    {
        $singleMode = OutageSettings::modeFor('single_los', $family);
        $dgMode = OutageSettings::mode('dying_gasp');

        /** @var array<int,array<string,mixed>> $byCustomer */
        $byCustomer = [];
        foreach ($rows as $r) {
            if ($r['customer'] !== null && $r['eligible']) {
                $byCustomer[$r['customer']->id] = $r;
            }
        }

        // Looked up by customer (scope_key is customer:{id}, not per OLT): a customer re-pointed to
        // another monitored OLT must continue its open episode instead of hitting the open_key UNIQUE.
        $open = collect();
        foreach (array_chunk(array_keys($byCustomer), 500) as $chunk) {
            $open = $open->concat(OutageSignal::query()->open()->whereIn('signal_type', ['single_los', 'dying_gasp'])
                ->whereIn('customer_id', $chunk)->get());
        }
        $open = $open->groupBy('customer_id');

        $losRows = [];
        foreach ($byCustomer as $r) {
            if ($this->isDownLike($family, $r['phase'])) {
                $losRows[] = $r;
            }
        }
        $suppressed = $singleMode === 'off' ? [] : $this->suppressionMap($olt, $losRows, $now, true);

        $massOf = OutageSignal::query()->open()->ofType('mass_los')->where('olt_id', $olt->id)
            ->where('state', OutageSignal::STATE_CONFIRMED)->get();

        // Existing open episodes: recovery / progression.
        foreach ($open as $customerId => $episodes) {
            $r = $byCustomer[(int) $customerId] ?? null;
            if ($r === null) {
                continue; // not observed this run: left to expire
            }
            foreach ($episodes as $ep) {
                $this->progressEpisode($ep, $r, $family, $now);
            }
        }

        // New / continuing LOS-like episodes.
        if ($singleMode !== 'off') {
            foreach ($losRows as $r) {
                $cid = $r['customer']->id;
                $reason = $suppressed[$cid] ?? null;
                $ep = ($open->get($cid) ?? collect())->firstWhere('signal_type', 'single_los');
                $parent = $massOf->first(fn ($m) => in_array($cid, array_map('intval', (array) $m->affected_customer_ids), true));

                if ($ep === null) {
                    $ep = $this->createEpisode('single_los', 'customer:'.$cid, [
                        'olt_id' => $olt->id,
                        'pon_port' => $r['pon'],
                        'customer_id' => $cid,
                        'area_id' => $r['customer']->area_id,
                        'opened_at' => $r['since'] ?? ($r['collected'] ?? $now),
                        'last_seen_at' => $now,
                        'observations' => 1,
                        'affected_count' => 1,
                        // Already LOS / offline when first seen (day-1 baseline or after a stale
                        // period): age unknown, so it is never counted as "would have ticketed".
                        // RCA-1: a ZTE "offline, no cause" ONU that was already offline before this run (tracker with an
                        // old / NULL phase_since_at) is baseline too, not a fresh online -> offline transition.
                        'payload' => ['family' => $family, 'cause_unknown' => $family === 'huawei', 'baseline' => (bool) $r['fresh_baseline']
                            || ($family === 'zte' && $r['phase'] !== 'los' && ($r['since'] === null || $r['since']->lt($now->copy()->subMinutes(self::DOWN_UNKNOWN_FRESH_MINUTES))))]
                            + ($family === 'zte' ? ['cause' => $r['phase'] === 'los' ? 'los' : 'down_unknown'] : []),
                    ]);
                    $this->summary['opened']++;
                } else {
                    $payload = (array) $ep->payload;
                    if ($family === 'zte') {
                        // LOS wins; a bare "offline" only labels the episode when no cause was seen yet.
                        if ($r['phase'] === 'los') {
                            $payload['cause'] = 'los';
                        } elseif (empty($payload['cause'])) {
                            $payload['cause'] = 'down_unknown';
                        }
                    }
                    if ((isset($payload['online_since']) || ! empty($payload['seen_online'])) && $ep->state === OutageSignal::STATE_OPEN) {
                        // Seen online since it opened (even with a DG / offline blip in between), now back in
                        // LOS: the 30 minutes count from this new LOS.
                        $ep->opened_at = $r['since'] ?? $now;
                    }
                    unset($payload['online_since'], $payload['seen_online']);
                    $ep->payload = $payload;
                    $ep->olt_id = $olt->id;
                    $ep->pon_port = $r['pon'];
                    $ep->last_seen_at = $now;
                    $ep->observations = (int) $ep->observations + 1;
                }

                if ($reason === null && ! empty(((array) $ep->payload)['baseline'])) {
                    $reason = 'baseline';
                }
                // RCA-2: ask the OLT ONCE why this ONU went down (low priority queue job, read-only), and use
                // an answer that already came back: power-off / never-registered ONUs are never "would ticket".
                $this->maybeQueueEnrichment($ep, $olt, $family, $r);
                if ($reason === null) {
                    $reason = self::enrichedSuppression((array) $ep->payload);
                }

                if ($parent !== null) {
                    $ep->parent_signal_id ??= $parent->id;
                    if ($ep->state === OutageSignal::STATE_OPEN) {
                        $reason = 'inside_mass';
                    }
                }

                if ($ep->state === OutageSignal::STATE_OPEN) {
                    $ep->suppressed_reason = $reason;
                    if ($reason !== null) {
                        $this->bump('suppressed', $reason);
                    }
                    $downUnknown = (((array) $ep->payload)['cause'] ?? null) === 'down_unknown';
                    $minutes = $downUnknown ? OutageSettings::downUnknownMinutes() : OutageSettings::singleLosMinutes();
                    if ($reason === null && ! $r['no_confirm'] && $ep->opened_at->lte($now->copy()->subMinutes($minutes))) {
                        $payload = (array) $ep->payload;
                        $payload['thresholds'] = $downUnknown ? ['down_unknown_minutes' => $minutes] : ['single_los_minutes' => $minutes];
                        $ep->payload = $payload;
                        $this->confirm($ep, 'single_los', $singleMode, 'ticket_single', $now, $olt, $family);

                        continue;
                    }
                }
                $ep->save();
            }
        }

        // Dying gasp: record only, never a ticket.
        if ($dgMode !== 'off') {
            foreach ($byCustomer as $cid => $r) {
                if ($r['phase'] !== 'dying_gasp') {
                    continue;
                }
                $ep = ($open->get($cid) ?? collect())->firstWhere('signal_type', 'dying_gasp');
                if ($ep === null) {
                    $this->createEpisode('dying_gasp', 'customer:'.$cid.':dg', [
                        'olt_id' => $olt->id,
                        'pon_port' => $r['pon'],
                        'customer_id' => $cid,
                        'area_id' => $r['customer']->area_id,
                        'opened_at' => $r['since'] ?? ($r['collected'] ?? $now),
                        'last_seen_at' => $now,
                        'observations' => 1,
                        'affected_count' => 1,
                        'would_action' => 'log_only',
                        'payload' => ['family' => $family, 'baseline' => (bool) $r['fresh_baseline']],
                    ]);
                    $this->summary['opened']++;

                    continue;
                }
                $payload = (array) $ep->payload;
                unset($payload['online_since']);
                $ep->payload = $payload;
                $ep->last_seen_at = $now;
                $ep->observations = (int) $ep->observations + 1;
                if ($ep->state === OutageSignal::STATE_OPEN && ! $r['no_confirm']
                    && $ep->opened_at->lte($now->copy()->subHours(self::DG_LONG_HOURS))) {
                    // Long power-off: reported as "long outage?" only, no action.
                    $ep->state = OutageSignal::STATE_CONFIRMED;
                    $ep->confirmed_at = $now;
                    $ep->mode_at_confirm = 'shadow';
                    $ep->would_action = 'log_only';
                    $this->summary['confirmed']++;
                }
                $ep->save();
            }
        }
    }

    /**
     * Queue a one-ONU cause read for a fresh down episode without a clear cause (ZTE offline / Huawei offline).
     * Never throws, never in dry-run, and only when the episode is new (not baseline) and was not enriched yet.
     *
     * @param  array<string,mixed>  $r
     */
    private function maybeQueueEnrichment(OutageSignal $ep, Olt $olt, string $family, array $r): void
    {
        if ($this->dryRun || $ep->id === null || ! in_array($ep->state, [OutageSignal::STATE_OPEN, OutageSignal::STATE_CONFIRMED], true)) {
            return;
        }
        $payload = (array) $ep->payload;
        if (! empty($payload['baseline']) || ! empty($payload['enrich'])) {
            return;
        }
        $unclear = $family === 'huawei' ? $r['phase'] === 'offline' : (($payload['cause'] ?? null) === 'down_unknown');
        // L6: an ONU read lately or backing off (rate limited / failed) is not queued again every 5 minutes.
        if (! $unclear || ! OltCauseEnricher::dispatchEnabled() || OltCauseEnricher::recentlyAttempted((int) $olt->id, (string) $r['ref'])) {
            return;
        }
        try {
            EnrichOnuCauseJob::dispatch((int) $olt->id, (string) $r['ref'], $ep->customer_id !== null ? (int) $ep->customer_id : null, (int) $ep->id);
        } catch (\Throwable $e) {
            Log::warning('[Outage] enrichment dispatch failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
        }
    }

    /**
     * Suppression reason from an enrichment answer: a powered-off ONU (dying gasp) is the customer's side and a
     * never-registered ONU is leftover configuration; neither is a would-be ticket.
     *
     * @param  array<string,mixed>  $payload
     */
    private static function enrichedSuppression(array $payload): ?string
    {
        return match ($payload['cause_detail'] ?? null) {
            'dying_gasp' => 'power_off',
            'never_registered' => 'never_registered',
            default => null,
        };
    }

    /**
     * Recovery handling for an open single_los / dying_gasp episode of a customer seen this run.
     *
     * @param  array<string,mixed>  $r
     */
    private function progressEpisode(OutageSignal $ep, array $r, string $family, Carbon $now): void
    {
        $isDg = $ep->signal_type === 'dying_gasp';
        $stillSame = $isDg ? $r['phase'] === 'dying_gasp' : $this->isDownLike($family, $r['phase']);
        if ($stillSame) {
            return; // handled by the creation / continuation loops
        }

        $payload = (array) $ep->payload;
        if ($r['phase'] === 'online') {
            $onlineSince = isset($payload['online_since']) ? Carbon::parse($payload['online_since']) : $now;
            $payload['online_since'] = $onlineSince->toIso8601String();
            // Survives intermediate non-LOS phases (DG / offline / unknown) until the next LOS consumes it.
            $payload['seen_online'] = true;
            // Observed online: a later LOS on a re-opened episode is a real new one, not baseline.
            unset($payload['baseline']);
            $ep->payload = $payload;
            $ep->last_seen_at = $now;
            if ($onlineSince->lte($now->copy()->subMinutes(OutageSettings::recoveryStableMinutes()))) {
                $this->resolve($ep, $now);

                return;
            }
            $ep->save();

            return;
        }

        unset($payload['online_since']);
        $ep->payload = $payload;
        if ($isDg) {
            // No longer dying gasp and not online (e.g. DG -> LOS): the DG episode ends here.
            $this->resolve($ep, $now);

            return;
        }
        $ep->save();
    }

    // ------------------------------------------------------------------ olt_wide

    /** Id of an olt_wide episode accepted (2 consecutive snapshots) for this OLT in the last hour, if any. */
    private function recentAcceptedOltWide(Olt $olt, Carbon $now): ?int
    {
        $ep = OutageSignal::query()
            ->where('signal_type', 'olt_wide')
            ->where('olt_id', $olt->id)
            ->where('updated_at', '>=', $now->copy()->subMinutes(60))
            ->orderByDesc('id')
            ->first();

        return ($ep !== null && (int) (((array) $ep->payload)['consecutive'] ?? 0) >= 2) ? (int) $ep->id : null;
    }

    /** Consecutive wide snapshots already recorded for this OLT (0 when no olt_wide episode is open). */
    private function oltWideConsecutive(Olt $olt): int
    {
        $ep = OutageSignal::query()->open()->where('scope_key', 'olt:'.$olt->id)->first();
        $fromEpisode = $ep === null ? 0 : (int) (((array) $ep->payload)['consecutive'] ?? 0);

        // The streak is also kept in the cache so the hold + accept rule works when olt_wide recording is off.
        try {
            $fromCache = (int) Cache::get('outage:olt-wide-streak:'.$olt->id, 0);
        } catch (\Throwable $e) {
            $fromCache = 0;
            $this->logCacheFailure('read', $olt, $e);
        }

        return max($fromEpisode, $fromCache);
    }

    private function oltWideStreak(Olt $olt, int $n, Carbon $now): void
    {
        if ($this->dryRun) {
            return; // dry-run must not touch the streak (it would skip / reset a real hold)
        }
        try {
            if ($n <= 0) {
                Cache::forget('outage:olt-wide-streak:'.$olt->id);
            } else {
                Cache::put('outage:olt-wide-streak:'.$olt->id, $n, $now->copy()->addMinutes(30));
            }
        } catch (\Throwable $e) {
            // Best effort, but not silent: with olt_wide mode off a broken cache means the hold never ends.
            $this->logCacheFailure('write', $olt, $e);
        }
    }

    private function logCacheFailure(string $op, Olt $olt, \Throwable $e): void
    {
        try {
            Log::warning('[Outage] olt_wide streak cache '.$op.' failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
        } catch (\Throwable) {
            // logging must never throw
        }
    }

    private function recordOltWide(Olt $olt, int $changed, int $eligible, Carbon $now, int $consecutive): void
    {
        if (OutageSettings::mode('olt_wide') === 'off') {
            return;
        }
        $scope = 'olt:'.$olt->id;
        $ep = OutageSignal::query()->open()->where('scope_key', $scope)->first();
        $payload = ['changed' => $changed, 'mapped_eligible' => $eligible, 'consecutive' => $consecutive];
        if ($ep === null) {
            $ep = $this->createEpisode('olt_wide', $scope, [
                'olt_id' => $olt->id,
                'opened_at' => $now,
                'last_seen_at' => $now,
                'observations' => 1,
                'affected_count' => $changed,
                'payload' => $payload,
            ]);
            $ep->payload = $payload;
            $ep->save();
            $this->summary['opened']++;
            if ($ep->confirmed_at === null) {
                $this->confirm($ep, 'olt_wide', 'shadow', 'log_only', $now, $olt, null);
            }

            return;
        }
        $ep->last_seen_at = $now;
        $ep->observations = (int) $ep->observations + 1;
        $ep->affected_count = $changed;
        $ep->payload = $payload;
        $ep->save();
    }

    private function resolveOltWide(Olt $olt, Carbon $now): void
    {
        $ep = OutageSignal::query()->open()->where('scope_key', 'olt:'.$olt->id)->first();
        if ($ep !== null) {
            $this->resolve($ep, $now);
        }
    }

    // ------------------------------------------------------------ episode helpers

    /**
     * Open a new episode for the scope, or re-open a recently closed one (flap) instead of creating
     * a second "would have ticketed".
     *
     * @param  array<string,mixed>  $attrs
     */
    private function createEpisode(string $type, string $scope, array $attrs): OutageSignal
    {
        $reference = ($attrs['last_seen_at'] ?? null) instanceof CarbonInterface ? Carbon::instance($attrs['last_seen_at']) : now();
        $recent = OutageSignal::query()
            ->where('scope_key', $scope)
            // expired too: a LOS that outlives a stale period must not "would ticket" a second time
            ->whereIn('state', [OutageSignal::STATE_RESOLVED, OutageSignal::STATE_EXPIRED])
            ->where('resolved_at', '>=', $reference->copy()->subMinutes(OutageSettings::flapWindowMinutes($type)))
            ->orderByDesc('id')
            ->first();

        if ($recent !== null) {
            $payload = (array) $recent->payload;
            unset($payload['online_since'], $payload['seen_online']);
            $recent->state = $recent->confirmed_at !== null ? OutageSignal::STATE_CONFIRMED : OutageSignal::STATE_OPEN;
            if ($recent->confirmed_at === null && ($attrs['opened_at'] ?? null) !== null) {
                $recent->opened_at = $attrs['opened_at']; // unconfirmed re-open: the clock restarts with the new outage
            }
            $recent->resolved_at = null;
            $recent->flap_count = (int) $recent->flap_count + 1;
            $recent->last_seen_at = $attrs['last_seen_at'] ?? now();
            $recent->observations = (int) $recent->observations + 1;
            $recent->payload = $payload;
            $recent->save();

            return $recent;
        }

        $created = OutageSignal::query()->create($attrs + [
            'signal_type' => $type,
            'scope_key' => $scope,
            'state' => OutageSignal::STATE_OPEN,
            'action_taken' => 'none',
        ]);
        $this->diagnoseEpisode($created);

        return $created;
    }

    // ------------------------------------------------------------------ RCA-4 hook (auto_episode diagnosis)

    /**
     * RCA-4: stores a diagnosis for a customer episode the detector just opened / confirmed. DB only, never in a
     * dry run, never throws; the answer goes to outage_signals.diagnosed_cause / diagnosis_id (not cause_code).
     */
    private function diagnoseEpisode(OutageSignal $ep): void
    {
        if ($this->dryRun || $ep->customer_id === null || ! in_array($ep->signal_type, ['single_los', 'dying_gasp', 'pppoe_down'], true)) {
            return;
        }
        app(OutageDiagnoser::class)->forEpisode($ep);
    }

    private function confirm(OutageSignal $ep, string $type, string $mode, string $wouldAction, Carbon $now, Olt $olt, ?string $family): void
    {
        $quiet = $type === 'single_los'
            && OutageSettings::isQuietHour((int) $now->copy()->timezone('Asia/Jakarta')->format('G'));
        $ep->state = OutageSignal::STATE_CONFIRMED;
        $ep->confirmed_at = $now;
        // PR-2 never acts: "live" is recorded as shadow until the auto-ticket PR lands.
        $ep->mode_at_confirm = $mode === 'live' ? 'shadow' : $mode;
        $ep->would_action = $wouldAction;
        $ep->action_taken = 'none';
        $ep->suppressed_reason = $quiet ? 'quiet_hours' : null;
        $ep->save();
        $this->diagnoseEpisode($ep);

        $this->summary['confirmed']++;
        $this->summary['new_confirmed'][] = [
            'id' => $ep->id,
            'type' => $type,
            'olt_id' => $olt->id,
            'olt_name' => (string) $olt->name,
            'pon_port' => $ep->pon_port,
            'affected_count' => (int) $ep->affected_count,
            'customer_id' => $ep->customer_id !== null ? (int) $ep->customer_id : null, // id only; names are looked up at send time
            'would_action' => $wouldAction,
            'cause_unknown' => $family === 'huawei' || (((array) $ep->payload)['cause'] ?? null) === 'down_unknown',
            'cause' => ((array) $ep->payload)['cause'] ?? null,
            'quiet_hours' => $quiet,
        ];
    }

    private function resolve(OutageSignal $ep, Carbon $now): void
    {
        $ep->state = OutageSignal::STATE_RESOLVED;
        $ep->resolved_at = $now;
        $ep->save(); // saving hook nulls open_key
        $this->summary['resolved']++;
    }

    /** Episodes not seen for 2 hours (stale data / vanished ONU) are closed without a conclusion. */
    private function expireStale(Carbon $now): void
    {
        $cut = $now->copy()->subMinutes(self::EXPIRE_AFTER_MINUTES);
        // Bulk update bypasses the model saving hook, so open_key is nulled explicitly here.
        $n = OutageSignal::query()->open()
            ->where(function ($q) use ($cut) {
                $q->where('last_seen_at', '<', $cut)
                    ->orWhere(fn ($q2) => $q2->whereNull('last_seen_at')->where('opened_at', '<', $cut));
            })
            ->update([
                'state' => OutageSignal::STATE_EXPIRED,
                'resolved_at' => $now,
                'open_key' => null,
                'updated_at' => $now,
            ]);
        $this->summary['expired'] += $n;
    }

    // ----------------------------------------------------------------- exclusions

    /**
     * Per-customer suppression reasons for the given LOS-like rows (set-based, a handful of queries).
     *
     * @param  list<array<string,mixed>>  $losRows
     * @return array<int,string> customer_id => reason
     */
    private function suppressionMap(Olt $olt, array $losRows, Carbon $now, bool $includeOpenTicket): array
    {
        if ($losRows === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(fn ($r) => $r['customer']->id, $losRows)));
        $reasons = [];
        $set = function (iterable $customerIds, string $reason) use (&$reasons): void {
            foreach ($customerIds as $id) {
                $reasons[(int) $id] ??= $reason;
            }
        };

        // RCA-1 age limits (read-only on psb_drafts / customer_migrations): a draft or migration that nobody
        // touched for days is leftover data (644 "olt_ok" drafts and 356 bulk-imported "received" migrations
        // blinded 68% of the network), so it only suppresses while it was updated recently.
        $migrationSince = $now->copy()->subDays(OutageSettings::migrationSuppressDays());
        $psbSince = $now->copy()->subDays(OutageSettings::psbSuppressDays());
        $set(CustomerMigration::query()->open()->whereIn('customer_id', $ids)->where('updated_at', '>=', $migrationSince)->pluck('customer_id'), 'migration');
        $set(PsbDraft::query()->whereIn('customer_id', $ids)->whereIn('status', self::PSB_OPEN_STATUSES)->where('updated_at', '>=', $psbSince)->pluck('customer_id'), 'psb');
        $set(DeviceRecoveryCase::query()->whereIn('customer_id', $ids)
            ->whereNotIn('status', DeviceRecoveryService::FINAL_STATUSES)->pluck('customer_id'), 'recovery');

        $written = OltOperationLog::query()
            ->where('olt_id', $olt->id)
            ->where('created_at', '>=', $now->copy()->subMinutes(60))
            ->pluck('onu_ref')
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->flip();
        $windows = $this->activeWindows($olt, $now);

        foreach ($losRows as $r) {
            $cid = $r['customer']->id;
            if ($r['ambiguous'] || self::serialConflict((string) $r['customer']->onu_serial, $r['serial'])) {
                $reasons[$cid] ??= 'mapping_conflict';
            }
            if ($written->has($r['ref'])) {
                $reasons[$cid] ??= 'olt_write';
            }
            foreach ($windows as $w) {
                if ($w['pon_port'] === null || $w['pon_port'] === $r['pon']) {
                    $reasons[$cid] ??= 'maintenance';
                }
            }
        }

        if ($includeOpenTicket) {
            $set(SupportTicket::query()->whereIn('customer_id', $ids)
                ->whereNotIn('status', self::TICKET_FINAL_STATUSES)->pluck('customer_id'), 'open_ticket');
        }

        return $reasons;
    }

    /** @return list<array{olt_id:int,pon_port:?string,from:string,to:string}> */
    private function activeWindows(Olt $olt, Carbon $now): array
    {
        $out = [];
        foreach (OutageSettings::maintenanceWindows() as $w) {
            if ($w['olt_id'] !== (int) $olt->id) {
                continue;
            }
            try {
                $from = Carbon::parse($w['from'], 'Asia/Jakarta');
                $to = Carbon::parse($w['to'], 'Asia/Jakarta');
            } catch (\Throwable) {
                continue;
            }
            if ($now->gte($from) && $now->lte($to)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------- helpers

    public static function normalizeRef(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^gpon-onu_/i', '', $value) ?? $value;

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $value) === 1 ? $value : null;
    }

    public static function ponOf(string $ref): ?string
    {
        return preg_match('/^(\d+\/\d+\/\d+):\d+$/', $ref, $m) === 1 ? $m[1] : null;
    }

    /**
     * Comparable ONU serial: letters/digits only, upper case, and a Huawei hex vendor prefix
     * ("48575443" + rest) turned into its ASCII form ("HWTC" + rest), as the Huawei collectors store it.
     * Too short values (< 8) are not used for joining.
     */
    public static function serialKey(string $value): ?string
    {
        $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        if (preg_match('/^([0-9A-F]{8})([0-9A-F]+)$/', $v, $m) === 1) {
            $vendor = hex2bin($m[1]);
            if ($vendor !== false && preg_match('/^[A-Z0-9]{2,8}$/', $vendor) === 1) {
                $v = $vendor.$m[2];
            }
        }

        return strlen($v) >= 8 ? $v : null;
    }

    private static function customerEligible(Customer $c): bool
    {
        return (string) $c->status === 'active'
            && ! str_contains(strtolower((string) $c->mikrotik_profile), 'isolir');
    }

    private static function serialConflict(string $customerSerial, string $onuSerial): bool
    {
        $a = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $customerSerial));
        $b = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $onuSerial));
        if (strlen($a) < 6 || strlen($b) < 6) {
            return false;
        }

        return ! (str_contains($a, $b) || str_contains($b, $a));
    }

    /** LOS-like phase for the vendor: ZTE explicit LOS; Huawei only knows offline (cause unknown). */
    private function isLosLike(string $family, string $phase): bool
    {
        return $family === 'huawei' ? $phase === 'offline' : $phase === 'los';
    }

    /**
     * LOS-like for individual episodes: isLosLike() plus, for ZTE, "offline without a cause" (status 7 OffLine /
     * stale phase) as down_unknown (RCA-1, shadow). mass_los keeps the strict isLosLike() on purpose so unknown
     * causes never feed LOS statistics or whole-PON decisions.
     */
    private function isDownLike(string $family, string $phase): bool
    {
        return $this->isLosLike($family, $phase)
            || ($family === 'zte' && $phase === 'offline' && OutageSettings::downUnknownEnabled());
    }

    /**
     * Explicit state only: a blank state/phase is "unknown", never offline (normalizeOperationalState
     * would turn null into "offline").
     */
    private function classify(string $family, OltOnu $onu, ?string $trackerPhase, Carbon $now): string
    {
        return self::classifyOnu($family, $onu, $trackerPhase, $now);
    }

    /**
     * The same classification, callable without a detector: the diagnosis correlator reads olt_onus (refreshed every
     * 2 minutes by the collectors) and needs the phase the detector would give, not the tracker stamp that only moves
     * when the phase changes.
     */
    public static function classifyOnu(string $family, OltOnu $onu, ?string $trackerPhase, Carbon $now): string
    {
        $state = trim((string) $onu->state);
        $phaseRaw = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) $onu->phase_state));
        if ($state === '' && $phaseRaw === '') {
            return 'unknown';
        }

        // The 5-minute ZTE tick writes `state` plus the SNMP phase code (phase_state @ phase_collected_at);
        // inventory (RA ~15 min, Krian hourly) also writes phase_state @ inventory_collected_at. So an
        // explicit online state always wins, and LOS / DyingGasp from phase_state is trusted only while the
        // ONU is offline AND the phase reading is fresh (newest of the two phase stamps).
        if ($family === 'zte' && $state !== '') {
            if (OltOnu::normalizeOperationalState($state, (string) $onu->phase_state) === 'online') {
                return 'online';
            }

            $phaseKind = match (true) {
                $phaseRaw === 'los' => 'los',
                in_array($phaseRaw, ['dyinggasp', 'dgi'], true) => 'dying_gasp',
                default => null,
            };
            $phaseAt = $onu->phase_collected_at;
            if ($phaseAt === null || ($onu->inventory_collected_at !== null && $onu->inventory_collected_at->gt($phaseAt))) {
                // No tick-written phase, or inventory wrote phase_state more recently.
                $phaseAt = $onu->inventory_collected_at ?? $phaseAt;
            }
            $phaseFresh = $phaseAt !== null && $phaseAt->gte($now->copy()->subMinutes(self::PHASE_MAX_AGE_MINUTES));
            if ($phaseFresh) {
                return $phaseKind ?? 'offline';
            }

            // Stale phase: no new cause. An ONU already tracked as LOS / DG that is still offline (no
            // online state seen) simply continues; anything else is "offline, cause unknown".
            return in_array($trackerPhase, ['los', 'dying_gasp'], true) ? (string) $trackerPhase : 'offline';
        }

        if ($state === '') {
            // Blank state: only an explicit phase may decide; anything else is unknown, never offline.
            $phase = strtoupper(trim((string) $onu->phase_state));
            if ($phase === 'O5') {
                return 'online';
            }
            if (in_array($phase, ['OFFLINE', 'O1', 'O2', 'O3', 'O4', 'O6', 'O7', 'SYNCMIB'], true)) {
                return 'offline';
            }

            return 'unknown';
        }

        return OltOnu::normalizeOperationalState($state, (string) $onu->phase_state) === 'online' ? 'online' : 'offline';
    }

    private function bump(string $bucket, string $key): void
    {
        $this->summary[$bucket][$key] = ($this->summary[$bucket][$key] ?? 0) + 1;
    }
}
