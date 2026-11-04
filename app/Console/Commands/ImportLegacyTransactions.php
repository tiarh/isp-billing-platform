<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\LegacyAreaResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportLegacyTransactions extends Command
{
    protected $signature = 'legacy:import-transactions
                            {path=migration_data/transactions.json : JSON file path}
                            {--dry-run : Show what would change without writing}
                            {--limit=0 : Maximum rows to process, 0 for all}';

    protected $description = 'Import historical payment transactions from the legacy scraper JSON export';

    public function __construct(private LegacyAreaResolver $areaResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));

        if (! File::exists($path)) {
            $this->error("Transaction JSON not found: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(File::get($path), true);
        if (! is_array($rows)) {
            $this->error("Invalid transaction JSON: {$path}");

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $customerIdsByCode = Customer::withTrashed()->pluck('id', 'code');
        $invoiceIdsByCustomerPeriod = Invoice::query()
            ->select('id', 'customer_id', 'period', 'amount', 'status')
            ->get()
            ->keyBy(fn (Invoice $invoice) => $invoice->customer_id.'|'.$invoice->period->toDateString());

        $stats = [
            'rows' => count($rows),
            'prepared' => 0,
            'upserted' => 0,
            'created_stub_customers' => 0,
            'created_stub_invoices' => 0,
            'skipped_bad_customer_code' => 0,
            'skipped_bad_period' => 0,
            'null_paid_at' => 0,
        ];
        $upsertRows = [];
        $now = now();
        $legacyOccurrences = [];
        $fallbackPackageId = Package::firstOrCreate(
            ['name' => 'Legacy/Unknown Package'],
            [
                'code' => 'PKG-UNKNOWN',
                'price' => 0,
            ]
        )->id;

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            $customerCode = trim((string) ($row['id_pelanggan'] ?? ''));
            if ($customerCode === '') {
                $stats['skipped_bad_customer_code']++;
                $bar->advance();

                continue;
            }

            $customerId = $customerIdsByCode->get($customerCode);
            if (! $customerId) {
                $customerId = $this->createStubCustomer($row, $customerCode, $fallbackPackageId, $now);
                $customerIdsByCode->put($customerCode, $customerId);
                $stats['created_stub_customers']++;
            }

            $period = $this->parsePeriod($row['periode'] ?? null);
            if (! $period) {
                $stats['skipped_bad_period']++;
                $bar->advance();

                continue;
            }

            $invoice = $invoiceIdsByCustomerPeriod->get($customerId.'|'.$period->toDateString());
            if (! $invoice) {
                $invoice = $this->createStubInvoice($row, $customerId, $customerCode, $period, $now);
                $invoiceIdsByCustomerPeriod->put($customerId.'|'.$period->toDateString(), $invoice);
                $stats['created_stub_invoices']++;
            }

            $paidAt = $this->parsePaidAt($row['waktu_entry'] ?? null);
            if (! $paidAt) {
                $stats['null_paid_at']++;
            }

            $amount = (float) ($row['nominal_pembayaran'] ?? 0);
            $baseLegacyId = $this->legacyId($row, $period);
            $legacyOccurrences[$baseLegacyId] = ($legacyOccurrences[$baseLegacyId] ?? 0) + 1;
            $legacyId = $legacyOccurrences[$baseLegacyId] === 1
                ? $baseLegacyId
                : $baseLegacyId.'-'.$legacyOccurrences[$baseLegacyId];

            $upsertRows[] = [
                'legacy_id' => $legacyId,
                'legacy_customer_code' => $customerCode,
                'legacy_period' => $period->toDateString(),
                'invoice_id' => $invoice->id,
                'reference' => 'LEGACY-'.$legacyId,
                'channel' => 'manual',
                'admin_id' => null,
                'amount' => $amount,
                'status' => $this->mapStatus($row['status_pembayaran'] ?? null),
                'method' => $this->mapMethod($row['metode'] ?? null),
                'proof_url' => $this->cleanUrl($row['bukti_pembayaran_url'] ?? null),
                'paid_at' => $paidAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $stats['prepared']++;
            $bar->advance();
        }

        if (! $dryRun) {
            foreach (array_chunk($upsertRows, 1000) as $chunk) {
                DB::table('transactions')->upsert($chunk, ['reference'], [
                    'legacy_id',
                    'legacy_customer_code',
                    'legacy_period',
                    'invoice_id',
                    'channel',
                    'admin_id',
                    'amount',
                    'status',
                    'method',
                    'proof_url',
                    'paid_at',
                    'updated_at',
                ]);
                $stats['upserted'] += count($chunk);
            }
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($stats as $label => $count) {
            $this->line(str_replace('_', ' ', $label).": {$count}");
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function parsePeriod(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parsePaidAt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        foreach (['d-m-Y h:i:s A', 'd-m-Y H:i:s', 'Y-m-d H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                //
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function legacyId(array $row, Carbon $period): string
    {
        return substr(sha1(implode('|', [
            $row['id_pelanggan'] ?? '',
            $period->toDateString(),
            $row['nominal_pembayaran'] ?? '',
            $row['metode'] ?? '',
            $row['waktu_entry'] ?? '',
        ])), 0, 24);
    }

    private function mapMethod(?string $method): string
    {
        $method = strtolower((string) $method);

        return match (true) {
            str_contains($method, 'cash'), str_contains($method, 'tunai') => 'cash',
            str_contains($method, 'transfer'), str_contains($method, 'bank') => 'transfer',
            str_contains($method, 'qris'), str_contains($method, 'qr') => 'qris',
            default => 'other',
        };
    }

    private function mapStatus(?string $status): string
    {
        $status = strtolower((string) $status);

        return str_contains($status, 'lunas') || str_contains($status, 'paid')
            ? 'verified'
            : 'pending';
    }

    private function cleanUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' || str_ends_with($url, '/') ? null : $url;
    }

    private function createStubCustomer(array $row, string $customerCode, int $fallbackPackageId, Carbon $now): int
    {
        $resolvedArea = $this->areaResolver->resolve([
            'id_pelanggan' => $customerCode,
            'nama_pelanggan' => $row['nama_pelanggan'] ?? null,
            'alamat' => $row['alamat'] ?? null,
        ]);
        $areaId = null;
        if ($resolvedArea['area']) {
            $areaId = Area::firstOrCreate(
                ['name' => $resolvedArea['area']],
                ['code' => Str::slug($resolvedArea['area'])]
            )->id;
        }

        return DB::table('customers')->insertGetId([
            'code' => $customerCode,
            'legacy_id' => $customerCode,
            'name' => $row['nama_pelanggan'] ?: 'Unknown (Legacy Payment)',
            'phone' => '-',
            'address' => $row['alamat'] ?: '-',
            'area_id' => $areaId,
            'package_id' => $fallbackPackageId,
            'pppoe_user' => $customerCode.'_LEGACY',
            'status' => 'terminated',
            'due_day' => 20,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $now,
        ]);
    }

    private function createStubInvoice(array $row, int $customerId, string $customerCode, Carbon $period, Carbon $now): Invoice
    {
        $amount = (float) ($row['nominal_harus_dibayar'] ?? $row['nominal_pembayaran'] ?? 0);
        $status = str_contains(strtolower((string) ($row['status_pembayaran'] ?? '')), 'lunas') ? 'paid' : 'unpaid';
        $invoiceId = DB::table('invoices')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => 'LEGACY-'.$period->format('Ym').'-'.$customerCode,
            'customer_id' => $customerId,
            'period' => $period->toDateString(),
            'amount' => $amount,
            'status' => $status,
            'due_date' => $period->copy()->day(min(20, $period->daysInMonth))->toDateString(),
            'generated_at' => $now,
            'last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Invoice::findOrFail($invoiceId);
    }
}
