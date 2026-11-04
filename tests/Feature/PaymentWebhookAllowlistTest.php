<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayEvent;
use App\Models\Setting;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Webhook provider allowlist (midtrans + duitku) and fail-closed behaviour in
 * every environment (this suite runs with APP_ENV=testing). Valid-signature
 * 200 paths are covered by DuitkuCallbackTest and PaymentGatewayWebhookFlowTest.
 */
#[Group('blocking-billing')]
class PaymentWebhookAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('midtrans_server_key', 'SB-Mid-server-allow', 'text', 'payment');
        Setting::set('midtrans_client_key', 'SB-Mid-client-allow', 'text', 'payment');
        Setting::set(DuitkuConfig::MERCHANT_CODE, 'DTEST01', 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, 'test-duitku-key', 'payment');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, '0', 'boolean', 'payment');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownProviders(): array
    {
        return [
            'tripay' => ['tripay'],
            'xendit' => ['xendit'],
            'manual' => ['manual'],
            'fake' => ['fake'],
            'garbage' => ['not-a-provider'],
        ];
    }

    #[DataProvider('unknownProviders')]
    public function test_unknown_provider_is_404(string $provider): void
    {
        $this->postJson("/webhooks/payment-gateway/{$provider}", ['order_id' => 'INV1-X'])
            ->assertNotFound();
    }

    public function test_unknown_provider_is_404_even_with_a_configured_shared_secret(): void
    {
        Setting::set('payment_gateway_tripay_webhook_secret', 'legacy-secret', 'text', 'payment');

        $this->postJson('/webhooks/payment-gateway/tripay', ['order_id' => 'INV1-X'], ['X-Webhook-Token' => 'legacy-secret'])
            ->assertNotFound();

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_unsigned_generic_webhook_is_never_accepted_in_a_non_production_env(): void
    {
        $this->assertFalse(app()->environment('production', 'staging'));

        $this->postJson('/webhooks/payment-gateway/manual', ['order_id' => 'INV1-X', 'status' => 'paid'])
            ->assertNotFound();

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_midtrans_with_invalid_signature_is_rejected_in_non_production_env(): void
    {
        $this->postJson('/webhooks/payment-gateway/midtrans', [
            'order_id' => 'INV1-ALLOW',
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid',
        ])->assertUnauthorized();

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_duitku_with_invalid_signature_is_rejected_in_non_production_env(): void
    {
        $this->post('/webhooks/payment-gateway/duitku', [
            'merchantCode' => 'DTEST01',
            'amount' => '100000',
            'merchantOrderId' => 'INV1-ALLOW',
            'resultCode' => '00',
            'signature' => 'invalid',
        ])->assertUnauthorized();

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_provider_segment_is_case_insensitive_for_allowlisted_providers(): void
    {
        $this->postJson('/webhooks/payment-gateway/MIDTRANS', ['order_id' => 'INV1-ALLOW', 'signature_key' => 'invalid'])
            ->assertUnauthorized();
    }

    public function test_csrf_exception_for_dead_tripay_callback_is_removed(): void
    {
        $this->assertStringNotContainsString(
            'callback/tripay',
            (string) file_get_contents(base_path('bootstrap/app.php')),
        );
    }
}
