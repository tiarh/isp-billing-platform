<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;
use App\Services\PaymentGateway\Drivers\MidtransGateway;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * PG-2: PaymentGateway interface, manager and the Midtrans adapter.
 */
#[Group('blocking-billing')]
class PaymentGatewayManagerTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    public function test_midtrans_stays_active_when_setting_was_never_saved(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertSame('midtrans', $manager->activeProvider());
        // No keys yet → not usable.
        $this->assertNull($manager->active());
        $this->assertFalse($manager->onlineAvailable());
    }

    public function test_none_and_unknown_providers_disable_online_payment(): void
    {
        $manager = app(PaymentGatewayManager::class);

        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'none', 'text', 'payment');
        $this->assertNull($manager->activeProvider());

        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'unknown-provider', 'text', 'payment');
        $this->assertNull($manager->activeProvider());

        $this->expectException(InvalidArgumentException::class);
        $manager->driver('unknown-provider');
    }

    public function test_online_available_needs_configured_driver_and_kill_switch_on(): void
    {
        $manager = app(PaymentGatewayManager::class);
        $fake = new FakePaymentGateway;
        $manager->extend('fake', fn () => $fake);
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'fake', 'text', 'payment');

        $this->setPaymentWrites(false);
        $this->assertSame($fake, $manager->active());
        $this->assertFalse($manager->onlineAvailable());

        $this->setPaymentWrites(true);
        $this->assertTrue($manager->onlineAvailable());

        $fake->configured = false;
        $this->assertNull($manager->active());
        $this->assertFalse($manager->onlineAvailable());
    }

    public function test_midtrans_hidden_on_public_page_when_another_provider_is_active(): void
    {
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg2-fake', 'payment');
        Setting::setSecret('midtrans_server_key', 'SB-Mid-server-pg2-fake', 'payment');
        $this->setPaymentWrites(true);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where('midtrans_enabled', true));

        app(PaymentGatewayManager::class)->extend('fake', fn () => new FakePaymentGateway);
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'fake', 'text', 'payment');

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where('midtrans_enabled', false));
        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])->assertStatus(422);
    }

    public function test_public_page_hides_manual_transfer_only_when_online_payment_is_available(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg2-fake', 'payment');
        Setting::setSecret('midtrans_server_key', 'SB-Mid-server-pg2-fake', 'payment');

        // Kill switch OFF: manual transfer stays exactly as before.
        $this->setPaymentWrites(false);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where('online_only', false)->where('online', null));

        // Online payment available: manual tab is hidden by the page.
        $this->setPaymentWrites(true);
        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where('online_only', true));
    }

    public function test_midtrans_adapter_maps_snap_to_redirect_charge(): void
    {
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-pg2-fake', 'payment');
        Setting::setSecret('midtrans_server_key', 'SB-Mid-server-pg2-fake', 'payment');
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-fake',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-fake',
            ], 201),
        ]);
        $invoice = $this->gatewayInvoice($this->gatewayCustomer(), ['amount' => 150000]);
        $gateway = app(PaymentGatewayManager::class)->driver('midtrans');

        $this->assertInstanceOf(MidtransGateway::class, $gateway);
        $this->assertSame(PaymentMethodOption::GROUP_HOSTED, $gateway->paymentMethods(150000)[0]->group);

        $result = $gateway->createCharge(new ChargeRequest(
            invoice: $invoice,
            merchantOrderId: 'ignored-by-midtrans',
            amount: 150000,
            methodCode: 'midtrans',
            customerName: 'Gateway Customer',
            email: 'customer-1@invalid.local',
            phone: null,
            productDetails: 'Tagihan',
            returnUrl: 'https://ebilling.example.test/pay/x',
            callbackUrl: 'https://ebilling.example.test/webhooks/payment-gateway/midtrans',
        ));

        $this->assertSame(ChargeResult::KIND_REDIRECT, $result->kind);
        $this->assertSame('snap-token-fake', $result->providerReference);
        $this->assertStringStartsWith('INV'.$invoice->id.'-', $result->merchantOrderId);
        $this->assertSame(150000, $result->amount);
        $this->assertSame(StatusResult::UNKNOWN, $gateway->checkStatus($result->merchantOrderId)->state);
    }

    public function test_payment_method_option_maps_to_transaction_method(): void
    {
        $this->assertSame('va', (new PaymentMethodOption('BC', 'BCA', PaymentMethodOption::GROUP_VA))->transactionMethod());
        $this->assertSame('qris', (new PaymentMethodOption('NQ', 'QRIS', PaymentMethodOption::GROUP_QRIS))->transactionMethod());
        $this->assertSame('other', (new PaymentMethodOption('midtrans', 'Hosted', PaymentMethodOption::GROUP_HOSTED))->transactionMethod());
    }
}
