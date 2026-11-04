<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltInventorySync extends Command
{
    protected $signature = 'olt:inventory-sync
                            {--olt_id= : Specific OLT ID}
                            {--pon= : Reconcile single PON only (e.g. 1/3/11)}
                            {--timeout= : Per-OLT collector budget in seconds (default: services.olt_inventory_sync.timeout_seconds)}
                            {--source=auto : ZTE inventory source: auto (SNMP first for services.olt_inventory_sync.snmp_olt_ids), snmp or ssh}';

    protected $description = 'Enterprise inventory sync: ZTE SSH inventory or HiOSO collect → olt_onus + snapshot';

    public function handle(OltCollectorService $collector): int
    {
        $olts = $this->resolveOlts();

        if ($olts->isEmpty()) {
            $this->warn('No OLTs found for inventory sync.');

            return self::SUCCESS;
        }

        $pon = $this->option('pon');

        foreach ($olts as $olt) {
            if (! $this->isReachable($olt)) {
                $this->warn("Skipping OLT #{$olt->id}: unreachable");

                continue;
            }

            if ($pon) {
                $this->info("PON reconcile OLT #{$olt->id} {$olt->name} PON {$pon}...");
                $result = $collector->reconcilePonInventory($olt, (string) $pon);
                $this->line(sprintf(
                    '  live=%d purged=%d updated=%d snapshot_onus=%d',
                    $result['live_refs'],
                    $result['purged'],
                    $result['updated'],
                    $result['snapshot_onus']
                ));

                continue;
            }

            $this->info("Inventory sync OLT #{$olt->id} {$olt->name}...");
            $run = $collector->runInventorySync($olt, $this->budgetSeconds(), $this->source());
            $meta = $run->meta ?? [];
            $this->line(sprintf(
                '  %s [%s]: %d updated, %d purged stale, snapshot=%d (%dms)',
                strtoupper($run->status),
                $meta['inventory_source'] ?? 'n/a',
                $run->onus_updated,
                $meta['purged_stale'] ?? 0,
                $meta['snapshot_onus'] ?? $run->onus_total,
                $run->duration_ms ?? 0
            ));

            if ($run->error) {
                $this->warn('  '.$run->error);
            }
        }

        return self::SUCCESS;
    }

    protected function source(): string
    {
        $source = strtolower(trim((string) $this->option('source')));

        return in_array($source, ['snmp', 'ssh'], true) ? $source : 'auto';
    }

    protected function budgetSeconds(): ?int
    {
        $timeout = $this->option('timeout');
        if ($timeout === null || $timeout === '' || ! is_numeric($timeout) || (int) $timeout <= 0) {
            return null;
        }

        return (int) $timeout;
    }

    protected function resolveOlts()
    {
        $oltId = $this->option('olt_id');

        // Explicit ID: any vendor (HiOSO + ZTE).
        if ($oltId) {
            return Olt::query()->where('id', (int) $oltId)->get();
        }

        // Default fleet: ZTE + HiOSO/HTB.
        return Olt::query()
            ->where(function ($q) {
                $q->where('vendor', 'zte_c300')
                    ->orWhere('vendor', 'like', '%zte%')
                    ->orWhere('vendor', 'hioso')
                    ->orWhere('vendor', 'like', '%hioso%')
                    ->orWhere('vendor', 'like', '%htb%')
                    ->orWhereNull('vendor');
            })
            ->orderBy('id')
            ->get();
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
            'ssh' => 22,
            default => 23,
        });

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
