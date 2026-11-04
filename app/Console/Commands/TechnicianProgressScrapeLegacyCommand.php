<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\LegacyProgressTaskService;
use App\Services\TechnicianProgressService;
use App\Services\TechnicianTeamPlannerService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TechnicianProgressScrapeLegacyCommand extends Command
{
    protected $signature = 'technician-progress:scrape-legacy
                            {--date= : Work date Y-m-d}
                            {--materialize : Store auto-planned daily tasks}';

    protected $description = 'Read old eBilling ticket/PSB scraper for technician progress planning';

    public function handle(
        LegacyProgressTaskService $legacy,
        TechnicianTeamPlannerService $planner,
        TechnicianProgressService $progress
    ): int {
        if (! (bool) Setting::get('ticket_progress_legacy_scraper_enabled', true)) {
            $this->info('LEGACY_SCRAPER_DISABLED');

            return 0;
        }

        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), 'Asia/Jakarta')
            : now('Asia/Jakarta');

        $snapshot = $legacy->fetchActiveTasks($date);
        $jobs = $snapshot['jobs'] ?? [];
        $planned = $planner->plan($jobs);
        $stats = ['created' => 0, 'updated' => 0];

        if ($this->option('materialize')) {
            $stats = $progress->materializePlannedTasks($date->toDateString(), $planned);
        }

        Setting::set('ticket_progress_last_legacy_scrape', [
            'at' => now('Asia/Jakarta')->toDateTimeString(),
            'date' => $date->toDateString(),
            'jobs' => count($jobs),
            'planned_teams' => count($planned),
            'materialized' => (bool) $this->option('materialize'),
            'stats' => $stats,
            'endpoints' => $snapshot['endpoints'] ?? [],
            'errors' => $snapshot['errors'] ?? [],
        ], 'json', 'ticket', 'Last technician legacy scraper run');

        $this->info('LEGACY_JOBS='.count($jobs));
        $this->info('PLANNED_TEAMS='.count($planned));
        $this->info('MATERIALIZED_CREATED='.$stats['created'].' UPDATED='.$stats['updated']);

        foreach (($snapshot['errors'] ?? []) as $error) {
            $this->warn($error);
        }

        return 0;
    }
}
