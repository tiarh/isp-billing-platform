<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention for wa_chat_signals (owner 2026-10-11: 18 months). Dry-run unless --apply. Work channel only (owner-approved scope).
 * Deletes in chunks by primary key; never touches other tables.
 */
class PruneWaSignals extends Command
{
    protected $signature = 'wa-signals:prune {--months=18 : Keep this many months} {--apply : Actually delete (default is dry-run)}';

    protected $description = 'Delete work-channel wa_chat_signals rows older than the retention window (dry-run by default)';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $months = (int) $this->option('months');
        if ($months < 1) {
            $this->error('--months must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subMonths($months);
        $base = fn () => DB::table('wa_chat_signals')->where('channel', 'work')->where(function ($q) use ($cutoff) {
            $q->where('occurred_at', '<', $cutoff)
                ->orWhere(fn ($n) => $n->whereNull('occurred_at')->where('created_at', '<', $cutoff));
        });

        $eligible = $base()->count();
        $this->info("Cutoff {$cutoff->toDateTimeString()} ({$months} months): {$eligible} row(s) eligible.");

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing deleted. Re-run with --apply.');

            return self::SUCCESS;
        }

        $deleted = 0;
        while (($ids = $base()->orderBy('id')->limit(self::CHUNK)->pluck('id')->all()) !== []) {
            $n = DB::table('wa_chat_signals')->whereIn('id', $ids)->delete();
            if ($n === 0) {
                break;
            }
            $deleted += $n;
        }

        Log::info('wa-signals:prune applied', ['cutoff' => $cutoff->toDateTimeString(), 'months' => $months, 'eligible' => $eligible, 'deleted' => $deleted]);
        $this->info("Deleted {$deleted} row(s).");

        return self::SUCCESS;
    }
}
