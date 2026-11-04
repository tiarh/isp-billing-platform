<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

/**
 * RX / optical sync:
 * - ZTE C300 → SNMP RX path
 * - HiOSO/HTB → OltHiosoService collect (state + optical)
 */
class OltRxSync extends Command
{
    protected $signature = 'olt:rx-sync
                            {--olt_id= : Specific OLT ID (required for HiOSO until fleet-wide is healthy)}';

    protected $description = 'Sync OLT optical/RX: SNMP (ZTE) or HiOSO collect (web/snmp/cli)';

    public function handle(OltCollectorService $collector): int
    {
        $oltId = $this->option('olt_id');
        if (! $oltId) {
            $this->error('--olt_id= is required');

            return self::FAILURE;
        }

        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            $this->error("OLT #{$oltId} not found");

            return self::FAILURE;
        }

        if (! $this->isReachable($olt)) {
            $this->warn("OLT #{$olt->id} {$olt->name}: port not reachable — skip");

            return self::SUCCESS;
        }

        $this->info("RX/inventory sync OLT #{$olt->id} {$olt->name} ({$olt->vendor})...");

        try {
            $run = $collector->runRxSync($olt);
            $meta = $run->meta ?? [];
            $this->line(sprintf(
                '  %s: updated=%d total=%d purged=%s with_rx=%s (%dms)',
                strtoupper((string) $run->status),
                (int) $run->onus_updated,
                (int) $run->onus_total,
                $meta['purged_stale'] ?? '-',
                $meta['with_rx'] ?? '-',
                (int) ($run->duration_ms ?? 0)
            ));
            if ($run->error) {
                $this->warn('  '.$run->error);
            }
        } catch (\Throwable $e) {
            $this->error('  FAIL: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function isReachable(Olt $olt): bool
    {
        $ip = $olt->management_ip;
        if (empty($ip)) {
            return false;
        }

        $protocol = strtolower((string) ($olt->management_protocol ?: 'telnet'));
        $port = (int) ($olt->management_port ?: match ($protocol) {
            'http' => 80,
            'https' => 443,
            'snmp' => 161,
            default => 23,
        });

        // SNMP is UDP — skip TCP probe; try collect anyway.
        if ($protocol === 'snmp' || $port === 161) {
            return true;
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, 4);
        if ($fp) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
