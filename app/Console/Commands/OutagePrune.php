<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention for outage_signals (roadmap 2.4). Dry-run unless --execute. NOT scheduled yet.
 * Only closed episodes (resolved/expired) older than the window are removed; open/confirmed
 * rows are never touched. outage_onu_tracker is a live cache and is not pruned here.
 */
class OutagePrune extends Command
{
    protected $signature = 'outage:prune {--days=180 : Keep this many days} {--execute : Actually delete (default is dry-run)}';

    protected $description = 'Delete closed outage_signals older than the retention window (dry-run by default)';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $base = fn () => DB::table('outage_signals')
            ->whereIn('state', ['resolved', 'expired'])
            ->whereRaw('COALESCE(resolved_at, opened_at) < ?', [$cutoff->toDateTimeString()]);

        $eligible = $base()->count();
        $this->info("Cutoff {$cutoff->toDateTimeString()} ({$days} days): {$eligible} row(s) eligible.");

        if (! $this->option('execute')) {
            $this->info('Dry run: nothing deleted. Re-run with --execute.');

            return self::SUCCESS;
        }

        $deleted = 0;
        while (($ids = $base()->orderBy('id')->limit(self::CHUNK)->pluck('id')->all()) !== []) {
            // Re-check state: a flap may have reopened an episode between select and delete.
            $n = DB::table('outage_signals')->whereIn('id', $ids)->whereIn('state', ['resolved', 'expired'])->delete();
            if ($n === 0) {
                break;
            }
            $deleted += $n;
        }

        Log::info('outage:prune executed', ['cutoff' => $cutoff->toDateTimeString(), 'days' => $days, 'eligible' => $eligible, 'deleted' => $deleted]);
        $this->info("Deleted {$deleted} row(s).");

        return self::SUCCESS;
    }
}
