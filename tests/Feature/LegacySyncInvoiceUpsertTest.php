<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\LegacySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacySyncInvoiceUpsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_sync_upserts_by_code_when_customer_period_differs(): void
    {
        $package = Package::create([
            'name' => 'Package A',
            'code' => 'PKG-A',
            'price' => 125000,
        ]);

        $customer = Customer::create([
            'code' => 'KRN048',
            'name' => 'Invoice Customer',
            'address' => 'Legacy Sync Address',
            'pppoe_user' => 'legacy.invoice.customer',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        Invoice::create([
            'code' => 'INV-202606-KRN048',
            'customer_id' => $customer->id,
            'period' => '2026-05-01',
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-05-20',
        ]);

        Http::fake([
            '*/api/v1/invoices' => Http::response(json_encode([
                [
                    'id' => '90001',
                    'customer_id' => 'KRN048',
                    'code' => 'INV-202606-KRN048',
                    'period' => '2026-06-01',
                    'amount' => 125000,
                    'status' => 'paid',
                    'due_date' => '2026-06-20',
                    'generated_at' => '2026-06-01 00:00:00',
                    'last_synced_at' => '2026-07-10 00:00:00',
                    'payment_link' => null,
                ],
            ])),
        ]);

        $count = app(LegacySyncService::class)->syncInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::count());

        $invoice = Invoice::first();
        $this->assertSame('INV-202606-KRN048', $invoice->code);
        $this->assertSame('2026-06-01', $invoice->period->toDateString());
        $this->assertSame('125000.00', $invoice->amount);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_invoice_sync_dedupes_duplicate_codes_in_single_batch(): void
    {
        $package = Package::create([
            'name' => 'Package A',
            'code' => 'PKG-A',
            'price' => 125000,
        ]);

        Customer::create([
            'code' => 'KRN100',
            'name' => 'Batch Customer',
            'address' => 'Legacy Sync Address',
            'pppoe_user' => 'legacy.batch.customer',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        Http::fake([
            '*/api/v1/invoices' => Http::response(json_encode([
                [
                    'id' => '90002',
                    'customer_id' => 'KRN100',
                    'code' => 'INV-202606-KRN100',
                    'period' => '2026-06-01',
                    'amount' => 100000,
                    'status' => 'unpaid',
                    'due_date' => '2026-06-20',
                ],
                [
                    'id' => '90003',
                    'customer_id' => 'KRN100',
                    'code' => 'INV-202606-KRN100',
                    'period' => '2026-06-01',
                    'amount' => 150000,
                    'status' => 'paid',
                    'due_date' => '2026-06-25',
                ],
            ])),
        ]);

        $count = app(LegacySyncService::class)->syncInvoices();

        $this->assertSame(2, $count);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('150000.00', Invoice::first()->amount);
        $this->assertSame('paid', Invoice::first()->status);
    }

    public function test_invoice_sync_generates_new_uuid_when_scraper_uuid_belongs_to_other_code(): void
    {
        $package = Package::create([
            'name' => 'Package A',
            'code' => 'PKG-A',
            'price' => 125000,
        ]);

        $existingCustomer = Customer::create([
            'code' => 'KRN200',
            'name' => 'Existing Customer',
            'address' => 'Legacy Sync Address',
            'pppoe_user' => 'legacy.existing.customer',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        $newCustomer = Customer::create([
            'code' => 'KRN201',
            'name' => 'New Customer',
            'address' => 'Legacy Sync Address',
            'pppoe_user' => 'legacy.new.customer',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        $sharedUuid = 'dc797ceb-dc80-43da-a56d-e954e6b182a9';

        Invoice::create([
            'uuid' => $sharedUuid,
            'code' => 'INV-202606-KRN200',
            'customer_id' => $existingCustomer->id,
            'period' => '2026-06-01',
            'amount' => 100000,
            'status' => 'paid',
            'due_date' => '2026-06-20',
        ]);

        Http::fake([
            '*/api/v1/invoices' => Http::response(json_encode([
                [
                    'id' => '90004',
                    'customer_id' => 'KRN201',
                    'uuid' => $sharedUuid,
                    'code' => 'INV-202606-KRN201',
                    'period' => '2026-06-01',
                    'amount' => 125000,
                    'status' => 'paid',
                    'due_date' => '2026-06-20',
                ],
            ])),
        ]);

        $count = app(LegacySyncService::class)->syncInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(2, Invoice::count());

        $newInvoice = Invoice::where('code', 'INV-202606-KRN201')->first();
        $this->assertNotNull($newInvoice);
        $this->assertNotSame($sharedUuid, $newInvoice->uuid);
    }
}
