<?php

namespace App\Services;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Jobs\ReconcilePonAfterProvisionJob;
use App\Jobs\SyncOltOpticalJob;
use App\Models\MonitoringAlertEvent;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\Outage\OnuStateEvents;
use App\Support\OltSnmpCommunity;
use App\Support\OltVendor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class OltCollectorService
{
    private const LIVE_ROW_PURGE_GRACE_MINUTES = 15;

    public function __construct(
        protected OltZteC300Service $zteService,
        protected OltHiosoService $hiosoService,
        protected OltHuaweiMa5608tService $huaweiService,
        protected OltSnapshotBuilder $snapshotBuilder,
    ) {}

    public function runInteractiveRefresh(Olt $olt): array
    {
        $this->assertEnterpriseTables();
        // Inventory path already routes Hioso vs ZTE.
        $this->runInventorySync($olt);

        return $this->snapshotBuilder->materialize($olt);
    }

    /**
     * Background / UI snapshot refresh — vendor-aware entrypoint.
     * Hioso: inventory+optical. Huawei/ZTE click: SNMP optical, not SSH-per-ONU.
     *
     * @return array{snmp?: OltCollectRun, state?: OltCollectRun, names_updated?: int}|OltCollectRun
     */
    public function runSnapshotRefresh(Olt $olt): array|OltCollectRun
    {
        $this->assertEnterpriseTables();

        if ($this->isHiosoVendor($olt)) {
            return $this->runHiosoInventorySync($olt);
        }

        if ($this->isHuaweiVendor($olt)) {
            // Click path: SNMP optical (RX, distance, SN). Do not open 16 SSH sessions.
            return $this->runHuaweiOpticalSync($olt);
        }

        if ($this->isZteLike($olt)) {
            // Click path: fast SNMP read, not the status-only live tick and not per-ONU SSH.
            return $this->runInteractiveSnmpRead($olt);
        }

        return $this->runQuickRefresh($olt);
    }

    /**
     * Setelah aktivasi / migrasi FiberHome (atau ZTE activate): tulis baris ONU ke olt_onus
     * + refresh snapshot UI segera — tidak nunggu olt:inventory-sync jam-jaman.
     *
     * Contoh: port 1/2/1, onu_id 3 → onu_ref 1/2/1:3, nama dari form, SN terikat.
     * Migrasi: baris lain di OLT yang sama dengan SN ini dihapus (ghost port lama).
     *
     * mode=direct (default): nama+SN+RX ke DB + materialize 1× (cepat).
     *   Sibling PON reconcile di-queue (tidak nahan HTTP / port lock).
     * mode=full: legacy reconcile PON sync (lebih lambat).
     *
     * @return array{
     *   ok: bool,
     *   mode?: string,
     *   onu_ref?: string,
     *   onu_name?: ?string,
     *   rx_power_dbm?: ?float,
     *   state?: ?string,
     *   cleared_old?: int,
     *   snapshot_onus?: int,
     *   pon_reconcile_queued?: bool,
     *   pon_live_refs?: int,
     *   message?: string
     * }
     */
    public function upsertProvisionedOnu(
        Olt $olt,
        string $port,
        int $onuId,
        string $serial,
        string $name,
        ?string $state = null,
        ?float $rxPowerDbm = null,
        ?int $distanceM = null,
        string $mode = 'direct',
    ): array {
        if (! Schema::hasTable('olt_onus')) {
            return ['ok' => false, 'message' => 'tabel olt_onus tidak ada'];
        }

        $ponPort = $this->normalizePonPort($port);
        if ($ponPort === '' || $onuId < 1) {
            return ['ok' => false, 'message' => 'port/onu_id tidak valid'];
        }

        $mode = $mode === 'full' ? 'full' : 'direct';
        $onuRef = strtolower("{$ponPort}:{$onuId}");
        $serial = strtoupper(trim($serial));
        $normalizedSerial = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serial) ?? '');
        $name = trim($name);
        $now = now();

        $clearedOld = 0;
        if ($normalizedSerial !== '') {
            // Migrasi: SN pindah port → hapus ghost di ref lama
            $oldRows = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('onu_ref', '!=', $onuRef)
                ->whereRaw(
                    'UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?',
                    [$normalizedSerial]
                )
                ->get(['id', 'onu_ref']);

            foreach ($oldRows as $old) {
                $old->delete();
                $clearedOld++;
            }

            if ($clearedOld > 0) {
                Log::info('OLT provision cleared old SN rows', [
                    'olt_id' => $olt->id,
                    'serial' => $serial,
                    'new_ref' => $onuRef,
                    'cleared' => $clearedOld,
                ]);
            }
        }

        $stateNorm = OltOnu::normalizeOperationalState($state, null, $rxPowerDbm);
        $payload = [
            'pon_port' => $ponPort,
            'serial_number' => $serial !== '' ? $serial : null,
            'onu_name' => $name !== '' ? $name : null,
            'state' => $stateNorm,
            'serial_source' => $serial !== '' ? 'provision' : null,
            'name_source' => $name !== '' ? 'provision' : null,
            'state_collected_at' => $now,
            'inventory_collected_at' => $now,
        ];

        if ($rxPowerDbm !== null && is_finite($rxPowerDbm)) {
            $payload['rx_power_dbm'] = $rxPowerDbm;
            $payload['rx_source'] = 'provision';
            $payload['rx_collected_at'] = $now;
        }

        if ($distanceM !== null && $distanceM > 0) {
            $payload['distance_m'] = $distanceM;
        }

        OltOnu::query()->updateOrCreate(
            ['olt_id' => $olt->id, 'onu_ref' => $onuRef],
            $payload
        );

        $ponLive = 0;
        $ponReconcileQueued = false;

        if ($mode === 'full') {
            // Legacy: pull sibling ONUs on same PON in-request (slow; extra SSH).
            try {
                $rec = $this->reconcilePonInventory($olt, $ponPort);
                $ponLive = (int) ($rec['live_refs'] ?? 0);
                OltOnu::query()
                    ->where('olt_id', $olt->id)
                    ->where('onu_ref', $onuRef)
                    ->update(array_filter([
                        'serial_number' => $payload['serial_number'] ?? null,
                        'onu_name' => $payload['onu_name'] ?? null,
                        'serial_source' => $payload['serial_source'] ?? null,
                        'name_source' => $payload['name_source'] ?? null,
                        'state' => $payload['state'] ?? null,
                        'rx_power_dbm' => $payload['rx_power_dbm'] ?? null,
                        'rx_source' => $payload['rx_source'] ?? null,
                        'inventory_collected_at' => $now,
                    ], fn ($v) => $v !== null));
            } catch (\Throwable $e) {
                Log::warning('OLT provision PON reconcile skipped', [
                    'olt_id' => $olt->id,
                    'pon' => $ponPort,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Direct: queue sibling reconcile — do not block provision / port lock.
            if (! (bool) Setting::get('olt_deferred_pon_reconcile_enabled', false)) {
                Log::info('OLT provision deferred PON reconcile disabled', [
                    'olt_id' => $olt->id,
                    'pon' => $ponPort,
                ]);
            } else {
                try {
                    ReconcilePonAfterProvisionJob::dispatch([
                        'olt_id' => $olt->id,
                        'pon_port' => $ponPort,
                        'onu_ref' => $onuRef,
                    ]);
                    $ponReconcileQueued = true;
                } catch (\Throwable $e) {
                    Log::warning('OLT provision deferred PON reconcile dispatch failed', [
                        'olt_id' => $olt->id,
                        'pon' => $ponPort,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Materialize 1× from DB so OLT management shows name+RX immediately.
        // Direct path skips customer-wide sync (single customer already updated by controller).
        $snapshotOnus = 0;
        try {
            $snapshot = $this->snapshotBuilder->materialize($olt->fresh(), $mode === 'full');
            $snapshotOnus = count($snapshot['onus'] ?? []);
        } catch (\Throwable $e) {
            Log::warning('OLT provision materialize/customer-sync skipped', [
                'olt_id' => $olt->id,
                'onu_ref' => $onuRef,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('OLT provision upserted onu', [
            'olt_id' => $olt->id,
            'onu_ref' => $onuRef,
            'serial' => $serial,
            'name' => $name,
            'state' => $stateNorm,
            'rx' => $rxPowerDbm,
            'mode' => $mode,
            'cleared_old' => $clearedOld,
            'pon_live_refs' => $ponLive,
            'pon_reconcile_queued' => $ponReconcileQueued,
        ]);

        return [
            'ok' => true,
            'mode' => $mode,
            'onu_ref' => $onuRef,
            'onu_name' => $payload['onu_name'] ?? null,
            'rx_power_dbm' => isset($payload['rx_power_dbm']) ? (float) $payload['rx_power_dbm'] : null,
            'state' => $stateNorm,
            'cleared_old' => $clearedOld,
            'pon_live_refs' => $ponLive,
            'pon_reconcile_queued' => $ponReconcileQueued,
            'snapshot_onus' => $snapshotOnus,
        ];
    }

    /**
     * Fast reconcile for one PON: read live state from OLT, purge ghost rows in olt_onus.
     *
     * @return array{pon_port: string, live_refs: int, purged: int, snapshot_onus: int}
     */
    public function reconcilePonInventory(Olt $olt, string $ponPort): array
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        $ponPort = $this->normalizePonPort($ponPort);
        if ($ponPort === '') {
            throw new RuntimeException('Invalid PON port. Expected format 1/3/11.');
        }

        $payload = $this->zteService->collectPonLiveRefs($olt, $ponPort);
        $liveRefs = $payload['refs'] ?? [];
        $states = $payload['states'] ?? [];
        $now = now();
        $updated = 0;

        foreach ($liveRefs as $ref) {
            $existing = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('onu_ref', $ref)
                ->first();

            $state = $states[strtolower($ref)] ?? [];
            $rowPayload = [
                'pon_port' => $ponPort,
                'state' => OltOnu::normalizeOperationalState($state['state'] ?? null, $state['phase_state'] ?? null),
                'phase_state' => $state['phase_state'] ?? null,
                'state_collected_at' => $now,
                'inventory_collected_at' => $now,
            ];

            if ($existing) {
                $existing->fill($rowPayload);
                $existing->save();
                $updated++;
            } else {
                OltOnu::query()->create(array_merge([
                    'olt_id' => $olt->id,
                    'onu_ref' => $ref,
                ], $rowPayload));
                $updated++;
            }
        }

        $purged = $this->purgeStaleOnus($olt, $liveRefs, $ponPort);
        $snapshot = $this->snapshotBuilder->materialize($olt);

        return [
            'pon_port' => $ponPort,
            'live_refs' => count($liveRefs),
            'purged' => $purged,
            'updated' => $updated,
            'snapshot_onus' => count($snapshot['onus'] ?? []),
        ];
    }

    /**
     * Fast live refresh: SNMP RX+status first (target <60s), SSH only if needed.
     *
     * @return array{snmp: OltCollectRun, state: OltCollectRun, names_updated?: int}
     */
    public function runFastLiveSync(Olt $olt): array
    {
        return $this->runQuickRefresh($olt);
    }

    /**
     * Enterprise live tick (ref: Cepat-Kilat snmp-olt-zte pure SNMP path).
     * 1) snmpbulkwalk RX + status OIDs (~under 60s)
     * 2) Persist RX + operational state from SNMP
     * 3) SSH state only if SNMP status missing/stale
     * 4) Name sync throttled (not every 2 minutes)
     *
     * @return array{snmp: OltCollectRun, state: OltCollectRun, names_updated?: int}
     */
    public function runQuickRefresh(Olt $olt): array
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        // Purge only every 10 minutes on live path (full scan is multi-query).
        $purgeKey = 'olt:purge-rx:'.$olt->id;
        if (! Cache::has($purgeKey)) {
            Cache::put($purgeKey, 1, now()->addMinutes(10));
            $this->purgeInvalidSnmpRxRows($olt);
        }

        $onlineNoRx = $this->countOnlineOnusMissingRx($olt);

        $recentSnmp = OltCollectRun::query()
            ->where('olt_id', $olt->id)
            ->where('tier', OltCollectRun::TIER_SNMP)
            ->where('status', 'success')
            ->where('finished_at', '>=', now()->subMinutes(2))
            ->exists();

        if ($recentSnmp && $onlineNoRx === 0) {
            // Lightweight tick: state only when RX is fresh and complete.
            $state = $this->runStateSyncSnmpPreferred($olt);

            return [
                'snmp' => OltCollectRun::query()
                    ->where('olt_id', $olt->id)
                    ->where('tier', OltCollectRun::TIER_SNMP)
                    ->where('status', 'success')
                    ->orderByDesc('id')
                    ->first(),
                'state' => $state,
                'names_updated' => 0,
            ];
        }

        // LIVE: status walk + snmpget RX for online only (target <40s total).
        // Avoids 125s full-tree RX walk. Gap list for online-without-RX is included via mode=read.
        // LIVE = status only (<15s). RX is READ tier (olt:snmp-sync) — physics of this
        // OLT: RX tree ≈5 vars/s so full 611 needs ~120s; do NOT block live tick.
        $walkTimeout = (int) (config('services.snmp_collector.live_timeout') ?: 15);
        $walkTimeout = max(10, min(20, $walkTimeout));

        $snmpProcess = new Process(
            ['python3', base_path('app/Support/zte_c300_snmp_rxpower.py')],
            base_path(),
            [
                'OLT_HOST' => $olt->management_ip,
                'OLT_COMMUNITY' => $olt->snmp_community ?: OltSnmpCommunity::fallback(),
                'SNMP_TIMEOUT' => (string) $walkTimeout,
                'SNMP_BULK_REPS' => (string) (config('services.snmp_collector.bulk_reps') ?: 20),
                'SNMP_MODE' => 'status',
            ]
        );
        $snmpProcess->setTimeout($walkTimeout + 5);

        $parsed = null;
        try {
            $snmpProcess->run();
            if ($snmpProcess->isSuccessful()) {
                $parsed = json_decode($snmpProcess->getOutput(), true);
            }
        } catch (\Throwable $e) {
            Log::warning("Enterprise SNMP live failed for #{$olt->id}: {$e->getMessage()}");
        }

        $snmp = OltCollectRun::query()
            ->where('olt_id', $olt->id)
            ->where('tier', OltCollectRun::TIER_SNMP)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->first();

        $statusOnline = (int) data_get($parsed, 'stats.status_online', 0);
        $state = $this->runStateSyncSnmpPreferred($olt, $parsed['onus'] ?? null, $statusOnline);

        return [
            'snmp' => $snmp,
            'state' => $state,
            'names_updated' => 0,
        ];
    }

    /**
     * Prefer SNMP status already collected; fall back to SSH only if coverage poor.
     *
     * @param  array<string, mixed>|null  $snmpOnus
     */
    protected function runStateSyncSnmpPreferred(Olt $olt, ?array $snmpOnus = null, int $statusOnline = 0): OltCollectRun
    {
        $statusRows = 0;
        if (is_array($snmpOnus)) {
            foreach ($snmpOnus as $info) {
                if (is_array($info) && ! empty($info['state'])) {
                    $statusRows++;
                }
            }
        }

        // Enough SNMP status coverage → skip SSH (saves ~2m OLT CPU).
        // Coverage is relative to the OLT's known ONU count: the old absolute
        // ">= 50 rows or >= 20 online" made small OLTs (Krian OLT 9, 8 ONUs)
        // skip the live state forever (audit 2026-10-08: 1869 skipped / 0 ok).
        if ($statusRows > 0 && ($statusRows >= $this->minimumLiveStateRows($olt) || $statusOnline >= 20)) {
            return $this->persistSnmpStates($olt, $snmpOnus ?? []);
        }

        // Re-use any recent successful state — never start SSH from the live path.
        $recentState = OltCollectRun::query()
            ->where('olt_id', $olt->id)
            ->where('tier', OltCollectRun::TIER_STATE)
            ->where('status', 'success')
            ->where('finished_at', '>=', now()->subMinutes(15))
            ->orderByDesc('id')
            ->first();

        if ($recentState) {
            return $recentState;
        }

        // Placeholder run so callers always get a state object without SSH.
        $run = OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_STATE,
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => [
                'source' => 'skipped_live',
                'reason' => 'No SNMP status and no recent state; SSH deferred to inventory tier',
            ],
        ]);

        return $run;
    }

    /**
     * SNMP status rows needed before the live tick may persist ONU state:
     * min(50, ceil(80% of the ONUs already known for this OLT)), at least 1.
     * An OLT with no known ONUs keeps the conservative absolute 50.
     */
    public function minimumLiveStateRows(Olt $olt): int
    {
        $known = OltOnu::query()->where('olt_id', $olt->id)->count();
        if ($known <= 0) {
            return 50;
        }

        return max(1, min(50, (int) ceil($known * 0.8)));
    }

    /**
     * Apply SNMP status codes to olt_onus (no SSH).
     *
     * @param  array<string, mixed>  $snmpOnus
     */
    protected function persistSnmpStates(Olt $olt, array $snmpOnus): OltCollectRun
    {
        $run = OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_STATE,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $started = microtime(true);
            $now = now();
            $onlineRefs = [];
            $offlineRefs = [];
            /** @var array<string, array<string, list<string>>> $phaseRefs tick state (online|offline) => phase_state label => refs */
            $phaseRefs = [];

            foreach ($snmpOnus as $ref => $info) {
                $normalizedRef = $this->normalizeRef((string) $ref);
                if ($normalizedRef === '' || ! is_array($info)) {
                    continue;
                }

                $rawState = $info['state'] ?? null;
                if (! is_string($rawState) || $rawState === '') {
                    continue;
                }

                $state = OltOnu::normalizeOperationalState($rawState, null);
                if ($state === 'online') {
                    $onlineRefs[] = $normalizedRef;
                } else {
                    $offlineRefs[] = $normalizedRef;
                }

                // Same status walk already carries the numeric ZTE phase code; map it to the phase_state
                // vocabulary inventory writes (no extra OID / session).
                $code = isset($info['status_code']) && is_numeric($info['status_code']) ? (int) $info['status_code'] : null;
                $phase = OltZteC300Service::snmpInventoryPhaseState($code);
                if ($phase !== null) {
                    $phaseRefs[$state === 'online' ? 'online' : 'offline'][$phase][] = $normalizedRef;
                }
            }

            // RCA-2: record phase TRANSITIONS (history only; never throws, no device I/O) before the rows change.
            $tickNew = [];
            foreach ($snmpOnus as $ref => $info) {
                $r = $this->normalizeRef((string) $ref);
                $s = is_array($info) ? ($info['state'] ?? null) : null;
                if ($r === '' || ! is_string($s) || $s === '') {
                    continue;
                }
                $st = OltOnu::normalizeOperationalState($s, null) === 'online' ? 'online' : 'offline';
                $code = isset($info['status_code']) && is_numeric($info['status_code']) ? (int) $info['status_code'] : null;
                $tickNew[$r] = ['state' => $st, 'phase' => OltZteC300Service::snmpInventoryPhaseState($code)];
            }
            OnuStateEvents::recordTransitions($olt, $tickNew, 'olt_poll', $now);

            // Critical: create rows for ONUs that exist on OLT but not yet in eBilling
            // (PSB FiberHome beruntun / CLI register — tanpa ini UI stuck di 6/8 sampai inventory jam-jaman).
            $createdMissing = $this->ensureMissingOnuRefs(
                $olt,
                array_values(array_unique(array_merge($onlineRefs, $offlineRefs))),
                $now
            );

            // 2 bulk updates instead of 600 single-row writes (live SLA).
            $updated = 0;
            if ($onlineRefs !== []) {
                foreach (array_chunk($onlineRefs, 200) as $chunk) {
                    $updated += OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->whereIn('onu_ref', $chunk)
                        ->where(function ($q) {
                            $q->where('state', '!=', 'online')->orWhereNull('state');
                        })
                        ->update([
                            'state' => 'online',
                            'state_collected_at' => $now,
                        ]);
                }
                // Always stamp collected_at for online set (even if already online).
                foreach (array_chunk($onlineRefs, 200) as $chunk) {
                    OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->whereIn('onu_ref', $chunk)
                        ->update(['state_collected_at' => $now]);
                }
            }
            if ($offlineRefs !== []) {
                foreach (array_chunk($offlineRefs, 200) as $chunk) {
                    $updated += OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->whereIn('onu_ref', $chunk)
                        ->where(function ($q) {
                            $q->where('state', '!=', 'offline')->orWhereNull('state');
                        })
                        ->update([
                            'state' => 'offline',
                            'state_collected_at' => $now,
                            'rx_power_dbm' => null,
                            'rx_source' => null,
                            'rx_collected_at' => null,
                        ]);
                }
                // Stamp already-offline ONUs too: a fresh "offline" is evidence,
                // an unstamped one looked stale forever (read model => unknown).
                foreach (array_chunk($offlineRefs, 200) as $chunk) {
                    OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->whereIn('onu_ref', $chunk)
                        ->update(['state_collected_at' => $now]);
                }
            }

            // Phase code (LOS / DyingGasp / ...) from the same tick, stamped separately from
            // inventory_collected_at so the outage detector sees a fresh cause every 5 min.
            if ($phaseRefs !== [] && Schema::hasColumn('olt_onus', 'phase_collected_at')) {
                // The phase UPDATE is guarded by the state this tick wrote: if an inventory per-row write
                // flipped the row's state in between, the phase is skipped instead of contradicting it
                // (#513 LOW-1; the next tick re-writes it).
                foreach ($phaseRefs as $tickState => $byPhase) {
                    foreach ($byPhase as $phase => $refs) {
                        foreach (array_chunk($refs, 200) as $chunk) {
                            OltOnu::query()
                                ->where('olt_id', $olt->id)
                                ->whereIn('onu_ref', $chunk)
                                ->where('state', $tickState)
                                ->update([
                                    'phase_state' => (string) $phase,
                                    'phase_collected_at' => $now,
                                ]);
                        }
                    }
                }
            }

            if ($createdMissing > 0) {
                $this->maybeRematerializeAfterCreate($olt);
            }

            $run->update([
                'status' => 'success',
                'onus_total' => count($onlineRefs) + count($offlineRefs),
                'onus_updated' => $updated,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [
                    'source' => 'snmp_status',
                    'online' => count($onlineRefs),
                    'offline' => count($offlineRefs),
                    'created_missing' => $createdMissing,
                ],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * Insert stub olt_onus rows for ONU refs seen on OLT (SNMP/SSH) but missing in eBilling.
     * Names/serial diisi nanti lewat inventory, provision, atau detail backfill.
     *
     * @param  list<string>  $refs
     */
    public function ensureMissingOnuRefs(Olt $olt, array $refs, $now = null): int
    {
        if ($refs === [] || ! Schema::hasTable('olt_onus')) {
            return 0;
        }

        $now ??= now();
        $normalized = [];
        foreach ($refs as $ref) {
            $r = $this->normalizeRef((string) $ref);
            if ($r !== '' && preg_match('/^\d+\/\d+\/\d+:\d+$/', $r)) {
                $normalized[strtolower($r)] = $r;
            }
        }
        if ($normalized === []) {
            return 0;
        }

        $existing = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereIn('onu_ref', array_values($normalized))
            ->pluck('onu_ref')
            ->map(fn ($r) => strtolower((string) $r))
            ->flip();

        $created = 0;
        foreach ($normalized as $lower => $ref) {
            if ($existing->has($lower)) {
                continue;
            }

            OltOnu::query()->create([
                'olt_id' => $olt->id,
                'onu_ref' => $ref,
                'pon_port' => $this->extractPort($ref),
                'state' => 'online',
                'state_collected_at' => $now,
                'inventory_collected_at' => $now,
            ]);
            $created++;
        }

        if ($created > 0) {
            Log::info('OLT ensured missing ONU refs from live status', [
                'olt_id' => $olt->id,
                'created' => $created,
            ]);
        }

        return $created;
    }

    protected function maybeRematerializeAfterCreate(Olt $olt): void
    {
        $key = 'olt:materialize-after-create:'.$olt->id;
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, 1, now()->addMinutes(2));
        try {
            $this->snapshotBuilder->materialize($olt->fresh());
        } catch (\Throwable $e) {
            Log::debug('OLT rematerialize after create skipped', [
                'olt_id' => $olt->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncOnuNamesThrottled(Olt $olt): int
    {
        $cacheKey = 'olt:name-sync:'.$olt->id;
        if (Cache::has($cacheKey)) {
            return 0;
        }

        // Names change rarely — at most once every 15 minutes on the live path.
        Cache::put($cacheKey, 1, now()->addMinutes(15));

        return $this->syncOnuNamesSafe($olt);
    }

    protected function countOnlineOnusMissingRx(Olt $olt): int
    {
        return OltOnu::query()
            ->where('olt_id', $olt->id)
            ->where('state', 'online')
            ->whereNull('rx_power_dbm')
            ->count();
    }

    /**
     * System self-awareness: which online ONUs still have RX N/A (no user prompt needed).
     *
     * @return array{count: int, refs: list<string>, cached_at: string}
     */
    public function publishOnlineMissingRxReport(Olt $olt, int $maxRefs = 50): array
    {
        $refs = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->where('state', 'online')
            ->whereNull('rx_power_dbm')
            ->orderBy('onu_ref')
            ->limit(max(1, $maxRefs))
            ->pluck('onu_ref')
            ->map(fn ($r) => (string) $r)
            ->values()
            ->all();

        $count = $this->countOnlineOnusMissingRx($olt);
        $report = [
            'olt_id' => $olt->id,
            'olt_name' => $olt->name,
            'count' => $count,
            'refs' => $refs,
            'cached_at' => now()->toISOString(),
        ];
        Cache::put('olt.rx.online_na.'.$olt->id, $report, now()->addHours(6));
        Cache::put('olt.rx.online_na.summary', array_merge(
            Cache::get('olt.rx.online_na.summary', []),
            [(string) $olt->id => ['count' => $count, 'at' => $report['cached_at']]]
        ), now()->addHours(6));

        return $report;
    }

    /**
     * Auto residual: if system sees online+RX null, fill via CLI (ZTE) without manual ONU list.
     * No-op when gap is 0. Bounded so host SNMP path is not blocked forever.
     */
    public function autoFillOnlineMissingRx(Olt $olt, int $limit = 80): ?OltCollectRun
    {
        $report = $this->publishOnlineMissingRxReport($olt);
        if (($report['count'] ?? 0) <= 0) {
            return null;
        }

        if ($this->isHiosoVendor($olt)) {
            // HiOSO: re-collect inventory+optical (web) — still system-driven.
            return $this->runHiosoInventorySync($olt);
        }

        if ($this->isHuaweiVendor($olt)) {
            return $this->runHuaweiOpticalSync($olt);
        }

        if (! $this->isZteLike($olt)) {
            return null;
        }

        $run = $this->runCliRxBackfill($olt, null, $limit);
        $this->publishOnlineMissingRxReport($olt);

        return $run;
    }

    protected function isZteLike(Olt $olt): bool
    {
        $vendor = strtolower((string) ($olt->vendor ?? ''));

        return $vendor === '' || str_contains($vendor, 'zte');
    }

    protected function syncOnuNamesSafe(Olt $olt): int
    {
        try {
            return $this->zteService->syncOnuNamesFromRunningConfig($olt);
        } catch (\Throwable $e) {
            Log::warning("OLT name sync failed for #{$olt->id}: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * SNMP per-index gap fill for online ONUs missing RX after bulk walk.
     *
     * @return array{queued: int, updated: int}
     */
    public function runSnmpGapFill(Olt $olt, int $limit = 150, int $maxPasses = 5): array
    {
        $totalQueued = 0;
        $totalUpdated = 0;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $indices = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('state', 'online')
                ->whereNull('rx_power_dbm')
                ->whereNotNull('snmp_index')
                ->limit($limit)
                ->pluck('snmp_index')
                ->all();

            if ($indices === []) {
                break;
            }

            $totalQueued += count($indices);
            $payload = $this->zteService->collectSnmpGapPayload($olt, $indices);
            $snmpOnus = $payload['meta']['onus'] ?? [];
            $rxPowers = $payload['rx'];

            if (empty($snmpOnus) && empty($rxPowers)) {
                break;
            }

            $persisted = $this->persistSnmpEntries($olt, $snmpOnus, $rxPowers);
            $totalUpdated += $persisted['updated'];

            if ($persisted['updated'] === 0 || count($indices) < $limit) {
                break;
            }
        }

        return [
            'queued' => $totalQueued,
            'updated' => $totalUpdated,
        ];
    }

    public function runStateSync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        return $this->executeTier($olt, OltCollectRun::TIER_STATE, 'olt-collect:'.$olt->id.':state', 180, function () use ($olt) {
            $started = microtime(true);
            $live = $this->zteService->collectOltLiveStates($olt);
            $states = $live['states'] ?? [];
            $now = now();
            $updated = 0;
            $online = 0;
            $offline = 0;

            foreach ($states as $ref => $stateInfo) {
                $normalizedRef = $this->normalizeRef((string) $ref);
                if ($normalizedRef === '') {
                    continue;
                }

                $state = OltOnu::normalizeOperationalState(
                    is_array($stateInfo) ? ($stateInfo['state'] ?? null) : null,
                    is_array($stateInfo) ? ($stateInfo['phase_state'] ?? null) : null,
                );

                if ($state === 'online') {
                    $online++;
                } else {
                    $offline++;
                }

                $changed = OltOnu::query()
                    ->where('olt_id', $olt->id)
                    ->where('onu_ref', $normalizedRef)
                    ->update([
                        'state' => $state,
                        'phase_state' => is_array($stateInfo) ? ($stateInfo['phase_state'] ?? null) : null,
                        'state_collected_at' => $now,
                    ]);

                $updated += $changed;
            }

            $clearedRx = $this->clearRxForOfflineOnus($olt);
            $snapshot = $this->snapshotBuilder->materialize($olt, syncCustomers: false);

            return [
                'onus_total' => count($states),
                'onus_updated' => $updated,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [
                    'source' => 'ssh_state',
                    'live_refs' => count($live['refs'] ?? []),
                    'online' => $online,
                    'offline' => $offline,
                    'rx_cleared_offline' => $clearedRx,
                    'snapshot_onus' => count($snapshot['onus'] ?? []),
                ],
            ];
        });
    }

    public function runSnmpSync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        $this->purgeInvalidSnmpRxRows($olt);

        return $this->executeTier($olt, OltCollectRun::TIER_SNMP, 'olt-collect:'.$olt->id.':snmp', 420, function () use ($olt) {
            $started = microtime(true);
            $payload = $this->zteService->collectSnmpRxPayload($olt);

            return $this->buildSnmpSyncResult($olt, $payload, $started);
        });
    }

    /**
     * @param  array{rx: array<string, float>, meta: array<string, mixed>}  $payload
     */
    protected function buildSnmpSyncResult(Olt $olt, array $payload, float $started): array
    {
        $rxPowers = $payload['rx'];
        $snmpOnus = $payload['meta']['onus'] ?? [];
        $snmpStats = $payload['meta']['stats'] ?? [];

        if (empty($rxPowers) && empty($snmpOnus)) {
            $snapshot = $this->snapshotBuilder->materialize($olt, syncCustomers: false);

            return [
                'onus_total' => count($snapshot['onus'] ?? []),
                'onus_updated' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [
                    'source' => 'snmp',
                    'status' => 'skipped',
                    'reason' => 'SNMP RX collection returned no data (check socat/snmpwalk/tunnel)',
                    'snapshot_onus' => count($snapshot['onus'] ?? []),
                ],
            ];
        }

        $persisted = $this->persistSnmpEntries($olt, $snmpOnus, $rxPowers);
        // Skip heavy materialize on pure snmp persist — live/read callers materialize once.

        return [
            'onus_total' => (int) ($snmpStats['mapped'] ?? count($persisted['entries'])),
            'onus_updated' => $persisted['updated'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'meta' => [
                'source' => 'snmp',
                'snmp_entries' => $snmpStats['snmp_entries'] ?? null,
                'snmp_mapped' => $snmpStats['mapped'] ?? null,
                'snmp_matched_db' => $persisted['matched_db'],
                'snmp_with_rx' => $snmpStats['with_rx'] ?? count($rxPowers),
                'snmp_mapped_null_rx' => $snmpStats['mapped_null_rx'] ?? null,
                'snmp_index_linked' => $persisted['index_linked'],
                'oids_used' => $snmpStats['oids_used'] ?? null,
                'online_no_rx_sentinel' => $snmpStats['online_no_rx_sentinel'] ?? null,
                'reject_counts' => $snmpStats['reject_counts'] ?? null,
                'tool_rx' => $snmpStats['tool_rx'] ?? null,
                'wall_sec' => $snmpStats['wall_sec'] ?? null,
            ],
        ];
    }

    /**
     * @param  array{rx: array<string, float>, meta: array<string, mixed>}  $payload
     */
    protected function persistSnmpRxPayload(Olt $olt, array $payload): OltCollectRun
    {
        $run = OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_SNMP,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $started = microtime(true);
            $result = $this->buildSnmpSyncResult($olt, $payload, $started);
            $run->update([
                'status' => ($result['meta']['status'] ?? null) === 'skipped' ? 'skipped' : 'success',
                'onus_total' => $result['onus_total'] ?? 0,
                'onus_updated' => $result['onus_updated'] ?? 0,
                'duration_ms' => $result['duration_ms'] ?? null,
                'meta' => $result['meta'] ?? null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * @param  array<string, mixed>  $snmpOnus
     * @param  array<string, float|null>  $rxPowers
     * @return array{updated: int, matched_db: int, index_linked: int, entries: array}
     */
    protected function persistSnmpEntries(Olt $olt, array $snmpOnus, array $rxPowers): array
    {
        $now = now();
        $updated = 0;
        $indexLinked = 0;
        $matchedDb = 0;

        $hasSnmpIndex = Schema::hasColumn('olt_onus', 'snmp_index');
        $entries = ! empty($snmpOnus) ? $snmpOnus : array_map(
            fn ($rx) => ['rx_dbm' => $rx, 'snmp_index' => null],
            $rxPowers
        );

        // One query for all ONUs — avoid N+1 first() on every live tick.
        $existingByRef = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->get(['id', 'onu_ref', 'state', 'phase_state', 'distance_m', 'rx_power_dbm', 'rx_source', 'snmp_index', 'serial_number'])
            ->keyBy(fn (OltOnu $row) => strtolower((string) $row->onu_ref));

        // Create missing refs first (same gap as 1/3/14:7-8) so RX/state can attach.
        $entryRefs = [];
        foreach (array_keys($entries) as $refKey) {
            $nr = $this->normalizeRef((string) $refKey);
            if ($nr !== '') {
                $entryRefs[] = $nr;
            }
        }
        $createdMissing = $this->ensureMissingOnuRefs($olt, $entryRefs, $now);
        if ($createdMissing > 0) {
            $existingByRef = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->get(['id', 'onu_ref', 'state', 'phase_state', 'distance_m', 'rx_power_dbm', 'rx_source', 'snmp_index', 'serial_number'])
                ->keyBy(fn (OltOnu $row) => strtolower((string) $row->onu_ref));
            $this->maybeRematerializeAfterCreate($olt);
        }

        foreach ($entries as $ref => $info) {
            $normalizedRef = $this->normalizeRef((string) $ref);
            if ($normalizedRef === '') {
                continue;
            }

            $existing = $existingByRef[strtolower($normalizedRef)] ?? null;
            if (! $existing) {
                continue;
            }

            $matchedDb++;

            $rx = OltZteC300Service::sanitizeSnmpRxDbm(
                is_array($info) ? ($info['rx_dbm'] ?? null) : $info,
                $existing->distance_m !== null ? (int) $existing->distance_m : null
            );
            $snmpIndex = is_array($info) ? ($info['snmp_index'] ?? null) : null;
            $snmpState = is_array($info) ? ($info['state'] ?? null) : null;

            $rowPayload = [];
            if ($hasSnmpIndex && $snmpIndex) {
                $rowPayload['snmp_index'] = $snmpIndex;
                $indexLinked++;
            }

            if (is_string($snmpState) && $snmpState !== '') {
                $rowPayload['state'] = OltOnu::normalizeOperationalState($snmpState, null);
                $rowPayload['state_collected_at'] = $now;
            }

            $effectiveState = $rowPayload['state'] ?? $existing->state;
            $isOnline = OltOnu::normalizeOperationalState($effectiveState, $existing->phase_state) === 'online';

            // Partial walks OK under 60s — never blank prior good snmp RX on miss.
            if ($rx !== null && $isOnline) {
                $rowPayload['rx_power_dbm'] = $rx;
                $rowPayload['rx_source'] = 'snmp';
                $rowPayload['rx_collected_at'] = $now;
            }

            $serial = OltZteC300Service::normalizeSnmpSerial(is_array($info) ? ($info['serial'] ?? null) : null);
            if ($serial !== null && strtoupper((string) $existing->serial_number) !== $serial) {
                $rowPayload['serial_number'] = $serial;
                if (Schema::hasColumn('olt_onus', 'serial_source')) {
                    $rowPayload['serial_source'] = 'snmp';
                }
            }

            $distance = OltZteC300Service::normalizeSnmpDistance(is_array($info) ? ($info['distance_m'] ?? null) : null);
            if ($distance !== null && (int) $existing->distance_m !== $distance) {
                $rowPayload['distance_m'] = $distance;
            }

            if ($rowPayload === []) {
                continue;
            }

            // Skip no-op updates (status unchanged + no new RX) to keep live tick under 60s.
            $noop = true;
            foreach ($rowPayload as $key => $value) {
                if ($key === 'state_collected_at' || $key === 'rx_collected_at') {
                    continue;
                }
                if ((string) ($existing->{$key} ?? '') !== (string) $value) {
                    $noop = false;
                    break;
                }
            }
            if ($noop && ! isset($rowPayload['rx_power_dbm'])) {
                continue;
            }

            $changed = OltOnu::query()
                ->where('id', $existing->id)
                ->update($rowPayload);

            if ($changed) {
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'matched_db' => $matchedDb,
            'index_linked' => $indexLinked,
            'entries' => $entries,
        ];
    }

    protected function purgeInvalidSnmpRxRows(Olt $olt): int
    {
        // Keep CLI/ssh_attenuation fills — they are ground truth when SNMP raw is 0/sentinel.
        // Only drop values outside physical window (aligned with sanitizeSnmpRxDbm −8…−42).
        $purged = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereNotNull('rx_power_dbm')
            ->where(function ($query) {
                $query->where('rx_power_dbm', '>', -8)
                    ->orWhere('rx_power_dbm', '<', -42)
                    ->orWhere('rx_power_dbm', 0);
            })
            ->update([
                'rx_power_dbm' => null,
                'rx_source' => null,
                'rx_collected_at' => null,
            ]);

        $purged += OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereNotNull('rx_power_dbm')
            ->whereIn('state', ['offline', 'los', 'dyinggasp'])
            ->update([
                'rx_power_dbm' => null,
                'rx_source' => null,
                'rx_collected_at' => null,
            ]);

        // No distance-based purge — false "SNMP kosong" on long PON (see sanitizeSnmpRxDbm).
        // Do NOT wipe rx_source=ssh_attenuation / cli_attenuation — residual SNMP raw 0 needs CLI.
        return $purged;
    }

    protected function clearRxForOfflineOnus(Olt $olt): int
    {
        return OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereNotNull('rx_power_dbm')
            ->where(function ($query) {
                $query->where('state', 'offline')
                    ->orWhere('state', 'los')
                    ->orWhere('phase_state', 'LOS')
                    ->orWhere('phase_state', 'DYINGGASP');
            })
            ->update([
                'rx_power_dbm' => null,
                'rx_source' => null,
                'rx_collected_at' => null,
            ]);
    }

    /**
     * SSH attenuation backfill for online ONUs where SNMP returned no valid RX.
     */
    public function runCliRxBackfill(Olt $olt, ?string $ponPort = null, int $limit = 0): OltCollectRun
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        return $this->executeTier($olt, OltCollectRun::TIER_FHTT_BACKFILL, 'olt-collect:'.$olt->id.':cli-rx', 3600, function () use ($olt, $ponPort, $limit) {
            $started = microtime(true);

            $query = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('state', 'online')
                ->whereNull('rx_power_dbm');

            if ($ponPort !== null && $ponPort !== '') {
                $pon = $this->normalizePonPort($ponPort);
                if ($pon !== '') {
                    $query->where(function ($builder) use ($pon) {
                        $builder->where('pon_port', $pon)
                            ->orWhere('onu_ref', 'like', $pon.':%');
                    });
                }
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            $refs = $query->pluck('onu_ref')->all();

            if ($refs === []) {
                return [
                    'onus_total' => 0,
                    'onus_updated' => 0,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'meta' => ['message' => 'No online ONUs missing RX'],
                ];
            }

            $now = now();
            $updated = 0;
            $collected = 0;

            foreach (array_chunk($refs, 40) as $chunk) {
                $payload = $this->zteService->collectRxPowerViaCli($olt, $chunk);
                $onus = $payload['onus'] ?? [];

                if (! empty($payload['error'])) {
                    Log::warning("CLI RX chunk failed for OLT #{$olt->id}: {$payload['error']}");
                }

                foreach ($onus as $ref => $info) {
                    $rx = OltZteC300Service::sanitizeSnmpRxDbm(is_array($info) ? ($info['rx_dbm'] ?? null) : null);
                    if ($rx === null) {
                        continue;
                    }

                    $collected++;
                    $normalizedRef = $this->normalizeRef((string) $ref);
                    $updated += OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->where('onu_ref', $normalizedRef)
                        ->where('state', 'online')
                        ->update([
                            'rx_power_dbm' => $rx,
                            'rx_source' => 'ssh_attenuation',
                            'rx_collected_at' => $now,
                        ]);
                }
            }

            $snapshot = $this->snapshotBuilder->materialize($olt, syncCustomers: true);

            return [
                'onus_total' => count($refs),
                'onus_updated' => $updated,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [
                    'source' => 'ssh_attenuation',
                    'queued_refs' => count($refs),
                    'collected_refs' => $collected,
                    'customers_updated' => $snapshot['meta']['updated_customers_count'] ?? 0,
                    'online_with_rx' => collect($snapshot['onus'] ?? [])
                        ->filter(fn (array $onu) => ($onu['state'] ?? '') === 'online' && ($onu['rx_power_dbm'] ?? null) !== null)
                        ->count(),
                ],
            ];
        });
    }

    /**
     * @param  string|null  $source  ZTE only: auto (SNMP first for
     *                               services.olt_inventory_sync.snmp_olt_ids), snmp
     *                               (SNMP first) or ssh (SSH only, the daily cross-check).
     */
    public function runInventorySync(Olt $olt, ?int $budgetSeconds = null, ?string $source = null): OltCollectRun
    {
        $this->assertEnterpriseTables();

        if ($this->isHiosoVendor($olt)) {
            return $this->runHiosoInventorySync($olt);
        }

        if ($this->isHuaweiVendor($olt)) {
            return $this->runHuaweiInventorySync($olt);
        }

        $this->assertZteC300($olt);

        $budget = self::inventoryBudgetSeconds($budgetSeconds);
        // The lock outlives the budget only briefly: a worker killed mid-run
        // can no longer block the next scheduled run for an hour.
        $lockSeconds = $budget + 60;

        return $this->executeTier($olt, OltCollectRun::TIER_INVENTORY, 'olt-collect:'.$olt->id.':inventory', $lockSeconds, function () use ($olt, $budget, $source) {
            $started = microtime(true);
            try {
                $inventory = $this->zteService
                    ->withInventorySource($source)
                    ->collectInventorySnapshot($olt, $started + $budget);
            } finally {
                $this->zteService->withInventorySource(null);
            }
            $onus = $inventory['onus'] ?? [];
            $inventoryMeta = $inventory['meta'] ?? [];

            if (empty($onus)) {
                throw new RuntimeException('Inventory collection returned no ONUs'
                    .(isset($inventoryMeta['warning']) ? ': '.$inventoryMeta['warning'] : ''));
            }

            $viaSnmp = ($inventoryMeta['source'] ?? null) === 'snmp';
            $result = $this->persistInventoryOnus($olt, $onus, $started, $viaSnmp ? 'snmp_inventory' : 'ssh_inventory');
            $result['meta']['budget_seconds'] = $budget;
            $result['meta']['inventory_source'] = $viaSnmp ? 'snmp' : 'ssh';
            $result['meta']['requested_source'] = $source ?? 'auto';
            foreach (['collector', 'snmp_coverage', 'snmp_existing_rows', 'snmp_wall_ms', 'snmp_rejected_reason', 'snmp_onus', 'snmp_fallback'] as $key) {
                if (array_key_exists($key, $inventoryMeta)) {
                    $result['meta'][$key] = $inventoryMeta[$key];
                }
            }

            if (! empty($inventoryMeta['partial'])) {
                $result['meta']['status'] = 'partial';
                $result['meta']['detail_collected'] = $inventoryMeta['detail_collected'] ?? null;
                $result['meta']['detail_total'] = $inventoryMeta['detail_total'] ?? null;
                $reason = $inventoryMeta['partial_reason'] ?? sprintf(
                    '%ds budget reached after %d/%d ONU details',
                    $budget,
                    (int) ($inventoryMeta['detail_collected'] ?? 0),
                    (int) ($inventoryMeta['detail_total'] ?? count($onus)),
                );
                $result['meta']['partial_reason'] = $reason;
                $result['error'] = sprintf('Partial inventory: %s; state for all %d ONUs persisted', $reason, count($onus));
            }
            if (isset($inventoryMeta['inventory_mode'])) {
                $result['meta']['inventory_mode'] = $inventoryMeta['inventory_mode'];
                $result['meta']['names_found'] = $inventoryMeta['names_found'] ?? null;
            }

            return $result;
        });
    }

    /** Collector budget (seconds) for one ZTE inventory run. */
    public static function inventoryBudgetSeconds(?int $requested = null): int
    {
        $budget = $requested ?? (int) config('services.olt_inventory_sync.timeout_seconds', 130);

        return max(30, min(3000, $budget > 0 ? $budget : 130));
    }

    /**
     * HiOSO/HTB inventory + optical in one collect (web/snmp/cli via OltHiosoService).
     * Refreshes last_gpon_synced_at so Monitoring "OLT Fresh" counts these OLTs.
     */
    public function runHiosoInventorySync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();

        return $this->executeTier($olt, OltCollectRun::TIER_INVENTORY, 'olt-collect:'.$olt->id.':hioso', 900, function () use ($olt) {
            $started = microtime(true);
            $inventory = $this->hiosoService->collectSnapshot($olt);
            $onus = $inventory['onus'] ?? [];

            if (empty($onus)) {
                $warn = $inventory['meta']['warning'] ?? null;
                $proto = $inventory['meta']['protocol'] ?? ($olt->management_protocol ?: '?');
                $detail = $warn
                    ? "HiOSO empty ONUs (protocol={$proto}): {$warn}"
                    : "HiOSO collection returned no ONUs (protocol={$proto}). Device may have empty PON/ONU tables.";
                throw new RuntimeException($detail);
            }

            // Mark snapshot collector for monitoring/UI enterprise badge.
            if (! isset($inventory['meta']) || ! is_array($inventory['meta'])) {
                $inventory['meta'] = [];
            }
            $inventory['meta']['collector'] = 'olt_onus';
            $inventory['meta']['vendor'] = $inventory['meta']['vendor'] ?? 'hioso';

            $result = $this->persistInventoryOnus($olt, $onus, $started, 'hioso_inventory', includeRx: true);

            // Keep raw meta from collector (commands/warnings) without blowing snapshot size.
            $olt->refresh();
            $snap = is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [];
            $snap['meta'] = array_merge($snap['meta'] ?? [], [
                'collector' => 'olt_onus',
                'hioso_protocol' => $inventory['meta']['protocol'] ?? null,
                'hioso_collected_at' => $inventory['meta']['collected_at'] ?? now()->toISOString(),
            ]);
            $olt->forceFill(['last_gpon_snapshot' => $snap])->save();

            return $result;
        });
    }

    /**
     * Huawei MA5608T inventory + state snapshot via read-only SSH CLI.
     */
    public function runHuaweiInventorySync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();

        return $this->executeTier($olt, OltCollectRun::TIER_INVENTORY, 'olt-collect:'.$olt->id.':huawei', 900, function () use ($olt) {
            $started = microtime(true);
            $inventory = $this->huaweiService->collectSnapshot($olt);
            $onus = $inventory['onus'] ?? [];

            if (empty($onus)) {
                $warn = $inventory['meta']['warning'] ?? null;
                $detail = $warn
                    ? "Huawei MA5608T empty ONUs: {$warn}"
                    : 'Huawei MA5608T collection returned no ONUs.';
                throw new RuntimeException($detail);
            }

            $result = $this->persistInventoryOnus($olt, $onus, $started, 'huawei_inventory', includeRx: true);

            $olt->refresh();
            $snapshot = is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [];
            $snapshot['meta'] = array_merge($snapshot['meta'] ?? [], [
                'collector' => 'huawei-ma5608t-cli',
                'huawei_collected_at' => $inventory['meta']['collected_at'] ?? now()->toISOString(),
            ]);
            $olt->forceFill(['last_gpon_snapshot' => $snapshot])->save();

            return $result;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $onus
     * @return array{onus_total: int, onus_updated: int, duration_ms: int, meta: array<string, mixed>}
     */
    protected function persistInventoryOnus(
        Olt $olt,
        array $onus,
        float $started,
        string $source,
        bool $includeRx = false,
    ): array {
        $now = now();
        $updated = 0;
        $seenRefs = [];
        $withRx = 0;

        foreach ($onus as $onuData) {
            $ref = $this->normalizeRef((string) ($onuData['onu_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            $seenRefs[] = $ref;
            $existing = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('onu_ref', $ref)
                ->first();

            $state = OltOnu::normalizeOperationalState(
                $onuData['state'] ?? null,
                $onuData['phase_state'] ?? null
            );

            $incomingName = isset($onuData['onu_name']) ? trim((string) $onuData['onu_name']) : '';
            $incomingSerial = isset($onuData['serial_number']) ? strtoupper(trim((string) $onuData['serial_number'])) : '';

            $rowPayload = [
                'pon_port' => $onuData['pon_port'] ?? $this->extractPort($ref),
                'state' => $state,
                'phase_state' => $onuData['phase_state'] ?? null,
                'state_collected_at' => $now,
                'inventory_collected_at' => $now,
            ];

            // Preserve a fresher optical distance when an inventory collector
            // has no distance field (for example Huawei CLI table output).
            if (array_key_exists('distance_m', $onuData) && $onuData['distance_m'] !== null) {
                $rowPayload['distance_m'] = $onuData['distance_m'];
            }

            if ($incomingSerial !== '') {
                $rowPayload['serial_number'] = $incomingSerial;
                $rowPayload['serial_source'] = $source;
            }

            // Only apply name if non-generic — never clobber provision / customer names with ONU-14:1
            if ($incomingName !== '' && ! $this->isGenericOnuName($incomingName)) {
                $rowPayload['onu_name'] = $incomingName;
                $rowPayload['name_source'] = $source;
            }

            if ($includeRx && array_key_exists('rx_power_dbm', $onuData) && $onuData['rx_power_dbm'] !== null) {
                $rowPayload['rx_power_dbm'] = (float) $onuData['rx_power_dbm'];
                $rowPayload['rx_source'] = $source;
                $rowPayload['rx_collected_at'] = $now;
                $withRx++;
            }

            if ($existing) {
                // Don't clobber fresher snmp RX on ZTE when inventory has no RX.
                if (! $includeRx && $existing->rx_collected_at && $existing->rx_source === 'snmp') {
                    unset($rowPayload['rx_power_dbm'], $rowPayload['rx_source'], $rowPayload['rx_collected_at']);
                }

                // Keep better existing name (provision / customer) if inventory has nothing useful
                if (
                    isset($rowPayload['onu_name'])
                    && $this->isGenericOnuName($rowPayload['onu_name'])
                    && ! $this->isGenericOnuName($existing->onu_name)
                ) {
                    unset($rowPayload['onu_name'], $rowPayload['name_source']);
                }
                if (
                    empty($rowPayload['serial_number'])
                    && filled($existing->serial_number)
                ) {
                    unset($rowPayload['serial_number'], $rowPayload['serial_source']);
                }

                $existing->fill($rowPayload);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            } else {
                // New row: allow generic placeholder name only if nothing else
                if ($incomingName !== '' && ! isset($rowPayload['onu_name'])) {
                    $rowPayload['onu_name'] = $incomingName;
                    $rowPayload['name_source'] = $source;
                }
                if ($incomingSerial !== '' && ! isset($rowPayload['serial_number'])) {
                    $rowPayload['serial_number'] = $incomingSerial;
                    $rowPayload['serial_source'] = $source;
                }

                OltOnu::query()->create(array_merge([
                    'olt_id' => $olt->id,
                    'onu_ref' => $ref,
                ], $rowPayload));
                $updated++;
            }
        }

        $purged = $this->purgeStaleOnus($olt, $seenRefs);
        $snapshot = $this->snapshotBuilder->materialize($olt);

        return [
            'onus_total' => count($onus),
            'onus_updated' => $updated,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'meta' => [
                'source' => $source,
                'seen_refs' => count($seenRefs),
                'with_rx' => $withRx,
                'purged_stale' => $purged,
                'snapshot_onus' => count($snapshot['onus'] ?? []),
                'fhtt_total' => $snapshot['meta']['fhtt_total'] ?? null,
                'fhtt_with_name' => $snapshot['meta']['fhtt_with_name'] ?? null,
            ],
        ];
    }

    /**
     * RX tier: ZTE → SNMP; HiOSO → full hioso collect (optical included).
     */
    public function runRxSync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();

        if ($this->isHiosoVendor($olt)) {
            return $this->runHiosoInventorySync($olt);
        }

        if ($this->isHuaweiVendor($olt)) {
            return $this->runHuaweiOpticalSync($olt);
        }

        return $this->runSnmpSync($olt);
    }

    /**
     * User-facing ZTE refresh. Mode `read` is the bounded per-PON path.
     * The scheduled full tree walk stays on olt:snmp-sync.
     */
    public function runInteractiveSnmpRead(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();
        $this->assertZteC300($olt);

        return $this->executeTier($olt, OltCollectRun::TIER_SNMP, 'olt-collect:'.$olt->id.':snmp', 120, function () use ($olt) {
            $started = microtime(true);
            $payload = $this->zteService->collectSnmpRxPayload($olt, [
                'SNMP_MODE' => 'read',
                'SNMP_TIMEOUT' => '70',
            ]);
            $result = $this->buildSnmpSyncResult($olt, $payload, $started);
            $this->snapshotBuilder->materialize($olt->fresh(), syncCustomers: false);

            return $result;
        });
    }

    /**
     * Huawei click/read path. Reuses the bounded SNMP optical collector.
     * SSH `display ont info all` stays on the hourly inventory tier.
     */
    public function runHuaweiOpticalSync(Olt $olt): OltCollectRun
    {
        $this->assertEnterpriseTables();

        $lock = Cache::lock('olt-collect:'.$olt->id.':huawei', 120);
        if (! $lock->get()) {
            return OltCollectRun::query()->create([
                'olt_id' => $olt->id,
                'tier' => OltCollectRun::TIER_HUAWEI_OPTICAL,
                'status' => 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'meta' => ['reason' => 'Huawei collector already running'],
            ]);
        }

        try {
            (new SyncOltOpticalJob($olt->id))->handle(
                app(HuaweiOltSnmpOpticalService::class),
                $this->snapshotBuilder,
            );
        } finally {
            $lock->release();
        }

        $run = OltCollectRun::query()
            ->where('olt_id', $olt->id)
            ->where('tier', OltCollectRun::TIER_HUAWEI_OPTICAL)
            ->orderByDesc('id')
            ->first();

        if ($run instanceof OltCollectRun && $run->status === 'failed') {
            throw new RuntimeException($run->error ?: 'Huawei optical SNMP failed');
        }

        return $run ?? OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_HUAWEI_OPTICAL,
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => ['reason' => 'Huawei optical sync produced no run'],
        ]);
    }

    protected function isHiosoVendor(Olt $olt): bool
    {
        $vendor = strtolower((string) ($olt->vendor ?? ''));

        return str_contains($vendor, 'hioso') || str_contains($vendor, 'htb');
    }

    protected function isHuaweiVendor(Olt $olt): bool
    {
        $vendor = strtolower((string) ($olt->vendor ?? ''));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    public function collectHealthSummary(): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [
                'enterprise_olts' => 0,
                'healthy_olts' => 0,
                'weak_rx_total' => 0,
                'rows' => [],
            ];
        }

        $olts = Olt::query()->orderBy('id')->get();
        $rows = [];
        $healthy = 0;
        $weakRx = 0;

        foreach ($olts as $olt) {
            $onus = OltOnu::query()->where('olt_id', $olt->id);
            $total = (clone $onus)->count();
            $withRx = (clone $onus)->whereNotNull('rx_power_dbm')->count();
            $weak = (clone $onus)->where('rx_power_dbm', '<', -27)->count();
            $weakRx += $weak;

            if ($total > 0 && $withRx > 0) {
                $healthy++;
            }

            $rows[] = [
                'olt_id' => $olt->id,
                'healthy' => $total > 0 && $withRx > 0,
                'onus_total' => $total,
                'onus_with_rx' => $withRx,
                'weak_rx' => $weak,
            ];
        }

        return [
            'enterprise_olts' => $olts->count(),
            'healthy_olts' => $healthy,
            'weak_rx_total' => $weakRx,
            'rows' => $rows,
        ];
    }

    public function scanWeakRxAlerts(): int
    {
        $this->assertEnterpriseTables();
        if (! Schema::hasTable('monitoring_alert_events')) {
            return 0;
        }

        $changed = 0;
        $threshold = (float) config('services.monitoring.weak_rx_dbm', -27);
        $eventClass = MonitoringAlertEvent::class;
        $olts = Olt::query()->get()->filter(fn (Olt $olt) => OltVendor::isEnterprise($olt));

        foreach ($olts as $olt) {
            $weakCount = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereNotNull('rx_power_dbm')
                ->where('rx_power_dbm', '<', $threshold)
                ->whereRaw('LOWER(state) IN (?, ?, ?, ?, ?, ?)', [
                    'online', 'working', 'up', 'active', 'enable', 'enabled',
                ])
                ->count();

            $dedupeKey = "olt:{$olt->id}:weak_rx";
            if ($weakCount > 0) {
                $event = $eventClass::firstOrNew(['dedupe_key' => $dedupeKey]);
                $event->fill([
                    'source' => 'olt_collector',
                    'entity_type' => 'olt',
                    'entity_id' => (string) $olt->id,
                    'entity_name' => $olt->name,
                    'issue' => 'weak_rx',
                    'severity' => $weakCount >= 10 ? 'critical' : 'warning',
                    'status' => 'active',
                    'payload' => [
                        'weak_count' => $weakCount,
                        'threshold_dbm' => $threshold,
                        'vendor' => $olt->vendor,
                    ],
                    'last_seen_at' => now(),
                ]);
                $event->first_seen_at ??= now();
                $event->recovered_at = null;
                $event->save();
                $changed++;
            } else {
                $event = $eventClass::query()->where('dedupe_key', $dedupeKey)->first();
                if ($event && $event->status === 'active') {
                    $event->update([
                        'status' => 'recovered',
                        'recovered_at' => now(),
                        'last_seen_at' => now(),
                    ]);
                    $changed++;
                }
            }

            $lastRun = OltCollectRun::query()
                ->where('olt_id', $olt->id)
                ->orderByDesc('finished_at')
                ->first();

            if ($lastRun && $lastRun->status === 'failed' && $lastRun->finished_at?->gt(now()->subHours(2))) {
                $failKey = "olt:{$olt->id}:collector_failed:{$lastRun->tier}";
                $fail = $eventClass::firstOrNew(['dedupe_key' => $failKey]);
                $fail->fill([
                    'source' => 'olt_collector',
                    'entity_type' => 'olt',
                    'entity_id' => (string) $olt->id,
                    'entity_name' => $olt->name,
                    'issue' => 'collector_failed',
                    'severity' => 'critical',
                    'status' => 'active',
                    'payload' => [
                        'tier' => $lastRun->tier,
                        'error' => $lastRun->error,
                    ],
                    'last_seen_at' => now(),
                ]);
                $fail->first_seen_at ??= now();
                $fail->recovered_at = null;
                $fail->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $liveRefs
     */
    public function purgeStaleOnus(Olt $olt, array $liveRefs, ?string $ponPort = null): int
    {
        $liveSet = collect($liveRefs)
            ->map(fn ($ref) => strtolower($this->normalizeRef((string) $ref)))
            ->filter()
            ->flip();

        $query = OltOnu::query()->where('olt_id', $olt->id);

        if ($ponPort !== null && $ponPort !== '') {
            $pon = $this->normalizePonPort($ponPort);
            if ($pon !== '') {
                $query->where(function ($builder) use ($pon) {
                    $builder->where('pon_port', $pon)
                        ->orWhere('onu_ref', 'like', $pon.':%');
                });
            }
        }

        $purged = 0;
        foreach ($query->get([
            'id',
            'onu_ref',
            'serial_source',
            'name_source',
            'state',
            'state_collected_at',
            'rx_collected_at',
            'updated_at',
        ]) as $row) {
            $ref = strtolower($this->normalizeRef($row->onu_ref));
            if ($ref === '' || $liveSet->has($ref)) {
                continue;
            }

            $recentlyObserved = collect([$row->state_collected_at, $row->rx_collected_at])
                ->filter()
                ->contains(fn ($at) => $at->gt(now()->subMinutes(self::LIVE_ROW_PURGE_GRACE_MINUTES)));
            $freshProvision = in_array(
                strtolower((string) ($row->serial_source ?: $row->name_source)),
                ['provision', 'migration'],
                true
            ) && $row->updated_at?->gt(now()->subMinutes(self::LIVE_ROW_PURGE_GRACE_MINUTES));

            if ($recentlyObserved || $freshProvision) {
                continue;
            }

            $row->delete();
            $purged++;
        }

        if ($purged > 0) {
            Log::info('OLT stale ONU purged', [
                'olt_id' => $olt->id,
                'pon_port' => $ponPort,
                'purged' => $purged,
            ]);
        }

        return $purged;
    }

    protected function executeTier(Olt $olt, string $tier, string $lockKey, int $lockSeconds, callable $callback): OltCollectRun
    {
        $run = OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => $tier,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $lock = Cache::lock($lockKey, $lockSeconds);
        if (! $lock->get()) {
            $run->update([
                'status' => 'skipped',
                'finished_at' => now(),
                'error' => 'Another collector run is active',
            ]);

            return $run->fresh();
        }

        $this->reapAbandonedRuns($olt, $tier, $run, $lockSeconds);

        try {
            $result = $callback();
            $status = match ($result['meta']['status'] ?? null) {
                'skipped' => 'skipped',
                'partial' => 'partial',
                default => 'success',
            };
            $run->update([
                'status' => $status,
                'onus_total' => $result['onus_total'] ?? 0,
                'onus_updated' => $result['onus_updated'] ?? 0,
                'duration_ms' => $result['duration_ms'] ?? null,
                'meta' => $result['meta'] ?? null,
                'error' => $result['error'] ?? null,
                'finished_at' => now(),
            ]);
        } catch (HuaweiOltSessionBusyException $e) {
            // Another SSH session owns the OLT: same as a concurrent collector
            // run. Skip (no hard failure); the next scheduled tick retries.
            $run->update([
                'status' => 'skipped',
                'error' => $e->userMessage(),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("OLT {$tier} sync failed for #{$olt->id} {$olt->name}: {$e->getMessage()}");
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        } finally {
            $lock->release();
        }

        return $run->fresh();
    }

    /**
     * A queue worker killed on timeout (SIGKILL) cannot finish its run row.
     * Once this run holds the tier lock, any older `running` row for the same
     * OLT/tier that is past the lock TTL is orphaned: close it as failed so
     * monitoring stops reporting a phantom in-flight collector.
     */
    protected function reapAbandonedRuns(Olt $olt, string $tier, OltCollectRun $current, int $lockSeconds): int
    {
        try {
            return OltCollectRun::query()
                ->where('olt_id', $olt->id)
                ->where('tier', $tier)
                ->where('status', 'running')
                ->where('id', '<', $current->id)
                ->where('started_at', '<=', now()->subSeconds(max(60, $lockSeconds)))
                ->update([
                    'status' => 'failed',
                    'error' => 'Abandoned: collector process ended before recording a result (worker timeout/restart)',
                    'finished_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::debug("OLT {$tier} abandoned-run reap skipped for #{$olt->id}: {$e->getMessage()}");

            return 0;
        }
    }

    protected function assertEnterpriseTables(): void
    {
        if (! Schema::hasTable('olt_onus')) {
            throw new RuntimeException('Enterprise OLT tables missing. Run: php artisan migrate');
        }
    }

    protected function assertZteC300(Olt $olt): void
    {
        $vendor = strtolower((string) ($olt->vendor ?? 'zte_c300'));
        if (str_contains($vendor, 'hioso') || str_contains($vendor, 'htb') || $this->isHuaweiVendor($olt)) {
            throw new RuntimeException("OLT #{$olt->id} is not ZTE C300 (vendor: {$vendor})");
        }
    }

    protected function normalizeRef(string $ref): string
    {
        $ref = strtolower(trim($ref));

        return preg_replace('/^gpon-onu_/', '', $ref) ?? $ref;
    }

    protected function normalizePonPort(string $port): string
    {
        $port = strtolower(trim($port));
        $port = preg_replace('/^gpon-olt_/', '', $port) ?? $port;

        return preg_match('/^\d+\/\d+\/\d+$/', $port) ? $port : '';
    }

    protected function extractPort(string $ref): ?string
    {
        if (preg_match('/^(\d+\/\d+\/\d+):\d+$/', $this->normalizeRef($ref), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * ZTE default labels like ONU-14:1 (also ONU-1/3/1:14, ONU_5) — not real
     * customer names. Audit 2026-10-08: 7 OLT RA ONUs keep a generic name on
     * the OLT while the DB holds the GenieACS / provision / backfill name.
     */
    protected function isGenericOnuName(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }

        return (bool) preg_match('#^ONU[-_ ]?[\d/:._-]*$#i', $name);
    }
}
