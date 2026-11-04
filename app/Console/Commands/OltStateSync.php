<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltStateSync extends Command
{
    protected $signature = 'olt:state-sync {--olt_id= : Specific OLT ID}';

    protected $description = 'Fast ONU state sync from OLT (show gpon onu state, ~1-2 menit)';

    public function handle(OltCollectorService $collector): int
    {
        $olts = $this->resolveOlts();

        if ($olts->isEmpty()) {
            $this->warn('No ZTE C300 OLTs found.');

            return self::SUCCESS;
        }

        foreach ($olts as $olt) {
            if (! $this->isReachable($olt)) {
                $this->warn("Skipping OLT #{$olt->id}: unreachable");

                continue;
            }

            $this->info("State sync OLT #{$olt->id} {$olt->name}...");
            $run = $collector->runStateSync($olt);
            $meta = $run->meta ?? [];
            $this->line(sprintf(
                '  %s: %d rows updated, live=%d online=%d offline=%d (%dms)',
                strtoupper($run->status),
                $run->onus_updated,
                $meta['live_refs'] ?? $run->onus_total,
                $meta['online'] ?? 0,
                $meta['offline'] ?? 0,
                $run->duration_ms ?? 0
            ));

            if ($run->error) {
                $this->warn('  '.$run->error);
            }
        }

        return self::SUCCESS;
    }

    protected function resolveOlts()
    {
        $oltId = $this->option('olt_id');

        $query = Olt::query()->where(function ($q) {
            $q->where('vendor', 'zte_c300')
                ->orWhere('vendor', 'like', '%zte%')
                ->orWhereNull('vendor');
        });

        if ($oltId) {
            $query->where('id', $oltId);
        }

        return $query->get();
    }

    protected function isReachable(Olt $olt): bool
    {
        $ip = $olt->management_ip;
        if (empty($ip)) {
            return false;
        }

        $port = (int) ($olt->management_port ?: 22);
        $fp = @fsockopen($ip, $port, $errno, $errstr, 3);

        if ($fp) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
