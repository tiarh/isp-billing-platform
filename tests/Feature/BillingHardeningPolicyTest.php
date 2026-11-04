<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FreezesMidMonthClock;
use Tests\TestCase;

#[Group('blocking-billing')]
class BillingHardeningPolicyTest extends TestCase
{
    use FreezesMidMonthClock;
    use RefreshDatabase;

    public function test_current_paid_reconnects_even_when_old_invoice_is_unpaid(): void
    {
        $customer = $this->customer(['status' => 'isolated']);
        $this->invoice($customer, ['period' => now()->subMonth()->startOfMonth(), 'due_date' => now()->subMonth()->endOfMonth()]);
        $current = $this->invoice($customer, ['period' => now()->startOfMonth(), 'due_date' => now()->subDay(), 'status' => 'paid']);

        $decision = app(BillingEligibilityService::class)->evaluate($customer);

        $this->assertSame($current->id, $decision['current_invoice_id']);
        $this->assertTrue($decision['current_is_paid']);
        $this->assertTrue($decision['reconnect_eligible']);
    }

    public function test_old_paid_does_not_open_access_when_current_is_past_due(): void
    {
        // The current invoice is already past its due date (H+1): the "latest
        // billable month paid" reconnect applies only to a NOT-YET-DUE current
        // invoice, so a past-due current invoice must not reconnect on old-paid
        // alone.
        $customer = $this->customer(['status' => 'isolated']);
        $this->invoice($customer, ['period' => now()->subMonth()->startOfMonth(), 'status' => 'paid']);
        $current = $this->invoice($customer, ['period' => now()->startOfMonth(), 'due_date' => now()->subDay(), 'status' => 'unpaid']);

        $decision = app(BillingEligibilityService::class)->evaluate($customer);

        $this->assertSame($current->id, $decision['current_invoice_id']);
        $this->assertFalse($decision['reconnect_eligible']);
        $this->assertTrue($decision['isolation_eligible'] === false);
    }

    public function test_future_paid_invoice_does_not_override_current_unpaid_policy(): void
    {
        // Freeze time: this test compares the current period against a future
        // one and was flaky on month-end days (30/31st), where
        // now()->addMonth()->startOfMonth() and the current period could land
        // in the same month and break the current-vs-future distinction.
        Carbon::setTestNow(now()->startOfMonth()->addDays(5));

        $customer = $this->customer(['status' => 'isolated']);
        $this->invoice($customer, ['period' => now()->startOfMonth(), 'due_date' => now()->subDay(), 'status' => 'unpaid']);
        $future = $this->invoice($customer, ['period' => now()->addMonth()->startOfMonth(), 'due_date' => now()->addMonth()->addDays(10), 'status' => 'paid']);

        $decision = app(BillingEligibilityService::class)->evaluate($customer);

        $this->assertSame($future->id, $decision['current_invoice_id']);
        $this->assertTrue($decision['current_is_future']);
        $this->assertFalse($decision['reconnect_eligible']);
    }

    public function test_no_current_invoice_is_review_only(): void
    {
        $customer = $this->customer(['status' => 'active']);
        $decision = app(BillingEligibilityService::class)->evaluate($customer);
        $this->assertSame('no_current_invoice', $decision['reason']);
        $this->assertFalse($decision['isolation_eligible']);
    }

    public function test_pending_payment_does_not_settle_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PENDING-'.uniqid(),
            'amount' => $invoice->amount,
            'status' => 'pending',
        ]);
        $this->assertFalse($invoice->fresh()->isSettled());
    }

    public function test_dry_run_command_does_not_write_last_run_or_dispatch(): void
    {
        Setting::set('billing_enforcement_dry_run', '1', 'boolean', 'billing');
        $customer = $this->customer();
        $this->invoice($customer, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);

        $this->artisan('billing:check-overdue', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(Setting::get('billing_enforcement_last_run', null));
    }

    public function test_terminating_customer_does_not_void_historical_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, ['status' => 'unpaid']);
        $customer->update(['status' => 'terminated']);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-HARDENING', 'name' => 'Hardening', 'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'BILL-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Billing Test', 'phone' => '081234567890', 'address' => 'Test',
            'pppoe_user' => 'billing.'.substr(uniqid(), -8), 'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(), 'amount' => 100000,
            'due_date' => now()->addDays(7)->toDateString(), 'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }
}
