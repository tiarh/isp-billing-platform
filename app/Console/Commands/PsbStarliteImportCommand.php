<?php

namespace App\Console\Commands;

use App\Services\PsbStarliteImportService;
use Illuminate\Console\Command;

/**
 * Import STARLITE Excel / Google Sheet history into psb_starlite_entries.
 * Safe: no MT/OLT/WA writes.
 */
class PsbStarliteImportCommand extends Command
{
    protected $signature = 'psb:import-starlite
                            {--path= : Local xlsx path}
                            {--sheet-id=1gybwIO3W-84MKzcn-IP9rbfPGeP5n2ms : Google spreadsheet ID}
                            {--from-sheet : Download from Google Sheet}';

    protected $description = 'Import STARLITE Daily Tracker Excel into dashboard history (read-only store)';

    public function handle(PsbStarliteImportService $import): int
    {
        $path = $this->option('path');
        if ($this->option('from-sheet') || ! $path) {
            $id = (string) $this->option('sheet-id');
            $this->info("Downloading sheet {$id} …");
            $result = $import->importFromGoogleSheet($id, 'sheet');
        } else {
            $this->info("Importing file {$path} …");
            $result = $import->importFromXlsx((string) $path, 'excel');
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (! empty($result['errors'])) {
            $this->error(implode('; ', $result['errors']));

            return self::FAILURE;
        }
        $this->info("imported={$result['imported']} updated={$result['updated']} skipped={$result['skipped']}");

        return self::SUCCESS;
    }
}
