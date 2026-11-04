<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\OpsAlertService;
use App\Services\PaymentGateway\Duitku\DuitkuCallbackHandler;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\GatewayPaymentRecorder;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * PG-5: Duitku HTTP callback → verified, idempotent invoice settlement.
 * Synthetic merchant code / API key only.
 */
#[Group('blocking-billing')]
class DuitkuCallbackTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const MERCHANT = 'DTEST01';

    private const SECRET = 'test-duitku-key';

    private const URL = '/webhooks/payment-gateway/duitku';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(DuitkuConfig::MERCHANT_CODE, self::MERCHANT, 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, self::SECRET, 'payment');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '0', 'boolean', 'payment');
        $this->setPaymentWrites(true);
        Queue::fake();
        Http::fake();
    }

    public function test_paid_callback_credits_net_invoice_amount_and_reconnects(): void
    {
        $customer = $this->gatewayCustomer(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $invoice = $this->gatewayInvoice($customer);
        $txn = $this->attempt($invoice, fee: 4000);

        $this->sendCallback($this->fields($txn->merchant_order_id, '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', GatewayPaymentRecorder::STATUS_SUCCESS);

        $this->assertSame('paid', $invoice->fresh()->status);
        $transaction = Transaction::query()->sole();
        $this->assertSame('PG-DUITKU-'.$txn->merchant_order_id, $transaction->reference);
        $this->assertSame(100000.0, (float) $transaction->amount, 'fee is not credited to the invoice');
        $this->assertSame('gateway', $transaction->channel);
        $this->assertSame('va', $transaction->method);

        $txn->refresh();
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->status);
        $this->assertSame($transaction->id, $txn->transaction_id);
        $this->assertNotNull($txn->paid_at);
        $this->assertSame('DREF0001', $txn->provider_reference);

        $event = PaymentGatewayEvent::query()->sole();
        $this->assertSame('127.0.0.1', $event->payload['_source_ip']);
        $this->assertSame(DuitkuConfig::SIGNATURE_HMAC, $event->payload['_signature_mode']);
        $this->assertArrayNotHasKey('signature', $event->payload);
        Queue::assertPushed(ReconnectCustomerJob::class, fn (ReconnectCustomerJob $job) => $job->customer->is($customer));
    }

    public function test_legacy_md5_signature_is_accepted(): void
    {
        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $fields = $this->fields($txn->merchant_order_id, '104000', '00');
        $fields['signature'] = md5(self::MERCHANT.'104000'.$txn->merchant_order_id.self::SECRET);

        $this->sendCallback($fields)->assertOk();

        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);
        $this->assertSame(DuitkuConfig::SIGNATURE_LEGACY, PaymentGatewayEvent::query()->sole()->payload['_signature_mode']);
    }

    public function test_forged_or_foreign_callbacks_are_rejected_without_side_effects(): void
    {
        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));

        $badSignature = $this->fields($txn->merchant_order_id, '104000', '00');
        $badSignature['signature'] = hash_hmac('sha256', 'tampered', self::SECRET);
        $this->sendCallback($badSignature)->assertStatus(401);

        // Signed for a different amount than claimed.
        $tampered = $this->fields($txn->merchant_order_id, '104000', '00');
        $tampered['amount'] = '1000';
        $this->sendCallback($tampered)->assertStatus(401);

        $foreign = $this->fields($txn->merchant_order_id, '104000', '00', merchant: 'DOTHER9');
        $this->sendCallback($foreign)->assertStatus(401);

        $this->sendCallback(['merchantOrderId' => $txn->merchant_order_id])->assertStatus(400);

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(PaymentGatewayTransaction::STATUS_PENDING, $txn->fresh()->status);
    }

    public function test_query_string_cannot_override_signed_body(): void
    {
        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $failed = $this->fields($txn->merchant_order_id, '104000', '01');

        $this->post(self::URL.'?resultCode=00&amount=104000', $failed)->assertOk()->assertJsonPath('status', 'failed');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(PaymentGatewayTransaction::STATUS_FAILED, $txn->fresh()->status);
    }

    public function test_amount_mismatch_is_not_credited_and_alerts_ops(): void
    {
        $this->expectOpsAlert('does not match charged amount');
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice);

        $this->sendCallback($this->fields($txn->merchant_order_id, '100000', '00'))
            ->assertOk()
            ->assertJsonPath('status', GatewayPaymentRecorder::STATUS_AMOUNT_MISMATCH);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(PaymentGatewayTransaction::STATUS_REJECTED, $txn->fresh()->status);
        $this->assertStringContainsString('received 100000, expected 104000', (string) $txn->fresh()->last_error);
    }

    public function test_duplicate_callbacks_credit_once(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice);
        $fields = $this->fields($txn->merchant_order_id, '104000', '00');

        $first = $this->sendCallback($fields)->assertOk()->json('event_id');
        $second = $this->sendCallback($fields)->assertOk()->json('event_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
    }

    public function test_unknown_order_is_recorded_unmatched_and_alerts(): void
    {
        $this->expectOpsAlert('unknown order id');

        $this->sendCallback($this->fields('INV999-UNKNOWN', '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_UNMATCHED);

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_kill_switch_off_holds_then_release_marks_attempt_paid(): void
    {
        $this->setPaymentWrites(false);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice);

        $this->sendCallback($this->fields($txn->merchant_order_id, '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_HELD);
        $this->assertSame(PaymentGatewayTransaction::STATUS_HELD, $txn->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());

        $this->setPaymentWrites(true);
        app(PaymentGatewayService::class)->releaseHeld();

        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);
        $this->assertSame(100000.0, (float) Transaction::query()->sole()->amount);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_invoice_settled_manually_before_callback_becomes_customer_credit(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice);
        Transaction::create([
            'reference' => 'MANUAL-PG5',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);
        $invoice->update(['status' => 'paid']);

        $this->sendCallback($this->fields($txn->merchant_order_id, '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_CREDITED_TO_BALANCE);

        // No second invoice transaction: the money is tracked as a customer credit (see CustomerCreditsTest).
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(PaymentGatewayTransaction::STATUS_CREDITED_BALANCE, $txn->fresh()->status);
        $this->assertSame(100000.0, (float) CustomerCredit::query()->sole()->amount);
    }

    public function test_void_invoice_payment_is_rejected(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $txn = $this->attempt($invoice);
        $invoice->update(['status' => 'void']);

        $this->sendCallback($this->fields($txn->merchant_order_id, '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_VOID);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_ip_whitelist_blocks_unknown_sources_when_enabled(): void
    {
        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $fields = $this->fields($txn->merchant_order_id, '104000', '00');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '1', 'boolean', 'payment');
        Setting::set(DuitkuConfig::CALLBACK_IPS, ['198.51.100.10'], 'json', 'payment');

        $this->sendCallback($fields)->assertStatus(403);
        $this->assertSame('127.0.0.1', Cache::get(DuitkuCallbackHandler::LAST_REJECTED_IP_CACHE_KEY)['ip']);
        $this->assertSame(0, PaymentGatewayEvent::query()->count());

        Setting::set(DuitkuConfig::CALLBACK_IPS, ['198.51.100.10', '127.0.0.1'], 'json', 'payment');
        $this->sendCallback($fields)->assertOk();
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);
    }

    public function test_default_whitelist_is_split_by_environment(): void
    {
        // Lists from docs.duitku.com, kept in config/site.php (config/services.php defaults).
        $production = config('site.duitku_callback_ips.production');
        $sandbox = config('site.duitku_callback_ips.sandbox');
        $this->assertGreaterThanOrEqual(2, count($production));
        $this->assertGreaterThanOrEqual(2, count($sandbox));
        $this->assertSame($production, config('services.duitku.callback_ips.production'));
        $this->assertSame($sandbox, config('services.duitku.callback_ips.sandbox'));
        $this->assertSame([], array_intersect($production, $sandbox));

        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '1', 'boolean', 'payment');
        Setting::set(DuitkuConfig::ENVIRONMENT, DuitkuConfig::ENV_PRODUCTION, 'text', 'payment');
        $this->assertSame($production, app(DuitkuConfig::class)->callbackIps());

        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $fields = $this->fields($txn->merchant_order_id, '104000', '00');

        // Production mode: a sandbox source IP is refused, a production one accepted.
        foreach ($sandbox as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(self::URL, $fields)->assertStatus(403);
        }
        $this->assertSame(0, PaymentGatewayEvent::query()->count());
        $this->withServerVariables(['REMOTE_ADDR' => end($production)])->post(self::URL, $fields)->assertOk();
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);

        // Sandbox mode: production source IPs are refused.
        Setting::set(DuitkuConfig::ENVIRONMENT, DuitkuConfig::ENV_SANDBOX, 'text', 'payment');
        $other = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $this->withServerVariables(['REMOTE_ADDR' => $production[0]])
            ->post(self::URL, $this->fields($other->merchant_order_id, '104000', '00'))
            ->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => $sandbox[0]])
            ->post(self::URL, $this->fields($other->merchant_order_id, '104000', '00'))
            ->assertOk();
    }

    public function test_unconfigured_duitku_rejects_callbacks(): void
    {
        $txn = $this->attempt($this->gatewayInvoice($this->gatewayCustomer()));
        $fields = $this->fields($txn->merchant_order_id, '104000', '00');
        Setting::setSecret(DuitkuConfig::API_KEY, '', 'payment');

        $this->sendCallback($fields)->assertStatus(401);
        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_pilot_allowlist_never_filters_callbacks(): void
    {
        $listed = $this->gatewayCustomer();
        $unlisted = $this->gatewayCustomer();
        Setting::set(PaymentGatewaySettings::PILOT_CUSTOMER_IDS, [$listed->id], 'json', 'payment');

        // A VA created before the pilot list was narrowed, for a customer who is not listed.
        $invoice = $this->gatewayInvoice($unlisted);
        $txn = $this->attempt($invoice);

        $this->sendCallback($this->fields($txn->merchant_order_id, '104000', '00'))
            ->assertOk()
            ->assertJsonPath('status', GatewayPaymentRecorder::STATUS_SUCCESS);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, $txn->fresh()->status);
        $this->assertSame(1, Transaction::query()->count());
    }

    private function sendCallback(array $fields): TestResponse
    {
        return $this->post(self::URL, $fields);
    }

    private function fields(string $orderId, string $amount, string $resultCode, string $merchant = self::MERCHANT): array
    {
        return [
            'merchantCode' => $merchant,
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'productDetail' => 'Tagihan internet',
            'additionalParam' => '',
            'paymentCode' => 'BC',
            'resultCode' => $resultCode,
            'merchantUserId' => '',
            'reference' => 'DREF0001',
            'signature' => hash_hmac('sha256', $merchant.$amount.$orderId, self::SECRET),
            'publisherOrderId' => 'PUB0001',
            'spUserHash' => '',
            'settlementDate' => now()->toDateString(),
            'issuerCode' => '',
        ];
    }

    private function attempt(Invoice $invoice, int $fee = 4000): PaymentGatewayTransaction
    {
        return PaymentGatewayTransaction::create([
            'provider' => 'duitku',
            'environment' => 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => 'INV'.$invoice->id.'-261010120000'.strtoupper(substr(uniqid(), -3)),
            'payment_method' => 'BC',
            'method_group' => 'va',
            'method_name' => 'BCA Virtual Account',
            'kind' => 'va',
            'amount_invoice' => (int) $invoice->amount,
            'fee' => $fee,
            'fee_bearer' => 'customer',
            'amount_charged' => (int) $invoice->amount + $fee,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'va_number' => '7007014000000001',
            'expires_at' => now()->addDay(),
        ]);
    }

    private function expectOpsAlert(string $contains): void
    {
        $alerts = Mockery::mock(OpsAlertService::class);
        $alerts->shouldReceive('send')->once()->withArgs(fn (string $message) => str_contains($message, $contains))->andReturnTrue();
        $this->app->instance(OpsAlertService::class, $alerts);
    }
}
