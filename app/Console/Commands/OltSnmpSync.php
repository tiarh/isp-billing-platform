<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltSnmpSync extends Command
{
    protected $signature = 'olt:snmp-sync {--olt_id= : Specific OLT ID}';

    protected $description = 'Enterprise SNMP READ: full RX tree walk (~130s, background); merges without wipe';

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

            $this->info("SNMP sync OLT #{$olt->id} {$olt->name}...");
            $run = $collector->runSnmpSync($olt);

            $label = strtoupper($run->status);
            if ($label === 'SKIPPED') {
                $this->warn(sprintf('  SKIPPED: SNMP unavailable (%d ONUs in snapshot)', $run->onus_total));
            } else {
                $meta = $run->meta ?? [];
                $this->line(sprintf(
                    '  %s: %d updated, %d matched DB, %d index linked, %d with RX (%dms)',
                    $label,
                    $run->onus_updated,
                    $meta['snmp_matched_db'] ?? $meta['snmp_mapped'] ?? $run->onus_total,
                    $meta['snmp_index_linked'] ?? 0,
                    $meta['snmp_with_rx'] ?? 0,
                    $run->duration_ms ?? 0
                ));
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
