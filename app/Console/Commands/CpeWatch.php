<?php

namespace App\Console\Commands;

use App\Services\Cpe\CpeWatchService;
use Illuminate\Console\Command;

/**
 * RCA-3: 5-minute watch of symptomatic ONUs. Default OFF (setting cpe_watch_enabled=0): the run is a no-op.
 * Queues getParameterValues WITHOUT connection_request and reads the answers back; DB + NBI only, no job pushed.
 */
class CpeWatch extends Command
{
    protected $signature = 'cpe:watch';

    protected $description = 'RCA-3: 5-menit pantau ONU bermasalah (default mati; tanpa connection request)';

    public function handle(CpeWatchService $watch): int
    {
        $r = $watch->run();
        if (! $r['enabled']) {
            $this->info('cpe:watch mati (cpe_watch_enabled=0).');

            return self::SUCCESS;
        }
        $this->info(sprintf(
            'cpe:watch: dipilih %d, antre %d, lewati (task pending) %d, lewati (PSB/migrasi) %d, sampel %d%s',
            $r['picked'], $r['queued'], $r['skipped_pending'], $r['skipped_busy'], $r['sampled'],
            $r['error'] !== null ? ', error '.$r['error'] : '',
        ));

        return $r['error'] === null ? self::SUCCESS : self::FAILURE;
    }
}
