<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Console\Command;

class OltLiveSync extends Command
{
    protected $signature = 'olt:live-sync {--olt_id= : Specific OLT ID}';

    protected $description = 'Enterprise live: SNMP status only (target <15s); RX via olt:snmp-sync';

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

            $this->info("Live sync OLT #{$olt->id} {$olt->name}...");
            $runs = $collector->runFastLiveSync($olt);
            $snmp = $runs['snmp'];
            $state = $runs['state'];
            $snmpStatus = (string) data_get($snmp, 'status', 'unknown');
            $stateStatus = (string) data_get($state, 'status', 'unknown');
            $snmpMeta = (array) data_get($snmp, 'meta', []);
            $stateMeta = (array) data_get($state, 'meta', []);

            $this->line(sprintf(
                '  SNMP %s: %d RX updated (%dms)',
                strtoupper($snmpStatus),
                (int) data_get($snmp, 'onus_updated', 0),
                (int) data_get($snmp, 'duration_ms', 0)
            ));
            $this->line(sprintf(
                '  STATE %s: %d rows, online=%d offline=%d (%dms)',
                strtoupper($stateStatus),
                (int) data_get($state, 'onus_updated', 0),
                $stateMeta['online'] ?? 0,
                $stateMeta['offline'] ?? 0,
                (int) data_get($state, 'duration_ms', 0)
            ));

            if (($snmpMeta['snmp_with_rx'] ?? 0) === 0 && $snmpStatus === 'skipped') {
                $this->warn('  SNMP skipped: '.(data_get($snmp, 'error') ?: ($snmpMeta['reason'] ?? 'no data')));
            }

            foreach ([$snmp, $state] as $run) {
                if ($error = data_get($run, 'error')) {
                    $this->warn('  '.$error);
                }
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
