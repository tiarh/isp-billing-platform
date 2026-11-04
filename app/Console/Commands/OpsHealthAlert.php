<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use Illuminate\Console\Command;

class OpsHealthAlert extends Command
{
    protected $signature = 'ops:health-alert
                            {--force : Send a ping even if healthy}
                            {--dry-run : Print health without sending a WhatsApp alert or changing checkpoints}
                            {--acknowledge-existing : Mark current failed_jobs IDs as historical backlog}';

    protected $description = 'Check new failed jobs and queue depth, then alert the Evolution ops group';

    public function handle(OpsAlertService $ops): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('acknowledge-existing')) {
            $checkpoint = $dryRun
                ? $ops->failedJobCheckpoint()
                : $ops->acknowledgeExistingFailedJobs();
            $prefix = $dryRun ? 'DRY RUN acknowledge' : 'acknowledged';
            $this->info(
                "{$prefix} failed_jobs through id=".($checkpoint['max_id'] ?? 0).
                ' total='.($checkpoint['total'] ?? 0)
            );
        }

        $report = $ops->runHealthSweep(! $dryRun);
        foreach ([
            'failed_jobs',
            'failed_jobs_historical',
            'failed_jobs_new',
            'failed_jobs_unalerted',
            'failed_jobs_ack_id',
        ] as $key) {
            if (array_key_exists($key, $report)) {
                $this->info($key.'='.$report[$key]);
            }
        }
        foreach ($report as $key => $value) {
            if (is_string($key) && preg_match('/^queue_.+_len$/', $key) === 1) {
                $this->info($key.'='.$value);
            }
        }
        if (! empty($report['redis_error'])) {
            $this->warn('redis_error=1');
        }

        if ($this->option('force') && ! $dryRun) {
            $ops->send(
                'eBilling ops health ping OK',
                'ops_ping',
                60,
                '',
                OpsAlertSource::OPS_PING
            );
            $this->info('force ping sent (if Evolution ops group is configured)');
        } elseif ($this->option('force')) {
            $this->warn('DRY RUN force ping not sent');
        }

        if (! empty($report['alerts'])) {
            $this->warn(implode(', ', $report['alerts']));

            return self::SUCCESS;
        }

        $this->info('ok');

        return self::SUCCESS;
    }
}
