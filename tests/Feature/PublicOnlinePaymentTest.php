<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingWaTemplateService;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * PG-7: customer pay page for direct-API providers (Duitku) + WA {payment_link}.
 */
#[Group('blocking-billing')]
class PublicOnlinePaymentTest extends TestCase
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

    public function test_page_offers_online_payment_only_when_available(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('online.provider', 'fake')
                ->where('online.remaining', 100000)
                ->where('online.fee_bearer', 'customer')
                ->where('online.open_transaction', null)
                ->where('from_isolir', false)
                ->where('midtrans_enabled', false));

        $this->setPaymentWrites(false);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online', null));

        $this->setPaymentWrites(true);
        $invoice->update(['status' => 'paid']);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online', null));
    }

    public function test_isolir_signed_link_marks_source(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $url = URL::temporarySignedRoute('public.isolated.payment', now()->addMinutes(15), ['uuid' => $invoice->uuid]);

        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('from_isolir', true));

        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC', 'from_isolir' => true])->assertOk();
        $this->assertSame('isolir', PaymentGatewayTransaction::query()->sole()->source);
    }

    public function test_methods_start_reuse_and_public_dto(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->getJson(route('public.invoice.online.methods', $invoice->uuid))
            ->assertOk()
            ->assertJsonPath('remaining', 100000)
            ->assertJsonPath('methods.0.code', 'BC')
            ->assertJsonPath('methods.0.total', 104000);

        $first = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertOk()
            ->assertJsonPath('transaction.kind', 'va')
            ->assertJsonPath('transaction.amount_charged', 104000)
            ->assertJsonPath('transaction.fee', 4000)
            ->json('transaction');

        $this->assertArrayNotHasKey('id', $first);
        $this->assertArrayNotHasKey('invoice_id', $first);
        $this->assertArrayNotHasKey('meta', $first);

        $again = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])->assertOk()->json('transaction');
        $this->assertSame($first['order_id'], $again['order_id']);
        $this->assertCount(1, $this->fake->charges);

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online.open_transaction.order_id', $first['order_id']));
    }

    public function test_start_refusals_return_safe_messages(): void
    {
        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);

        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'ZZ'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_method');
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => '../x'])->assertStatus(422);

        $void = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'void']);
        $this->postJson(route('public.invoice.online.start', $void->uuid), ['method' => 'BC'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'void');

        $this->fake->chargeFailure = new \RuntimeException('provider_secret=do-not-disclose');
        $response = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error');
        $this->assertStringNotContainsString('do-not-disclose', $response->getContent());

        $this->getJson(route('public.invoice.online.methods', 'missing-uuid'))->assertNotFound();
    }

    public function test_methods_are_empty_without_leaking_when_provider_throws_or_amount_below_minimum(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->fake->methodsFailure = new \RuntimeException('provider_secret=do-not-disclose');
        $response = $this->getJson(route('public.invoice.online.methods', $invoice->uuid))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('methods', []);
        $this->assertStringNotContainsString('do-not-disclose', $response->getContent());

        // Below the configured minimum: same clean empty list (the page then offers manual transfer).
        $this->fake->methodsFailure = null;
        $small = $this->gatewayInvoice($this->gatewayCustomer(), ['amount' => 5000]);
        $this->getJson(route('public.invoice.online.methods', $small->uuid))
            ->assertOk()
            ->assertJsonPath('methods', []);
    }

    public function test_start_failures_carry_reason_for_the_fallback_allowlist(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->fake->chargeFailure = new \RuntimeException('provider down');
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error');
        $this->fake->chargeFailure = null;

        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'ZZ'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_method');

        $paid = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'paid']);
        $this->postJson(route('public.invoice.online.start', $paid->uuid), ['method' => 'BC'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_payable');

        // Switch turned OFF mid-session: a system "unavailable" reason, not a silent message-only 422.
        $this->setPaymentWrites(false);
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unavailable');
    }

    public function test_page_props_expose_manual_accounts_when_online_only_and_keep_the_same_key_set_off_and_on(): void
    {
        Setting::set('payment_channels', [
            ['bank' => 'BANK DEMO', 'account_number' => '0000000001', 'account_name' => 'PT Contoh Demo'],
            ['bank' => '', 'account_number' => '1', 'account_name' => 'incomplete row is dropped'],
        ]);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $keys = [];
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$keys) {
                $props = $page->toArray()['props'];
                $keys['on'] = array_keys($props);
                $page->where('online_only', true)
                    ->where('manual_accounts', [
                        ['bank' => 'BANK DEMO', 'account_number' => '0000000001', 'account_name' => 'PT Contoh Demo'],
                    ]);
            });

        $this->setPaymentWrites(false);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$keys) {
                $keys['off'] = array_keys($page->toArray()['props']);
                $page->where('online_only', false)->where('online', null);
            });

        // Switch OFF (production today) and ON expose the identical prop key set: no new fallback prop.
        $this->assertSame($keys['off'], $keys['on']);
        foreach ($keys['off'] as $key) {
            $this->assertStringNotContainsString('fallback', (string) $key);
        }
    }

    public function test_midtrans_public_pay_json_failures_carry_additive_reason_keys(): void
    {
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'midtrans', 'text', 'payment');
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg7', 'payment');
        Setting::setSecret('midtrans_server_key', 'SB-Mid-server-pg7', 'payment');
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['error_messages' => ['do-not-disclose']], 401)]);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $response = $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'provider_error');
        $this->assertStringNotContainsString('do-not-disclose', $response->getContent());

        $void = $this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'void']);
        $this->postJson(route('public.invoice.pay', $void->uuid), ['method' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'void');

        $this->setPaymentWrites(false);
        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unavailable');
    }

    public function test_midtrans_active_keeps_snap_flow_and_disables_direct_endpoints(): void
    {
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'midtrans', 'text', 'payment');
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg7', 'payment');
        Setting::setSecret('midtrans_server_key', 'SB-Mid-server-pg7', 'payment');
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online', null)->where('midtrans_enabled', true));
        $this->getJson(route('public.invoice.online.methods', $invoice->uuid))->assertStatus(422)->assertJsonMissingPath('open_transactions');
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])->assertStatus(422);

        // Prod today (Midtrans / no direct provider): the new prop key never appears anywhere in the props.
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $this->assertStringNotContainsString('other_open_transactions', (string) json_encode($page->toArray()['props'])));
    }

    public function test_switch_method_keeps_other_active_codes_listed_with_cap_and_409_refusal(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $bca = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])->assertOk()->json('transaction');
        $qris = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'NQ'])->assertOk()->json('transaction');

        // Reload: the latest code is shown, the older one stays visible (not hidden) in other_open_transactions.
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('online.open_transaction.order_id', $qris['order_id'])
                ->has('online.other_open_transactions', 1)
                ->where('online.other_open_transactions.0.order_id', $bca['order_id'])
                ->where('online.other_open_transactions.0.method_name', 'BCA Virtual Account')
                ->where('online.other_open_transactions.0.amount_charged', 104000)
                ->has('online.other_open_transactions.0.expires_at')
                ->missing('online.other_open_transactions.0.id')
                ->missing('online.other_open_transactions.0.invoice_id'));

        // Methods JSON lists every active code (same public DTO) so the panel can refresh without a reload.
        $listed = $this->getJson(route('public.invoice.online.methods', $invoice->uuid))->assertOk()->json('open_transactions');
        $this->assertSame([$qris['order_id'], $bca['order_id']], array_column($listed, 'order_id'));
        $this->assertArrayNotHasKey('id', $listed[0]);

        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'DA'])->assertOk();

        // 4th distinct code: 409 too_many_open, nothing created at the provider.
        PaymentGatewayTransaction::query()->where('merchant_order_id', $bca['order_id'])->update(['amount_invoice' => 90000]);
        $charges = count($this->fake->charges);
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'too_many_open');
        $this->assertCount($charges, $this->fake->charges);
        $this->assertSame(3, PaymentGatewayTransaction::query()->where('status', 'pending')->count());
    }

    public function test_expired_and_paid_codes_are_not_listed_and_nothing_listed_when_not_payable(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $old = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])->json('transaction');
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'NQ'])->assertOk();
        PaymentGatewayTransaction::query()->where('merchant_order_id', $old['order_id'])->update(['expires_at' => now()->subMinute()]);

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('online.other_open_transactions', 0));

        $invoice->update(['status' => 'paid']);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online', null));
    }

    public function test_status_endpoint_reports_paid_and_scopes_order_to_invoice(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $order = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'NQ'])->json('transaction.order_id');
        $other = $this->gatewayInvoice($this->gatewayCustomer());

        $this->getJson(route('public.invoice.online.status', [$invoice->uuid, 'order' => $order]))
            ->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('transaction.status', 'pending');

        $this->getJson(route('public.invoice.online.status', [$other->uuid, 'order' => $order]))
            ->assertOk()
            ->assertJsonPath('transaction', null);

        $invoice->update(['status' => 'paid']);
        $this->getJson(route('public.invoice.online.status', [$invoice->uuid, 'order' => $order]))
            ->assertJsonPath('paid', true);
    }

    public function test_end_to_end_duitku_sandbox_flow_with_http_fakes(): void
    {
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'duitku', 'text', 'payment');
        Setting::set(DuitkuConfig::MERCHANT_CODE, 'DTEST01', 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, 'test-duitku-key', 'payment');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '0', 'boolean', 'payment');
        Http::fake([
            'sandbox.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod' => Http::response(['paymentFee' => [
                ['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'totalFee' => '4000'],
            ], 'responseCode' => '00']),
            'sandbox.duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
                'reference' => 'DREF0009', 'vaNumber' => '7007014000000009', 'amount' => '104000', 'statusCode' => '00',
            ]),
        ]);
        $customer = $this->gatewayCustomer(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $invoice = $this->gatewayInvoice($customer);

        $this->getJson(route('public.invoice.online.methods', $invoice->uuid))->assertOk()->assertJsonPath('methods.0.total', 104000);
        $txn = $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])
            ->assertOk()
            ->assertJsonPath('transaction.va_number', '7007014000000009')
            ->json('transaction');

        $orderId = $txn['order_id'];
        $this->post('/webhooks/payment-gateway/duitku', [
            'merchantCode' => 'DTEST01',
            'amount' => '104000',
            'merchantOrderId' => $orderId,
            'resultCode' => '00',
            'reference' => 'DREF0009',
            'signature' => hash_hmac('sha256', 'DTEST01104000'.$orderId, 'test-duitku-key'),
        ])->assertOk();

        $this->getJson(route('public.invoice.online.status', [$invoice->uuid, 'order' => $orderId]))->assertJsonPath('paid', true);
        $this->assertSame(100000.0, (float) Transaction::query()->sole()->amount);
        $this->assertSame(PaymentGatewayTransaction::STATUS_PAID, PaymentGatewayTransaction::query()->sole()->status);
    }

    public function test_wa_payment_link_falls_back_to_public_pay_page(): void
    {
        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);
        $templates = app(BillingWaTemplateService::class);

        $rendered = $templates->render('Bayar: {payment_link}', $customer, ['invoice' => $invoice]);
        $this->assertStringContainsString(route('public.invoice.show', $invoice->uuid), $rendered);

        $invoice->update(['payment_link' => 'https://pay.example.test/custom']);
        $this->assertStringContainsString('https://pay.example.test/custom', $templates->render('Bayar: {payment_link}', $customer, ['invoice' => $invoice]));

        $this->assertStringContainsString('Bayar: -', $templates->render('Bayar: {payment_link}', $customer, []));
    }
}
