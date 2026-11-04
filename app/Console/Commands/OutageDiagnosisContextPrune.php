<?php

namespace App\Console\Commands;

use App\Models\OutageDiagnosis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Detector episodes (auto_episode) keep a context snapshot for the back-test (about 3.5 MB a week). After 180 days the
 * snapshot is cleared (the diagnosis row itself stays). Bounded: only a rolling window just before the cutoff is read
 * (the `state, created_at` index), in chunks of 500 ids. ticket_open / manual_check snapshots are never touched.
 */
class OutageDiagnosisContextPrune extends Command
{
    protected $signature = 'outage:diagnosis-context-prune {--days=180 : Keep snapshots of detector episodes this many days} {--window=30 : Look back this many days before the cutoff} {--dry-run : Only count}';

    protected $description = 'Clear the context snapshot of auto_episode diagnoses older than the retention window (default 180 days)';

    private const CHUNK = 500;

    private const MAX_CHUNKS = 200;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $window = (int) $this->option('window');
        if ($days < 1 || $window < 1) {
            $this->error('--days and --window must be at least 1.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('outage_diagnoses') || ! Schema::hasColumn('outage_diagnoses', 'context')) {
            $this->info('No context column yet.');

            return self::SUCCESS;
        }
        $cutoff = now()->utc()->subDays($days);
        $from = $cutoff->copy()->subDays($window)->format('Y-m-d H:i:s');
        $to = $cutoff->format('Y-m-d H:i:s');
        $base = fn () => DB::table('outage_diagnoses')->where('state', OutageDiagnosis::STATE_DONE)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('trigger', OutageDiagnosis::TRIGGER_AUTO_EPISODE)->whereNotNull('context');

        if ($this->option('dry-run')) {
            $this->info('auto_episode snapshots to clear: '.$base()->count());

            return self::SUCCESS;
        }
        $cleared = 0;
        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            $ids = $base()->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $cleared += DB::table('outage_diagnoses')->whereIn('id', $ids)->update(['context' => null]);
        }
        $this->info("auto_episode snapshots cleared: {$cleared}.");
        Log::info('outage:diagnosis-context-prune executed', ['cleared' => $cleared, 'cutoff' => $to]);

        return self::SUCCESS;
    }
}
