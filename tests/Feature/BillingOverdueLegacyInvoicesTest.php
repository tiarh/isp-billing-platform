<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Services\BillingEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingOverdueLegacyInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now()->startOfMonth()->addDays(5));

        // packages.code is NOT NULL and customers.router_id / area_id are
        // foreign keys, so the fixture creates real rows instead of pointing
        // at production ids (router 511 / area 414) that do not exist in CI.
        $package = Package::query()->create([
            'code' => 'PKG-50MB-TEST',
            'name' => 'Paket 50MB TEST',
            'price' => 115000,
            'mikrotik_profile' => '50MB',
        ]);
        $router = Router::query()->create([
            'name' => 'Billing Test Router',
            'ip_address' => '192.0.2.51',
            'username' => 'test',
            'password' => 'test-only-router-password',
            'port' => 8728,
            'is_active' => true,
        ]);
        $area = Area::query()->create(['code' => 'AREA-BILLING-TEST', 'name' => 'Billing Test Area']);
        $this->customer = Customer::query()->create([
            'name' => 'USER OVERDUE TEST',
            'pppoe_user' => 'USEROVERDUE_RT1_RW1',
            'status' => 'active',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'area_id' => $area->id,
            'address' => 'TEST',
            'join_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $period, string $due, string $status): Invoice
    {
        return Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'period' => $period,
            'due_date' => $due,
            'status' => $status,
            'amount' => 115000,
        ]);
    }

    public function test_arrears_past_grace_isolate_even_when_current_invoice_is_not_due(): void
    {
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $current = $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['arrears_past_grace']);
        $this->assertTrue($decision['isolation_eligible'], 'Tunggakan lama lewat H+3 harus mengisolir');
        $this->assertSame($current->id, $decision['current_invoice_id']);
    }

    public function test_isolated_customer_with_all_arrears_paid_becomes_reconnect_eligible(): void
    {
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['reconnect_eligible'], 'Tunggakan lunas + isolated -> reconnect eligible');
    }

    public function test_isolated_customer_who_paid_prior_month_reconnects_when_current_not_due(): void
    {
        // Paid September (previous month), October invoice issued but not due yet.
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['prior_invoices_settled']);
        $this->assertTrue($decision['reconnect_paid_through_prior_month'], 'Bayar s/d bulan lalu + current belum due harus reconnect');
        $this->assertTrue($decision['reconnect_eligible'], 'User paid September tetap isolir harus dibuka');
    }

    public function test_isolated_customer_with_unpaid_prior_month_does_not_reconnect(): void
    {
        // Previous month still unpaid: must stay isolated even if current is not due.
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertFalse($decision['reconnect_paid_through_prior_month']);
        $this->assertFalse($decision['reconnect_eligible'], 'Ada tunggakan bulan lalu -> tetap isolir');
    }

    public function test_group_b_isolated_customer_with_older_arrears_but_latest_month_paid_reconnects(): void
    {
        // Group B (owner 2026-10-02): paid the latest billable month (previous
        // month) but an OLDER month is still unpaid. Older arrears are
        // receivables and must not keep the customer isolated.
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonths(3)->startOfMonth()->toDateString(), now()->subMonths(3)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['latest_prior_invoice_settled'], 'Bulan billable terbaru harus terbaca paid');
        $this->assertFalse($decision['prior_invoices_settled'], 'Masih ada tunggakan lama');
        $this->assertTrue($decision['reconnect_paid_through_prior_month']);
        $this->assertTrue($decision['reconnect_eligible'], 'Group B: bayar bulan terbaru walau ada tunggakan lama -> reconnect');
    }

    public function test_active_customer_with_current_month_paid_is_not_isolated_despite_older_arrears(): void
    {
        // Owner 2026-10-02: current month (latest invoice) is PAID but an older
        // month is still unpaid. A customer who paid the current month must not
        // be cut off for older arrears.
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(22)->toDateString(), 'paid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['current_is_paid']);
        $this->assertTrue($decision['arrears_past_grace']);
        $this->assertFalse($decision['isolation_eligible'], 'Bayar bulan berjalan -> jangan isolir walau ada tunggakan lama');
    }

    public function test_isolated_customer_with_current_month_paid_reconnects_despite_older_arrears(): void
    {
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(22)->toDateString(), 'paid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['reconnect_eligible'], 'Bayar bulan berjalan + isolated -> reconnect walau tunggakan lama');
    }

    public function test_group_b_active_customer_with_older_arrears_but_latest_month_paid_is_not_isolated(): void
    {
        // Active-side mirror of Group B: older arrears exist, but the latest
        // billable month is paid, so arrears isolation must not fire.
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['arrears_past_grace'], 'Tunggakan lama tetap terbaca');
        $this->assertFalse($decision['isolation_eligible'], 'Group B: bayar bulan terbaru -> jangan isolir walau ada tunggakan lama');
    }

    public function test_isolated_customer_with_current_past_grace_does_not_reconnect(): void
    {
        // Current invoice already past grace and unpaid: reconnect must NOT fire.
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(2)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['grace_elapsed']);
        $this->assertFalse($decision['reconnect_paid_through_prior_month']);
        $this->assertFalse($decision['reconnect_eligible']);
    }

    public function test_current_invoice_past_grace_still_isolates(): void
    {
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(2)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['isolation_eligible']);
    }

    public function test_fully_paid_customer_is_not_isolated(): void
    {
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'paid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'paid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertFalse($decision['isolation_eligible']);
    }
}
