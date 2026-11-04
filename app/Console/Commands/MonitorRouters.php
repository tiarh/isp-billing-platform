<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\RouterSyncService;
use Illuminate\Console\Command;

class MonitorRouters extends Command
{
    protected $signature = 'routers:monitor {router?}';

    protected $description = 'Check health and update online status for active routers';

    public function handle()
    {
        $routerId = $this->argument('router');
        $query = Router::where('is_active', true);

        if ($routerId) {
            $query->where('id', $routerId);
        }

        $routers = $query->get();

        if ($routers->isEmpty()) {
            $this->warn('No active routers found to monitor.');

            return;
        }

        $this->info('Monitoring '.$routers->count().' router(s)...');

        $sync = app(RouterSyncService::class);
        foreach ($routers as $router) {
            $this->info("Checking {$router->name} ({$router->ip_address})...");
            $r = $sync->syncLiveHealth($router, 10, true);
            if ($r['success']) {
                $this->info($r['message']);
            } else {
                $this->error($r['message']);
            }
        }

        $this->info('Monitoring completed.');
    }
}
