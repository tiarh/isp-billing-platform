<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentGateway\Data\StatusResult;
use App\Services\PaymentGateway\GatewayPaymentRecorder;
use App\Services\PaymentGateway\GatewayStatusChecker;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * PG-8: missed-callback status fallback, reconciliation page, audit.
 */
#[Group('blocking-billing')]
class PaymentGatewayReconciliationTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private FakePaymentGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakePaymentGateway;
        app(PaymentGatewayManager::class)->extend('fake', fn () => $this->fake);
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'fake', 'text', 'payment');
        $this->setPaymentWrites(true);
        Queue::fake();
    }

    public function test_due_selection_respects_backoff_lookback_and_provider(): void
    {
        $fresh = $this->attempt($this->invoice(), ['created_at' => now()->subMinutes(5)]);
        $due = $this->attempt($this->invoice(), ['created_at' => now()->subMinutes(15)]);
        $justChecked = $this->attempt($this->invoice(), ['created_at' => now()->subHour(), 'status_check_count' => 1, 'last_status_check_at' => now()->subMinutes(10)]);
        $backoffOver = $this->attempt($this->invoice(), ['created_at' => now()->subHours(2), 'status_check_count' => 1, 'last_status_check_at' => now()->subMinutes(31)]);
        $exhausted = $this->attempt($this->invoice(), ['created_at' => now()->subHours(9), 'status_check_count' => 4, 'last_status_check_at' => now()->subHours(7)]);
        $tooOld = $this->attempt($this->invoice(), ['created_at' => now()->subDays(4)]);
        $midtrans = $this->attempt($this->invoice(), ['created_at' => now()->subHour(), 'provider' => 'midtrans']);
        $paid = $this->attempt($this->invoice(), ['created_at' => now()->subHour(), 'status' => PaymentGatewayTransaction::STATUS_PAID]);

        $ids = app(GatewayStatusChecker::class)->due()->pluck('id')->all();

        sort($ids);
        $this->assertSame([$due->id, $backoffOver->id], $ids);
        unset($fresh, $justChecked, $exhausted, $tooOld, $midtrans, $paid);
    }

    public function test_paid_status_credits_invoice_net_amount_once(): void
    {
        $invoice = $this->invoice();
        $txn = $this->attempt($invoice);
        $this->fake->statuses[$txn->merchant_order_id] = new StatusResult(StatusResult::PAID, 104000, 4000, 'FAKEREF1');

        $this->assertSame('paid', app(GatewayStatusChecker::class)->check($txn));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(100000.0, (float) Transaction::query()->sole()->amount);
        $txn->refresh();
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->status);
        $this->assertSame(1, $txn->status_check_count);
        $this->assertSame('status_check', PaymentGatewayEvent::query()->sole()->payload['_source']);

        // A late callback for the same order is a duplicate credit, not a second payment.
        app(GatewayPaymentRecorder::class)->recordPaid($txn->fresh(), 104000, $txn->merchant_order_id.':cb:00', []);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_status_outcomes_mismatch_failed_pending_unknown(): void
    {
        $mismatch = $this->attempt($this->invoice());
        $this->fake->statuses[$mismatch->merchant_order_id] = new StatusResult(StatusResult::PAID, 1000);
        $this->assertSame('paid', app(GatewayStatusChecker::class)->check($mismatch));
        $this->assertSame(PaymentGatewayTransaction::STATUS_REJECTED, $mismatch->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());

        $failed = $this->attempt($this->invoice());
        $this->fake->statuses[$failed->merchant_order_id] = new StatusResult(StatusResult::FAILED);
        $this->assertSame('failed', app(GatewayStatusChecker::class)->check($failed));
        $this->assertSame(PaymentGatewayTransaction::STATUS_FAILED, $failed->fresh()->status);

        $expired = $this->attempt($this->invoice(), ['expires_at' => now()->subHours(2), 'status_check_count' => 3]);
        $this->fake->statuses[$expired->merchant_order_id] = new StatusResult(StatusResult::PENDING);
        $this->assertSame('expired', app(GatewayStatusChecker::class)->check($expired));
        $this->assertSame(PaymentGatewayTransaction::STATUS_EXPIRED, $expired->fresh()->status);

        $unknown = $this->attempt($this->invoice());
        $this->assertSame('unknown', app(GatewayStatusChecker::class)->check($unknown));
        $this->assertSame(PaymentGatewayTransaction::STATUS_PENDING, $unknown->fresh()->status);
        $this->assertSame(1, $unknown->fresh()->status_check_count);

        $paidNoAmount = $this->attempt($this->invoice());
        $this->fake->statuses[$paidNoAmount->merchant_order_id] = new StatusResult(StatusResult::PAID, null);
        $this->assertSame('unknown', app(GatewayStatusChecker::class)->check($paidNoAmount));

        $this->assertSame('skipped', app(GatewayStatusChecker::class)->check($failed->fresh()));
    }

    public function test_command_checks_due_attempts_and_closes_stale(): void
    {
        $due = $this->attempt($this->invoice(), ['created_at' => now()->subMinutes(20)]);
        $stale = $this->attempt($this->invoice(), ['created_at' => now()->subDays(2), 'status_check_count' => 4, 'expires_at' => now()->subDays(2)]);
        $this->fake->statuses[$due->merchant_order_id] = new StatusResult(StatusResult::PENDING);

        $this->artisan('payments:check-pending-gateway')
            ->expectsOutputToContain('pending=1. Closed stale: 1.')
            ->assertExitCode(0);

        $this->assertSame([$due->merchant_order_id], $this->fake->statusChecks);
        $this->assertSame(PaymentGatewayTransaction::STATUS_EXPIRED, $stale->fresh()->status);
    }

    public function test_fallbacks_are_scheduled_off_the_five_minute_boundaries(): void
    {
        $events = collect(app(Schedule::class)->events());

        $check = $events->first(fn ($e) => str_contains((string) $e->command, 'payments:check-pending-gateway'));
        $release = $events->first(fn ($e) => str_contains((string) $e->command, 'payments:release-held'));

        $this->assertNotNull($check);
        $this->assertNotNull($release);
        $this->assertSame('7,22,37,52 * * * *', $check->expression);
        $this->assertSame('13,43 * * * *', $release->expression);
    }

    public function test_reconciliation_page_access_and_area_scope(): void
    {
        [$areaA, $areaB] = [Area::create(['code' => 'PGA', 'name' => 'PG Area A']), Area::create(['code' => 'PGB', 'name' => 'PG Area B'])];
        $txnA = $this->attempt($this->invoice(['area_id' => $areaA->id]));
        $txnB = $this->attempt($this->invoice(['area_id' => $areaB->id]));

        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($teknisi)->get(route('payment-gateway.transactions'))->assertForbidden();

        $noc = User::factory()->create(['role' => 'noc']);
        $this->actingAs($noc)->get(route('payment-gateway.transactions'))->assertForbidden();
        $this->actingAs($noc)->post(route('payment-gateway.transactions.check', $txnA))->assertForbidden();
        $this->assertSame(0, $txnA->fresh()->status_check_count);

        $unscopedAdmin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($unscopedAdmin)->get(route('payment-gateway.transactions'))->assertOk();

        $superadmin = $this->gatewayUser();
        $this->actingAs($superadmin)->get(route('payment-gateway.transactions'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PaymentGateway/Transactions')
                ->has('transactions.data', 2)
                ->where('summary.pending.count', 2));

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($areaA);
        $this->actingAs($scoped)->get(route('payment-gateway.transactions'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.order_id', $txnA->merchant_order_id));

        $this->actingAs($scoped)->post(route('payment-gateway.transactions.check', $txnB))->assertForbidden();
        $this->actingAs($scoped)->post(route('payment-gateway.transactions.check', $txnA))->assertRedirect()->assertSessionHas('warning');
        $this->assertSame(1, $txnA->fresh()->status_check_count);
        $this->assertDatabaseHas('activity_log', ['description' => 'payment_gateway_status_checked']);

        $this->actingAs($superadmin)->get(route('payment-gateway.transactions', ['status' => 'paid', 'q' => 'nothing-matches']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transactions.data', 0)->where('filters.status', 'paid'));
    }

    public function test_audit_reports_held_and_stale_attempts(): void
    {
        $invoice = $this->invoice();
        PaymentGatewayEvent::create([
            'provider' => 'fake',
            'external_id' => 'HELD-1',
            'reference' => 'INV'.$invoice->id.'-HELD',
            'credit_key' => 'INV'.$invoice->id.'-HELD',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'credit_amount' => 100000,
            'status' => PaymentGatewayService::STATUS_HELD,
            'payload' => [],
            'processed_at' => now(),
            'held_at' => now(),
        ]);
        $this->attempt($this->invoice(), ['expires_at' => now()->subDays(2)]);

        $this->artisan('payments:audit-gateway', ['--fail-on-findings' => true])
            ->expectsOutputToContain('[4] Paid gateway notifications held')
            ->expectsOutputToContain('[6] Online payment attempts still open')
            ->expectsOutputToContain('Total findings: 2')
            ->assertExitCode(1);
    }

    private function invoice(array $customerOverrides = []): Invoice
    {
        return $this->gatewayInvoice($this->gatewayCustomer($customerOverrides));
    }

    private function attempt(Invoice $invoice, array $overrides = []): PaymentGatewayTransaction
    {
        $txn = PaymentGatewayTransaction::create(array_merge([
            'provider' => 'fake',
            'environment' => 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => 'INV'.$invoice->id.'-'.strtoupper(substr(uniqid(), -8)),
            'payment_method' => 'BC',
            'method_group' => 'va',
            'method_name' => 'BCA Virtual Account',
            'kind' => 'va',
            'amount_invoice' => 100000,
            'fee' => 4000,
            'fee_bearer' => 'customer',
            'amount_charged' => 104000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ], array_diff_key($overrides, ['created_at' => true])));

        if (isset($overrides['created_at'])) {
            $txn->forceFill(['created_at' => $overrides['created_at']])->saveQuietly();
        }

        return $txn->fresh();
    }
}
