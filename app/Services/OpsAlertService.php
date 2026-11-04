<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\OpsAlertSource;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Lightweight, deduplicated ops alerts. Messages must not contain secrets.
 */
class OpsAlertService
{
    public const SETTING_SOURCES_ENABLED = 'ops_alert_sources_enabled';

    private const SUPPRESSED_COUNTER_PREFIX = 'ops_alert_suppressed_count:';

    private const FAILED_ACK_ID = 'ops_failed_jobs_ack_id';

    private const FAILED_ACK_AT = 'ops_failed_jobs_ack_at';

    private const FAILED_LAST_ALERTED_ID = 'ops_failed_jobs_last_alerted_id';

    public function __construct(private EvolutionApiService $evolution) {}

    /**
     * True when an alert of this source would be delivered. send() itself also
     * reaches the "suppressed" log for sources that are off, so callers need not
     * (and do not) check this before sending.
     */
    public function isConfigured(string $source = OpsAlertSource::GENERAL): bool
    {
        if (! $this->sourceAllowed($source)) {
            return false;
        }

        return $this->opsGroupId() !== ''
            && $this->opsChannel()->isConfigured();
    }

    private function envEnabled(): bool
    {
        return (bool) config('services.evolution_api.ops_alerts_enabled', true);
    }

    /**
     * Env flag true: every source is allowed (unchanged behaviour). Env flag
     * false: only sources listed in Setting ops_alert_sources_enabled.
     */
    private function sourceAllowed(string $source): bool
    {
        return $this->envEnabled() || in_array($this->normalizeSource($source), $this->enabledSources(), true);
    }

    /**
     * Comma list from Setting ops_alert_sources_enabled, trimmed and lowercased.
     * Empty = none. "all" is not a wildcard: sources must be named.
     *
     * @return list<string>
     */
    public function enabledSources(): array
    {
        try {
            $raw = (string) Setting::get(self::SETTING_SOURCES_ENABLED, '');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = strtolower(trim($part));
            if ($part !== '') {
                $out[$part] = $part;
            }
        }

        return array_values($out);
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));

        return $source === '' ? OpsAlertSource::GENERAL : $source;
    }

    /**
     * Env flag off and source not enabled: nothing is sent. Log one INFO line
     * per (source, dedupe key) per dedupe window and bump a daily counter per
     * source. The message text is never logged (it may hold customer data).
     */
    private function recordSuppressed(string $source, string $message, string $dedupeKey, int $dedupeSeconds, string $dedupeStore): void
    {
        try {
            $hash = substr(md5($dedupeKey !== '' ? $dedupeKey : 'no-key'), 0, 8);
            $claim = 'ops_alert_suppressed:'.$source.':'.$hash;
            // A failed-job batch (key = max failed id) stays unalerted while the alert is
            // held back, so count it once, not on every sweep.
            $window = $source === OpsAlertSource::FAILED_JOBS ? max($dedupeSeconds, 7 * 86400) : max(60, $dedupeSeconds);
            if (! $this->claimDedupe($claim, $window, $dedupeStore)) {
                return;
            }

            $counter = self::SUPPRESSED_COUNTER_PREFIX.$source.':'.OpsTime::now()->format('Ymd');
            try {
                Cache::add($counter, 0, now()->addDays(9));
                Cache::increment($counter);
            } catch (\Throwable) {
                // counter is best effort
            }

            Log::info('[OpsAlert] suppressed', [
                'source' => $source,
                'dedupe' => $hash,
                'message_length' => mb_strlen($message),
            ]);
        } catch (\Throwable) {
            // never break the caller because of the suppressed log
        }
    }

    /**
     * Suppressed (would-have-been-sent) alerts per source for the last $days
     * days including today. Read-only.
     *
     * @return array<string, int>
     */
    public function suppressedCounts(int $days): array
    {
        $counts = [];
        $sources = array_values(array_unique(array_merge(OpsAlertSource::all(), $this->enabledSources())));
        foreach ($sources as $source) {
            $total = 0;
            for ($i = 0; $i < $days; $i++) {
                try {
                    $total += (int) Cache::get(self::SUPPRESSED_COUNTER_PREFIX.$source.':'.OpsTime::now()->subDays($i)->format('Ymd'), 0);
                } catch (\Throwable) {
                    // unreadable counter counts as 0
                }
            }
            $counts[$source] = $total;
        }

        return $counts;
    }

    public function envFlagEnabled(): bool
    {
        return $this->envEnabled();
    }

    /**
     * forChannel() reads the instance name through Setting (cache-backed). When
     * the cache store (Redis) is down that throws, which must not stop the
     * Redis-down alert itself: fall back to the configured instance name.
     */
    private function opsChannel(): EvolutionApiService
    {
        try {
            return $this->evolution->forChannel('ops');
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] ops channel lookup failed, using configured instance', [
                'exception_class' => $e::class,
            ]);

            return $this->evolution->forInstance((string) config('services.evolution_api.instance_ops', 'skynet-ebilling'));
        }
    }

    /**
     * @param  string  $dedupeStore  Cache store holding the dedupe/cooldown key. Empty
     *                               = default store. The Redis-down alert passes
     *                               'file' because the default store is Redis and
     *                               cannot record the cooldown while Redis is down.
     */
    public function send(string $message, string $dedupeKey = '', int $dedupeSeconds = 300, string $dedupeStore = '', string $source = OpsAlertSource::GENERAL): bool
    {
        $source = $this->normalizeSource($source);
        if (! $this->sourceAllowed($source)) {
            $this->recordSuppressed($source, $message, $dedupeKey, $dedupeSeconds, $dedupeStore);

            return false;
        }

        if (! $this->isConfigured($source)) {
            return false;
        }

        $message = $this->sanitize($message);
        if ($message === '') {
            return false;
        }

        if ($dedupeKey !== '') {
            $cacheKey = 'ops_alert:'.md5($dedupeKey);
            if (! $this->claimDedupe($cacheKey, $dedupeSeconds, $dedupeStore)) {
                return false;
            }
        }

        try {
            $response = $this->opsChannel()
                ->sendToGroup($this->opsGroupId(), mb_substr($message, 0, 3500));
            if (! ($response['success'] ?? false)) {
                Log::warning('[OpsAlert] Evolution API failed', [
                    'error' => $response['error'] ?? 'unknown',
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] provider failure', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Atomically claim a dedupe/cooldown slot. A failing cache store (Redis
     * down) must never crash the alert path: fall back to the file store so the
     * cooldown is still recorded, and if even that fails, allow the send.
     */
    private function claimDedupe(string $cacheKey, int $seconds, string $store): bool
    {
        $expires = now()->addSeconds($seconds);

        try {
            return $store === ''
                ? Cache::add($cacheKey, 1, $expires)
                : Cache::store($store)->add($cacheKey, 1, $expires);
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] dedupe store unavailable, using file store', [
                'exception_class' => $e::class,
            ]);
        }

        try {
            return Cache::store('file')->add($cacheKey, 1, $expires);
        } catch (\Throwable) {
            return true;
        }
    }

    public function reportException(\Throwable $e): void
    {
        // Exception details must never be broadcast to a WhatsApp group.
        // Laravel logging and failed_jobs remain the internal source of truth.
    }

    /**
     * @return array<string, mixed>
     */
    public function runHealthSweep(bool $notify = true): array
    {
        $report = [
            'failed_jobs' => 0,
            'failed_jobs_historical' => 0,
            'failed_jobs_new' => 0,
            'failed_jobs_unalerted' => 0,
            'failed_jobs_ack_id' => 0,
            'alerts' => [],
            'ok' => true,
        ];

        try {
            $failed = (int) DB::table('failed_jobs')->count();
            $maxId = (int) (DB::table('failed_jobs')->max('id') ?? 0);
            $ackId = max(0, (int) Setting::get(self::FAILED_ACK_ID, 0));
            $lastAlertedId = max($ackId, (int) Setting::get(self::FAILED_LAST_ALERTED_ID, $ackId));
            $newFailed = $ackId > 0
                ? (int) DB::table('failed_jobs')->where('id', '>', $ackId)->count()
                : $failed;
            $unalerted = $lastAlertedId > 0
                ? (int) DB::table('failed_jobs')->where('id', '>', $lastAlertedId)->count()
                : $failed;

            $report['failed_jobs'] = $failed;
            $report['failed_jobs_historical'] = max(0, $failed - $newFailed);
            $report['failed_jobs_new'] = $newFailed;
            $report['failed_jobs_unalerted'] = $unalerted;
            $report['failed_jobs_ack_id'] = $ackId;
            $report['failed_jobs_max_id'] = $maxId;

            $threshold = (int) config('services.evolution_api.ops_failed_jobs_threshold', 1);
            if ($newFailed >= $threshold) {
                $report['ok'] = false;
                $report['alerts'][] = "failed_jobs_new={$newFailed}";
            }

            // PR-ALERT (audit O1): broadcast COUNTS per job class for failures
            // not yet alerted. Exception text and payloads are never sent.
            $report['failed_jobs_alert_sent'] = false;
            if ($notify && $unalerted > 0 && $newFailed >= $threshold && $this->failedJobAlertsEnabled()) {
                $report['failed_jobs_alert_sent'] = $this->alertFailedJobCounts($lastAlertedId, $maxId);
            }
        } catch (\Throwable $e) {
            $report['alerts'][] = 'failed_jobs_query_error';
            Log::warning('[OpsAlert] failed_jobs: '.$e->getMessage());
        }

        // Every queue in config/ops.php (the ones VM102 workers consume). A
        // Redis error is an alert of its own, never read as "length 0".
        if (config('queue.default') === 'redis') {
            $depths = $this->queueDepths();
            if ($depths['redis_error']) {
                $report['ok'] = false;
                $report['redis_error'] = true;
                $report['alerts'][] = 'redis_error';
                if ($notify) {
                    $this->send(
                        "eBilling ALERT: Redis tidak bisa diakses dari app (queue depth tidak terbaca).\n"
                        .'Worker queue (isolir/reconnect, WA, billing) kemungkinan berhenti. Cek container redis & worker.',
                        'redis_error',
                        $this->queueAlertCooldown('redis_alert_cooldown_seconds'),
                        'file',
                        OpsAlertSource::REDIS_DOWN
                    );
                }
            }

            $thresholds = $this->queueDepthThresholds();
            $over = [];
            foreach ($depths['lengths'] as $queue => $len) {
                $report["queue_{$queue}_len"] = $len;
                $threshold = $thresholds[$queue] ?? PHP_INT_MAX;
                if ($len <= $threshold) {
                    continue;
                }
                $report['ok'] = false;
                $report['alerts'][] = "queue_{$queue}_len={$len}";
                $over[$queue] = [$len, $threshold];
            }

            // One message per sweep, cooled down per set of affected queues
            // (not per length, which changes every run and would spam the group).
            if ($notify && $over !== []) {
                $lines = [];
                foreach ($over as $queue => [$len, $threshold]) {
                    $lines[] = "- {$queue}: {$len} (batas {$threshold})";
                }
                $names = array_keys($over);
                sort($names);
                $this->send(
                    "eBilling ops: antrean Redis menumpuk.\n".implode("\n", $lines),
                    'queue_depth:'.implode(',', $names),
                    $this->queueAlertCooldown('alert_cooldown_seconds'),
                    '',
                    OpsAlertSource::QUEUE_DEPTH
                );
            }
            // Skipped when Redis is unreachable: that has its own alert and the heartbeat lives in cache.
            if (! $depths['redis_error']) {
                $this->checkEnforceWorker($report, $notify);
            }
        }

        return $report;
    }

    /**
     * worker-enforce-102 heartbeat. A missing or old key means the dedicated
     * isolir / buka isolir worker is down; worker-ops still drains the queue.
     *
     * @param  array<string, mixed>  $report
     */
    private function checkEnforceWorker(array &$report, bool $notify): void
    {
        $report['enforce_worker_heartbeat_age'] = null;
        if (! (bool) config('ops.enforce_worker_alert', true)) {
            return;
        }

        $key = trim((string) config('services.monitoring.admission_gate.worker_heartbeat_keys.enforce', ''));
        if ($key === '') {
            return;
        }

        $maxAge = max(300, (int) config('ops.enforce_worker_max_age_seconds', 900));
        $age = null;
        try {
            $value = Cache::get($key);
            if (is_string($value) && $value !== '') {
                $age = max(0, now()->timestamp - Carbon::parse($value)->timestamp);
            }
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] enforce heartbeat unreadable', ['exception_class' => $e::class]);

            return;
        }

        $report['enforce_worker_heartbeat_age'] = $age;
        $markerKey = 'ops.enforce_worker_missing_since';
        try {
            if ($age !== null && $age <= $maxAge) {
                Cache::forget($markerKey);

                return;
            }

            if ($age === null) {
                // Missing key (first deploy, recreate): alert only once it has been missing for $maxAge.
                Cache::add($markerKey, now()->timestamp, now()->addDay());
                $since = (int) Cache::get($markerKey, now()->timestamp);
                $report['enforce_worker_missing_seconds'] = max(0, now()->timestamp - $since);
                if ($report['enforce_worker_missing_seconds'] < $maxAge) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] enforce marker unavailable', ['exception_class' => $e::class]);

            return;
        }

        $report['ok'] = false;
        $report['alerts'][] = $age === null ? 'enforce_worker_missing' : "enforce_worker_age={$age}";
        if ($notify) {
            $this->send(
                "eBilling ops: worker isolir/buka isolir mati.\n"
                .'Buka isolir tetap jalan lewat worker ops, tapi bisa lambat.',
                'ops_enforce_worker_dead',
                1800,
                '',
                OpsAlertSource::ENFORCE_HEARTBEAT
            );
        }
    }

    /**
     * Monitored queues and their alert thresholds (config `ops.queue_depth`).
     *
     * @return array<string, int>
     */
    public function queueDepthThresholds(): array
    {
        $thresholds = [];
        foreach ((array) config('ops.queue_depth.queues', []) as $queue => $threshold) {
            $name = preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) $queue) ?? '';
            if ($name === '' || (int) $threshold < 0) {
                continue;
            }
            $thresholds[$name] = (int) $threshold;
        }

        return $thresholds;
    }

    /**
     * Pending length per monitored queue. On any Redis failure the lengths
     * read so far are returned with `redis_error = true`; only the exception
     * class name is logged.
     *
     * @return array{lengths: array<string, int>, redis_error: bool}
     */
    public function queueDepths(): array
    {
        $lengths = [];

        try {
            $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
            foreach (array_keys($this->queueDepthThresholds()) as $queue) {
                $lengths[$queue] = (int) $redis->llen('queues:'.$queue);
            }
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] Redis queue depth unavailable', ['exception_class' => $e::class]);

            return ['lengths' => $lengths, 'redis_error' => true];
        }

        return ['lengths' => $lengths, 'redis_error' => false];
    }

    private function queueAlertCooldown(string $key): int
    {
        return max(60, (int) config('ops.queue_depth.'.$key, 1800));
    }

    /**
     * Immediate alert when an enforcement job (isolate/reconnect) fails for
     * good. Only the job class and queue name are sent, never the exception.
     */
    public function reportImmediateJobFailure(string $jobName, ?string $queue = null): bool
    {
        if (! $this->failedJobAlertsEnabled()) {
            return false;
        }

        $immediate = array_map('strval', (array) config('ops.failed_job_alerts.immediate_jobs', []));
        if (! in_array($jobName, $immediate, true)) {
            return false;
        }

        $class = $this->jobBasename($jobName);
        $queueName = preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) $queue) ?: 'unknown';

        return $this->send(
            "eBilling ALERT: {$class} gagal permanen (queue {$queueName}).\n"
            .'Cek ledger isolir/reconnect dan failed_jobs di server. Detail error sengaja tidak dikirim ke grup.',
            'failed_job_immediate:'.$class,
            (int) config('ops.failed_job_alerts.immediate_dedupe_seconds', 300),
            '',
            OpsAlertSource::FAILED_JOB_IMMEDIATE
        );
    }

    public function schedulerDeadManConfigured(): bool
    {
        return $this->schedulerDeadManUrl() !== '';
    }

    /**
     * Ping the external dead-man URL at most once per configured interval.
     * Called every minute by the scheduler; failures are logged without the
     * URL (it embeds the check's secret UUID).
     */
    public function pingSchedulerDeadMan(): bool
    {
        $url = $this->schedulerDeadManUrl();
        if ($url === '') {
            return false;
        }

        $interval = (int) config('ops.dead_man.ping_interval_seconds', 300);
        if (! Cache::add('ops_dead_man:scheduler', 1, now()->addSeconds(max(50, $interval - 10)))) {
            return false;
        }

        try {
            $response = Http::timeout((int) config('ops.dead_man.timeout_seconds', 5))
                ->connectTimeout(3)
                ->get($url);
            if (! $response->successful()) {
                Log::warning('[OpsAlert] scheduler dead-man ping rejected', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[OpsAlert] scheduler dead-man ping failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * @return array{total: int, max_id: int, newest: mixed}
     */
    public function failedJobCheckpoint(): array
    {
        return [
            'total' => (int) DB::table('failed_jobs')->count(),
            'max_id' => (int) (DB::table('failed_jobs')->max('id') ?? 0),
            'newest' => DB::table('failed_jobs')->max('failed_at'),
        ];
    }

    /**
     * Explicit operator action. No failed job is retried or deleted.
     *
     * @return array{total: int, max_id: int, newest: mixed}
     */
    public function acknowledgeExistingFailedJobs(): array
    {
        $checkpoint = $this->failedJobCheckpoint();
        Setting::set(self::FAILED_ACK_ID, $checkpoint['max_id'], 'integer', 'ops', 'Acknowledged failed job ID');
        Setting::set(self::FAILED_LAST_ALERTED_ID, $checkpoint['max_id'], 'integer', 'ops', 'Last failed job ID alerted');
        Setting::set(self::FAILED_ACK_AT, now()->toISOString(), 'text', 'ops', 'Failed jobs acknowledged at');

        return $checkpoint;
    }

    private function failedJobAlertsEnabled(): bool
    {
        return (bool) config('ops.failed_job_alerts.enabled', true);
    }

    /**
     * Send "N failed jobs: Class=count" for ids in (afterId, throughId] and
     * advance the alerted checkpoint only when the message was delivered, so
     * a WhatsApp outage does not silently swallow failures.
     */
    private function alertFailedJobCounts(int $afterId, int $throughId): bool
    {
        if ($throughId <= $afterId) {
            return false;
        }

        $base = DB::table('failed_jobs')->where('id', '>', $afterId)->where('id', '<=', $throughId);
        $total = (int) (clone $base)->count();
        if ($total === 0) {
            return false;
        }

        $counts = [];
        $rows = (clone $base)
            ->orderBy('id')
            ->limit((int) config('ops.failed_job_alerts.max_rows_scanned', 2000))
            ->get(['payload']);
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $name = is_array($payload) ? (string) ($payload['displayName'] ?? '') : '';
            $class = $this->jobBasename($name);
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        arsort($counts);

        $maxClasses = (int) config('ops.failed_job_alerts.max_classes_listed', 10);
        $lines = [];
        foreach (array_slice($counts, 0, $maxClasses, true) as $class => $count) {
            $lines[] = "- {$class}: {$count}";
        }
        $listed = array_sum(array_slice($counts, 0, $maxClasses, true));
        if ($total > $listed) {
            $lines[] = '- lainnya: '.($total - $listed);
        }

        $sent = $this->send(
            "eBilling ops: {$total} failed job baru.\n".implode("\n", $lines)
            ."\nHanya hitungan; detail ada di failed_jobs/log server.",
            'failed_jobs_counts:'.$throughId,
            900,
            '',
            OpsAlertSource::FAILED_JOBS
        );

        if ($sent) {
            Setting::set(self::FAILED_LAST_ALERTED_ID, $throughId, 'integer', 'ops', 'Last failed job ID alerted');
        }

        return $sent;
    }

    private function jobBasename(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_\\\\]+$/', $name)) {
            return 'unknown';
        }

        return class_basename($name);
    }

    private function schedulerDeadManUrl(): string
    {
        $url = trim((string) config('ops.dead_man.scheduler_url', ''));
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function sanitize(string $message): string
    {
        $message = SensitiveDataRedactor::redact($message);
        $message = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/password[=:]\s*\S+/i', 'password=[redacted]', $message) ?? $message;
        $message = preg_replace('/api[_-]?key[=:]\s*\S+/i', 'api_key=[redacted]', $message) ?? $message;

        return trim($message);
    }

    private function opsGroupId(): string
    {
        $fallback = config('services.evolution_api.ops_group_id', '');

        try {
            return trim((string) Setting::get('wa_report_group_jid', $fallback));
        } catch (\Throwable $e) {
            // Setting is cache-backed; with Redis down use the configured group.
            Log::warning('[OpsAlert] ops group lookup failed, using config', [
                'exception_class' => $e::class,
            ]);

            return trim((string) $fallback);
        }
    }
}
