<?php

namespace App\Http\Controllers;

use App\Services\OpsAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * GET /health/deep. Unlike `/up` (process alive) this checks the dependencies
 * billing needs: DB, Redis, scheduler heartbeat and queue backlog. Access: a
 * logged-in global admin, or the X-Health-Token header matching
 * HEALTH_DEEP_TOKEN (header only, so it never lands in access logs). Everyone
 * else gets 404. Returns only flags and numbers.
 *
 * 200 + status=ok        everything healthy
 * 200 + status=degraded  queue backlog above threshold (still serving)
 * 503 + status=fail      DB, Redis or scheduler heartbeat failing
 */
class HealthDeepController extends Controller
{
    public function __invoke(Request $request, OpsAlertService $ops): JsonResponse
    {
        if (! $this->authorized($request)) {
            abort(404);
        }

        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'scheduler' => $this->scheduler(),
        ];
        $checks['queues'] = $this->queues($ops, $checks['redis']['ok'] ?? false);

        $failed = ! $checks['database']['ok']
            || ! $checks['scheduler']['ok']
            || ! $checks['redis']['ok'];

        $status = $failed ? 'fail' : ($checks['queues']['ok'] ? 'ok' : 'degraded');

        return response()->json([
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $failed ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    private function authorized(Request $request): bool
    {
        $token = (string) config('ops.health.token', '');
        $given = (string) $request->header('X-Health-Token', '');
        if ($token !== '' && $given !== '' && hash_equals($token, $given)) {
            return true;
        }

        return (bool) $request->user()?->isGlobalAdmin();
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $started = microtime(true);
        try {
            DB::select('select 1');

            return ['ok' => true, 'ms' => $this->ms($started)];
        } catch (\Throwable $e) {
            Log::warning('[HealthDeep] database check failed', ['exception_class' => $e::class]);

            return ['ok' => false, 'ms' => $this->ms($started)];
        }
    }

    /** @return array<string, mixed> */
    private function redis(): array
    {
        $started = microtime(true);
        try {
            Redis::connection((string) config('queue.connections.redis.connection', 'default'))->ping();

            return ['ok' => true, 'ms' => $this->ms($started)];
        } catch (\Throwable $e) {
            Log::warning('[HealthDeep] redis check failed', ['exception_class' => $e::class]);

            return ['ok' => false, 'ms' => $this->ms($started)];
        }
    }

    /**
     * Reads the heartbeat row directly (not through the Setting cache) so a
     * stale cache cannot hide a dead scheduler.
     *
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $maxAge = (int) config('ops.health.scheduler_max_age_seconds', 300);
        try {
            $raw = DB::table('settings')->where('key', 'scheduler_last_run_at')->value('value');
            if (! is_string($raw) || trim($raw) === '') {
                return ['ok' => false, 'age_seconds' => null, 'max_age_seconds' => $maxAge];
            }
            $age = max(0, (int) CarbonImmutable::parse($raw)->diffInSeconds(now(), true));

            return ['ok' => $age <= $maxAge, 'age_seconds' => $age, 'max_age_seconds' => $maxAge];
        } catch (\Throwable $e) {
            Log::warning('[HealthDeep] scheduler check failed', ['exception_class' => $e::class]);

            return ['ok' => false, 'age_seconds' => null, 'max_age_seconds' => $maxAge];
        }
    }

    /** @return array<string, mixed> */
    private function queues(OpsAlertService $ops, bool $redisOk): array
    {
        if (! $redisOk) {
            return ['ok' => false, 'redis_error' => true, 'over_threshold' => []];
        }

        $depths = $ops->queueDepths();
        $thresholds = $ops->queueDepthThresholds();
        $over = [];
        foreach ($depths['lengths'] as $queue => $len) {
            if ($len > ($thresholds[$queue] ?? PHP_INT_MAX)) {
                $over[] = $queue;
            }
        }

        return [
            'ok' => ! $depths['redis_error'] && $over === [],
            'redis_error' => $depths['redis_error'],
            'lengths' => $depths['lengths'],
            'over_threshold' => $over,
        ];
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
