<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\BillingEligibilityService;
use App\Services\InvoiceGenerationService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer's first invoice is for the month AFTER they join, with the due
 * date in that same next month. Before this, a customer who joined on the 25th
 * got an invoice for that same month with a due date on the 25th -> overdue
 * within days -> isolated before their first month (production incident).
 */
class InvoiceFirstMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Scenarios are written as of the joining month (Aug 2026): invoices
        // are created before their due date. A late-created invoice is floored
        // to its creation date (owner 2026-10-08), covered elsewhere.
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00', OpsTime::TZ));
    }

    private function customer(string $joinDate, ?int $dueDay = null): Customer
    {
        $package = Package::create([
            'code' => 'FIRST-'.uniqid(),
            'name' => 'First Month Test',
            'price' => 150000,
        ]);

        return Customer::create(array_filter([
            'code' => 'FIRST-'.uniqid(),
            'name' => 'First Month Customer',
            'phone' => '081200000000',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'first-month-'.uniqid(),
            'status' => 'active',
            'join_date' => $joinDate,
            'due_day' => $dueDay,
        ], fn ($value) => $value !== null));
    }

    public function test_joining_month_invoice_is_created_for_the_next_month(): void
    {
        $customer = $this->customer('2026-08-01', 1);
        $generator = app(InvoiceGenerationService::class);

        // Generate for the joining month (Aug) -> invoice must be for Sep.
        $invoice = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-08-01'));

        $this->assertNotNull($invoice);
        $this->assertSame('2026-09-01', $invoice->period->toDateString());
        $this->assertSame('2026-09-01', $invoice->due_date->toDateString());
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_mid_month_join_defers_period_and_due_to_next_month(): void
    {
        $customer = $this->customer('2026-08-25', 25);
        $generator = app(InvoiceGenerationService::class);

        $invoice = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-08-01'));

        $this->assertNotNull($invoice);
        $this->assertSame('2026-09-01', $invoice->period->toDateString());
        $this->assertSame('2026-09-25', $invoice->due_date->toDateString());
    }

    public function test_existing_customer_invoice_is_unchanged(): void
    {
        $customer = $this->customer('2024-08-20', 25);
        $generator = app(InvoiceGenerationService::class);

        $invoice = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-09-01'));

        $this->assertNotNull($invoice);
        $this->assertSame('2026-09-01', $invoice->period->toDateString());
        $this->assertSame('2026-09-25', $invoice->due_date->toDateString());
    }

    public function test_next_month_generation_is_idempotent(): void
    {
        $customer = $this->customer('2026-08-25', 25);
        $generator = app(InvoiceGenerationService::class);

        // Joining-month run creates the Sep invoice; the September run must not duplicate.
        $first = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-08-01'));
        $second = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-09-01'));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_customer_is_not_isolated_for_an_invoice_in_their_join_month(): void
    {
        $join = OpsTime::now()->startOfMonth();
        $customer = $this->customer($join->toDateString());

        // Legacy/edge data: an unpaid invoice for the join month, past due + grace.
        Invoice::create([
            'customer_id' => $customer->id,
            'period' => $join->toDateString(),
            'due_date' => OpsTime::now()->subDays(10)->toDateString(),
            'amount' => 150000,
            'status' => 'unpaid',
            'generated_at' => OpsTime::now(),
        ]);

        $decision = app(BillingEligibilityService::class)->evaluate($customer->fresh());

        $this->assertTrue($decision['join_month_not_billable']);
        $this->assertFalse($decision['isolation_eligible']);
        $this->assertSame('join_month_not_billable', $decision['reason']);
    }

    public function test_join_date_is_used_as_default_due_day(): void
    {
        $customer = $this->customer('2026-08-11');
        $this->assertSame(11, (int) $customer->due_day);

        $generator = app(InvoiceGenerationService::class);
        $invoice = $generator->ensureForPeriod($customer, CarbonImmutable::parse('2026-08-01'));

        $this->assertNotNull($invoice);
        $this->assertSame('2026-09-11', $invoice->due_date->toDateString());
    }
}
