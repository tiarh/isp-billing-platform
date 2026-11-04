<?php

namespace App\Console\Commands;

use App\Services\Outage\OnuRxDailyArchiver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily RX archive: copies outage_onu_tracker.rx_daily (8 days) into onu_rx_daily (kept 400 days).
 * DB only, no OLT access. Safe to run any time and as often as needed; the first run is the backfill.
 */
class OutageRxArchive extends Command
{
    protected $signature = 'outage:rx-archive {--dry-run : Only count}';

    protected $description = 'Archive per-ONU daily RX from the outage tracker into onu_rx_daily (DB only)';

    public function handle(OnuRxDailyArchiver $archiver): int
    {
        $stats = $archiver->run((bool) $this->option('dry-run'));
        $this->info(sprintf('trackers=%d rows=%d with_range=%d%s', $stats['trackers'], $stats['rows'], $stats['with_range'], $this->option('dry-run') ? ' (dry run)' : ''));
        if (! $this->option('dry-run')) {
            Log::info('outage:rx-archive executed', $stats);
        }

        return self::SUCCESS;
    }
}
