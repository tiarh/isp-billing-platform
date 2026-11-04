<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Single admission decision for all monitoring work.
 *
 * The gate is deliberately feature-off by default. Once enabled in production
 * it fails closed for critical-queue/resource evidence, while preserving the
 * last-good monitoring snapshot and avoiding a retry storm.
 */
class MonitoringAdmissionGate
{
    private const ZERO_SINCE_KEY = 'monitoring.admission_gate.critical_zero_since';

    private const SWAP_SAMPLE_KEY = 'monitoring.admission_gate.swap_sample';

    private const CPU_SAMPLE_KEY = 'monitoring.admission_gate.cpu_sample';

    /** A /proc/stat baseline older than this is discarded (not a useful window). */
    private const CPU_SAMPLE_MAX_AGE_SECONDS = 900;

    /** Keep the baseline at least this long so deltas are not single-tick noise. */
    private const CPU_SAMPLE_MIN_WINDOW_SECONDS = 30;

    /** Below this many jiffies (~1 CPU-second at USER_HZ=100) a delta is noise. */
    private const CPU_SAMPLE_MIN_TICKS = 100;

    public function __construct(private readonly HostCpuProbe $cpuProbe = new HostCpuProbe) {}

    /** @return array<string, mixed> */
    public function evaluate(string $operation = 'monitoring'): array
    {
        $config = (array) config('services.monitoring.admission_gate', []);
        $checkedAt = CarbonImmutable::now('Asia/Jakarta');
        $strict = (bool) ($config['strict'] ?? false);

        if (! $this->enabled($config)) {
            if ($this->requiresStrictAdmission($operation)) {
                return [
                    'allowed' => false,
                    'operation' => $operation,
                    'strict' => false,
                    'reason_codes' => ['traffic_admission_gate_off'],
                    'checks' => ['admission_gate' => ['enabled' => false, 'strict' => false]],
                    'checked_at' => $checkedAt->toIso8601String(),
                ];
            }

            return [
                'allowed' => true,
                'operation' => $operation,
                'strict' => false,
                'reason_codes' => ['feature_off'],
                'checks' => [],
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        if ($this->requiresStrictAdmission($operation) && ! $strict) {
            return [
                'allowed' => false,
                'operation' => $operation,
                'strict' => false,
                'reason_codes' => ['traffic_admission_gate_not_strict'],
                'checks' => ['admission_gate' => ['enabled' => true, 'strict' => false]],
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            return [
                'allowed' => false,
                'operation' => $operation,
                'strict' => $strict,
                'reason_codes' => ['operator_hold'],
                'checks' => ['operator_hold' => ['enabled' => true]],
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        $reasons = [];
        $checks = [];
        $criticalQueues = $this->criticalQueues($config);
        $queueDepths = [];
        $queueError = null;

        foreach ($criticalQueues as $queue) {
            try {
                $queueDepths[$queue] = max(0, (int) Queue::size($queue));
            } catch (\Throwable $e) {
                $queueError = get_class($e);
                $queueDepths[$queue] = null;
            }
        }

        if ($queueError !== null || in_array(null, $queueDepths, true)) {
            $reasons[] = 'critical_queue_unavailable';
        } else {
            foreach ($queueDepths as $queue => $depth) {
                if ($depth > 0) {
                    $reasons[] = 'critical_queue_busy:'.$queue;
                }
            }
        }

        $allQueuesClear = $queueError === null
            && $queueDepths !== []
            && ! in_array(null, $queueDepths, true)
            && max($queueDepths) === 0;

        $cooldownSeconds = max(120, (int) ($config['cooldown_seconds'] ?? 120));
        $zeroSince = Cache::get(self::ZERO_SINCE_KEY);
        if ($allQueuesClear) {
            $zeroSinceTimestamp = $zeroSince === null ? null : $this->timestamp($zeroSince);
            // A start time in the future (clock skew, bad value) never clears: invalid.
            if ($zeroSinceTimestamp !== null && $zeroSinceTimestamp > $checkedAt->timestamp + 60) {
                $zeroSinceTimestamp = null;
            }
            if ($zeroSinceTimestamp === null) {
                // First clear check, or an unreadable/future value (self-heal).
                Cache::put(self::ZERO_SINCE_KEY, $checkedAt->timestamp, now()->addHours(2));
                $reasons[] = 'critical_queue_cooldown';
            } else {
                // Keep the original start time, but refresh the TTL so a long
                // quiet period never expires the key and re-triggers cooldown.
                // This Laravel version has no Cache::touch(), so the same value is
                // re-put. Known tiny race: a forget() by a concurrent busy check
                // between our get and put can be undone; the next check sees the
                // queue state again and forgets it, so the effect is one extra
                // clear tick at worst.
                Cache::put(self::ZERO_SINCE_KEY, $zeroSince, now()->addHours(2));
                if (($checkedAt->timestamp - $zeroSinceTimestamp) < $cooldownSeconds) {
                    $reasons[] = 'critical_queue_cooldown';
                }
            }
        } else {
            Cache::forget(self::ZERO_SINCE_KEY);
        }

        $checks['critical_queues'] = [
            'depths' => $queueDepths,
            'clear' => $allQueuesClear,
            'cooldown_seconds' => $cooldownSeconds,
            'zero_since' => $zeroSince,
        ];

        $failedCritical = $this->failedCriticalJobs($criticalQueues);
        $checks['failed_critical_jobs'] = $failedCritical;
        if (($failedCritical['error'] ?? null) !== null) {
            $reasons[] = 'failed_jobs_unavailable';
        } elseif (($failedCritical['last_hour'] ?? 0) > 0) {
            $reasons[] = 'critical_failed_jobs';
        }

        if ($strict) {
            if (! (bool) ($config['heartbeat_required'] ?? false)) {
                $reasons[] = 'worker_heartbeat_check_not_enabled';
                $checks['worker_heartbeat'] = ['enabled' => false, 'healthy' => false];
            } else {
                $heartbeat = $this->workerHeartbeat($config, $checkedAt);
                $checks['worker_heartbeat'] = $heartbeat;
                if (! $heartbeat['healthy']) {
                    $reasons[] = 'worker_heartbeat_unhealthy';
                }
            }

            if (! (bool) ($config['resource_checks_enabled'] ?? false)) {
                $reasons[] = 'resource_checks_not_enabled';
                $checks['resources'] = ['enabled' => false, 'healthy' => false];
            } else {
                $resources = $this->resources($config, $checkedAt);
                $checks['resources'] = $resources;
                if (! $resources['healthy']) {
                    foreach ($resources['reasons'] as $reason) {
                        $reasons[] = $reason;
                    }
                }
            }

            $storage = $this->storageAndDataPlane($config);
            $checks['storage_data_plane'] = $storage;
            if (! $storage['healthy']) {
                foreach ($storage['reasons'] as $reason) {
                    $reasons[] = $reason;
                }
            }
        }

        $allowed = $reasons === [];
        if (! $allowed) {
            $context = [
                'operation' => $operation,
                'reason_codes' => array_values(array_unique($reasons)),
            ];
            if (isset($checks['resources']['cpu_signal'])) {
                // Tell the NOC *why* CPU held: steal vs real work vs stall time.
                $context['cpu'] = array_intersect_key($checks['resources'], array_flip([
                    'cpu_signal', 'normalized_load', 'steal_adjusted_load', 'steal_pct',
                    'real_busy_pct', 'usable_busy_pct', 'psi_cpu_some_avg60',
                ]));
            }
            Log::notice('Monitoring admission gate held monitoring work.', $context);
        }

        return [
            'allowed' => $allowed,
            'operation' => $operation,
            'strict' => $strict,
            'reason_codes' => array_values(array_unique($reasons)),
            'checks' => $checks,
            'checked_at' => $checkedAt->toIso8601String(),
        ];
    }

    public function allows(string $operation = 'monitoring'): bool
    {
        return (bool) ($this->evaluate($operation)['allowed'] ?? false);
    }

    /**
     * Huawei optical polling is one bounded, read-only OLT lane. Keep it
     * independent from unrelated PSB/migration queue pressure, while still
     * honoring an explicit operator hold and refusing to enqueue without DB.
     */
    public function allowsHuaweiOptical(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('Huawei optical monitoring held by operator.', [
                'operation' => 'huawei_optical',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('Huawei optical monitoring held because DB is unavailable.', [
                'operation' => 'huawei_optical',
                'exception' => get_class($e),
            ]);

            return false;
        }

        return true;
    }

    /**
     * OLT collector lane (SNMP/CLI state + RX + inventory) is the core data
     * plane for NOC truth. Like Huawei optical, keep it independent from
     * unrelated PSB/migration queue pressure and host load spikes, while
     * still honoring an explicit operator hold and refusing without DB.
     *
     * Router-health/ACS collectors stay behind allows()/legacy schedule gate;
     * PPPoE session lanes use allowsRouterSessionCollector().
     */
    public function allowsOltCollector(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('OLT collector monitoring held by operator.', [
                'operation' => 'olt_collector',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('OLT collector monitoring held because DB is unavailable.', [
                'operation' => 'olt_collector',
                'exception' => get_class($e),
            ]);

            return false;
        }

        return true;
    }

    /**
     * PPPoE session lanes (routers:mt-session-sync) keep customers.is_online +
     * pppoe_online_checked_at inside ONLINE_PPP_MAX_AGE_MINUTES. The work is a
     * bounded remote API read per router plus a small flag write, so host load
     * does not grow with it. On VM102 (CPU steal) normalized_load_high is red
     * most of the day, which starved both lanes for hours (2026-10-08: zero
     * T1 snapshots 07:44–10:20 UTC, pppoe_online_checked_at never stamped).
     *
     * Like the OLT lane it skips the host-load/cooldown/heartbeat checks, but
     * it still yields to PSB/migration: any critical queue with pending work
     * (or an unreadable depth) holds the lane. Operator hold + DB still apply.
     */
    public function allowsRouterSessionCollector(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('Router session monitoring held by operator.', [
                'operation' => 'router_session_collector',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        $reasons = [];
        foreach ($this->criticalQueues($config) as $queue) {
            try {
                if ((int) Queue::size($queue) > 0) {
                    $reasons[] = 'critical_queue_busy:'.$queue;
                }
            } catch (\Throwable) {
                $reasons[] = 'critical_queue_unavailable';
            }
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $reasons[] = 'db_unavailable';
        }

        if ($reasons !== []) {
            Log::notice('Router session monitoring held.', [
                'operation' => 'router_session_collector',
                'reason_codes' => array_values(array_unique($reasons)),
            ]);

            return false;
        }

        return true;
    }

    /**
     * NOC dashboard read-model refresh. This is a bounded, read-only aggregate
     * of DB state; a busy host must not silently freeze the whole cockpit
     * (the observed failure mode was a permanently cold payload cache). It
     * stays independent from host-load/queue pressure while still honoring an
     * explicit operator hold and refusing without DB.
     */
    public function allowsMonitoringDashboard(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('Monitoring dashboard refresh held by operator.', [
                'operation' => 'monitoring_dashboard',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('Monitoring dashboard refresh held because DB is unavailable.', [
                'operation' => 'monitoring_dashboard',
                'exception' => get_class($e),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Per-user traffic ingest from the isolated collector. It is a bounded,
     * authenticated write (≤250 rows/chunk, ≤4 MiB) and the primary data
     * plane for bandwidth usage. A busy host must not silently drop it
     * (observed failure: collector pushed every cycle but got HTTP 503
     * normalized_load_high, so realtime rates froze for hours). Operator hold
     * and DB availability still apply.
     */
    public function allowsTrafficIngest(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('Traffic ingest held by operator.', [
                'operation' => 'traffic_ingest',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('Traffic ingest held because DB is unavailable.', [
                'operation' => 'traffic_ingest',
                'exception' => get_class($e),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Traffic READ (dashboard bandwidth view). Bounded DB + Redis read that
     * powers the realtime bandwidth panel. Like the dashboard read-model it
     * must not be held by host-load/queue pressure (operator hold + DB check
     * still apply), otherwise the panel shows a permanent "paused/stale"
     * banner while the data is actually present.
     */
    public function allowsTrafficRead(): bool
    {
        $config = (array) config('services.monitoring.admission_gate', []);

        if (! $this->enabled($config)) {
            return true;
        }

        if ((bool) ($config['operator_hold'] ?? false)) {
            Log::notice('Traffic read held by operator.', [
                'operation' => 'traffic_read',
                'reason_codes' => ['operator_hold'],
            ]);

            return false;
        }

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('Traffic read held because DB is unavailable.', [
                'operation' => 'traffic_read',
                'exception' => get_class($e),
            ]);

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $config */
    private function enabled(array $config): bool
    {
        return filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function requiresStrictAdmission(string $operation): bool
    {
        return $operation === 'traffic_export' || str_starts_with($operation, 'traffic_');
    }

    /** @param array<string, mixed> $config @return list<string> */
    private function criticalQueues(array $config): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $queue): string => trim((string) $queue),
            (array) ($config['critical_queues'] ?? []),
        )));
    }

    /** @param list<string> $queues @return array<string, mixed> */
    private function failedCriticalJobs(array $queues): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return ['last_hour' => 0, 'available' => false, 'error' => 'table_missing'];
            }

            $query = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour());
            if ($queues !== []) {
                $query->whereIn('queue', $queues);
            }

            return [
                'last_hour' => (int) $query->count(),
                'available' => true,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['last_hour' => null, 'available' => false, 'error' => get_class($e)];
        }
    }

    /** @param array<string, mixed> $config */
    private function workerHeartbeat(array $config, CarbonImmutable $now): array
    {
        $maxAge = max(300, (int) ($config['heartbeat_max_age_seconds'] ?? 900));
        $keys = (array) ($config['worker_heartbeat_keys'] ?? []);
        $ages = [];
        $healthy = true;

        // 'enforce' (worker-enforce-102) is intentionally not gated: worker-ops still drains
        // network-enforcement, and a dead enforce worker must not pause monitoring. OpsAlertService alerts on it.
        foreach (['psb', 'migration', 'ocr'] as $worker) {
            $key = trim((string) ($keys[$worker] ?? ''));
            $value = $key !== '' ? Cache::get($key) : null;
            $timestamp = $this->timestamp($value);
            $age = $timestamp === null ? null : max(0, $now->timestamp - $timestamp);
            $ages[$worker] = [
                'key_configured' => $key !== '',
                'age_seconds' => $age,
            ];
            if ($timestamp === null || $age > $maxAge) {
                $healthy = false;
            }
        }

        return [
            'enabled' => true,
            'healthy' => $healthy,
            'max_age_seconds' => $maxAge,
            'workers' => $ages,
        ];
    }

    /** @param array<string, mixed> $config */
    private function resources(array $config, CarbonImmutable $now): array
    {
        $cpu = $this->cpuPressure($config);
        $reasons = $cpu['reasons'];
        $availableMemory = $this->procMemAvailable();
        $rootFree = @disk_free_space(base_path());
        $swap = $this->swapActivity();

        if ($availableMemory === null) {
            $reasons[] = 'available_memory_unavailable';
        } elseif ($availableMemory < (int) ($config['available_memory_bytes'] ?? 8589934592)) {
            $reasons[] = 'available_memory_low';
        }
        if ($rootFree === false || $rootFree === null) {
            $reasons[] = 'root_free_unavailable';
        } elseif ($rootFree < (int) ($config['root_free_bytes'] ?? 16106127360)) {
            $reasons[] = 'root_free_low';
        }
        if ($swap['active']) {
            $reasons[] = 'swap_activity';
        }

        return [
            'enabled' => true,
            'healthy' => $reasons === [],
            'reasons' => $reasons,
            'normalized_load' => $cpu['normalized_load'],
            'cpu_signal' => $cpu['signal'],
            'steal_adjusted_load' => $cpu['steal_adjusted_load'],
            'steal_pct' => $cpu['steal_pct'],
            'real_busy_pct' => $cpu['real_busy_pct'],
            'usable_busy_pct' => $cpu['usable_busy_pct'],
            'psi_cpu_some_avg60' => $cpu['psi_cpu_some_avg60'],
            'cpu' => $cpu['detail'],
            'available_memory_bytes' => $availableMemory,
            'root_free_bytes' => $rootFree === false ? null : $rootFree,
            'swap' => $swap,
            'sampled_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Steal-aware CPU admission signal.
     *
     * On VM102 (2026-10-08 measurement) load1/8 >= 0.70 held ~88% of the time
     * while real busy CPU was ~21%: the load average counts runnable tasks
     * waiting for a hypervisor-stolen physical CPU (steal avg ~38%). Holding
     * monitoring cannot fix that, so the gate now holds on:
     *   (a) PSI /proc/pressure/cpu "some" avg60 >= psi_cpu_some_threshold, or
     *   (b) load excluding the steal share (load1 * (1 - steal/(busy+steal)) /
     *       cores) >= load_threshold, or busy share of the non-stolen CPU
     *       >= cpu_usable_busy_threshold.
     * When neither PSI nor /proc/stat is readable (tests, non-Linux) or
     * cpu_signal=load, it falls back to the legacy raw load1/cores rule.
     *
     * @param  array<string, mixed>  $config
     * @return array{reasons: list<string>, signal: string, normalized_load: ?float, steal_adjusted_load: ?float, steal_pct: ?float, real_busy_pct: ?float, usable_busy_pct: ?float, psi_cpu_some_avg60: ?float, detail: array<string, mixed>}
     */
    private function cpuPressure(array $config): array
    {
        $cores = max(1, (int) ($config['cpu_cores'] ?? 1));
        $loadThreshold = (float) ($config['load_threshold'] ?? 0.70);
        $psiThreshold = (float) ($config['psi_cpu_some_threshold'] ?? 40.0);
        $usableThreshold = (float) ($config['cpu_usable_busy_threshold'] ?? 80.0);
        $legacyOnly = strtolower(trim((string) ($config['cpu_signal'] ?? 'steal_aware'))) === 'load';

        $load = $this->cpuProbe->loadAverage();
        $normalizedLoad = $load !== null ? $load[0] / $cores : null;
        $psi = $legacyOnly ? null : $this->parsePressure($this->cpuProbe->pressureCpu());
        $stat = $legacyOnly ? null : $this->cpuStatWindow();

        $stealAdjustedLoad = $normalizedLoad !== null && $stat !== null
            ? $normalizedLoad * (1 - $stat['steal_share'])
            : null;
        $psiSome = $psi['some_avg60'] ?? null;

        $reasons = [];
        if ($psi === null && $stat === null) {
            $signal = 'load';
            if ($normalizedLoad === null) {
                $reasons[] = 'load_unavailable';
            } elseif ($normalizedLoad >= $loadThreshold) {
                $reasons[] = 'normalized_load_high';
            }
        } else {
            $signal = 'steal_aware';
            if ($psiSome !== null && $psiSome >= $psiThreshold) {
                $reasons[] = 'cpu_pressure_high';
            }
            if ($stealAdjustedLoad !== null && $stealAdjustedLoad >= $loadThreshold) {
                $reasons[] = 'steal_adjusted_load_high';
            }
            if (($stat['usable_busy_pct'] ?? null) !== null && $stat['usable_busy_pct'] >= $usableThreshold) {
                $reasons[] = 'cpu_usable_capacity_high';
            }
        }

        $round = static fn (?float $value): ?float => $value === null ? null : round($value, 3);

        return [
            'reasons' => $reasons,
            'signal' => $signal,
            'normalized_load' => $normalizedLoad,
            'steal_adjusted_load' => $round($stealAdjustedLoad),
            'steal_pct' => $round($stat['steal_pct'] ?? null),
            'real_busy_pct' => $round($stat['real_busy_pct'] ?? null),
            'usable_busy_pct' => $round($stat['usable_busy_pct'] ?? null),
            'psi_cpu_some_avg60' => $psiSome,
            'detail' => [
                'load1' => $load[0] ?? null,
                'load5' => $load[1] ?? null,
                'cpu_cores' => $cores,
                'iowait_pct' => $round($stat['iowait_pct'] ?? null),
                'idle_pct' => $round($stat['idle_pct'] ?? null),
                'steal_share' => $round($stat['steal_share'] ?? null),
                'stat_window' => $stat['window'] ?? null,
                'stat_window_seconds' => $stat['window_seconds'] ?? null,
                'psi_cpu_some_avg10' => $psi['some_avg10'] ?? null,
                'psi_cpu_full_avg60' => $psi['full_avg60'] ?? null,
                'thresholds' => [
                    'load' => $loadThreshold,
                    'psi_cpu_some' => $psiThreshold,
                    'usable_busy_pct' => $usableThreshold,
                ],
            ],
        ];
    }

    /**
     * CPU time shares from /proc/stat. Uses the delta against a briefly cached
     * baseline (shared via the cache store, like the swap sample); without a
     * usable baseline it uses since-boot counters so the first call is still
     * steal-aware.
     *
     * @return array{window: string, window_seconds: ?int, steal_pct: float, real_busy_pct: float, iowait_pct: float, idle_pct: float, steal_share: float, usable_busy_pct: ?float}|null
     */
    private function cpuStatWindow(): ?array
    {
        $counters = $this->parseProcStat($this->cpuProbe->procStat());
        if ($counters === null) {
            return null;
        }

        $now = CarbonImmutable::now()->timestamp;
        $previous = Cache::get(self::CPU_SAMPLE_KEY);
        $window = 'since_boot';
        $windowSeconds = null;
        $values = $counters;
        $keepBaseline = false;

        if (is_array($previous) && isset($previous['at'], $previous['counters']) && is_array($previous['counters'])) {
            $age = $now - (int) $previous['at'];
            $delta = [];
            foreach ($counters as $key => $value) {
                $delta[$key] = $value - (int) ($previous['counters'][$key] ?? 0);
            }
            $monotonic = min($delta) >= 0;

            if ($age >= 0 && $age <= self::CPU_SAMPLE_MAX_AGE_SECONDS && $monotonic) {
                $keepBaseline = $age < self::CPU_SAMPLE_MIN_WINDOW_SECONDS;
                if (array_sum($delta) >= self::CPU_SAMPLE_MIN_TICKS) {
                    $window = 'delta';
                    $windowSeconds = $age;
                    $values = $delta;
                }
            }
        }

        if (! $keepBaseline) {
            Cache::put(self::CPU_SAMPLE_KEY, ['at' => $now, 'counters' => $counters], now()->addSeconds(self::CPU_SAMPLE_MAX_AGE_SECONDS * 2));
        }

        $total = array_sum($values);
        if ($total <= 0) {
            return null;
        }

        $busy = $values['user'] + $values['nice'] + $values['system'] + $values['irq'] + $values['softirq'];
        $steal = $values['steal'];
        $nonStolen = $total - $steal;

        return [
            'window' => $window,
            'window_seconds' => $windowSeconds,
            'steal_pct' => $steal / $total * 100,
            'real_busy_pct' => $busy / $total * 100,
            'iowait_pct' => $values['iowait'] / $total * 100,
            'idle_pct' => $values['idle'] / $total * 100,
            'steal_share' => ($busy + $steal) > 0 ? $steal / ($busy + $steal) : 0.0,
            'usable_busy_pct' => $nonStolen > 0 ? $busy / $nonStolen * 100 : null,
        ];
    }

    /** @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null */
    private function parseProcStat(?string $contents): ?array
    {
        if ($contents === null || preg_match('/^cpu\s+(\d+(?:\s+\d+){3,})/m', $contents, $match) !== 1) {
            return null;
        }

        // guest/guest_nice are already included in user/nice, so stop at steal.
        $fields = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
        $counters = [];
        foreach (['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'] as $index => $name) {
            $counters[$name] = $fields[$index] ?? 0;
        }

        return $counters;
    }

    /** @return array{some_avg10: ?float, some_avg60: float, full_avg60: ?float}|null */
    private function parsePressure(?string $contents): ?array
    {
        if ($contents === null) {
            return null;
        }

        $lines = [];
        foreach (['some', 'full'] as $kind) {
            if (preg_match('/^'.$kind.'\s+avg10=([\d.]+)\s+avg60=([\d.]+)/m', $contents, $match) === 1) {
                $lines[$kind] = ['avg10' => (float) $match[1], 'avg60' => (float) $match[2]];
            }
        }

        if (! isset($lines['some'])) {
            return null;
        }

        return [
            'some_avg10' => $lines['some']['avg10'],
            'some_avg60' => $lines['some']['avg60'],
            'full_avg60' => $lines['full']['avg60'] ?? null,
        ];
    }

    /** @param array<string, mixed> $config */
    private function storageAndDataPlane(array $config): array
    {
        $reasons = [];
        $redis = ['available' => false, 'used_memory' => null, 'maxmemory' => null, 'used_ratio' => null, 'evicted_keys' => null, 'rejected_connections' => null];
        try {
            $info = Redis::connection()->info();
            $memory = (array) ($info['Memory'] ?? $info['memory'] ?? $info);
            $stats = (array) ($info['Stats'] ?? $info['stats'] ?? $info);
            $used = (int) ($memory['used_memory'] ?? 0);
            $max = (int) ($memory['maxmemory'] ?? 0);
            $redis = [
                'available' => true,
                'used_memory' => $used,
                'maxmemory' => $max,
                'used_ratio' => $max > 0 ? $used / $max : null,
                'evicted_keys' => (int) ($stats['evicted_keys'] ?? 0),
                'rejected_connections' => (int) ($stats['rejected_connections'] ?? 0),
            ];
            if (($redis['used_ratio'] ?? 0) > (float) ($config['redis_used_ratio'] ?? 0.70)) {
                $reasons[] = 'redis_memory_high';
            }
            if ($redis['evicted_keys'] > 0 || $redis['rejected_connections'] > 0) {
                $reasons[] = 'redis_eviction_or_rejection';
            }
        } catch (\Throwable $e) {
            $redis['error'] = get_class($e);
            $reasons[] = 'redis_unavailable';
        }

        $db = ['available' => false, 'threads_running' => null, 'latency_ms' => null];
        try {
            $started = microtime(true);
            DB::select('SELECT 1');
            $db['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
            $db['available'] = true;
            try {
                $status = collect(DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Threads_connected')"))
                    ->mapWithKeys(fn ($row) => [(string) $row->Variable_name => (int) $row->Value]);
                $db['threads_running'] = $status->get('Threads_running');
                $db['threads_connected'] = $status->get('Threads_connected');
            } catch (\Throwable) {
                // Non-MySQL test/disposable databases do not expose SHOW STATUS.
            }
        } catch (\Throwable $e) {
            $db['error'] = get_class($e);
            $reasons[] = 'db_unavailable';
        }

        return [
            'healthy' => $reasons === [],
            'reasons' => $reasons,
            'redis' => $redis,
            'db' => $db,
        ];
    }

    private function procMemAvailable(): ?int
    {
        $path = '/proc/meminfo';
        if (! is_readable($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $contents, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 1024;
    }

    /** @return array{active: bool, pswpin: ?int, pswpout: ?int} */
    private function swapActivity(): array
    {
        $contents = is_readable('/proc/vmstat') ? (string) @file_get_contents('/proc/vmstat') : '';
        $current = [];
        foreach (['pswpin', 'pswpout'] as $key) {
            if (preg_match('/^'.preg_quote($key, '/').'\s+(\d+)$/m', $contents, $match) === 1) {
                $current[$key] = (int) $match[1];
            }
        }
        $previous = (array) Cache::get(self::SWAP_SAMPLE_KEY, []);
        Cache::put(self::SWAP_SAMPLE_KEY, $current, now()->addMinutes(10));

        $active = false;
        foreach (['pswpin', 'pswpout'] as $key) {
            if (isset($current[$key], $previous[$key]) && $current[$key] > (int) $previous[$key]) {
                $active = true;
            }
        }

        return [
            'active' => $active,
            'pswpin' => $current['pswpin'] ?? null,
            'pswpout' => $current['pswpout'] ?? null,
        ];
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }
}
