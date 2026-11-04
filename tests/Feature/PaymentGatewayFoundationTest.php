<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Jobs\ReleaseHeldGatewayPaymentsJob;
use App\Models\PaymentGatewayEvent;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MidtransService;
use App\Services\OpsAlertService;
use App\Services\PaymentGateway\PaymentWriteGate;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * PG-1: encrypted gateway credentials, "Payment write" kill switch
 * enforcement (hold + release), gateway channel/method on transactions.
 */
#[Group('blocking-billing')]
class PaymentGatewayFoundationTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-pg1-fake';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg1-fake', 'payment');
        Setting::setSecret('midtrans_server_key', self::SERVER_KEY, 'payment');
        Setting::set('midtrans_is_production', '0', 'boolean', 'payment');
        Queue::fake();
        Http::fake();
    }

    public function test_secret_is_encrypted_at_rest_and_decrypts(): void
    {
        $raw = (string) DB::table('settings')->where('key', 'midtrans_server_key')->value('value');

        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertStringNotContainsString(self::SERVER_KEY, $raw);
        $this->assertSame('encrypted', DB::table('settings')->where('key', 'midtrans_server_key')->value('type'));
        $this->assertSame(self::SERVER_KEY, Setting::secret('midtrans_server_key'));
        $this->assertSame(self::SERVER_KEY, app(MidtransService::class)->serverKey());
    }

    public function test_legacy_plaintext_secret_still_reads_and_bad_ciphertext_reads_empty(): void
    {
        Setting::set('legacy_key', 'plain-legacy-value', 'text', 'payment');
        $this->assertSame('plain-legacy-value', Setting::secret('legacy_key'));

        Setting::set('broken_key', Setting::ENCRYPTED_PREFIX.'not-valid-ciphertext', 'encrypted', 'payment');
        $this->assertSame('', Setting::secret('broken_key'));

        Setting::setSecret('cleared_key', '   ', 'payment');
        $this->assertSame('fallback', Setting::secret('cleared_key', 'fallback'));
        $this->assertSame('', Setting::secret('missing_key'));
    }

    public function test_midtrans_config_update_stores_keys_encrypted(): void
    {
        $user = $this->gatewayUser('superadmin');

        $this->actingAs($user)->postJson(route('api.payment-gateway.config.update'), [
            'server_key' => 'SB-Mid-server-rot-fake',
            'client_key' => 'SB-Mid-client-rot-fake',
        ])->assertOk();

        $raw = (string) DB::table('settings')->where('key', 'midtrans_server_key')->value('value');
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertSame('SB-Mid-server-rot-fake', Crypt::decryptString(substr($raw, strlen(Setting::ENCRYPTED_PREFIX))));
        $this->assertSame('SB-Mid-client-rot-fake', app(MidtransService::class)->clientKey());
    }

    public function test_kill_switch_off_holds_paid_notification_without_credit(): void
    {
        $this->setPaymentWrites(false);
        $alerts = Mockery::mock(OpsAlertService::class);
        $alerts->shouldReceive('send')->once()->withArgs(fn (string $msg) => str_contains($msg, 'kill switch'))->andReturnTrue();
        $this->app->instance(OpsAlertService::class, $alerts);

        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($invoice->id, 100000))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_HELD);

        $event = PaymentGatewayEvent::query()->sole();
        $this->assertNotNull($event->held_at);
        $this->assertSame('INV'.$invoice->id.'-PG1', $event->credit_key);
        $this->assertSame(100000.0, (float) $event->credit_amount);
        $this->assertSame('qris', $event->credit_method);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);

        // Release is a no-op while the switch is still OFF.
        $this->assertSame(0, app(PaymentGatewayService::class)->releaseHeld());
        $this->assertSame(PaymentGatewayService::STATUS_HELD, $event->fresh()->status);
    }

    public function test_release_after_switch_on_credits_once_with_gateway_channel(): void
    {
        $this->setPaymentWrites(false);
        $customer = $this->gatewayCustomer(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $invoice = $this->gatewayInvoice($customer);
        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($invoice->id, 100000))->assertOk();

        $this->setPaymentWrites(true);
        $this->assertSame(1, app(PaymentGatewayService::class)->releaseHeld());
        $this->assertSame(0, app(PaymentGatewayService::class)->releaseHeld());

        $event = PaymentGatewayEvent::query()->sole();
        $this->assertSame(PaymentGatewayService::STATUS_RELEASED, $event->status);
        $this->assertNotNull($event->released_at);

        $transaction = Transaction::query()->sole();
        $this->assertSame('gateway', $transaction->channel);
        $this->assertSame('qris', $transaction->method);
        $this->assertSame('PG-MIDTRANS-INV'.$invoice->id.'-PG1', $transaction->reference);
        $this->assertSame('paid', $invoice->fresh()->status);
        Queue::assertPushed(ReconnectCustomerJob::class);

        // A retried notification after release must not credit again.
        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($invoice->id, 100000))->assertOk();
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_release_rejects_when_invoice_was_paid_manually_meanwhile(): void
    {
        $this->setPaymentWrites(false);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($invoice->id, 100000))->assertOk();

        Transaction::create([
            'reference' => 'MANUAL-PG1',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);
        $invoice->update(['status' => 'paid']);

        $this->setPaymentWrites(true);
        app(PaymentGatewayService::class)->releaseHeld();

        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_AMOUNT, PaymentGatewayEvent::query()->sole()->status);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_kill_switch_endpoint_requires_superadmin_and_totp_then_dispatches_release(): void
    {
        $route = 'api.payment-gateway.kill-switch';

        $admin = $this->gatewayUser('admin');
        $this->actingAs($admin)->post(route($route), ['enabled' => true])->assertForbidden();

        $superadmin = $this->gatewayUser('superadmin');
        $this->actingAs($superadmin)
            ->post(route($route), ['enabled' => true])
            ->assertRedirect(route('totp.step-up'));
        $this->assertFalse(app(PaymentWriteGate::class)->enabled());

        $this->actingAs($superadmin)
            ->withSession($this->totpGrant($superadmin, $route))
            ->post(route($route), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue(app(PaymentWriteGate::class)->enabled());
        Queue::assertPushed(ReleaseHeldGatewayPaymentsJob::class);
        $this->assertDatabaseHas('activity_log', ['description' => 'payment_write_kill_switch_on']);
    }

    public function test_kill_switch_off_blocks_new_online_charges(): void
    {
        $this->setPaymentWrites(false);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertStatus(422);
        Http::assertNothingSent();

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('midtrans_enabled', false)->where('channels', []));
    }

    public function test_release_command_reports_switch_state(): void
    {
        $this->setPaymentWrites(false);
        $this->artisan('payments:release-held')->expectsOutputToContain('OFF')->assertExitCode(0);

        $this->setPaymentWrites(true);
        $this->artisan('payments:release-held')->expectsOutputToContain('Processed 0 of 0')->assertExitCode(0);
    }

    private function midtransPayload(int $invoiceId, int $amount): array
    {
        $orderId = 'INV'.$invoiceId.'-PG1';
        $gross = number_format($amount, 2, '.', '');

        return [
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'signature_key' => hash('sha512', $orderId.'200'.$gross.self::SERVER_KEY),
            'payment_type' => 'qris',
            'order_id' => $orderId,
            'gross_amount' => $gross,
            'fraud_status' => 'accept',
        ];
    }
}
