<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PaymentGateway\Duitku\DuitkuCallbackHandler;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * PG-6: Settings > Payment Gateway (superadmin, TOTP step-up on save).
 */
#[Group('blocking-billing')]
class PaymentGatewaySettingsTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const ROUTE_UPDATE = 'settings.payment-gateway.update';

    public function test_only_superadmin_can_open_or_save(): void
    {
        $admin = $this->gatewayUser('admin');

        $this->actingAs($admin)->get(route('settings.payment-gateway'))->assertForbidden();
        $this->actingAs($admin)->get(route('modules.payment-gateway'))->assertForbidden();
        $this->actingAs($admin)->post(route(self::ROUTE_UPDATE), $this->payload())->assertForbidden();
        $this->actingAs($admin)->getJson(route('api.settings.payment-gateway.duitku-methods'))->assertForbidden();
    }

    public function test_page_renders_without_leaking_the_api_key(): void
    {
        Setting::set(DuitkuConfig::MERCHANT_CODE, 'DTEST01', 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, 'abcd-test-duitku-key-wxyz', 'payment');
        $superadmin = $this->gatewayUser();

        foreach (['settings.payment-gateway', 'modules.payment-gateway'] as $route) {
            $response = $this->actingAs($superadmin)->get(route($route))->assertOk();
            $this->assertStringNotContainsString('test-duitku-key', $response->getContent());
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/PaymentGateway')
                ->where('duitku.merchant_code', 'DTEST01')
                ->where('duitku.api_key_set', true)
                ->where('duitku.api_key_masked', 'abcd••••••••wxyz')
                ->where('duitku.callback_url', route('webhooks.payment-gateway', 'duitku'))
                ->where('duitku.callback_ips', config('services.duitku.callback_ips.sandbox'))
                ->where('general.fee_bearer', 'customer')
                ->where('general.va_expiry_minutes', 180)
                ->where('kill_switch.enabled', false)
                ->where('online_available', false));
        }
    }

    public function test_save_requires_totp_step_up(): void
    {
        $superadmin = $this->gatewayUser();

        $this->actingAs($superadmin)
            ->post(route(self::ROUTE_UPDATE), $this->payload())
            ->assertRedirect(route('totp.step-up'));

        $this->assertNull(Setting::get(DuitkuConfig::MERCHANT_CODE));
    }

    public function test_save_stores_settings_encrypts_key_and_audits_without_secret(): void
    {
        $superadmin = $this->gatewayUser();

        $this->save($superadmin, $this->payload([
            'duitku_api_key' => 'first-test-duitku-key',
            'duitku_enabled_methods' => ['bc', 'NQ', 'DA', 'IR'],
            'duitku_callback_ips' => ['198.51.100.10', '198.51.100.11'],
            'fee_bearer' => 'merchant',
            'va_expiry_minutes' => 720,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $config = app(DuitkuConfig::class);
        $this->assertSame('duitku', app(PaymentGatewayManager::class)->activeProvider());
        $this->assertSame('DTEST01', $config->merchantCode());
        $this->assertSame('first-test-duitku-key', $config->apiKey());
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, (string) DB::table('settings')->where('key', DuitkuConfig::API_KEY)->value('value'));
        $this->assertSame(['BC', 'NQ', 'DA', 'IR'], $config->enabledMethods());
        $this->assertSame(['198.51.100.10', '198.51.100.11'], $config->callbackIps());
        $this->assertSame('merchant', app(PaymentGatewaySettings::class)->feeBearer());
        $this->assertSame(720, app(PaymentGatewaySettings::class)->vaExpiryMinutes());

        $log = DB::table('activity_log')->where('description', 'payment_gateway_settings_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('first-test-duitku-key', (string) $log->properties);
        $this->assertStringContainsString('duitku_api_key', (string) $log->properties);

        // Blank key keeps the stored one; clear flag removes it.
        $this->save($superadmin, $this->payload(['duitku_api_key' => '']))->assertSessionHasNoErrors();
        $this->assertSame('first-test-duitku-key', $config->apiKey());

        $this->save($superadmin, $this->payload(['duitku_clear_api_key' => true]))->assertSessionHasNoErrors();
        $this->assertSame('', $config->apiKey());
    }

    public function test_switching_to_production_needs_explicit_confirmation(): void
    {
        $superadmin = $this->gatewayUser();

        $this->save($superadmin, $this->payload(['duitku_environment' => 'production']))
            ->assertSessionHasErrors('duitku_environment');
        $this->assertFalse(app(DuitkuConfig::class)->isProduction());

        $this->save($superadmin, $this->payload(['duitku_environment' => 'production', 'confirm_production' => true]))
            ->assertSessionHasNoErrors();
        $this->assertTrue(app(DuitkuConfig::class)->isProduction());

        // Already production: saving again needs no confirmation.
        $this->save($superadmin, $this->payload(['duitku_environment' => 'production']))->assertSessionHasNoErrors();
    }

    public function test_sandbox_to_production_form_never_pins_sandbox_ips(): void
    {
        $superadmin = $this->gatewayUser();
        $sandbox = config('services.duitku.callback_ips.sandbox');
        $production = config('services.duitku.callback_ips.production');

        // Form still carries the sandbox list while the environment switches.
        $this->save($superadmin, $this->payload([
            'duitku_environment' => 'production',
            'confirm_production' => true,
            'duitku_callback_ips' => $sandbox,
        ]))->assertSessionHasNoErrors();

        $config = app(DuitkuConfig::class);
        $this->assertTrue($config->isProduction());
        $this->assertSame($production, $config->callbackIps());
        $this->assertNull(Setting::get(DuitkuConfig::CALLBACK_IPS_SANDBOX));
        $this->assertNull(Setting::get(DuitkuConfig::CALLBACK_IPS_PRODUCTION));

        // And back: the production list must not pin sandbox either.
        $this->save($superadmin, $this->payload(['duitku_environment' => 'sandbox', 'duitku_callback_ips' => $production]))
            ->assertSessionHasNoErrors();
        $this->assertSame($sandbox, app(DuitkuConfig::class)->callbackIps());
    }

    public function test_custom_ip_lists_are_kept_per_environment(): void
    {
        $superadmin = $this->gatewayUser();

        $this->save($superadmin, $this->payload(['duitku_callback_ips' => ['198.51.100.10']]))->assertSessionHasNoErrors();
        $this->save($superadmin, $this->payload([
            'duitku_environment' => 'production',
            'confirm_production' => true,
            'duitku_callback_ips' => ['203.0.113.7'],
        ]))->assertSessionHasNoErrors();

        $config = app(DuitkuConfig::class);
        $this->assertSame(['203.0.113.7'], $config->callbackIps());
        $this->assertSame(['198.51.100.10'], $config->callbackIpsFor('sandbox'));

        $this->actingAs($superadmin)->get(route('settings.payment-gateway'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('duitku.callback_ips', ['203.0.113.7'])
            ->where('duitku.callback_ips_customized', true)
            ->where('duitku.callback_ips_by_env.sandbox', ['198.51.100.10'])
            ->where('duitku.callback_ips_by_env.production', ['203.0.113.7']));
    }

    public function test_old_single_ip_key_equal_to_other_env_list_is_ignored(): void
    {
        // The pin bug left the sandbox list in the old key while on production.
        Setting::set(DuitkuConfig::ENVIRONMENT, 'production', 'text', 'payment');
        Setting::set(DuitkuConfig::CALLBACK_IPS, config('services.duitku.callback_ips.sandbox'), 'json', 'payment');

        $config = app(DuitkuConfig::class);
        $this->assertSame(config('services.duitku.callback_ips.production'), $config->callbackIps());
        $this->assertFalse($config->callbackIpsCustomized());
    }

    public function test_last_rejected_callback_ip_is_shown_without_secrets(): void
    {
        Setting::set(DuitkuConfig::MERCHANT_CODE, 'DTEST01', 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, 'canary-pw', 'payment');
        Cache::put(
            DuitkuCallbackHandler::LAST_REJECTED_IP_CACHE_KEY,
            ['ip' => '192.0.2.55', 'at' => now()->toISOString()],
            now()->addDay(),
        );

        $response = $this->actingAs($this->gatewayUser())->get(route('settings.payment-gateway'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('duitku.last_rejected_ip.ip', '192.0.2.55'));
        $this->assertStringNotContainsString('canary-pw', $response->getContent());
    }

    public function test_pilot_allowlist_is_saved_validated_and_cleared(): void
    {
        $superadmin = $this->gatewayUser();
        $a = $this->gatewayCustomer();
        $b = $this->gatewayCustomer();
        $settings = app(PaymentGatewaySettings::class);

        $this->save($superadmin, $this->payload(['pilot_customer_ids' => [$a->id, $b->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$a->id, $b->id], $settings->pilotCustomerIds());

        $this->actingAs($superadmin)->get(route('settings.payment-gateway'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('general.pilot_customer_ids', [$a->id, $b->id])
            ->where('general.pilot_customers.0.id', $a->id)
            ->where('general.pilot_customers.0.name', 'Gateway Customer'));

        // Unknown id and duplicates are refused, saved list stays.
        $this->save($superadmin, $this->payload(['pilot_customer_ids' => [999999]]))->assertSessionHasErrors('pilot_customer_ids.0');
        $this->save($superadmin, $this->payload(['pilot_customer_ids' => [$a->id, $a->id]]))->assertSessionHasErrors('pilot_customer_ids.0');
        $this->assertSame([$a->id, $b->id], $settings->pilotCustomerIds());

        // A save without the field leaves it alone; an empty list clears it (= everyone).
        $this->save($superadmin, $this->payload())->assertSessionHasNoErrors();
        $this->assertSame([$a->id, $b->id], $settings->pilotCustomerIds());
        $this->save($superadmin, $this->payload(['pilot_customer_ids' => []]))->assertSessionHasNoErrors();
        $this->assertSame([], $settings->pilotCustomerIds());
    }

    public function test_pilot_errors_are_reported_for_every_bad_entry(): void
    {
        $superadmin = $this->gatewayUser();
        $a = $this->gatewayCustomer();

        $this->save($superadmin, $this->payload(['pilot_customer_ids' => [$a->id, 'E2E-CUSTOMER', 999999]]))
            ->assertSessionHasErrors(['pilot_customer_ids.1', 'pilot_customer_ids.2']);
        $this->assertSame([], app(PaymentGatewaySettings::class)->pilotCustomerIds());
    }

    public function test_custom_legacy_ip_key_is_retired_on_save_and_cannot_pin_production(): void
    {
        $superadmin = $this->gatewayUser();
        $production = config('services.duitku.callback_ips.production');
        // Left over from before per-environment lists: sandbox list plus one extra IP.
        Setting::set(DuitkuConfig::CALLBACK_IPS, [...config('services.duitku.callback_ips.sandbox'), '198.51.100.99'], 'json', 'payment');
        Setting::set(DuitkuConfig::ENVIRONMENT, 'production', 'text', 'payment');
        $this->assertContains('198.51.100.99', app(DuitkuConfig::class)->callbackIps(), 'precondition: legacy key applies until saved');

        // "Pakai daftar resmi" (documented production list) then save.
        $this->save($superadmin, $this->payload(['duitku_environment' => 'production', 'duitku_callback_ips' => $production]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', DuitkuConfig::CALLBACK_IPS)->first(), 'legacy key deleted');
        $this->assertSame($production, app(DuitkuConfig::class)->callbackIps());
        $this->assertFalse(app(DuitkuConfig::class)->callbackIpsCustomized());
    }

    public function test_saved_empty_row_blocks_legacy_fallback(): void
    {
        Setting::set(DuitkuConfig::CALLBACK_IPS, ['198.51.100.99'], 'json', 'payment');
        Setting::set(DuitkuConfig::CALLBACK_IPS_SANDBOX, '', 'json', 'payment');

        $this->assertSame(config('services.duitku.callback_ips.sandbox'), app(DuitkuConfig::class)->callbackIps());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $superadmin = $this->gatewayUser();

        $this->save($superadmin, $this->payload(['duitku_enabled_methods' => ['VC']]))->assertSessionHasErrors('duitku_enabled_methods.0');
        $this->save($superadmin, $this->payload(['duitku_callback_ips' => ['not-an-ip']]))->assertSessionHasErrors('duitku_callback_ips.0');
        $this->save($superadmin, $this->payload(['active_provider' => 'xendit']))->assertSessionHasErrors('active_provider');
        $this->save($superadmin, $this->payload(['min_amount' => 5000]))->assertSessionHasErrors('min_amount');
        $this->save($superadmin, $this->payload(['placeholder_email_domain' => 'no domain']))->assertSessionHasErrors('placeholder_email_domain');
    }

    public function test_duitku_methods_endpoint_tests_connection(): void
    {
        $superadmin = $this->gatewayUser();

        $this->actingAs($superadmin)->getJson(route('api.settings.payment-gateway.duitku-methods'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Setting::set(DuitkuConfig::MERCHANT_CODE, 'DTEST01', 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, 'test-duitku-key', 'payment');
        Http::fakeSequence('sandbox.duitku.com/*')
            ->push(['paymentFee' => [
                ['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'totalFee' => '4000'],
                ['paymentMethod' => 'VC', 'paymentName' => 'Credit Card', 'totalFee' => '5000'],
            ], 'responseCode' => '00'])
            ->push(['Message' => 'Wrong signature'], 401);

        $this->actingAs($superadmin)->getJson(route('api.settings.payment-gateway.duitku-methods', ['amount' => 100000]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('environment', 'sandbox')
            ->assertJsonCount(1, 'methods')
            ->assertJsonPath('methods.0.code', 'BC');

        $response = $this->actingAs($superadmin)->getJson(route('api.settings.payment-gateway.duitku-methods', ['amount' => 120000]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
        $this->assertStringContainsString('HTTP 401', $response->json('message'));
        $this->assertStringNotContainsString('test-duitku-key', $response->getContent());
    }

    public function test_new_routes_are_exposed_to_ziggy(): void
    {
        $only = config('ziggy.only');

        $this->assertContains('settings.payment-gateway*', $only);
        $this->assertContains('api.settings.payment-gateway.*', $only);
        $this->assertContains('api.payment-gateway.*', $only);
    }

    private function save($user, array $payload)
    {
        return $this->actingAs($user)
            ->withSession($this->totpGrant($user, self::ROUTE_UPDATE))
            ->post(route(self::ROUTE_UPDATE), $payload);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'active_provider' => 'duitku',
            'fee_bearer' => 'customer',
            'va_expiry_minutes' => 1440,
            'min_amount' => 10000,
            'placeholder_email_domain' => 'invalid.local',
            'duitku_merchant_code' => 'DTEST01',
            'duitku_api_key' => '',
            'duitku_clear_api_key' => false,
            'duitku_environment' => 'sandbox',
            'duitku_signature_mode' => 'hmac_sha256',
            'duitku_enabled_methods' => [],
            'duitku_ip_whitelist_enabled' => true,
            'duitku_callback_ips' => config('services.duitku.callback_ips.sandbox'),
            'confirm_production' => false,
        ], $overrides);
    }
}
