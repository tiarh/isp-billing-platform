<?php

namespace App\Console\Commands;

use App\Services\MtPlanCPulseService;
use Illuminate\Console\Command;

/** Plan C / C0: compare shadow snapshots vs Plan B routers columns. */
class RoutersMtPulseCompare extends Command
{
    protected $signature = 'routers:mt-pulse-compare';

    protected $description = 'Compare router_mt_snapshots vs routers.current_online_count / total_pppoe_count';

    public function handle(MtPlanCPulseService $pulse): int
    {
        $rows = $pulse->compareLatest();
        if ($rows === []) {
            $this->warn('no data (missing table or no routers)');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'live_on', 'snap_on', 'Δon', 'live_sec', 'snap_sec', 'Δsec', 'q', 'src', 'stale_s'],
            array_map(fn ($r) => [
                $r['router_id'],
                $r['name'],
                $r['live_online'],
                $r['snap_online'] ?? '-',
                $r['delta_online'] ?? '-',
                $r['live_secret'],
                $r['snap_secret'] ?? '-',
                $r['delta_secret'] ?? '-',
                $r['quality'] ?? '-',
                $r['source'] ?? '-',
                $r['stale_s'] ?? '-',
            ], $rows)
        );

        $big = collect($rows)->filter(fn ($r) => abs((int) ($r['delta_online'] ?? 0)) >= 10);
        $this->info('routers_with_|Δonline|>=10: '.$big->count());

        return self::SUCCESS;
    }
}
