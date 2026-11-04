<?php

namespace App\Console\Commands;

use App\Jobs\RunMonitoringCollectorJob;
use App\Models\Olt;
use App\Models\Router;
use App\Services\MonitoringAdmissionGate;
use App\Services\MonitoringQueueContract;
use Illuminate\Console\Command;

/**
 * Dispatches only the fixed monitoring collector contract. It is inert until
 * decomposition is enabled and every target consumer is present.
 */
class DispatchMonitoringLanes extends Command
{
    protected $signature = 'monitoring:dispatch-lanes
        {--router-limit=25 : Maximum routers per cycle}
        {--olt-limit=25 : Maximum OLTs per cycle}';

    protected $description = 'Queue bounded monitoring router/OLT/ACS collectors on isolated lanes';

    public function handle(MonitoringQueueContract $contract, MonitoringAdmissionGate $gate): int
    {
        if (! $gate->allows('dispatch_lanes')) {
            $this->info('Monitoring admission gate merah; no collector dispatched.');

            return self::SUCCESS;
        }

        if (! $contract->decompositionEnabled()) {
            $this->info('Monitoring queue decomposition OFF; no collector dispatched.');

            return self::SUCCESS;
        }

        foreach (['router', 'olt', 'acs', 'ping', 'evaluate'] as $lane) {
            if ($contract->queueFor($lane) === MonitoringQueueContract::FALLBACK_QUEUE) {
                $this->warn('Target consumer belum lengkap; no monitoring collector dispatched.');

                return self::SUCCESS;
            }
        }

        $routerLimit = max(1, min(25, (int) $this->option('router-limit')));
        $oltLimit = max(1, min(25, (int) $this->option('olt-limit')));
        $dispatched = ['router' => 0, 'olt' => 0, 'acs' => 0];

        Router::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit($routerLimit)
            ->pluck('id')
            ->each(function (int $routerId) use (&$dispatched): void {
                RunMonitoringCollectorJob::dispatch(
                    'router',
                    'routers:live-health',
                    [
                        '--router_id' => $routerId,
                        '--timeout' => 10,
                        '--no-customer-flags' => true,
                    ],
                    'router-'.$routerId,
                );
                $dispatched['router']++;
            });

        Olt::query()
            ->whereNotNull('management_ip')
            ->orderBy('id')
            ->limit($oltLimit)
            ->pluck('id')
            ->each(function (int $oltId) use (&$dispatched): void {
                RunMonitoringCollectorJob::dispatch(
                    'olt',
                    'olt:live-sync',
                    ['--olt_id' => $oltId],
                    'olt-'.$oltId,
                );
                $dispatched['olt']++;
            });

        RunMonitoringCollectorJob::dispatch('acs', 'genieacs:sync', [], 'genieacs');
        $dispatched['acs'] = 1;

        $this->line(sprintf(
            'Queued monitoring collectors: router=%d olt=%d acs=%d.',
            $dispatched['router'],
            $dispatched['olt'],
            $dispatched['acs'],
        ));

        return self::SUCCESS;
    }
}
