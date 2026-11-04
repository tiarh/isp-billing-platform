<?php

namespace App\Console\Commands;

use App\Services\PonPppoeMismatchDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanPonPppoeMismatch extends Command
{
    protected $signature = 'connectivity:scan-pon-mismatch {--dry-run : Scan and log without upserting monitoring events}';

    protected $description = 'Scan connectivity alerts: PON/PPPoE mismatch and bad signal on ZTE C300';

    public function handle(PonPppoeMismatchDetector $detector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $persist = ! $dryRun;

        $mismatch = $detector->scan($persist);
        $badSignal = $detector->scanBadSignal($persist);

        $detector->logScanSummary($mismatch, $dryRun);
        Log::info('Bad signal connectivity scan', [
            'dry_run' => $dryRun,
            'active' => $badSignal['active'],
            'upserted' => $badSignal['upserted'],
            'recovered' => $badSignal['recovered'],
        ]);

        $this->info(sprintf(
            'PON/PPPoE mismatch: %d raw · %d verified · %d skipped · Bad signal: %d active%s',
            $mismatch['scanned'],
            $mismatch['active'],
            $mismatch['skipped'] ?? 0,
            $badSignal['active'],
            $dryRun ? ' (dry-run, events not saved)' : ''
        ));

        if ($persist) {
            $this->line(sprintf(
                'Pre-synced=%d · upserted mismatch=%d recovered=%d · bad_signal upserted=%d recovered=%d',
                $mismatch['pre_synced'] ?? 0,
                $mismatch['upserted'],
                $mismatch['recovered'],
                $badSignal['upserted'],
                $badSignal['recovered'],
            ));
        }

        $this->printSamples('Mismatch', $mismatch['samples']);
        $this->printSamples('Bad signal', $badSignal['samples']);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     */
    private function printSamples(string $label, array $samples): void
    {
        if ($samples === []) {
            return;
        }

        $this->line($label.':');
        foreach ($samples as $sample) {
            $this->line(sprintf(
                '- %s (%s) | area=%s | ODP=%s | port=%s | SN=%s | RX=%s',
                $sample['customer_name'] ?? '-',
                $sample['customer_code'] ?? '-',
                $sample['area'] ?? '-',
                $sample['odp'] ?? '-',
                $sample['olt_port_label'] ?? '-',
                $sample['onu_serial'] ?? '-',
                $sample['onu_rx_power_dbm'] ?? '-',
            ));
        }
    }
}
