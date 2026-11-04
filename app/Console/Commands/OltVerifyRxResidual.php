<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Services\OltZteC300Service;
use App\Support\OltSnmpCommunity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Reconcile residual / CLI-filled RX: snmpget signed decode + CLI attenuation verify.
 * Never invents values — only writes when SNMP and/or CLI returns a real reading.
 */
class OltVerifyRxResidual extends Command
{
    protected $signature = 'olt:verify-rx-residual
                            {--olt_id=1 : OLT id}
                            {--apply : Write verified values to DB}
                            {--all-ssh : Include all rx_source=ssh_attenuation (default)}
                            {--null-only : Only online with null RX}';

    protected $description = 'SNMP signed + CLI verify residual RX (no fake values)';

    public function handle(OltZteC300Service $zte): int
    {
        $oltId = (int) $this->option('olt_id');
        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            $this->error("OLT #{$oltId} not found");

            return self::FAILURE;
        }

        $q = OltOnu::query()
            ->where('olt_id', $oltId)
            ->where('state', 'online')
            ->whereNotNull('snmp_index');

        if ($this->option('null-only')) {
            $q->whereNull('rx_power_dbm');
        } else {
            $q->where(function ($b) {
                $b->whereNull('rx_power_dbm')
                    ->orWhere('rx_source', 'ssh_attenuation')
                    ->orWhere('rx_source', 'cli_attenuation');
            });
        }

        $rows = $q->orderBy('onu_ref')->get();
        if ($rows->isEmpty()) {
            $this->info('No residual / CLI-sourced online ONUs to verify.');

            return self::SUCCESS;
        }

        $this->info("Targets: {$rows->count()} (apply=".($this->option('apply') ? 'yes' : 'dry-run').')');

        $host = $olt->management_ip ?: (string) config('site.olt_default_management_ip');
        $comm = $olt->snmp_community ?: OltSnmpCommunity::fallback();
        $rxOid = '1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10';
        $now = now();
        $apply = (bool) $this->option('apply');

        $refs = $rows->pluck('onu_ref')->all();
        $cliData = $zte->collectRxPowerViaCli($olt, $refs);
        if (! empty($cliData['error'])) {
            $this->warn('CLI error: '.$cliData['error']);
        }

        $stats = [
            'snmp_cli_match' => 0,
            'snmp_only' => 0,
            'cli_only' => 0,
            'mismatch_prefer_cli' => 0,
            'no_signal' => 0,
            'updated' => 0,
        ];
        $report = [];

        foreach ($rows as $row) {
            $idx = (string) $row->snmp_index;
            if (! str_ends_with($idx, '.1') && substr_count($idx, '.') === 1) {
                $idx .= '.1';
            }
            $oid = $rxOid.'.'.$idx;

            $raw = $this->snmpGetInteger($host, $comm, $oid);
            $snmpRx = $raw !== null ? OltZteC300Service::decodeZteSnmpRxRaw($raw) : null;

            $cliInfo = $cliData['onus'][$row->onu_ref] ?? $cliData['onus'][strtolower($row->onu_ref)] ?? null;
            $cliRx = is_array($cliInfo)
                ? OltZteC300Service::sanitizeSnmpRxDbm($cliInfo['rx_dbm'] ?? null)
                : null;

            $chosen = null;
            $source = null;
            $note = '';

            if ($snmpRx !== null && $cliRx !== null) {
                $delta = abs($snmpRx - $cliRx);
                if ($delta <= 0.5) {
                    $chosen = $snmpRx;
                    $source = 'snmp';
                    $note = 'snmp+cli_match';
                    $stats['snmp_cli_match']++;
                } else {
                    // Prefer CLI (OLT show attenuation is ground truth)
                    $chosen = $cliRx;
                    $source = 'ssh_attenuation';
                    $note = "mismatch_d={$delta}_prefer_cli";
                    $stats['mismatch_prefer_cli']++;
                }
            } elseif ($snmpRx !== null) {
                $chosen = $snmpRx;
                $source = 'snmp';
                $note = 'snmp_only';
                $stats['snmp_only']++;
            } elseif ($cliRx !== null) {
                $chosen = $cliRx;
                $source = 'ssh_attenuation';
                $note = 'cli_only_snmp_raw_'.($raw ?? 'fail');
                $stats['cli_only']++;
            } else {
                $note = 'no_signal raw='.($raw ?? 'null');
                $stats['no_signal']++;
            }

            $entry = [
                'onu_ref' => $row->onu_ref,
                'name' => $row->onu_name,
                'db_rx' => $row->rx_power_dbm,
                'db_src' => $row->rx_source,
                'snmp_raw' => $raw,
                'snmp_rx' => $snmpRx,
                'cli_rx' => $cliRx,
                'chosen' => $chosen,
                'source' => $source,
                'note' => $note,
            ];
            $report[] = $entry;

            $this->line(sprintf(
                '%s raw=%s snmp=%s cli=%s → %s [%s] %s',
                $row->onu_ref,
                $raw === null ? 'n/a' : (string) $raw,
                $snmpRx === null ? 'null' : (string) $snmpRx,
                $cliRx === null ? 'null' : (string) $cliRx,
                $chosen === null ? 'KEEP/NULL' : (string) $chosen,
                $source ?? '-',
                $note
            ));

            if ($apply && $chosen !== null && $source !== null) {
                $row->update([
                    'rx_power_dbm' => $chosen,
                    'rx_source' => $source,
                    'rx_collected_at' => $now,
                ]);
                $stats['updated']++;
            }
        }

        $online = OltOnu::query()->where('olt_id', $oltId)->where('state', 'online');
        $this->newLine();
        $this->info(json_encode([
            'stats' => $stats,
            'online' => (clone $online)->count(),
            'online_rx' => (clone $online)->whereNotNull('rx_power_dbm')->count(),
            'online_no_rx' => (clone $online)->whereNull('rx_power_dbm')->count(),
            'by_source' => (clone $online)->whereNotNull('rx_power_dbm')
                ->selectRaw('rx_source, count(*) as c')->groupBy('rx_source')->pluck('c', 'rx_source'),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    protected function snmpGetInteger(string $host, string $comm, string $oid): ?int
    {
        $proc = new Process([
            'snmpget', '-v2c', '-c', $comm, '-On', '-t', '3', '-r', '2',
            'udp:'.$host.':161', $oid,
        ]);
        $proc->setTimeout(15);
        $proc->run();
        $out = trim($proc->getOutput());
        if ($out === '' || ! preg_match('/INTEGER:\s*(-?\d+)/', $out, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
