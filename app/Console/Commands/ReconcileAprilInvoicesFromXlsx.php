<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcileAprilInvoicesFromXlsx extends Command
{
    protected $signature = 'billing:reconcile-april-xlsx
                            {path=Data Cust Akuisisi update April.xlsx : XLSX file path}
                            {--dry-run : Simulate reconciliation without database changes}';

    protected $description = 'Create or update April 2026 invoices from the customer XLSX payment-last column';

    private Carbon $period;

    public function handle(): int
    {
        $this->period = Carbon::create(2026, 4, 1)->startOfMonth();
        $path = $this->resolvePath((string) $this->argument('path'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("XLSX file not found: {$path}");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN - no database changes will be made.');
        }

        try {
            $rows = $this->readSheetRows($path);
        } catch (\Throwable $e) {
            $this->error('Failed to read XLSX: '.$e->getMessage());

            return self::FAILURE;
        }

        $requiredHeaders = ['ID Pelanggan', 'Status', 'Pembayaran Terakhir'];
        $missingHeaders = array_values(array_diff($requiredHeaders, array_keys($rows[0] ?? [])));

        if ($missingHeaders) {
            $this->error('Missing required XLSX headers: '.implode(', ', $missingHeaders));

            return self::FAILURE;
        }

        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'paid' => 0,
            'unpaid' => 0,
            'skipped_inactive' => 0,
            'skipped_missing_customer' => 0,
            'skipped_no_package_or_amount' => 0,
            'parse_warnings' => 0,
        ];

        foreach ($rows as $row) {
            $xlsxStatus = trim((string) ($row['Status'] ?? ''));

            if (strcasecmp($xlsxStatus, 'Aktif') !== 0) {
                $stats['skipped_inactive']++;

                continue;
            }

            $code = trim((string) ($row['ID Pelanggan'] ?? ''));
            if ($code === '') {
                $stats['skipped_missing_customer']++;

                continue;
            }

            $customer = Customer::with('package')->where('code', $code)->first();
            if (! $customer) {
                $stats['skipped_missing_customer']++;

                continue;
            }

            $invoice = Invoice::where('customer_id', $customer->id)
                ->where('period', $this->period->toDateString())
                ->first();

            $amount = $invoice?->amount ?? $customer->package?->price;
            if ($amount === null) {
                $stats['skipped_no_package_or_amount']++;

                continue;
            }

            $paymentDate = $this->parsePaymentDate($row['Pembayaran Terakhir'] ?? null);
            if (! $paymentDate && $this->hasNonEmptyPaymentValue($row['Pembayaran Terakhir'] ?? null)) {
                $stats['parse_warnings']++;
            }

            $targetStatus = $paymentDate
                && $paymentDate->year === 2026
                && $paymentDate->month === 4
                    ? 'paid'
                    : 'unpaid';

            $stats['processed']++;
            $stats[$targetStatus]++;

            if ($dryRun) {
                $this->line("{$code}: {$targetStatus}");

                continue;
            }

            $dueDate = $this->dueDateForCustomer($customer);

            if ($invoice) {
                $invoice->fill([
                    'amount' => $amount,
                    'status' => $targetStatus,
                    'due_date' => $dueDate->toDateString(),
                    'generated_at' => $invoice->generated_at ?? now(),
                ]);
                $invoice->save();
                $stats['updated']++;
            } else {
                Invoice::create([
                    'customer_id' => $customer->id,
                    'period' => $this->period->toDateString(),
                    'amount' => $amount,
                    'status' => $targetStatus,
                    'due_date' => $dueDate->toDateString(),
                    'generated_at' => now(),
                ]);
                $stats['created']++;
            }
        }

        $this->newLine();
        $this->info('April 2026 invoice reconciliation complete.');

        foreach ($stats as $label => $count) {
            $this->line(str_replace('_', ' ', $label).": {$count}");
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

    private function parsePaymentDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->startOfDay();
        }

        $text = trim((string) $value);
        if (strcasecmp($text, 'Data Belum Ada') === 0) {
            return null;
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasNonEmptyPaymentValue(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return false;
        }

        return strcasecmp(trim((string) $value), 'Data Belum Ada') !== 0;
    }

    private function dueDateForCustomer(Customer $customer): Carbon
    {
        $day = $customer->due_day ?: 20;
        $day = min($day, $this->period->daysInMonth);

        return $this->period->copy()->day($day);
    }
}
