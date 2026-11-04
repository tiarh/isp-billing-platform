<?php

namespace App\Console\Commands;

use App\Services\OnuSerialImportService;
use Illuminate\Console\Command;

class ImportOnuSerials extends Command
{
    protected $signature = 'customers:import-onu-serials
                            {file : Path to CSV exported from Excel}
                            {--apply : Write updates to database}
                            {--force : Overwrite existing onu_serial when different}
                            {--no-sync-olt : Skip syncing olt_port_label from olt_onus}';

    protected $description = 'Import customer ONU serial numbers from manual CSV (pppoe_user + serial_number)';

    public function handle(OnuSerialImportService $service): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if (! str_ends_with(strtolower($path), '.csv')) {
            $this->error('Use CSV format. Export Excel: Save As -> CSV UTF-8.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to update customers.');
        }

        $stats = $service->importFromPath(
            path: $path,
            apply: $apply,
            syncOlt: ! $this->option('no-sync-olt'),
            force: (bool) $this->option('force'),
        );

        $this->table(
            ['Metric', 'Count'],
            collect($stats)
                ->except('dry_run', 'samples')
                ->map(fn ($value, $key) => [$key, is_array($value) ? count($value) : $value])
                ->values()
                ->all()
        );

        foreach (['updated' => 'Will update / updated', 'not_found' => 'Not found in eBilling', 'conflicts' => 'SN conflict (skipped)'] as $key => $label) {
            $rows = $stats['samples'][$key] ?? [];
            if ($rows === []) {
                continue;
            }

            $this->line($label.':');
            foreach ($rows as $row) {
                $this->line('  '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
