<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingEligibilityService;
use App\Services\LegacySyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Arrears via Invoice::isSettled(), legacy invoice-sync M6 guard, and the
 * legacy_full_sync_enabled gate on `sync:legacy`.
 */
#[Group('blocking-billing')]
class BillingSettledArrearsLegacyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'Asia/Jakarta'));
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invoice_settled_by_transaction_but_status_unpaid_is_not_arrears(): void
    {
        $customer = $this->customer();
        $september = $this->invoice($customer, ['period' => '2026-09-01', 'due_date' => '2026-09-20']);
        $this->invoice($customer, ['period' => '2026-10-01', 'due_date' => '2026-10-20']);
        $this->payment($september, 100000, '2026-09-25 03:00:00', 'PG-MIDTRANS-'.uniqid());
        $this->assertSame('unpaid', $september->fresh()->status);

        $decision = $this->decide($customer);
        $this->assertFalse($decision['arrears_past_grace']);
        $this->assertTrue($decision['latest_prior_invoice_settled']);
        $this->assertFalse($decision['isolation_eligible']);
    }

    public function test_zero_amount_invoice_is_settled_and_not_arrears(): void
    {
        $customer = $this->customer();
        $september = $this->invoice($customer, ['period' => '2026-09-01', 'due_date' => '2026-09-20', 'amount' => 0]);
        $this->invoice($customer, ['period' => '2026-10-01', 'due_date' => '2026-10-20']);

        $this->assertTrue($september->fresh()->isSettled());
        $decision = $this->decide($customer);
        $this->assertFalse($decision['arrears_past_grace']);
        $this->assertFalse($decision['isolation_eligible']);
    }

    public function test_isolated_customer_reconnects_when_prior_month_settled_by_transaction(): void
    {
        $customer = $this->customer(['status' => 'isolated']);
        $september = $this->invoice($customer, ['period' => '2026-09-01', 'due_date' => '2026-09-20']);
        $this->invoice($customer, ['period' => '2026-10-01', 'due_date' => '2026-10-20']);
        $this->payment($september, 100000, '2026-09-28 03:00:00');

        $this->assertTrue($this->decide($customer)['reconnect_eligible']);
    }

    public function test_short_paid_invoice_with_status_paid_is_not_arrears(): void
    {
        // Admin-accepted short payment after a price change: status paid,
        // transactions below the amount.
        $customer = $this->customer();
        $september = $this->invoice($customer, ['period' => '2026-09-01', 'due_date' => '2026-09-20', 'status' => 'paid']);
        $this->invoice($customer, ['period' => '2026-10-01', 'due_date' => '2026-10-20']);
        $this->payment($september, 60000, '2026-09-25 03:00:00');
        $this->assertFalse($september->fresh()->isSettled());

        $decision = $this->decide($customer);
        $this->assertFalse($decision['arrears_past_grace']);
        $this->assertTrue($decision['latest_prior_invoice_settled']);
        $this->assertFalse($decision['isolation_eligible']);
    }

    public function test_unpaid_arrears_still_isolate(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, ['period' => '2026-09-01', 'due_date' => '2026-09-20']);
        $this->invoice($customer, ['period' => '2026-10-01', 'due_date' => '2026-10-20']);

        $decision = $this->decide($customer);
        $this->assertTrue($decision['arrears_past_grace']);
        $this->assertTrue($decision['isolation_eligible']);
    }

    public function test_legacy_sync_never_overwrites_ebilling_paid_invoices(): void
    {
        $customer = $this->customer(['code' => 'KRN900']);
        $owned = $this->invoice($customer, ['code' => 'INV-202609-KRN900', 'period' => '2026-09-01', 'due_date' => '2026-09-20', 'status' => 'paid']);
        $this->payment($owned, 100000, '2026-09-25 03:00:00');
        $paidNoTrx = $this->invoice($customer, ['code' => 'INV-202608-KRN900', 'period' => '2026-08-01', 'due_date' => '2026-08-20', 'status' => 'paid']);
        $plain = $this->invoice($customer, ['code' => 'INV-202607-KRN900', 'period' => '2026-07-01', 'due_date' => '2026-07-20', 'status' => 'unpaid']);

        Http::fake([
            '*/api/v1/invoices' => Http::response(json_encode([
                $this->legacyRow('INV-202609-KRN900', '2026-09-01', 150000, 'unpaid'),
                $this->legacyRow('INV-202608-KRN900', '2026-08-01', 100000, 'unpaid'),
                $this->legacyRow('INV-202607-KRN900', '2026-07-01', 100000, 'paid'),
            ])),
        ]);

        $service = app(LegacySyncService::class);
        $service->syncInvoices();

        $this->assertSame('paid', $owned->fresh()->status);
        $this->assertSame('100000.00', $owned->fresh()->amount);
        $this->assertSame('paid', $paidNoTrx->fresh()->status);
        $this->assertSame('paid', $plain->fresh()->status, 'legacy may still settle an untouched invoice');
        $this->assertSame(['has_ebilling_payment' => 1, 'ebilling_paid' => 1], $service->lastInvoiceGuardStats());
    }

    public function test_legacy_sync_guard_matches_on_customer_period_when_code_differs(): void
    {
        $customer = $this->customer(['code' => 'KRN901']);
        $owned = $this->invoice($customer, ['code' => 'INV-EB-0001', 'period' => '2026-09-01', 'due_date' => '2026-09-20', 'status' => 'unpaid']);
        $this->payment($owned, 50000, '2026-09-25 03:00:00');

        Http::fake([
            '*/api/v1/invoices' => Http::response(json_encode([
                $this->legacyRow('INV-202609-KRN901', '2026-09-01', 999000, 'paid'),
            ])),
        ]);

        app(LegacySyncService::class)->syncInvoices();

        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->count());
        $fresh = $owned->fresh();
        $this->assertSame('INV-EB-0001', $fresh->code);
        $this->assertSame('100000.00', $fresh->amount);
        $this->assertSame('unpaid', $fresh->status);
    }

    public function test_full_legacy_sync_is_disabled_by_default(): void
    {
        Http::fake();

        $this->artisan('sync:legacy')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_full_legacy_sync_runs_when_setting_enabled(): void
    {
        Setting::set('legacy_full_sync_enabled', '1', 'boolean', 'billing');
        Http::fake(['*' => Http::response('[]')]);

        $this->artisan('sync:legacy')->assertSuccessful();

        Http::assertSent(fn () => true);
    }

    private function decide(Customer $customer): array
    {
        return app(BillingEligibilityService::class)->evaluate($customer->fresh());
    }

    private function legacyRow(string $code, string $period, int $amount, string $status): array
    {
        return [
            'id' => (string) random_int(100000, 999999),
            'customer_id' => 'KRN'.substr($code, -3),
            'code' => $code,
            'period' => $period,
            'amount' => $amount,
            'status' => $status,
            'due_date' => substr($period, 0, 8).'20',
        ];
    }

    private function payment(Invoice $invoice, int $amount, string $paidAtUtc, ?string $reference = null): Transaction
    {
        return Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => $reference ?? 'TRX-'.uniqid(),
            'amount' => $amount,
            'status' => 'verified',
            'method' => 'transfer',
            'paid_at' => Carbon::parse($paidAtUtc, 'UTC'),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-ELIG', 'name' => 'Elig', 'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'ELG-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Eligibility Test', 'phone' => '081234567890', 'address' => 'Test',
            'pppoe_user' => 'elig.'.substr(uniqid(), -8), 'package_id' => $package->id,
            'status' => 'active',
            'join_date' => '2026-06-01',
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => '2026-10-01', 'amount' => 100000,
            'due_date' => '2026-10-20', 'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }
}
