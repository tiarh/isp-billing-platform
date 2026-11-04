<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SimpleXlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ImportInvoiceSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_index_filters_period_and_area(): void
    {
        $package = $this->package();
        $areaA = Area::create(['name' => 'Krian Test', 'code' => 'KRIAN-TEST']);
        $areaB = Area::create(['name' => 'Other Test', 'code' => 'OTHER-TEST']);
        $customerA = $this->customer('KRN001', $package->id, $areaA->id);
        $customerB = $this->customer('KRN002', $package->id, $areaB->id);

        Invoice::create([
            'customer_id' => $customerA->id,
            'period' => '2026-08-01',
            'amount' => 125000,
            'status' => 'unpaid',
            'due_date' => '2026-08-20',
        ]);
        Invoice::create([
            'customer_id' => $customerA->id,
            'period' => '2026-07-01',
            'amount' => 125000,
            'status' => 'paid',
            'due_date' => '2026-07-20',
        ]);
        Invoice::create([
            'customer_id' => $customerB->id,
            'period' => '2026-08-01',
            'amount' => 125000,
            'status' => 'unpaid',
            'due_date' => '2026-08-20',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('invoices.index', [
            'period' => '2026-08-01',
            'area_id' => $areaA->id,
        ]));

        $response->assertOk();
        $rows = $response->viewData('page')['props']['invoices']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame($customerA->id, $rows[0]['customer']['id']);
        $this->assertSame(['2026-08-01', '2026-07-01'], $response->viewData('page')['props']['periods']);

        $this->actingAs($admin)
            ->get(route('invoices.export', ['period' => '2026-08-01', 'area_id' => $areaA->id]))
            ->assertDownload();
    }

    public function test_invoice_export_includes_customer_pppoe(): void
    {
        $package = $this->package();
        $customer = $this->customer('KRN-PPPOE', $package->id);
        Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-08-01',
            'amount' => 125000,
            'status' => 'unpaid',
            'due_date' => '2026-08-20',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('invoices.export', ['period' => '2026-08-01']));

        $file = $response->baseResponse->getFile();
        self::assertNotNull($file);

        $zip = new ZipArchive;
        self::assertTrue($zip->open($file->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        self::assertIsString($sheet);
        self::assertStringContainsString('PPPoE', $sheet);
        self::assertStringContainsString('krn-pppoe', $sheet);
    }

    public function test_dry_run_does_not_write_and_location_is_exact(): void
    {
        $package = $this->package();
        $customer = $this->customer('KRN001', $package->id);
        $xlsx = $this->xlsx([
            $this->row(63282, 'KRN001', 'SKYNET-KRIAN', 'Lunas'),
            $this->row(63283, 'RDG001', 'RANDUAGUNG', 'Lunas'),
            ['Total Balance', null, null, null],
        ]);

        $this->artisan('billing:import-invoices-xlsx', [
            'path' => $xlsx,
            '--period' => '2026-08',
            '--location' => 'SKYNET-KRIAN',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Invoice::where('customer_id', $customer->id)->count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_import_is_idempotent_and_updates_invoice_and_ledger(): void
    {
        $package = $this->package();
        $customer = $this->customer('KRN001', $package->id);
        $xlsx = $this->xlsx([
            $this->row(63282, 'KRN001', 'SKYNET-KRIAN', 'Lunas'),
        ]);

        foreach ([false, false] as $unused) {
            $this->artisan('billing:import-invoices-xlsx', [
                'path' => $xlsx,
                '--period' => '2026-08',
                '--location' => 'SKYNET-KRIAN',
            ])->assertSuccessful();
        }

        $invoice = Invoice::where('customer_id', $customer->id)->where('period', '2026-08-01')->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('125000.00', $invoice->amount);
        $this->assertSame(1, Transaction::where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseHas('transactions', [
            'invoice_id' => $invoice->id,
            'amount' => 125000,
            'status' => 'verified',
            'method' => 'cash',
        ]);
    }

    private function package(): Package
    {
        return Package::create([
            'code' => 'PKG-'.uniqid(),
            'name' => 'Paket Krian',
            'price' => 125000,
        ]);
    }

    private function customer(string $code, int $packageId, ?int $areaId = null): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '08123456789',
            'address' => 'Krian',
            'area_id' => $areaId,
            'pppoe_user' => strtolower($code),
            'package_id' => $packageId,
            'status' => 'active',
            'join_date' => '2026-01-01',
            'due_day' => 20,
        ]);
    }

    /** @return array<int, mixed> */
    private function row(int $paymentId, string $code, string $location, string $status): array
    {
        return [
            $paymentId, $code, "'", 'Customer '.$code, 'Address', "'0", "'{$location}",
            'Paket Krian', '10Mbps', 'Rp. 125,000', 'Rp. 125,000', $status, null,
            '2026-08-01', 'Cash Tunai', 'Budi', '2026-08-13 11:47:10', 'Imported test',
            null, null, 'Rp.', 'https://example.test/receipt', null,
        ];
    }

    private function xlsx(array $rows): string
    {
        return SimpleXlsxWriter::create('invoice-import-test.xlsx', [
            'ID Pembayaran', 'ID Pelanggan', 'No KTP', 'Nama Pelanggan', 'Alamat', 'Tlp', 'Lokasi',
            'Nama Langganan', 'Keterangan Langganan', 'Nominal Harus Dibayar', 'Nominal Pembayaran',
            'Status Pembayaran', 'Bukti Pembayaran', 'Periode Pembayaran', 'Metode Pembayaran',
            'Nama Sales', 'Waktu Entry', 'Keterangan', 'Metode Insentif Sales', 'Insentif Sales',
            'Nominal Insentif', 'Link Kwitansi', 'Aksi',
        ], $rows);
    }
}
