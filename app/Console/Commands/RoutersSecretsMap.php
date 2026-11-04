<?php

namespace App\Console\Commands;

use App\Jobs\SyncRouterJob;
use App\Models\Router;
use App\Services\RouterSyncService;
use Illuminate\Console\Command;

/**
 * Plan B READ tier: queue fullSync (secrets map + health) for active routers.
 * Staggered dispatch so queue workers don't open 14 sockets at once.
 */
class RoutersSecretsMap extends Command
{
    protected $signature = 'routers:secrets-map
                            {--router_id= : Single router}
                            {--sync : Run inline fullSync instead of queue}
                            {--only-active : Only is_active=1 routers}
                            {--stagger_ms=1500 : Delay between job dispatches}';

    protected $description = 'READ tier: map PPP secrets (fullSync) for routers';

    public function handle(): int
    {
        $query = Router::query()->orderBy('id');
        if ($this->option('only-active')) {
            $query->where('is_active', true);
        }
        if ($this->option('router_id')) {
            $query->where('id', (int) $this->option('router_id'));
        }
        $routers = $query->get();
        if ($routers->isEmpty()) {
            $this->warn('No routers');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $svc = app(RouterSyncService::class);
            foreach ($routers as $router) {
                $this->info("fullSync {$router->name}...");
                $r = $svc->fullSync($router);
                $this->line(json_encode([
                    'router' => $router->name,
                    'success' => $r['success'] ?? false,
                    'secrets' => $r['scan']['total_secrets'] ?? null,
                    'online' => $r['online_count'] ?? null,
                    'ms' => $r['duration_ms'] ?? null,
                    'health_ok' => $r['health_ok'] ?? null,
                    'error' => $r['error'] ?? $r['health_error'] ?? null,
                ]));
            }

            return self::SUCCESS;
        }

        $stagger = max(0, (int) $this->option('stagger_ms'));
        $delayMs = 0;
        foreach ($routers as $router) {
            $router->update([
                'sync_status' => 'queued',
                'sync_message' => 'READ secrets map queued.',
                'sync_lock_until' => now()->addMinutes(5),
            ]);
            SyncRouterJob::dispatch($router->id)->delay(now()->addMilliseconds($delayMs));
            $this->line("queued {$router->name} (+{$delayMs}ms)");
            $delayMs += $stagger;
        }

        $this->info('Queued '.$routers->count().' fullSync jobs (stagger '.$stagger.'ms)');

        return self::SUCCESS;
    }
}
