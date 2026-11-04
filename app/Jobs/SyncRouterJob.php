<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\RouterMtSnapshot;
use App\Services\RouterSyncService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncRouterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Must stay under queue worker --timeout (router-sync: 300s supervisord / 660s prod).
     * Large MT (RA-OLT ~800 secrets) needs headroom beyond API socket budget.
     */
    public int $timeout = 280;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [30, 60];

    public function __construct(public int $routerId)
    {
        $this->onQueue('router-sync');
    }

    public function uniqueId(): string
    {
        return 'router-sync:'.$this->routerId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(15)
                ->expireAfter($this->uniqueFor),
        ];
    }

    public function handle(RouterSyncService $syncService): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        // Retired / inactive MT: never open socket (avoids "No route to host" failed_jobs).
        if (! $router->is_active) {
            $router->update([
                'sync_status' => 'idle',
                'sync_finished_at' => now(),
                'sync_lock_until' => null,
                'sync_message' => 'Skipped: router is inactive.',
            ]);

            return;
        }

        $router->update([
            'sync_status' => 'running',
            'sync_started_at' => now(),
            'sync_finished_at' => null,
            'sync_message' => 'Full sync is running.',
            'sync_lock_until' => now()->addMinutes(5),
        ]);

        try {
            $apiTimeout = $syncService->recommendedSyncTimeout($router);
            $result = $syncService->fullSync($router->fresh(), $apiTimeout);

            if (! ($result['success'] ?? false)) {
                throw new Exception($result['error'] ?? 'Unknown sync failure.');
            }

            $scan = $result['scan'] ?? [];
            $healthOk = (bool) ($result['health_ok'] ?? false);
            $online = $result['online_count'] ?? null;
            $msg = $this->successMessage($router, $scan);
            if (! $healthOk) {
                $msg .= ' · health partial (online count kept/not refreshed)';
                if (! empty($result['health_error'])) {
                    $msg .= ': '.$result['health_error'];
                }
            } elseif ($online !== null) {
                $msg .= " · online={$online}";
            }
            $router->fresh()->update([
                'sync_status' => $healthOk ? 'success' : 'success',
                'sync_finished_at' => now(),
                'sync_lock_until' => null,
                'sync_message' => $msg,
                'last_sync_stats' => array_merge(is_array($scan) ? $scan : [], [
                    'health_ok' => $healthOk,
                    'online_count' => $online,
                    'health_error' => $result['health_error'] ?? null,
                    'duration_ms' => $result['duration_ms'] ?? null,
                ]),
            ]);
        } catch (Exception $e) {
            Log::error("Queued full sync failed for router {$router->name}: {$e->getMessage()}");

            $msg = $e->getMessage();
            // Plan C: stream timeout while Online pulse is healthy → soft success (not permanent red badge)
            if ($this->isStreamTimeout($msg) && $this->hasRecentGoodPulse($router)) {
                $router->fresh()->update([
                    'sync_status' => 'success',
                    'sync_finished_at' => now(),
                    'sync_lock_until' => null,
                    'connection_status' => 'online',
                    'sync_message' => 'Online pulse OK; full secrets stream timed out (retry later).',
                ]);

                return; // do not rethrow — avoid infinite failed badge
            }

            $router->fresh()->update([
                'sync_status' => 'failed',
                'sync_finished_at' => now(),
                'sync_lock_until' => null,
                'sync_message' => "Sync failed: {$msg}",
            ]);

            // Permanent network / unreachable: mark failed on router, do not fill failed_jobs.
            if ($this->isUnreachableHost($msg)) {
                return;
            }

            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $message = $e?->getMessage() ?: 'Queue worker timeout atau job dibatalkan';

        if ($this->isStreamTimeout($message) && $this->hasRecentGoodPulse($router)) {
            $router->update([
                'sync_status' => 'success',
                'sync_finished_at' => now(),
                'sync_lock_until' => null,
                'connection_status' => 'online',
                'sync_message' => 'Online pulse OK; full secrets timed out (retry later).',
            ]);

            return;
        }

        $router->update([
            'sync_status' => 'failed',
            'sync_finished_at' => now(),
            'sync_lock_until' => null,
            'sync_message' => str_starts_with($message, 'Sync failed:') ? $message : "Sync failed: {$message}",
        ]);
    }

    private function isStreamTimeout(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'stream timed out')
            || str_contains($m, 'timed out')
            || str_contains($m, 'connection timed out');
    }

    /** Host down, retired path, or API port not reachable — not worth queue retries. */
    private function isUnreachableHost(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'no route to host')
            || str_contains($m, 'network is unreachable')
            || str_contains($m, 'connection refused')
            || str_contains($m, 'name or service not known')
            || str_contains($m, 'could not resolve host')
            || str_contains($m, 'no such host')
            || str_contains($m, 'host is unreachable');
    }

    private function hasRecentGoodPulse(Router $router): bool
    {
        if (! Schema::hasTable('router_mt_snapshots')) {
            return (int) ($router->current_online_count ?? 0) > 0;
        }

        return RouterMtSnapshot::query()
            ->where('router_id', $router->id)
            ->where('quality', 'good')
            ->where('collected_at', '>=', now()->subMinutes(15))
            ->exists();
    }

    private function successMessage(Router $router, array $scan): string
    {
        return sprintf(
            '%s: synced. %d mapped, %d router-only staged, %d eBilling missing.',
            $router->name,
            $scan['mapped'] ?? 0,
            $scan['staged_router_only'] ?? ($scan['unmatched_mikrotik'] ?? 0),
            $scan['not_found_ebilling'] ?? 0,
        );
    }
}
