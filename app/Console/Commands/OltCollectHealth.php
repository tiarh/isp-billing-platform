<?php

namespace App\Console\Commands;

use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltCollectHealth extends Command
{
    protected $signature = 'olt:collect-health';

    protected $description = 'Summarize enterprise OLT collector health (all vendors)';

    public function handle(OltCollectorService $collector): int
    {
        $summary = $collector->collectHealthSummary();

        $this->info(sprintf(
            'Enterprise OLTs: %d healthy / %d total, weak RX online: %d',
            $summary['healthy_olts'],
            $summary['enterprise_olts'],
            $summary['weak_rx_total']
        ));

        foreach ($summary['rows'] as $row) {
            $status = $row['healthy'] ? 'OK' : 'DEGRADED';
            $this->line(sprintf(
                '  [%s] #%d %s — weak_rx=%d',
                $status,
                $row['olt_id'],
                $row['olt_name'],
                $row['weak_rx_online']
            ));
        }

        return self::SUCCESS;
    }
}
