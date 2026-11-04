<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DispatchMonitoringAlerts extends Command
{
    protected $signature = 'monitoring:dispatch-alerts {--dry-run : Do not send notifications}';

    protected $description = 'Compatibility wrapper for the debounced connectivity alert dispatcher';

    public function handle(): int
    {
        $parameters = $this->option('dry-run') ? ['--dry-run' => true] : [];

        return $this->call('connectivity:dispatch-alerts', $parameters);
    }
}
