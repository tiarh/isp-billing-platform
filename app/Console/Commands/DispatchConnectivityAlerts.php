<?php

namespace App\Console\Commands;

use App\Models\MonitoringAlertEvent;
use App\Services\ConnectivityAlertDispatcher;
use App\Services\ConnectivityAlertSettings;
use App\Services\EvolutionApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DispatchConnectivityAlerts extends Command
{
    protected $signature = 'connectivity:dispatch-alerts {--dry-run : Log candidates without sending WhatsApp}';

    protected $description = 'Dispatch connectivity alerts per customer and per ODP via Evolution API';

    public function handle(
        EvolutionApiService $evolution,
        ConnectivityAlertSettings $settings,
        ConnectivityAlertDispatcher $dispatcher,
    ): int {
        if (! Schema::hasTable('monitoring_alert_events')) {
            $this->warn('monitoring_alert_events table missing; run migrations before enabling connectivity alerts.');

            return self::SUCCESS;
        }

        if (! $settings->enabled()) {
            $this->info('Connectivity alerts disabled in settings.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run') || $settings->dryRun();
        $pendingMinutes = $settings->pendingMinutes();
        $repeatMinutes = $settings->repeatMinutes();
        $now = now();

        $events = MonitoringAlertEvent::query()
            ->where('source', 'connectivity_scan')
            ->where('status', 'active')
            ->where(function ($query) use ($now, $repeatMinutes) {
                $query->whereNull('last_sent_at')
                    ->orWhere('last_sent_at', '<=', $now->copy()->subMinutes($repeatMinutes));
            })
            ->where('first_seen_at', '<=', $now->copy()->subMinutes($pendingMinutes))
            ->orderBy('first_seen_at')
            ->get();

        if ($events->isEmpty()) {
            $this->info('No connectivity alert events ready.');
        } else {
            $stats = $dispatcher->dispatch($events, $evolution, $dryRun);

            $this->table(
                ['Metric', 'Count'],
                collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all()
            );

            $this->info(sprintf(
                'Ready events: %d · customer WA: %d · ODP WA: %d%s',
                $events->count(),
                $stats['customer_sent'],
                $stats['odp_sent'],
                $dryRun ? ' (dry-run)' : ''
            ));
        }

        // Dispatch recovery notifications for events recovered in last 30 minutes
        $recovery = $dispatcher->dispatchRecovery($evolution, $dryRun);

        if ($recovery['recovery_sent'] > 0 || $recovery['recovery_skipped'] > 0 || $recovery['failed'] > 0) {
            $this->info(sprintf(
                'Recovery WA: %d sent · %d skipped · %d failed%s',
                $recovery['recovery_sent'],
                $recovery['recovery_skipped'],
                $recovery['failed'],
                $dryRun ? ' (dry-run)' : ''
            ));
        }

        return self::SUCCESS;
    }
}
