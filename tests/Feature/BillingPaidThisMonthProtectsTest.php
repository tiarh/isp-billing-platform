<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentReversal;
use App\Models\Router;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class BillingPaidThisMonthProtectsTest extends TestCase
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
            'ip_address' => '192.0.2.52',
            'username' => 'test',
            'password' => 'test-only-router-password',
            'port' => 8728,
            'is_active' => true,
        ]);
        $area = Area::query()->create(['code' => 'AREA-BILLING-TEST', 'name' => 'Billing Test Area']);
        $this->customer = Customer::query()->create([
            'name' => 'L1 TEST CUSTOMER',
            'pppoe_user' => 'L1TEST_RT1_RW1',
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

    private function payThisMonth(): void
    {
        $invoice = Invoice::query()->where('customer_id', $this->customer->id)->first();
        // transactions.reference is NOT NULL; status/method are MySQL enums.
        // Manual/bulk/CSV payments are stored as `verified` in production
        // (2026-10-08 audit: 2628/2628 rows). The old fixture used `paid`
        // and hid that L1 never matched real payments.
        Transaction::query()->create([
            'invoice_id' => $invoice->id,
            'reference' => 'TRX-L1-TEST-'.$invoice->id,
            // Partial: arrears settle via Invoice::isSettled(), so a full payment
            // would clear the oldest arrears invoice itself.
            'amount' => 50000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
    }

    public function test_payment_this_month_protects_from_arrears_isolation(): void
    {
        $this->invoice(now()->subMonths(3)->startOfMonth()->toDateString(), now()->subMonths(3)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->payThisMonth();

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['paid_this_month']);
        $this->assertTrue($decision['arrears_past_grace']);
        $this->assertFalse($decision['isolation_eligible'], 'Bayar bulan ini harus melindungi dari isolir');
    }

    public function test_isolated_customer_paying_this_month_is_reconnect_eligible(): void
    {
        $this->customer->forceFill(['status' => 'isolated'])->save();
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->payThisMonth();

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertTrue($decision['reconnect_eligible'], 'Isolated + bayar bulan ini -> reconnect');
    }

    public function test_no_payment_this_month_with_arrears_still_isolates(): void
    {
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertFalse($decision['paid_this_month']);
        $this->assertTrue($decision['isolation_eligible']);
    }

    public function test_protection_can_be_disabled_via_setting(): void
    {
        Setting::set('billing_paid_this_month_protects', '0');
        $this->invoice(now()->subMonths(2)->startOfMonth()->toDateString(), now()->subMonths(2)->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->invoice(now()->startOfMonth()->toDateString(), now()->startOfMonth()->addDays(19)->toDateString(), 'unpaid');
        $this->payThisMonth();

        $decision = app(BillingEligibilityService::class)->evaluate($this->customer->fresh());

        $this->assertFalse($decision['paid_this_month']);
        $this->assertTrue($decision['isolation_eligible']);
    }

    /**
     * 2026-10-08 audit (cust 147853/147807): paid September on 8 October
     * while the October invoice (due 5 Oct, grace 3 days) is past grace.
     */
    private function septemberPaidInOctoberScenario(string $customerStatus): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 05:00:00', 'UTC'));
        $this->customer->forceFill(['status' => $customerStatus, 'join_date' => '2026-01-01'])->save();
        $september = $this->invoice('2026-09-01', '2026-09-05', 'paid');
        $this->invoice('2026-10-01', '2026-10-05', 'unpaid');
        $this->verifiedPayment($september, '2026-10-08 03:20:01', '2026-10-08 03:20:01');
    }

    private function verifiedPayment(Invoice $invoice, string $paidAtUtc, string $createdAtUtc): Transaction
    {
        $transaction = Transaction::query()->create([
            'invoice_id' => $invoice->id,
            'reference' => 'MANUAL-L1-'.$invoice->id.'-'.uniqid(),
            'channel' => 'manual',
            'amount' => 115000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => $paidAtUtc,
        ]);
        $transaction->forceFill(['created_at' => $createdAtUtc])->saveQuietly();

        return $transaction;
    }

    private function evaluate(): array
    {
        return app(BillingEligibilityService::class)->evaluate($this->customer->fresh());
    }

    public function test_isolated_customer_paying_september_in_october_past_grace_is_reconnected(): void
    {
        $this->septemberPaidInOctoberScenario('isolated');

        $decision = $this->evaluate();

        $this->assertTrue($decision['paid_this_month'], 'verified manual payment must count for L1');
        $this->assertSame('current_unpaid', $decision['reason']);
        $this->assertTrue($decision['reconnect_eligible']);
        $this->assertFalse($decision['isolation_eligible']);
    }

    public function test_reconnected_customer_is_not_reisolated_by_the_two_hourly_pass(): void
    {
        $this->septemberPaidInOctoberScenario('active');

        $decision = $this->evaluate();

        $this->assertTrue($decision['paid_this_month']);
        $this->assertFalse($decision['isolation_eligible'], 'paid this month must protect from re-isolation');
    }

    public function test_october_past_grace_without_payment_this_month_still_isolates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 05:00:00', 'UTC'));
        $this->customer->forceFill(['join_date' => '2026-01-01'])->save();
        $september = $this->invoice('2026-09-01', '2026-09-05', 'paid');
        $this->invoice('2026-10-01', '2026-10-05', 'unpaid');
        $this->verifiedPayment($september, '2026-09-04 03:00:00', '2026-09-04 03:00:00');

        $decision = $this->evaluate();

        $this->assertFalse($decision['paid_this_month']);
        $this->assertTrue($decision['isolation_eligible']);
    }

    public function test_backdated_paid_at_recorded_this_month_counts(): void
    {
        // tx 2625 (cust 1930): paid_at backdated to 22 Sep, recorded 8 Oct.
        Carbon::setTestNow(Carbon::parse('2026-10-08 05:00:00', 'UTC'));
        $this->customer->forceFill(['status' => 'isolated', 'join_date' => '2026-01-01'])->save();
        $september = $this->invoice('2026-09-01', '2026-09-05', 'paid');
        $this->invoice('2026-10-01', '2026-10-05', 'unpaid');
        $this->verifiedPayment($september, '2026-09-22 00:00:00', '2026-10-08 03:47:00');

        $decision = $this->evaluate();

        $this->assertTrue($decision['paid_this_month']);
        $this->assertTrue($decision['reconnect_eligible']);
    }

    public function test_reversed_payment_does_not_count(): void
    {
        $this->septemberPaidInOctoberScenario('active');
        $transaction = Transaction::query()->firstOrFail();
        PaymentReversal::query()->create([
            'invoice_id' => $transaction->invoice_id,
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'reversal_reference' => 'REV-L1-'.$transaction->id,
            'idempotency_key' => 'rev-l1-'.$transaction->id,
            'reason' => 'salah input',
            'reversed_by' => User::factory()->create()->id,
            'reversed_at' => now(),
        ]);

        $this->assertFalse($this->evaluate()['paid_this_month']);
    }

    public function test_wib_month_boundary_payment_at_0030_wib_on_the_first_counts_for_new_month(): void
    {
        $this->customer->forceFill(['join_date' => '2026-01-01'])->save();
        $september = $this->invoice('2026-09-01', '2026-09-05', 'paid');
        $this->invoice('2026-10-01', '2026-10-25', 'unpaid');
        // 2026-10-01 00:30 WIB == 2026-09-30 17:30 UTC (a UTC whereMonth would say September).
        $this->verifiedPayment($september, '2026-09-30 17:30:00', '2026-09-30 17:30:00');

        Carbon::setTestNow(Carbon::parse('2026-10-01 00:45:00', 'Asia/Jakarta'));
        $this->assertTrue($this->evaluate()['paid_this_month'], '00:30 WIB on 1 Oct is October');

        Carbon::setTestNow(Carbon::parse('2026-10-31 23:30:00', 'Asia/Jakarta'));
        $this->assertTrue($this->evaluate()['paid_this_month']);

        // 1 Nov 00:30 WIB: the October payment no longer counts.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'Asia/Jakarta'));
        $this->assertFalse($this->evaluate()['paid_this_month']);
    }

    public function test_wib_month_boundary_payment_at_2330_wib_on_last_day_is_previous_month(): void
    {
        $this->customer->forceFill(['join_date' => '2026-01-01'])->save();
        $september = $this->invoice('2026-09-01', '2026-09-05', 'paid');
        $this->invoice('2026-10-01', '2026-10-25', 'unpaid');
        // 2026-09-30 23:30 WIB == 2026-09-30 16:30 UTC.
        $this->verifiedPayment($september, '2026-09-30 16:30:00', '2026-09-30 16:30:00');

        Carbon::setTestNow(Carbon::parse('2026-10-01 00:45:00', 'Asia/Jakarta'));

        $this->assertFalse($this->evaluate()['paid_this_month']);
    }
}
