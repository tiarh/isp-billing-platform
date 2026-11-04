<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

class CustomerPackageBillingBulkTest extends TestCase
{
    use RefreshDatabase;

    private int $invoiceSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze mid-month: the invoice period/due-date arithmetic below used to
        // depend on the wall-clock day (now()->subMonth() overflows on the 29th-31st,
        // colliding with the unique (customer_id, period) invoice key).
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_package_change_updates_only_unpaid_invoices_without_transactions(): void
    {
        $oldPackage = Package::create(['code' => 'OLD', 'name' => 'Old', 'price' => 100000]);
        $newPackage = Package::create(['code' => 'NEW', 'name' => 'New', 'price' => 200000]);
        $customer = $this->customer($oldPackage);
        $unpaid = $this->invoice($customer, 100000);
        $paidHistory = $this->invoice($customer, 100000, now()->startOfMonth()->addMonthNoOverflow());
        Transaction::create([
            'invoice_id' => $paidHistory->id,
            'reference' => 'HISTORY-001',
            'channel' => 'manual',
            'method' => 'transfer',
            'status' => 'verified',
            'amount' => 100000,
            'paid_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('customers.bulk-package'), [
            'customer_ids' => [$customer->id],
            'package_id' => $newPackage->id,
        ])->assertRedirect();

        $this->assertSame($newPackage->id, $customer->refresh()->package_id);
        $this->assertSame(200000.0, (float) $unpaid->refresh()->amount);
        $this->assertSame(100000.0, (float) $paidHistory->refresh()->amount);
    }

    public function test_bulk_payment_is_idempotent_and_skips_invoice_with_existing_transaction(): void
    {
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = $this->customer($package);
        $invoice = $this->invoice($customer, 100000);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'invoice_ids' => [$invoice->id],
            'invoice_confirmations' => [$this->invoiceConfirmation($invoice)],
            'method' => 'transfer',
            'idempotency_key' => 'bulk-test-001',
            'confirmed' => '1',
        ];

        $this->actingAs($admin)->post(route('payments.bulk'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('payments.bulk'), $payload)->assertRedirect();

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(1, Transaction::where('invoice_id', $invoice->id)->count());
    }

    public function test_bulk_payment_can_settle_one_hundred_selected_invoices(): void
    {
        $package = Package::create(['code' => 'BULK-100', 'name' => 'Bulk 100', 'price' => 100000]);
        $customer = $this->customer($package);
        $invoices = collect(range(1, 100))->map(fn (int $month) => $this->invoice(
            $customer,
            100000,
            now()->startOfMonth()->addMonths($month),
        ));
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'invoice_ids' => $invoices->pluck('id')->all(),
            'invoice_confirmations' => $invoices->map(fn (Invoice $invoice) => $this->invoiceConfirmation($invoice))->all(),
            'method' => 'transfer',
            'idempotency_key' => 'bulk-100-test',
            'confirmed' => '1',
        ];

        $this->actingAs($admin)->post(route('payments.bulk'), $payload)->assertRedirect();

        $this->assertSame(100, Transaction::whereIn('invoice_id', $invoices->pluck('id'))->count());
        $this->assertSame(100, $invoices->filter(fn (Invoice $invoice) => $invoice->refresh()->status === 'paid')->count());
    }

    public function test_bulk_payment_accepts_iso_dates_from_inertia_payload(): void
    {
        $package = Package::create(['code' => 'BULK-ISO', 'name' => 'Bulk ISO', 'price' => 100000]);
        $customer = $this->customer($package);
        $invoice = $this->invoice($customer, 100000);
        $admin = User::factory()->create(['role' => 'admin']);
        $confirmation = $this->invoiceConfirmation($invoice);
        $confirmation['period'] = $invoice->period->toIso8601String();
        $confirmation['due_date'] = $invoice->due_date->toIso8601String();

        $this->actingAs($admin)->post(route('payments.bulk'), [
            'invoice_ids' => [$invoice->id],
            'invoice_confirmations' => [$confirmation],
            'method' => 'cash',
            'idempotency_key' => 'bulk-iso-dates',
            'confirmed' => '1',
        ])->assertRedirect();

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'status' => 'verified']);
    }

    #[Group('blocking-billing')]
    public function test_bulk_payment_reconnects_isolated_customer_after_one_invoice_is_paid(): void
    {
        Bus::fake();
        $package = Package::create(['code' => 'BASIC-ISO', 'name' => 'Basic Isolated', 'price' => 100000]);
        $router = Router::create([
            'name' => 'Bulk Router '.uniqid(),
            'ip_address' => '198.18.23.10',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package);
        $customer->update(['router_id' => $router->id, 'status' => 'isolated']);
        $invoice = $this->invoice($customer, 100000);
        $this->invoice($customer, 100000, now()->startOfMonth()->subMonthNoOverflow());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('payments.bulk'), [
            'invoice_ids' => [$invoice->id],
            'invoice_confirmations' => [$this->invoiceConfirmation($invoice)],
            'method' => 'transfer',
            'idempotency_key' => 'test-key',
            'confirmed' => '1',
        ])->assertRedirect();

        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(1, $customer->invoices()->where('status', 'unpaid')->count());
    }

    public function test_bulk_payment_rejects_stale_invoice_identity_before_writing_any_transaction(): void
    {
        $package = Package::create(['code' => 'BASIC-STALE', 'name' => 'Basic Stale', 'price' => 100000]);
        $customer = $this->customer($package);
        $invoice = $this->invoice($customer, 100000);
        $admin = User::factory()->create(['role' => 'admin']);
        $confirmation = $this->invoiceConfirmation($invoice);
        $confirmation['amount'] = 1;

        $this->actingAs($admin)->post(route('payments.bulk'), [
            'invoice_ids' => [$invoice->id],
            'invoice_confirmations' => [$confirmation],
            'method' => 'transfer',
            'idempotency_key' => 'bulk-stale-identity',
            'confirmed' => '1',
        ])->assertSessionHasErrors('invoice_confirmations');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('unpaid', $invoice->refresh()->status);
    }

    public function test_editing_package_price_updates_eligible_customer_invoices(): void
    {
        $package = Package::create(['code' => 'PRICE', 'name' => 'Price Test', 'price' => 100000]);
        $customer = $this->customer($package);
        $invoice = $this->invoice($customer, 100000);
        $admin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($admin)->put(route('packages.update', $package), [
            'name' => 'Price Test Updated',
            'price' => 150000,
            'mikrotik_profile' => 'STARTLITE',
        ])->assertRedirect();

        $this->assertSame(150000.0, (float) $invoice->refresh()->amount);
    }

    private function customer(Package $package): Customer
    {
        return Customer::create([
            'code' => 'CUST-'.$package->id.'-'.uniqid(),
            'name' => 'Bulk Test Customer',
            'address' => 'Test address',
            'phone' => '0800000000',
            'package_id' => $package->id,
            'pppoe_user' => 'bulk-test-'.$package->id,
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $customer, int $amount, $period = null): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            // Explicit code: the model's random 4-char suffix can collide when 100 invoices share a customer/month prefix.
            'code' => 'INV-TEST-'.$customer->id.'-'.(++$this->invoiceSeq),
            'period' => ($period ?: now()->startOfMonth())->format('Y-m-d'),
            'amount' => $amount,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
    }

    private function invoiceConfirmation(Invoice $invoice): array
    {
        $invoice->loadMissing('customer');

        return [
            'id' => $invoice->id,
            'code' => $invoice->code,
            'customer_code' => $invoice->customer->code,
            'customer_name' => $invoice->customer->name,
            'area_id' => $invoice->customer->area_id,
            'period' => $invoice->period->toDateString(),
            'amount' => $invoice->amount,
            'due_date' => $invoice->due_date->toDateString(),
            'status' => $invoice->status,
        ];
    }
}
