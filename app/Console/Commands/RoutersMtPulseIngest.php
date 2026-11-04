<?php

namespace App\Console\Commands;

use App\Services\MtPlanCPulseService;
use Illuminate\Console\Command;

/**
 * Plan C / C0: T0 pulse → router_mt_snapshots (shadow).
 * Default: do NOT write routers.current_online_count (Plan B stays primary).
 */
class RoutersMtPulseIngest extends Command
{
    protected $signature = 'routers:mt-pulse-ingest
                            {--source=exporter : exporter|api}
                            {--url= : Exporter metrics URL}
                            {--file= : Read Prometheus text from file (host cron path)}
                            {--router_id=* : Limit API pulse to router ids}
                            {--write-live : Promote good samples to routers table (requires mt_plan_c.write_live)}
                            {--dry-run : Parse/guards only}';

    protected $description = 'Plan C T0 pulse ingest into router_mt_snapshots (shadow Online/Secret)';

    public function handle(MtPlanCPulseService $pulse): int
    {
        $source = strtolower((string) $this->option('source'));
        $writeLive = (bool) $this->option('write-live');
        $dry = (bool) $this->option('dry-run');

        try {
            if ($source === 'api') {
                $ids = array_map('intval', (array) $this->option('router_id'));
                $ids = array_values(array_filter($ids));
                $result = $pulse->ingestFromApi(
                    $ids !== [] ? $ids : null,
                    $writeLive,
                    $dry
                );
            } else {
                $result = $pulse->ingestFromExporter(
                    $this->option('url') ?: null,
                    $writeLive,
                    $dry,
                    $this->option('file') ?: null
                );
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'mt-pulse source=%s ingested=%d rejected=%d live_written=%d dry=%s',
            $source,
            $result['ingested'],
            $result['rejected'],
            $result['live_written'] ?? $result['liveWritten'] ?? 0,
            $dry ? 'yes' : 'no'
        ));

        if (! $dry) {
            $cleared = $pulse->clearAllStaleStreamTimeouts();
            if ($cleared > 0) {
                $this->info("cleared_stale_stream_timeout_failures={$cleared}");
            }
        }

        foreach ($result['rows'] as $row) {
            if (! ($row['ok'] ?? false)) {
                $this->warn(sprintf(
                    '  FAIL %s q=%s %s',
                    $row['router'] ?? '?',
                    $row['quality'] ?? '?',
                    $row['message'] ?? ($row['reason'] ?? '')
                ));
            } else {
                $this->line(sprintf(
                    '  OK %s online=%s secret=%s',
                    $row['router'] ?? '?',
                    $row['online'] ?? '-',
                    $row['secret'] ?? '-'
                ));
            }
        }

        return self::SUCCESS;
    }
}
