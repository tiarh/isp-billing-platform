<?php

namespace App\Console\Commands;

use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltWeakRxScan extends Command
{
    protected $signature = 'olt:weak-rx-scan';

    protected $description = 'Scan enterprise OLT ONUs for weak RX and upsert monitoring alert events';

    public function handle(OltCollectorService $collector): int
    {
        $events = $collector->scanWeakRxAlerts();
        $collector->collectHealthSummary();

        $this->info("Monitoring events upserted: {$events}");

        return self::SUCCESS;
    }
}
