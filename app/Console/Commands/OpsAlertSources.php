<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use Illuminate\Console\Command;

/**
 * Read-only: which ops alert sources are on and how many alerts each off source
 * held back (daily counters, not log scans).
 */
class OpsAlertSources extends Command
{
    protected $signature = 'ops:alert-sources';

    protected $description = 'Show the ops alert env flag, enabled sources and held-back alert counts per source (read-only)';

    public function handle(OpsAlertService $ops): int
    {
        $env = $ops->envFlagEnabled();
        $enabled = $ops->enabledSources();

        $this->line('EVOLUTION_API_OPS_ALERTS_ENABLED='.($env ? 'true (all sources send)' : 'false (only enabled sources send)'));
        $this->line('ops_alert_sources_enabled='.($enabled === [] ? '(none)' : implode(',', $enabled)));

        $day = $ops->suppressedCounts(1);
        $week = $ops->suppressedCounts(7);
        $rows = [];
        foreach ($week as $source => $count7) {
            $rows[] = [$source, in_array($source, $enabled, true) ? 'on' : 'off', $day[$source] ?? 0, $count7];
        }
        $this->table(['source', 'enabled', 'held back today (WIB)', 'held back 7 days (WIB)'], $rows);

        return self::SUCCESS;
    }
}
