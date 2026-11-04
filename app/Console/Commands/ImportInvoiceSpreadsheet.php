<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportInvoiceSpreadsheet extends Command
{
    protected $signature = 'billing:import-invoices-xlsx
                            {path : XLSX invoice/payment export}
                            {--period= : Billing period in YYYY-MM format}
                            {--location= : Exact spreadsheet location, for example SKYNET-KRIAN}
                            {--dry-run : Validate and report without database changes}';

    protected $description = 'Import an invoice spreadsheet into invoices and the payment ledger';

    public function handle(): int
    {
        $period = $this->parseRequestedPeriod((string) $this->option('period'));
        $location = $this->normalize((string) $this->option('location'));
        $path = $this->resolvePath((string) $this->argument('path'));
        $dryRun = (bool) $this->option('dry-run');

        if (! $period) {
            $this->error('Option --period wajib diisi dengan format YYYY-MM.');

            return self::FAILURE;
        }

        if ($location === '') {
            $this->error('Option --location wajib diisi agar import tidak melebar ke lokasi lain.');

            return self::FAILURE;
        }

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

        $missingHeaders = array_diff([
            'ID Pembayaran',
            'ID Pelanggan',
            'Lokasi',
            'Nominal Harus Dibayar',
            'Nominal Pembayaran',
            'Status Pembayaran',
            'Periode Pembayaran',
            'Metode Pembayaran',
            'Waktu Entry',
        ], array_keys($rows[0] ?? []));

        if ($missingHeaders !== []) {
            $this->error('Missing required XLSX headers: '.implode(', ', $missingHeaders));

            return self::FAILURE;
        }

        $prepared = [];
        $stats = [
            'source_rows' => count($rows),
            'eligible_rows' => 0,
            'matched_customers' => 0,
            'skipped_location' => 0,
            'skipped_period' => 0,
            'skipped_footer' => 0,
            'missing_customers' => 0,
            'invalid_rows' => 0,
            'created_invoices' => 0,
            'updated_invoices' => 0,
            'upserted_transactions' => 0,
        ];

        foreach ($rows as $row) {
            $code = $this->normalize((string) ($row['ID Pelanggan'] ?? ''));
            if ($code === '' || ! preg_match('/^[A-Z0-9_-]+$/', $code)) {
                $stats['skipped_footer']++;

                continue;
            }

            if ($this->normalize((string) ($row['Lokasi'] ?? '')) !== $location) {
                $stats['skipped_location']++;

                continue;
            }

            $rowPeriod = $this->parsePeriod($row['Periode Pembayaran'] ?? null);
            if (! $rowPeriod || ! $rowPeriod->equalTo($period)) {
                $stats['skipped_period']++;

                continue;
            }

            $amount = $this->parseMoney($row['Nominal Harus Dibayar'] ?? null);
            $paymentAmount = $this->parseMoney($row['Nominal Pembayaran'] ?? null);
            $paid = $this->isPaid((string) ($row['Status Pembayaran'] ?? ''));
            $paymentId = $this->normalize((string) ($row['ID Pembayaran'] ?? ''));

            if ($paymentId === '' || $amount <= 0 || ($paid && $paymentAmount <= 0)) {
                $stats['invalid_rows']++;

                continue;
            }

            $customer = Customer::withTrashed()->where('code', $code)->first();
            if (! $customer) {
                $stats['missing_customers']++;

                continue;
            }

            $stats['eligible_rows']++;
            $stats['matched_customers']++;
            $prepared[] = [
                'customer' => $customer,
                'period' => $period,
                'amount' => $amount,
                'payment_amount' => $paymentAmount,
                'paid' => $paid,
                'payment_id' => $paymentId,
                'paid_at' => $this->parseDate($row['Waktu Entry'] ?? null),
                'method' => $this->mapMethod((string) ($row['Metode Pembayaran'] ?? '')),
                'proof_url' => $this->firstUrl($row['Link Kwitansi'] ?? null),
            ];
        }

        $this->printStats($stats, $period, $location, $dryRun);

        if ($stats['missing_customers'] > 0) {
            $this->error('Import dibatalkan: ada customer code yang tidak ditemukan. Tidak ada customer baru dibuat.');

            return self::FAILURE;
        }

        if ($stats['invalid_rows'] > 0) {
            $this->error('Import dibatalkan: ada baris Krian dengan nilai invoice/payment tidak valid.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN selesai - tidak ada perubahan database.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($prepared, &$stats): void {
            foreach ($prepared as $item) {
                /** @var Customer $customer */
                $customer = $item['customer'];
                $invoice = Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->where('period', $item['period']->toDateString())
                    ->first();

                $invoiceData = [
                    'amount' => $item['amount'],
                    'status' => $item['paid'] ? 'paid' : 'unpaid',
                    'due_date' => $this->dueDateForCustomer($customer, $item['period'])->toDateString(),
                    'generated_at' => $invoice?->generated_at ?? now(),
                    'last_synced_at' => now(),
                ];

                if ($invoice) {
                    $invoice->fill($invoiceData);
                    $invoice->save();
                    $stats['updated_invoices']++;
                } else {
                    $invoice = Invoice::create(array_merge($invoiceData, [
                        'customer_id' => $customer->id,
                        'period' => $item['period']->toDateString(),
                    ]));
                    $stats['created_invoices']++;
                }

                if (! $item['paid']) {
                    continue;
                }

                $reference = 'XLSX-'.substr(sha1(implode('|', [
                    $item['payment_id'],
                    $customer->code,
                    $item['period']->toDateString(),
                    $item['payment_amount'],
                ])), 0, 32);
                $now = now();
                DB::table('transactions')->upsert([[
                    'legacy_id' => $reference,
                    'legacy_customer_code' => $customer->code,
                    'legacy_period' => $item['period']->toDateString(),
                    'invoice_id' => $invoice->id,
                    'reference' => $reference,
                    'channel' => 'manual',
                    'admin_id' => null,
                    'amount' => $item['payment_amount'],
                    'status' => 'verified',
                    'method' => $item['method'],
                    'proof_url' => $item['proof_url'],
                    'paid_at' => $item['paid_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['reference'], [
                    'legacy_id',
                    'legacy_customer_code',
                    'legacy_period',
                    'invoice_id',
                    'amount',
                    'status',
                    'method',
                    'proof_url',
                    'paid_at',
                    'updated_at',
                ]);
                $stats['upserted_transactions']++;
            }
        });

        $this->newLine();
        $this->info('Invoice spreadsheet import complete.');
        foreach ($stats as $label => $count) {
            $this->line(str_replace('_', ' ', $label).": {$count}");
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : base_path($path);
    }

    private function normalize(string $value): string
    {
        return strtoupper(ltrim(trim($value), "'"));
    }

    private function parseRequestedPeriod(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', trim($value).'-01')->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parsePeriod(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->startOfMonth();
        }

        try {
            return Carbon::parse((string) $value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value));
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMoney(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function isPaid(string $value): bool
    {
        $status = strtolower(trim($value));

        return str_contains($status, 'lunas') || str_contains($status, 'paid');
    }

    private function mapMethod(string $value): string
    {
        $method = strtolower($value);

        return match (true) {
            str_contains($method, 'cash'), str_contains($method, 'tunai') => 'cash',
            str_contains($method, 'transfer'), str_contains($method, 'bca'), str_contains($method, 'va_') => 'transfer',
            str_contains($method, 'qris'), str_contains($method, 'wallet'), str_contains($method, 'qr') => 'qris',
            default => 'other',
        };
    }

    private function firstUrl(mixed $value): ?string
    {
        preg_match('~https?://[^\s]+~', (string) $value, $matches);

        return isset($matches[0]) ? rtrim($matches[0], '.,);') : null;
    }

    private function dueDateForCustomer(Customer $customer, Carbon $period): Carbon
    {
        $day = $customer->due_day ?: ($customer->join_date?->day ?: 20);

        return $period->copy()->day(min((int) $day, $period->daysInMonth));
    }

    private function printStats(array $stats, Carbon $period, string $location, bool $dryRun): void
    {
        $this->line(($dryRun ? 'DRY RUN ' : '').'period='.$period->format('Y-m').' location='.$location);
        foreach ($stats as $label => $count) {
            $this->line(str_replace('_', ' ', $label).": {$count}");
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readSheetRows(string $path): array
    {
        $sharedStrings = $this->readSharedStrings($path);
        $sheetXml = $this->readZipEntry($path, 'xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            throw new \RuntimeException('Sheet1 not found in XLSX.');
        }

        $xml = simplexml_load_string($sheetXml, \SimpleXMLElement::class, LIBXML_NONET);
        if (! $xml) {
            throw new \RuntimeException('Invalid XLSX XML.');
        }
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $headers = [];
        $rows = [];
        foreach ($xml->xpath('//x:sheetData/x:row') as $row) {
            $row->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $values = [];
            foreach ($row->xpath('x:c') as $cell) {
                $values[$this->columnLetters((string) $cell['r'])] = $this->readCellValue($cell, $sharedStrings);
            }

            if ($headers === []) {
                $candidate = array_map(fn ($value) => trim((string) $value), $values);
                if (in_array('ID Pelanggan', $candidate, true) && in_array('Periode Pembayaran', $candidate, true)) {
                    $headers = $values;
                }

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

    /** @return array<int, string> */
    private function readSharedStrings(string $path): array
    {
        $content = $this->readZipEntry($path, 'xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        if (! $xml) {
            throw new \RuntimeException('Invalid sharedStrings.xml.');
        }
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xml->xpath('//x:si') as $item) {
            $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $strings[] = implode('', array_map('strval', $item->xpath('.//x:t')));
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

        $content = shell_exec('unzip -p '.escapeshellarg($path).' '.escapeshellarg($entry));

        return $content === null || $content === '' ? false : $content;
    }

    private function readCellValue(\SimpleXMLElement $cell, array $sharedStrings): mixed
    {
        $type = (string) $cell['t'];
        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            return implode('', array_map('strval', $cell->xpath('.//x:t')));
        }

        $raw = isset($cell->v) ? (string) $cell->v : null;
        if ($raw === null) {
            return null;
        }
        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? null;
        }

        return is_numeric($raw) ? (str_contains($raw, '.') ? (float) $raw : (int) $raw) : $raw;
    }

    private function columnLetters(string $cellRef): string
    {
        preg_match('/^[A-Z]+/', $cellRef, $matches);

        return $matches[0] ?? '';
    }
}
