<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\LegacySyncService;
use Illuminate\Console\Command;

class SyncLegacyData extends Command
{
    protected $signature = 'sync:legacy
        {--force : Run even though legacy_full_sync_enabled is off}';

    protected $description = 'Sync data from the legacy eBilling scraper API';

    public function handle(LegacySyncService $syncService)
    {
        // Legacy is retired: the full sync (areas, packages, customers,
        // invoices) only runs when explicitly enabled via setting (default off).
        $enabled = filter_var(Setting::get('legacy_full_sync_enabled', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled && ! $this->option('force')) {
            $this->warn('sync:legacy is disabled (legacy_full_sync_enabled is off). Set it to 1 or pass --force.');

            return self::SUCCESS;
        }

        $this->info('🚀 Starting Legacy Data Sync...');

        try {
            $this->info('Fetching Area data...');
            $areas = $syncService->syncAreas();
            $this->info("✅ Areas Synced: {$areas}");

            $this->info('Fetching Package data...');
            $packages = $syncService->syncPackages();
            $this->info("✅ Packages Synced: {$packages}");
            $dedupe = $syncService->dedupePackages();
            $this->info("✅ Packages Dedupe: groups={$dedupe['groups']} merged_customers={$dedupe['merged']} deleted={$dedupe['deleted']}");

            $this->info('Fetching Customer data... (This may take a moment)');
            $customers = $syncService->syncCustomers();
            $this->info("✅ Customers Synced: {$customers}");
            $this->line('Area resolution: '.$this->formatAreaResolutionStats($syncService->lastCustomerAreaResolutionStats()));
            $this->line('Network classification: '.$this->formatAreaResolutionStats($syncService->lastCustomerNetworkSyncStats()));

            $suppressed = $syncService->lastTerminateSuppressedIds();
            if ($suppressed !== []) {
                $this->warn('legacy_terminate_suppressed='.count($suppressed).' (status kept, not terminated; customer ids: '.implode(',', array_slice($suppressed, 0, 50)).(count($suppressed) > 50 ? ',...' : '').')');
            }

            $deletedEmptyAreas = $syncService->cleanupEmptyAreas();
            if ($deletedEmptyAreas === []) {
                $this->info('✅ No empty areas deleted');
            } else {
                $this->info('✅ Empty Areas Deleted: '.count($deletedEmptyAreas));
                $this->line('Deleted: '.implode(', ', $deletedEmptyAreas));
            }

            $this->info('Fetching Invoice data... (This may take a moment)');
            $invoices = $syncService->syncInvoices();
            $this->info("✅ Invoices Synced: {$invoices}");

            $this->info('🎉 Legacy Sync Completed Successfully!');

        } catch (\Exception $e) {
            $this->error('❌ Sync Failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function formatAreaResolutionStats(array $stats): string
    {
        return collect($stats)
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $reason) => "{$reason}={$count}")
            ->implode(', ');
    }
}
