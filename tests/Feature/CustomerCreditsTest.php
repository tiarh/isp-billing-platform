<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingWaEventService;
use App\Services\CustomerCreditService;
use App\Services\OpsAlertService;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\GatewayPaymentRecorder;
use App\Services\PaymentGatewayService;
use App\Services\PaymentReversalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * Second-VA PR-2: double / excess Duitku payments become customer credits,
 * the "Kelebihan bayar" admin page applies them. Synthetic data only.
 */
#[Group('blocking-billing')]
class CustomerCreditsTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const MERCHANT = 'DTEST01';

    private const SECRET = 'test-duitku-key';

    private const URL = '/webhooks/payment-gateway/duitku';

    /** @var list<string> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(DuitkuConfig::MERCHANT_CODE, self::MERCHANT, 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, self::SECRET, 'payment');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '0', 'boolean', 'payment');
        $this->setPaymentWrites(true);
        Queue::fake();
        Http::fake();

        $this->alerts = [];
        $alerts = Mockery::mock(OpsAlertService::class);
        $alerts->shouldReceive('send')->andReturnUsing(function (string $message): bool {
            $this->alerts[] = $message;

            return true;
        });
        $this->app->instance(OpsAlertService::class, $alerts);
    }

    // ---- gateway settlement ------------------------------------------------

    public function test_double_payment_becomes_available_credit_without_second_transaction_or_wa(): void
    {
        $wa = Mockery::mock(BillingWaEventService::class);
        $wa->shouldReceive('notifyPayment')->once();
        $this->app->instance(BillingWaEventService::class, $wa);

        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $first = $this->attempt($invoice, 'A01');
        $second = $this->attempt($invoice, 'B02');

        $this->sendCallback($first, 104000)->assertOk()->assertJsonPath('status', GatewayPaymentRecorder::STATUS_SUCCESS);
        $this->sendCallback($second, 104000)->assertOk()->assertJsonPath('status', PaymentGatewayService::STATUS_CREDITED_TO_BALANCE);

        // Revenue is counted once: exactly one invoice transaction, for the first payment.
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(PaymentGatewayTransaction::STATUS_CREDITED_BALANCE, $second->fresh()->status);

        $credit = CustomerCredit::query()->sole();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame(CustomerCredit::REASON_DOUBLE_PAYMENT, $credit->reason_code);
        $this->assertSame('100000.00', $credit->amount);
        $this->assertSame($invoice->customer_id, $credit->customer_id);
        $this->assertSame($invoice->id, $credit->source_invoice_id);
        $this->assertSame($second->merchant_order_id, $credit->gateway_reference);

        $alert = implode("\n", $this->alerts);
        $this->assertStringContainsString('saldo', $alert);
        $this->assertStringNotContainsString('refund required', $alert);
    }

    public function test_normal_single_payment_creates_no_credit(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $this->sendCallback($this->attempt($invoice, 'A01'), 104000)->assertOk();

        $this->assertSame(0, CustomerCredit::query()->count());
        $this->assertSame([], $this->alerts);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_overpay_after_partial_payment_settles_invoice_and_marks_excess_for_review(): void
    {
        $customer = $this->gatewayCustomer(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $invoice = $this->gatewayInvoice($customer);
        $txn = $this->attempt($invoice, 'A01');
        $this->manualPayment($invoice, 60000);

        $this->sendCallback($txn, 104000)
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_SETTLED_EXCESS_REVIEW);

        $this->assertSame('paid', $invoice->fresh()->status, 'customer must not stay unpaid/isolated');
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
        $gateway = Transaction::query()->where('channel', 'gateway')->sole();
        $this->assertSame(40000.0, (float) $gateway->amount);
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);
        Queue::assertPushed(ReconnectCustomerJob::class, fn (ReconnectCustomerJob $job) => $job->customer->is($customer));

        $credit = CustomerCredit::query()->sole();
        $this->assertSame(CustomerCredit::STATUS_NEEDS_REVIEW, $credit->status, 'excess is NOT auto-credited');
        $this->assertSame(CustomerCredit::REASON_OVERPAYMENT, $credit->reason_code);
        $this->assertSame('60000.00', $credit->amount);
        $this->assertStringContainsString('needs-review', implode("\n", $this->alerts));
        $this->assertStringNotContainsString('refund required', implode("\n", $this->alerts));
    }

    public function test_callback_retries_never_credit_twice(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $first = $this->attempt($invoice, 'A01');
        $second = $this->attempt($invoice, 'B02');
        $this->sendCallback($first, 104000)->assertOk();

        $a = $this->sendCallback($second, 104000)->assertOk()->json('event_id');
        $b = $this->sendCallback($second, 104000)->assertOk()->json('event_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, CustomerCredit::query()->count());

        // Same payment arriving under a DIFFERENT event key (e.g. status-check fallback racing the
        // callback): the loser of the insert race re-enters settlement and must reuse the credit row.
        app(GatewayPaymentRecorder::class)->recordPaid($second, 104000, 'status-check:other-key', ['x' => 1]);
        $this->assertSame(1, CustomerCredit::query()->count());
        $this->assertSame('100000.00', CustomerCredit::query()->sole()->amount);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_credit_row_is_unique_on_gateway_reference(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $service = app(CustomerCreditService::class);

        $one = $service->recordFromGateway($invoice, 'duitku', 'INV1-X', 5000, CustomerCredit::REASON_DOUBLE_PAYMENT, CustomerCredit::STATUS_AVAILABLE);
        $two = $service->recordFromGateway($invoice, 'duitku', 'INV1-X', 5000, CustomerCredit::REASON_DOUBLE_PAYMENT, CustomerCredit::STATUS_AVAILABLE);

        $this->assertTrue($one->is($two));
        $this->assertSame(1, CustomerCredit::query()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        CustomerCredit::create([
            'customer_id' => $invoice->customer_id, 'source_key' => 'duitku:INV1-X', 'amount' => 1,
            'reason_code' => 'double_payment', 'status' => 'available',
        ]);
    }

    public function test_double_payment_held_by_kill_switch_becomes_credit_on_release(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $first = $this->attempt($invoice, 'A01');
        $second = $this->attempt($invoice, 'B02');
        $this->sendCallback($first, 104000)->assertOk();

        $this->setPaymentWrites(false);
        $this->sendCallback($second, 104000)->assertOk()->assertJsonPath('status', PaymentGatewayService::STATUS_HELD);
        $this->assertSame(0, CustomerCredit::query()->count());

        $this->setPaymentWrites(true);
        app(PaymentGatewayService::class)->releaseHeld();

        $this->assertSame(1, CustomerCredit::query()->count());
        $this->assertSame(PaymentGatewayTransaction::STATUS_CREDITED_BALANCE, $second->fresh()->status);
        $this->assertSame(PaymentGatewayService::STATUS_CREDITED_TO_BALANCE, PaymentGatewayEvent::query()->where('reference', $second->merchant_order_id)->latest('id')->first()->status);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_payment_for_void_invoice_is_tracked_for_review_not_credited_to_invoice(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice, 'A01');
        $invoice->update(['status' => 'void']);

        $this->sendCallback($txn, 104000)->assertOk()->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_VOID);

        $this->assertSame(0, Transaction::query()->count());
        $credit = CustomerCredit::query()->sole();
        $this->assertSame(CustomerCredit::STATUS_NEEDS_REVIEW, $credit->status);
        $this->assertSame(CustomerCredit::REASON_VOID_INVOICE, $credit->reason_code);
    }

    // ---- Midtrans / other providers unchanged -------------------------------

    public function test_midtrans_overpayment_rules_are_unchanged(): void
    {
        $service = app(PaymentGatewayService::class);

        // Already settled invoice: still rejected_amount, no credit.
        $paid = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'paid']);
        $this->manualPayment($paid, 100000);
        $event = $service->handleMidtransNotification($this->midtransPayload($paid, 'INV'.$paid->id.'-LATE', 100000));
        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_AMOUNT, $event->status);

        // Larger than the remaining balance: whole payment rejected, invoice stays unpaid.
        $partial = $this->gatewayInvoice($this->gatewayCustomer());
        $this->manualPayment($partial, 60000);
        $event = $service->handleMidtransNotification($this->midtransPayload($partial, 'INV'.$partial->id.'-FULL', 100000));
        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_AMOUNT, $event->status);
        $this->assertSame('unpaid', $partial->fresh()->status);
        $this->assertSame(60000.0, $partial->fresh()->totalPaid());

        // Void invoice: still rejected_void.
        $void = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'void']);
        $event = $service->handleMidtransNotification($this->midtransPayload($void, 'INV'.$void->id.'-V', 100000));
        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_VOID, $event->status);

        $this->assertSame(0, CustomerCredit::query()->count());
        $this->assertStringContainsString('refund required', implode("\n", $this->alerts));
    }

    public function test_generic_webhook_provider_overpayment_rules_are_unchanged(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'paid']);
        $this->manualPayment($invoice, 100000);

        $event = app(PaymentGatewayService::class)->handleWebhook('acme', [
            'id' => 'evt-1', 'status' => 'paid', 'amount' => 100000, 'reference' => $invoice->code,
        ]);

        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_AMOUNT, $event->status);
        $this->assertSame(0, CustomerCredit::query()->count());
    }

    // ---- apply -------------------------------------------------------------

    public function test_admin_applies_full_credit_to_next_unpaid_invoice(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer, ['period' => now()->addMonth()->startOfMonth()->toDateString()]);
        $admin = $this->gatewayUser();

        $this->actingAs($admin)
            ->post(route('customer-credits.apply', $credit), ['invoice_id' => $next->id, 'expected_amount' => 100000])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('paid', $next->fresh()->status);
        $transaction = Transaction::query()->where('invoice_id', $next->id)->sole();
        $this->assertSame(100000.0, (float) $transaction->amount);
        $this->assertSame('verified', $transaction->status);
        $this->assertStringStartsWith('CREDIT-'.$credit->id.'-', $transaction->reference);
        $this->assertSame($admin->id, $transaction->admin_id);

        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->status);
        $this->assertSame($next->id, $credit->applied_invoice_id);
        $this->assertSame($transaction->id, $credit->applied_transaction_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_applied']);
    }

    public function test_partial_apply_leaves_remainder_available(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer, ['amount' => 30000]);

        app(CustomerCreditService::class)->apply($credit->id, $next->id, null, 100000, $this->gatewayUser());

        $this->assertSame('paid', $next->fresh()->status);
        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('70000.00', $credit->amount);
        $split = CustomerCredit::query()->where('parent_credit_id', $credit->id)->sole();
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $split->status);
        $this->assertSame('30000.00', $split->amount);
        $this->assertSame($next->id, $split->applied_invoice_id);
        $this->assertNull($split->source_key);
    }

    public function test_apply_locks_invoice_row_before_credit_row(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer);

        DB::enableQueryLog();
        app(CustomerCreditService::class)->apply($credit->id, $next->id, null, 100000, $this->gatewayUser());
        $sql = array_map(fn (array $q) => strtolower((string) $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $firstOf = function (string $table) use ($sql): ?int {
            foreach ($sql as $i => $q) {
                if (preg_match('/^select .* from [`"]?'.$table.'[`"]? where/', $q)) {
                    return $i;
                }
            }

            return null;
        };
        $invoiceAt = $firstOf('invoices');
        $creditAt = $firstOf('customer_credits');
        $this->assertNotNull($invoiceAt);
        $this->assertNotNull($creditAt);
        $this->assertLessThan($creditAt, $invoiceAt, 'invoice must be locked before the credit');
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $sql[$invoiceAt]);
            $this->assertStringContainsString('for update', $sql[$creditAt]);
        }
    }

    public function test_apply_with_explicit_amount_can_leave_invoice_partly_paid(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer);

        app(CustomerCreditService::class)->apply($credit->id, $next->id, 25000, 100000, $this->gatewayUser());

        $this->assertSame('unpaid', $next->fresh()->status);
        $this->assertSame(25000.0, $next->fresh()->totalPaid());
        $this->assertSame('75000.00', $credit->fresh()->amount);
    }

    public function test_apply_reconnects_isolated_customer_when_invoice_is_settled(): void
    {
        $customer = $this->gatewayCustomer(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer);

        app(CustomerCreditService::class)->apply($credit->id, $next->id, null, 100000, $this->gatewayUser());

        Queue::assertPushed(ReconnectCustomerJob::class, fn (ReconnectCustomerJob $job) => $job->customer->is($customer));
    }

    public function test_apply_guards_reject_invalid_targets_and_stale_or_double_requests(): void
    {
        $customer = $this->gatewayCustomer();
        $other = $this->gatewayCustomer();
        $credit = $this->credit($customer, 50000);
        $admin = $this->gatewayUser();
        $this->actingAs($admin);

        // Another customer's invoice.
        $foreign = $this->gatewayInvoice($other);
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $foreign->id, 'expected_amount' => 50000])->assertSessionHasErrors('invoice_id');

        // Amount above the credit / above the remaining invoice balance.
        $own = $this->gatewayInvoice($customer);
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $own->id, 'amount' => 60000, 'expected_amount' => 50000])->assertSessionHasErrors('amount');
        $small = $this->gatewayInvoice($customer, ['amount' => 20000, 'period' => now()->addMonth()->toDateString()]);
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $small->id, 'amount' => 30000, 'expected_amount' => 50000])->assertSessionHasErrors('amount');

        // Stale view (amount changed since the page was rendered).
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $own->id, 'expected_amount' => 99999])->assertSessionHasErrors('credit');

        // Paid / void invoices are not valid targets.
        $paid = $this->gatewayInvoice($customer, ['status' => 'paid', 'period' => now()->addMonths(2)->toDateString()]);
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $paid->id, 'expected_amount' => 50000])->assertSessionHasErrors('invoice_id');
        $void = $this->gatewayInvoice($customer, ['status' => 'void', 'period' => now()->addMonths(3)->toDateString()]);
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $void->id, 'expected_amount' => 50000])->assertSessionHasErrors('invoice_id');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);

        // Double click: the second identical request finds the credit already applied.
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $own->id, 'amount' => 20000, 'expected_amount' => 50000])->assertSessionHasNoErrors();
        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $own->id, 'amount' => 20000, 'expected_amount' => 50000])->assertSessionHasErrors('credit');
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('30000.00', $credit->fresh()->amount);
    }

    public function test_needs_review_credit_cannot_be_applied_until_made_balance(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 40000, CustomerCredit::STATUS_NEEDS_REVIEW);
        $next = $this->gatewayInvoice($customer);
        $admin = $this->gatewayUser();

        $this->actingAs($admin)->post(route('customer-credits.apply', $credit), ['invoice_id' => $next->id, 'expected_amount' => 40000])
            ->assertSessionHasErrors('credit');

        $this->post(route('customer-credits.resolve', $credit), ['action' => 'make_balance'])->assertSessionHas('success');
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_make_balance']);

        $this->post(route('customer-credits.apply', $credit), ['invoice_id' => $next->id, 'expected_amount' => 40000])->assertSessionHasNoErrors();
        $this->assertSame(40000.0, $next->fresh()->totalPaid());
    }

    public function test_refunded_manual_and_dismissed_need_a_reason(): void
    {
        $customer = $this->gatewayCustomer();
        $admin = $this->gatewayUser();
        $a = $this->credit($customer, 10000, CustomerCredit::STATUS_NEEDS_REVIEW);
        $b = $this->credit($customer, 20000, CustomerCredit::STATUS_NEEDS_REVIEW);

        $this->actingAs($admin)->post(route('customer-credits.resolve', $a), ['action' => 'refunded_manual'])->assertSessionHasErrors('reason');
        $this->post(route('customer-credits.resolve', $a), ['action' => 'dismissed', 'reason' => 'ab'])->assertSessionHasErrors('reason');
        $this->assertSame(CustomerCredit::STATUS_NEEDS_REVIEW, $a->fresh()->status);

        $this->post(route('customer-credits.resolve', $a), ['action' => 'refunded_manual', 'reason' => 'Transfer balik 16 Okt'])->assertSessionHas('success');
        $this->post(route('customer-credits.resolve', $b), ['action' => 'dismissed', 'reason' => 'Salah input pelanggan'])->assertSessionHas('success');

        $this->assertSame(CustomerCredit::STATUS_REFUNDED_MANUAL, $a->fresh()->status);
        $this->assertSame('Transfer balik 16 Okt', $a->fresh()->reason);
        $this->assertSame(CustomerCredit::STATUS_DISMISSED, $b->fresh()->status);
        $this->assertSame($admin->id, $b->fresh()->actor_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_refunded_manual']);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_dismissed']);

        // Final states cannot be resolved again.
        $this->post(route('customer-credits.resolve', $a), ['action' => 'dismissed', 'reason' => 'ulang lagi'])->assertSessionHasErrors('credit');
    }

    public function test_reversing_a_credit_application_returns_the_money_to_the_credit(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer);
        $admin = $this->gatewayUser();
        $service = app(CustomerCreditService::class);

        $service->apply($credit->id, $next->id, null, 100000, $admin);
        $this->assertSame('paid', $next->fresh()->status);
        $transaction = Transaction::query()->where('invoice_id', $next->id)->sole();

        app(PaymentReversalService::class)->reverse($next, $transaction, $admin, [
            'idempotency_key' => 'rev-credit-1',
            'reason' => 'Salah tagihan',
            'invoice_confirmation' => $next->code,
            'customer_confirmation' => (string) $customer->code,
        ]);

        $this->assertSame('unpaid', $next->fresh()->status);
        $this->assertSame(0.0, $next->fresh()->totalPaid());
        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('100000.00', $credit->amount);
        $this->assertNull($credit->applied_invoice_id);
        $this->assertNull($credit->applied_transaction_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_restored_by_reversal']);

        // The same credit can be applied again (once), with no double counting.
        $service->apply($credit->id, $next->id, null, 100000, $admin);
        $this->assertSame('paid', $next->fresh()->status);
        $this->assertSame(100000.0, $next->fresh()->totalPaid());
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->fresh()->status);
        $this->assertSame(1, CustomerCredit::query()->count());
    }

    public function test_reversing_a_partial_credit_application_restores_the_split_row(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer, ['amount' => 30000]);
        $admin = $this->gatewayUser();

        app(CustomerCreditService::class)->apply($credit->id, $next->id, null, 100000, $admin);
        $transaction = Transaction::query()->where('invoice_id', $next->id)->sole();
        app(PaymentReversalService::class)->reverse($next, $transaction, $admin, [
            'idempotency_key' => 'rev-credit-2',
            'reason' => 'Salah tagihan',
            'invoice_confirmation' => $next->code,
            'customer_confirmation' => (string) $customer->code,
        ]);

        $available = CustomerCredit::query()->where('status', CustomerCredit::STATUS_AVAILABLE)->sum('amount');
        $this->assertSame(100000.0, (float) $available, 'parent remainder + restored split = original credit');
        $this->assertSame(0, CustomerCredit::query()->where('status', CustomerCredit::STATUS_APPLIED)->count());
    }

    public function test_reversing_a_normal_payment_leaves_credits_alone(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 5000);
        $invoice = $this->gatewayInvoice($customer);
        $this->manualPayment($invoice, 100000);
        $admin = $this->gatewayUser();

        app(PaymentReversalService::class)->reverse($invoice, Transaction::query()->sole(), $admin, [
            'idempotency_key' => 'rev-normal',
            'reason' => 'Salah input',
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => (string) $customer->code,
        ]);

        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame('5000.00', $credit->fresh()->amount);
    }

    public function test_repeat_delivery_of_a_double_payment_does_not_resend_the_ops_alert(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $first = $this->attempt($invoice, 'A01');
        $second = $this->attempt($invoice, 'B02');
        $this->sendCallback($first, 104000)->assertOk();
        $this->sendCallback($second, 104000)->assertOk();
        $this->assertCount(1, $this->alerts);

        app(GatewayPaymentRecorder::class)->recordPaid($second, 104000, 'status-check:other-key', ['x' => 1]);
        $this->assertCount(1, $this->alerts, 'same gateway reference must alert once');
        $this->assertSame(1, CustomerCredit::query()->count());
    }

    public function test_list_includes_credits_of_archived_customers(): void
    {
        $customer = $this->gatewayCustomer(['name' => 'Pelanggan Arsip']);
        $this->credit($customer, 7000);
        $customer->delete();

        $this->actingAs($this->gatewayUser())->get(route('customer-credits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('credits.data', 1)
                ->where('credits.data.0.customer.name', 'Pelanggan Arsip')
                ->where('credits.data.0.customer.archived', true));
        $this->get(route('customer-credits.index', ['q' => 'Arsip']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('credits.data', 1));
    }

    public function test_applied_credit_is_labelled_saldo_in_the_invoice_export_label(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 100000);
        $next = $this->gatewayInvoice($customer);
        app(CustomerCreditService::class)->apply($credit->id, $next->id, null, 100000, $this->gatewayUser());

        $method = new ReflectionMethod(InvoiceController::class, 'paymentMethodLabel');
        $this->assertSame('saldo', $method->invoke(app(InvoiceController::class), Transaction::query()->sole()));
    }

    // ---- page + permissions --------------------------------------------------

    public function test_page_lists_credits_with_filters_and_apply_targets(): void
    {
        $customer = $this->gatewayCustomer(['name' => 'Budi Contoh']);
        $this->credit($customer, 100000);
        $this->credit($customer, 5000, CustomerCredit::STATUS_NEEDS_REVIEW);
        $next = $this->gatewayInvoice($customer);

        $this->actingAs($this->gatewayUser())->get(route('customer-credits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CustomerCredits/Index')
                ->has('credits.data', 2)
                ->where('summary.available.count', 1)
                ->where('summary.needs_review.amount', 5000)
                ->where('credits.data.1.open_invoices.0.id', $next->id)
                ->where('credits.data.1.open_invoices.0.remaining', 100000));

        $this->get(route('customer-credits.index', ['status' => 'needs_review']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('credits.data', 1)->where('filters.status', 'needs_review'));
        $this->get(route('customer-credits.index', ['q' => 'Budi']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('credits.data', 2));
        $this->get(route('customer-credits.index', ['q' => 'tidak-ada-yang-cocok']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('credits.data', 0));
    }

    public function test_permissions_superadmin_and_unscoped_admin_only(): void
    {
        $customer = $this->gatewayCustomer();
        $credit = $this->credit($customer, 10000);
        $invoice = $this->gatewayInvoice($customer);
        $payload = ['invoice_id' => $invoice->id, 'expected_amount' => 10000];

        $unscoped = User::factory()->create(['role' => 'admin']);
        $this->actingAs($unscoped)->get(route('customer-credits.index'))->assertOk();

        $area = Area::create(['code' => 'CRA', 'name' => 'Credit Area']);
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($area);
        $noc = User::factory()->create(['role' => 'noc']);
        $teknisi = User::factory()->create(['role' => 'teknisi']);

        foreach ([$scoped, $noc, $teknisi] as $denied) {
            $this->actingAs($denied)->get(route('customer-credits.index'))->assertForbidden();
            $this->actingAs($denied)->post(route('customer-credits.apply', $credit), $payload)->assertForbidden();
            $this->actingAs($denied)->post(route('customer-credits.resolve', $credit), ['action' => 'dismissed', 'reason' => 'tes tes'])->assertForbidden();
        }
        auth()->forgetGuards();
        $this->get(route('customer-credits.index'))->assertRedirect();

        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    // ---- helpers --------------------------------------------------------------

    private function credit(Customer $customer, int $amount, string $status = CustomerCredit::STATUS_AVAILABLE): CustomerCredit
    {
        return CustomerCredit::create([
            'customer_id' => $customer->id,
            'provider' => 'duitku',
            'gateway_reference' => 'INV0-TEST'.uniqid(),
            'amount' => $amount,
            'reason_code' => CustomerCredit::REASON_DOUBLE_PAYMENT,
            'status' => $status,
        ]);
    }

    private function manualPayment(Invoice $invoice, int $amount): void
    {
        Transaction::create([
            'reference' => 'MANUAL-'.uniqid(),
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function midtransPayload(Invoice $invoice, string $orderId, int $gross): array
    {
        return [
            'order_id' => $orderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'gross_amount' => number_format($gross, 2, '.', ''),
            'payment_type' => 'bank_transfer',
        ];
    }

    private function sendCallback(PaymentGatewayTransaction $txn, int $amount): TestResponse
    {
        $orderId = $txn->merchant_order_id;
        $amountText = (string) $amount;

        return $this->post(self::URL, [
            'merchantCode' => self::MERCHANT,
            'amount' => $amountText,
            'merchantOrderId' => $orderId,
            'productDetail' => 'Tagihan internet',
            'additionalParam' => '',
            'paymentCode' => 'BC',
            'resultCode' => '00',
            'merchantUserId' => '',
            'reference' => 'DREF'.$txn->id,
            'signature' => hash_hmac('sha256', self::MERCHANT.$amountText.$orderId, self::SECRET),
            'publisherOrderId' => 'PUB'.$txn->id,
            'spUserHash' => '',
            'settlementDate' => now()->toDateString(),
            'issuerCode' => '',
        ]);
    }

    private function attempt(Invoice $invoice, string $suffix): PaymentGatewayTransaction
    {
        return PaymentGatewayTransaction::create([
            'provider' => 'duitku',
            'environment' => 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => 'INV'.$invoice->id.'-2610161200'.$suffix,
            'payment_method' => 'BC',
            'method_group' => 'va',
            'method_name' => 'BCA Virtual Account',
            'kind' => 'va',
            'amount_invoice' => (int) $invoice->amount,
            'fee' => 4000,
            'fee_bearer' => 'customer',
            'amount_charged' => (int) $invoice->amount + 4000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'va_number' => '7007014000000001',
            'expires_at' => now()->addHours(3),
        ]);
    }
}
