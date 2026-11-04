<?php

namespace App\Services\Ops;

use App\Models\Setting;
use App\Services\HostCpuProbe;
use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * I/O around VmAlertEvaluator for `ops:vm-alert`: reads /proc/stat, one DB
 * count and the GenieACS sync marker, keeps the state in ONE file-cache key and
 * sends at most one WA per run to the ops group (OpsAlertService).
 *
 * Never calls MonitoringAdmissionGate::evaluate() (it rewrites its own CPU
 * baseline); only the operator hold flag is read. No queue, no job dispatch.
 */
class VmAlertService
{
    public const SETTING_MODE = 'ops_vm_alert_mode';

    public const MODES = ['off', 'log', 'on'];

    public const STATE_KEY = 'ops_vm_alert:state';

    private const STATE_TTL_DAYS = 9;

    public function __construct(
        private HostCpuProbe $probe,
        private OpsAlertService $ops,
    ) {}

    /** Mode from the Setting; anything unknown or unreadable counts as off. */
    public static function currentMode(): string
    {
        try {
            $mode = strtolower(trim((string) Setting::get(self::SETTING_MODE, 'off')));
        } catch (\Throwable) {
            return 'off';
        }

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /**
     * One scheduled run (mode log or on): evaluate, send/log, save state.
     *
     * @return array{ok: bool, decisions: array<string, string>, sent: list<string>, error?: string}
     */
    public function run(string $mode): array
    {
        $evaluator = $this->evaluator();
        $now = now()->timestamp;

        $loaded = $this->loadState($evaluator, $now);
        if ($loaded === null) {
            Log::warning('[OpsVmAlert] state store unavailable, run skipped');

            return ['ok' => false, 'decisions' => [], 'sent' => [], 'error' => 'state store unavailable'];
        }

        $in = $this->inputs();
        $result = $evaluator->step($loaded, $now, $in, $mode);
        $state = $result['state'];
        $sent = [];

        foreach ($result['decisions'] as $type => $decision) {
            if ($decision === 'alert_suppressed') {
                Log::info('[OpsVmAlert] alert suppressed by dedupe', ['type' => $type]);
            }
        }

        $pending = $evaluator->pending($state);
        $alertTypes = array_keys($pending['alerts']);
        $recoverTypes = array_keys($pending['recoveries']);

        if ($alertTypes === [] && $recoverTypes === []) {
            $this->saveState($state);
        } else {
            // Mark as sent and save FIRST: if the store cannot be written nothing is sent (no repeat every
            // 10 minutes). When the send fails the evaluated state (still pending) is saved back.
            $marked = $evaluator->markRecoverySent($evaluator->markAlertSent($state, $alertTypes, $now, $mode), $recoverTypes);
            if (! $this->saveState($marked)) {
                Log::warning('[OpsVmAlert] state write failed, not sending', ['types' => $alertTypes]);
            } else {
                $labels = array_merge($alertTypes, array_map(static fn (string $t): string => $t.':pulih', $recoverTypes));
                $text = implode("\n\n", array_merge(array_values($pending['alerts']), array_values($pending['recoveries'])));
                if ($this->deliver($mode, $labels, $text)) {
                    $sent = $labels;
                    $state = $marked;
                } elseif (! $this->saveState($state)) {
                    Log::warning('[OpsVmAlert] could not restore pending state after failed send', ['types' => $alertTypes]);
                }
            }
        }

        Log::info('[OpsVmAlert] sample', [
            'mode' => $mode,
            'steal_pct' => $this->latestStealPct($state),
            'inform_count' => $in['inform_count'],
            'snapshot_age_min' => $in['snapshot_at'] === null ? null : (int) round(($now - $in['snapshot_at']) / 60),
            'normal' => ($normal = $evaluator->normal($state, $now)) === null ? null : round($normal['value']),
            'decisions' => $result['decisions'],
        ]);

        return ['ok' => true, 'decisions' => $result['decisions'], 'sent' => $sent];
    }

    /**
     * Read-only picture of what a run would do now. Writes nothing.
     *
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $evaluator = $this->evaluator();
        $now = now()->timestamp;
        $loaded = $this->loadState($evaluator, $now) ?? $evaluator->newState($now);
        $in = $this->inputs();

        $short = $this->shortStealSample();
        $result = $evaluator->step($loaded, $now, $in, 'on');
        $pending = $evaluator->pending($result['state']);
        $normal = $evaluator->normal($loaded, $now);

        $samples = [];
        if ($short !== null) {
            $samples['steal'] = $evaluator->text('steal', 'alert', (int) round($short));
        }
        if ($normal !== null && $in['inform_count'] !== null && $normal['value'] > 0) {
            $samples['inform'] = $evaluator->text('inform', 'alert', [
                'n' => $in['inform_count'],
                'm' => (int) round($normal['value']),
                'd' => max(0, (int) round((1 - $in['inform_count'] / $normal['value']) * 100)),
            ]);
        }
        $samples['stale'] = $evaluator->text('stale', 'alert', ['since' => $in['snapshot_at']]);

        return [
            'now' => $now,
            'inputs' => $in,
            'short_steal_pct' => $short,
            'ring_steal_pct' => $this->latestStealPct($loaded),
            'normal' => $normal,
            'decisions' => $result['decisions'],
            'would_send' => $pending,
            'samples' => $samples,
        ];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $evaluator = $this->evaluator();
        $state = $this->loadState($evaluator, now()->timestamp, true);

        return [
            'mode' => self::currentMode(),
            'state_found' => $state !== null,
            'state' => $state === null ? null : [
                'started_at' => $state['started_at'],
                'ring_entries' => count($state['ring']),
                'inform_raw_samples' => count($state['raw']),
                'inform_hour_buckets' => count($state['hours']),
                'steal_pct_last_slice' => $this->latestStealPct($state),
                'types' => $state['types'],
            ],
        ];
    }

    private function evaluator(): VmAlertEvaluator
    {
        return new VmAlertEvaluator((array) config('ops.vm_alert', []));
    }

    /**
     * @param  list<string>  $types
     */
    private function deliver(string $mode, array $types, string $text): bool
    {
        if ($mode !== 'on') {
            Log::info('[OpsVmAlert] would send', ['types' => $types, 'text' => $text]);

            return true;
        }

        // No dedupe key on purpose: OpsAlertService would claim it before the
        // send, and a failed send would then swallow the alert for an hour.
        if ($this->ops->send($text, '', 300, '', OpsAlertSource::VM_ALERT)) {
            return true;
        }
        Log::warning('[OpsVmAlert] WA send failed, retry next run', ['types' => $types]);

        return false;
    }

    /**
     * @return array{cpu: ?array{steal: int, total: int}, inform_count: ?int, snapshot_at: ?int, snapshot_known: bool, hold: bool}
     */
    private function inputs(): array
    {
        $counters = $this->probe->cpuCounters();
        $cpu = $counters === null ? null : ['steal' => $counters['steal'], 'total' => array_sum($counters)];

        $snapshotAt = null;
        $known = true;
        try {
            $last = Cache::get('monitoring.genieacs.last_sync');
            $finished = is_array($last) ? (string) ($last['finished_at'] ?? '') : '';
            $snapshotAt = $finished === '' ? null : (strtotime($finished) ?: null);
        } catch (\Throwable $e) {
            $known = false;
            Log::warning('[OpsVmAlert] last_sync read failed', ['exception_class' => $e::class]);
        }

        // last_inform_at is copied in at sync time, so the 30 minutes are counted back from the snapshot,
        // not from now: an older snapshot must not look like a modem drop.
        $anchor = $snapshotAt === null ? now()->timestamp : min(now()->timestamp, $snapshotAt);
        $count = null;
        try {
            $count = DB::table('genieacs_devices')
                ->where('last_inform_at', '>=', Carbon::createFromTimestamp($anchor, config('app.timezone'))->subMinutes((int) config('ops.vm_alert.inform_window_minutes', 30)))
                ->count();
        } catch (\Throwable $e) {
            Log::warning('[OpsVmAlert] inform count failed', ['exception_class' => $e::class]);
        }

        return [
            'cpu' => $cpu,
            'inform_count' => $count,
            'snapshot_at' => $snapshotAt,
            'snapshot_known' => $known,
            'hold' => (bool) config('services.monitoring.admission_gate.operator_hold', false),
        ];
    }

    /** Steal % over a few seconds in this process (dry-run only; not a 30 minute value). */
    private function shortStealSample(): ?float
    {
        $first = $this->probe->cpuCounters();
        if ($first === null) {
            return null;
        }
        $seconds = (int) config('ops.vm_alert.dry_run_sample_seconds', 3);
        if ($seconds > 0) {
            sleep($seconds);
        }
        $second = $this->probe->cpuCounters();
        if ($second === null) {
            return null;
        }
        $dTotal = array_sum($second) - array_sum($first);
        $dSteal = $second['steal'] - $first['steal'];

        return $dTotal > 0 && $dSteal >= 0 ? $dSteal / $dTotal * 100 : null;
    }

    /** @param  array<string, mixed>  $state */
    private function latestStealPct(array $state): ?float
    {
        $ring = array_values($state['ring']);
        $n = count($ring);
        if ($n < 2) {
            return null;
        }
        $dTotal = $ring[$n - 1][2] - $ring[$n - 2][2];
        $dSteal = $ring[$n - 1][1] - $ring[$n - 2][1];

        return $dTotal > 0 ? round($dSteal / $dTotal * 100, 1) : null;
    }

    /**
     * Stored state, a fresh one when none exists, null when the store fails.
     *
     * @return array<string, mixed>|null
     */
    private function loadState(VmAlertEvaluator $evaluator, int $now, bool $nullWhenMissing = false): ?array
    {
        try {
            $stored = Cache::store((string) config('ops.vm_alert.store', 'file'))->get(self::STATE_KEY);
        } catch (\Throwable $e) {
            Log::warning('[OpsVmAlert] state read failed', ['exception_class' => $e::class]);

            return null;
        }

        $state = $evaluator->sanitizeState($stored);
        if ($state !== null) {
            return $state;
        }

        return $nullWhenMissing ? null : $evaluator->newState($now);
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): bool
    {
        try {
            return (bool) Cache::store((string) config('ops.vm_alert.store', 'file'))
                ->put(self::STATE_KEY, $state, now()->addDays(self::STATE_TTL_DAYS));
        } catch (\Throwable $e) {
            Log::warning('[OpsVmAlert] state write failed', ['exception_class' => $e::class]);

            return false;
        }
    }
}
