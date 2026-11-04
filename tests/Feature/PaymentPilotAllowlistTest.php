<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Services\PaymentGateway\CheckoutException;
use App\Services\PaymentGateway\PaymentGatewayCheckoutService;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * Pilot allowlist (payment_gateway_pilot_customer_ids): empty = everyone; when
 * set, only listed customers can START an online payment, everyone else gets the
 * manual transfer fallback. Callbacks are never filtered (see DuitkuCallbackTest).
 */
#[Group('blocking-billing')]
class PaymentPilotAllowlistTest extends TestCase
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

    public function test_empty_allowlist_means_everyone(): void
    {
        $customer = $this->gatewayCustomer();
        $manager = app(PaymentGatewayManager::class);

        $this->assertSame([], app(PaymentGatewaySettings::class)->pilotCustomerIds());
        $this->assertTrue($manager->onlineAvailable());
        $this->assertTrue($manager->onlineAvailable($customer->id));

        $invoice = $this->gatewayInvoice($customer);
        $this->postJson(route('public.invoice.online.start', $invoice->uuid), ['method' => 'BC'])->assertOk();
    }

    public function test_only_listed_customers_can_start_and_see_online_payment(): void
    {
        $pilot = $this->gatewayCustomer();
        $other = $this->gatewayCustomer();
        Setting::set(PaymentGatewaySettings::PILOT_CUSTOMER_IDS, [$pilot->id], 'json', 'payment');
        $manager = app(PaymentGatewayManager::class);

        $this->assertTrue($manager->onlineAvailable(), 'global answer is unchanged');
        $this->assertTrue($manager->onlineAvailable($pilot->id));
        $this->assertFalse($manager->onlineAvailable($other->id));

        $pilotInvoice = $this->gatewayInvoice($pilot);
        $otherInvoice = $this->gatewayInvoice($other);

        // Pay page: listed customer sees online payment, the other one the manual fallback.
        $this->get(route('public.invoice.show', $pilotInvoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online.provider', 'fake')->where('online_only', true));
        $this->get(route('public.invoice.show', $otherInvoice->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('online', null)->where('online_only', false));

        $this->getJson(route('public.invoice.online.methods', $pilotInvoice->uuid))->assertOk()->assertJsonPath('methods.0.code', 'BC');
        $this->getJson(route('public.invoice.online.methods', $otherInvoice->uuid))->assertStatus(422)->assertJsonPath('ok', false);

        $this->postJson(route('public.invoice.online.start', $pilotInvoice->uuid), ['method' => 'BC'])->assertOk();
        $this->postJson(route('public.invoice.online.start', $otherInvoice->uuid), ['method' => 'BC'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unavailable');

        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertCount(1, $this->fake->charges);
    }

    public function test_the_service_itself_refuses_unlisted_customers(): void
    {
        $pilot = $this->gatewayCustomer();
        $other = $this->gatewayCustomer();
        Setting::set(PaymentGatewaySettings::PILOT_CUSTOMER_IDS, [$pilot->id], 'json', 'payment');
        $checkout = app(PaymentGatewayCheckoutService::class);
        $otherInvoice = $this->gatewayInvoice($other);

        $this->assertSame([], $checkout->methodsFor($otherInvoice));
        $this->expectException(CheckoutException::class);
        $checkout->start($otherInvoice, 'BC');
    }

    public function test_pilot_never_applies_to_midtrans(): void
    {
        $pilot = $this->gatewayCustomer();
        $other = $this->gatewayCustomer();
        Setting::set(PaymentGatewaySettings::PILOT_CUSTOMER_IDS, [$pilot->id], 'json', 'payment');
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'midtrans', 'text', 'payment');

        $this->assertTrue(app(PaymentGatewayManager::class)->pilotAllowsStart($other->id));
    }

    public function test_garbage_values_in_the_setting_are_ignored(): void
    {
        Setting::set(PaymentGatewaySettings::PILOT_CUSTOMER_IDS, '["x", 0, -3, "7", 7]', 'text', 'payment');

        $this->assertSame([7], app(PaymentGatewaySettings::class)->pilotCustomerIds());
    }
}
