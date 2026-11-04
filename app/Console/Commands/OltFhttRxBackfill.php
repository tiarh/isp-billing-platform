<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltFhttRxBackfill extends Command
{
    protected $signature = 'olt:fhtt-rx-backfill
                            {--olt_id= : Specific OLT ID}
                            {--pon= : Limit to PON port e.g. 1/2/10}
                            {--limit=0 : Max ONUs to backfill (0 = all)}';

    protected $description = 'CLI attenuation backfill for online ONUs missing RX (SNMP gap)';

    public function handle(OltCollectorService $collector): int
    {
        $olts = $this->resolveOlts();

        if ($olts->isEmpty()) {
            $this->warn('No ZTE C300 OLTs found.');

            return self::SUCCESS;
        }

        $pon = $this->option('pon') ?: null;
        $limit = max(0, (int) $this->option('limit'));

        foreach ($olts as $olt) {
            if (! $this->isReachable($olt)) {
                $this->warn("Skipping OLT #{$olt->id}: unreachable");

                continue;
            }

            // System discovers online N/A targets — no user-supplied list needed.
            $before = $collector->publishOnlineMissingRxReport($olt);
            $this->info("CLI RX backfill OLT #{$olt->id} {$olt->name}".($pon ? " PON {$pon}" : '')
                ." (online_na={$before['count']})...");

            if (($before['count'] ?? 0) === 0 && ! $pon) {
                $this->line('  skip: no online ONUs missing RX');

                continue;
            }

            $run = $collector->runCliRxBackfill($olt, $pon, $limit);
            $after = $collector->publishOnlineMissingRxReport($olt);

            $meta = $run->meta ?? [];
            $this->line(sprintf(
                '  %s: %d updated / %d queued, %d collected, still_na=%d (%dms)',
                strtoupper($run->status),
                $run->onus_updated,
                $meta['queued_refs'] ?? $run->onus_total,
                $meta['collected_refs'] ?? 0,
                $after['count'] ?? 0,
                $run->duration_ms ?? 0
            ));
            if (! empty($after['refs'])) {
                $this->line('  still_na_refs: '.implode(', ', array_slice($after['refs'], 0, 20)));
            }

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
