<?php

namespace App\Console\Commands;

use App\Services\LegacySyncService;
use Illuminate\Console\Command;

class PackagesDedupeCommand extends Command
{
    protected $signature = 'packages:dedupe {--sync : Also re-sync packages from eBilling scraper first}';

    protected $description = 'Merge duplicate packages (same name+router) and optionally refresh from scraper';

    public function handle(LegacySyncService $sync): int
    {
        if ($this->option('sync')) {
            $this->info('Syncing packages from scraper…');
            $n = $sync->syncPackages();
            $this->info("Synced {$n} packages from scraper.");
        }

        $this->info('Deduping packages…');
        $r = $sync->dedupePackages();
        $this->info("groups={$r['groups']} customers_merged={$r['merged']} packages_deleted={$r['deleted']}");

        return self::SUCCESS;
    }
}
