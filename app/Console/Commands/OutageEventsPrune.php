<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis history retention: onu_state_events, cpe_events, pppoe_session_events and the archive tables
 * (onu_rx_daily, pppoe_session_presence, pppoe_presence_runs) older than the window are deleted in chunks.
 * The window is config('outage.events_retention_days'), 400 days by default (roadmap v2 section 9).
 * History only (no billing / customer data), so it deletes by default; --dry-run only counts.
 */
class OutageEventsPrune extends Command
{
    public const DEFAULT_DAYS = 400;

    protected $signature = 'outage:events-prune {--days= : Keep this many days (default: config outage.events_retention_days, 400)} {--dry-run : Only count}';

    protected $description = 'Delete diagnosis event history older than the retention window (default 400 days)';

    private const CHUNK = 1000;

    /** table => [time column, date-only column] */
    private const TABLES = [
        'onu_state_events' => ['occurred_at', false],
        'cpe_events' => ['at', false],
        'pppoe_session_events' => ['at', false],
        'onu_rx_daily' => ['day_wib', true],
        'pppoe_session_presence' => ['down_at', false],
        'pppoe_presence_runs' => ['day_wib', true],
        'outage_outcome_labels' => ['labelled_at', false],
    ];

    public function handle(): int
    {
        $option = $this->option('days');
        $days = $option !== null && $option !== '' ? (int) $option : (int) config('outage.events_retention_days', self::DEFAULT_DAYS);
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }
        $keepFrom = now()->utc()->subDays($days);
        $cutoff = $keepFrom->format('Y-m-d H:i:s');
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        // RCA-3 tables keep their own retention: table => [time column, days, date-only column]
        $own = [
            'cpe_wifi_samples' => ['at', 14, false],
            'cpe_daily' => ['day_wib', 400, true],
        ];
        $tables = [];
        foreach (self::TABLES as $table => [$column, $dateOnly]) {
            $tables[$table] = [$column, $dateOnly ? $keepFrom->format('Y-m-d') : $cutoff];
        }
        foreach ($own as $table => [$column, $keepDays, $dateOnly]) {
            $c = now()->utc()->subDays($keepDays);
            $tables[$table] = [$column, $dateOnly ? $c->format('Y-m-d') : $c->format('Y-m-d H:i:s')];
        }

        foreach ($tables as $table => [$column, $tableCutoff]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $eligible = DB::table($table)->where($column, '<', $tableCutoff)->count();
            if ($dry) {
                $this->info("{$table}: {$eligible} row(s) older than {$tableCutoff}.");

                continue;
            }
            $deleted = 0;
            while (($ids = DB::table($table)->where($column, '<', $tableCutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all()) !== []) {
                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            }
            $total += $deleted;
            $this->info("{$table}: deleted {$deleted}.");
        }

        if (! $dry) {
            Log::info('outage:events-prune executed', ['cutoff' => $cutoff, 'days' => $days, 'deleted' => $total]);
        }

        return self::SUCCESS;
    }
}
