<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsCustomerArchive;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Lifecycle\LifecycleBackstop;
use Illuminate\Console\Command;

class ScopeCustomersToXlsx extends Command
{
    use GuardsCustomerArchive;

    public const SOURCE = 'cli_scope_customers_xlsx';

    protected $signature = 'billing:scope-customers-xlsx
                            {path=Data Cust Akuisisi update April.xlsx : XLSX file path}
                            {--dry-run : Show what would change without writing}
                            {--chunk=500 : Number of customers to process per batch}';

    protected $description = 'Use the XLSX active customer list as the intended customer scope after legacy sync';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! is_file($path)) {
            $this->error("XLSX file not found: {$path}");

            return self::FAILURE;
        }

        try {
            $rows = $this->readSheetRows($path);
        } catch (\Throwable $e) {
            $this->error('Failed to read XLSX: '.$e->getMessage());

            return self::FAILURE;
        }

        $requiredHeaders = ['ID Pelanggan', 'Status'];
        $missingHeaders = array_values(array_diff($requiredHeaders, array_keys($rows[0] ?? [])));

        if ($missingHeaders) {
            $this->error('Missing required XLSX headers: '.implode(', ', $missingHeaders));

            return self::FAILURE;
        }

        $intendedCodes = [];
        $xlsxInactiveRows = 0;

        foreach ($rows as $row) {
            $code = trim((string) ($row['ID Pelanggan'] ?? ''));
            $status = trim((string) ($row['Status'] ?? ''));

            if ($code === '') {
                continue;
            }

            if (strcasecmp($status, 'Aktif') === 0) {
                $intendedCodes[$code] = true;
            } else {
                $xlsxInactiveRows++;
            }
        }

        $intendedCodes = array_keys($intendedCodes);
        sort($intendedCodes);

        if ($dryRun) {
            $this->warn('DRY RUN - no database changes will be made.');
        }

        $stats = [
            'xlsx_active_codes' => count($intendedCodes),
            'xlsx_inactive_rows' => $xlsxInactiveRows,
            'matched_existing_customers' => 0,
            'missing_from_legacy' => 0,
            'restored' => 0,
            'activated' => 0,
            'kept_active_or_isolated' => 0,
            'soft_deleted_out_of_scope' => 0,
            'already_soft_deleted_out_of_scope' => 0,
            'skipped_by_lifecycle_guard' => 0,
        ];

        $intendedCodeLookup = array_fill_keys($intendedCodes, true);
        $existingCodes = Customer::withTrashed()
            ->whereIn('code', $intendedCodes)
            ->pluck('code')
            ->all();
        $existingCodeLookup = array_fill_keys($existingCodes, true);

        $stats['matched_existing_customers'] = count($existingCodes);
        $stats['missing_from_legacy'] = count(array_diff_key($intendedCodeLookup, $existingCodeLookup));

        Customer::withTrashed()
            ->whereIn('code', $intendedCodes)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($customers) use (&$stats, $dryRun) {
                foreach ($customers as $customer) {
                    $shouldRestore = $customer->trashed();
                    $shouldActivate = ! in_array($customer->status, CustomerStatus::billable(), true);

                    if ($shouldRestore) {
                        $stats['restored']++;
                    }

                    if ($shouldActivate) {
                        $stats['activated']++;
                    } else {
                        $stats['kept_active_or_isolated']++;
                    }

                    if (! $dryRun && ($shouldRestore || $shouldActivate)) {
                        if ($shouldRestore) {
                            $customer->restore();
                        }

                        if ($shouldActivate) {
                            $customer->status = CustomerStatus::Active->value;
                            $customer->save();
                        }
                    }
                }
            });

        Customer::withTrashed()
            ->with(['router', 'olt'])
            ->whereNotIn('code', $intendedCodes)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($customers) use (&$stats, $dryRun) {
                foreach ($customers as $customer) {
                    if ($customer->trashed()) {
                        $stats['already_soft_deleted_out_of_scope']++;

                        continue;
                    }

                    // Roadmap 1.3: PPPoE/ONU/traffic in use or unknown => skipped (CLI never overrides).
                    $decision = $this->archiveDecision($customer, self::SOURCE);
                    if (! $decision->allowed()) {
                        $stats['skipped_by_lifecycle_guard']++;
                        $this->warn($this->guardLine($customer, $decision, $dryRun ? 'dry-run-skip' : 'skipped'));

                        continue;
                    }

                    $stats['soft_deleted_out_of_scope']++;

                    if (! $dryRun) {
                        LifecycleBackstop::allow([(int) $customer->getKey()], self::SOURCE, fn () => $customer->delete());
                    }
                }
            });

        $this->newLine();
        $this->info('Customer XLSX scoping complete.');

        foreach ($stats as $label => $count) {
            $this->line(str_replace('_', ' ', $label).": {$count}");
        }

        if ($stats['missing_from_legacy'] > 0) {
            $this->warn('Some XLSX active customer codes were not found in legacy-synced customers.');
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $candidates = [$path];

        $normalizedPath = preg_replace('/\s+/', ' ', trim($path));
        if ($normalizedPath !== null && $normalizedPath !== $path) {
            $candidates[] = $normalizedPath;
        }

        foreach ($candidates as $candidate) {
            $resolved = str_starts_with($candidate, '/')
                ? $candidate
                : base_path($candidate);

            if (is_file($resolved)) {
                return $resolved;
            }
        }

        $fallback = end($candidates);

        return str_starts_with($fallback, '/')
            ? $fallback
            : base_path($fallback);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSheetRows(string $path): array
    {
        $sharedStrings = $this->readSharedStrings($path);
        $sheetXml = $this->readZipEntry($path, 'xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            throw new \RuntimeException('Sheet1 not found in XLSX.');
        }

        $xml = $this->loadXml($sheetXml);
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $headers = [];
        $rows = [];

        foreach ($xml->xpath('//x:sheetData/x:row') as $rowIndex => $row) {
            $values = [];
            $row->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            foreach ($row->xpath('x:c') as $cell) {
                $ref = (string) $cell['r'];
                $column = $this->columnLetters($ref);
                $values[$column] = $this->readCellValue($cell, $sharedStrings);
            }

            if ($rowIndex === 0) {
                $headers = $values;

                continue;
            }

            $mapped = [];
            foreach ($headers as $column => $header) {
                $header = trim((string) $header);
                if ($header !== '') {
                    $mapped[$header] = $values[$column] ?? null;
                }
            }

            if (array_filter($mapped, fn ($value) => $value !== null && $value !== '') !== []) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(string $path): array
    {
        $xmlString = $this->readZipEntry($path, 'xl/sharedStrings.xml');
        if ($xmlString === false) {
            return [];
        }

        $xml = $this->loadXml($xmlString);
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xml->xpath('//x:si') as $item) {
            $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($item->xpath('.//x:t') as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function readZipEntry(string $path, string $entry): string|false
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Unable to open XLSX archive.');
            }

            $content = $zip->getFromName($entry);
            $zip->close();

            return $content;
        }

        $command = 'unzip -p '.escapeshellarg($path).' '.escapeshellarg($entry);
        $content = shell_exec($command);

        return $content === null || $content === '' ? false : $content;
    }

    private function loadXml(string $xml): \SimpleXMLElement
    {
        $loaded = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if (! $loaded) {
            throw new \RuntimeException('Invalid XLSX XML.');
        }

        return $loaded;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function readCellValue(\SimpleXMLElement $cell, array $sharedStrings): mixed
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($cell->xpath('.//x:t') as $text) {
                $parts[] = (string) $text;
            }

            return implode('', $parts);
        }

        $raw = isset($cell->v) ? (string) $cell->v : null;
        if ($raw === null) {
            return null;
        }

        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? null;
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    private function columnLetters(string $cellRef): string
    {
        preg_match('/^[A-Z]+/', $cellRef, $matches);

        return $matches[0] ?? '';
    }
}
