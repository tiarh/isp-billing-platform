<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\LegacySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Frequent invoice-only pull from legacy eBilling scraper (auto update invoice).
 */
class SyncLegacyInvoices extends Command
{
    protected $signature = 'billing:sync-invoices-legacy
                            {--quiet-ok : Less chatter on success}';

    protected $description = 'Sync invoices only from legacy eBilling scraper (auto-update billing)';

    public function handle(LegacySyncService $sync): int
    {
        $this->info('Starting legacy invoice sync…');

        try {
            $count = $sync->syncInvoices();
            Setting::set(
                'billing_invoice_last_sync_at',
                now('Asia/Jakarta')->toDateTimeString(),
                'text',
                'billing',
                'Last legacy invoice sync (WIB)'
            );
            Setting::set(
                'billing_invoice_last_sync_count',
                (string) $count,
                'integer',
                'billing',
                'Last legacy invoice sync count'
            );

            $guard = $sync->lastInvoiceGuardStats();
            if (! $this->option('quiet-ok')) {
                $this->info("Invoices synced/updated: {$count}");
            }
            if ($guard !== []) {
                $this->warn('Skipped (eBilling-owned invoices): '.json_encode($guard));
            }
            Log::info('[billing:sync-invoices-legacy] ok', ['count' => $count, 'guard_skipped' => $guard]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Invoice sync failed: '.$e->getMessage());
            Log::error('[billing:sync-invoices-legacy] failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }
}
