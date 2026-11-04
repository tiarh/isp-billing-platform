<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningS0Test extends TestCase
{
    use RefreshDatabase;

    public function test_profile_push_is_post_only_and_requires_a_configured_header_token(): void
    {
        $router = Router::create([
            'name' => 'Security Test Router',
            'ip_address' => '192.0.2.10',
            'username' => 'test',
            'password' => 'test-only-router-password',
            'is_active' => true,
        ]);

        $this->get('/wg/push-profile')->assertStatus(405);

        $this->postJson('/wg/push-profile', [
            'router_id' => $router->id,
            'name' => '50MB',
        ])->assertStatus(503);

        Setting::set('wg_push_profile_token', 'test-push-token');

        $this->postJson('/wg/push-profile', [
            'router_id' => $router->id,
            'name' => '50MB',
        ])->assertStatus(403);

        $this->withHeader('X-Push-Token', 'test-push-token')
            ->postJson('/wg/push-profile', [
                'router_id' => $router->id,
                'name' => '50MB',
            ])
            ->assertOk();
    }

    public function test_settings_page_does_not_serialize_raw_setting_values(): void
    {
        $secret = 'setting-secret-that-must-not-reach-html';
        Setting::set('payment_gateway_test_secret', $secret, 'text', 'payment');

        $response = $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->get(route('settings.index'))
            ->assertOk();

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringContainsString('payment_gateway_test_secret', $response->getContent());
    }

    public function test_router_page_does_not_serialize_vpn_or_radius_secrets(): void
    {
        $router = Router::create([
            'name' => 'Secret Projection Router',
            'ip_address' => '192.0.2.11',
            'username' => 'test',
            'password' => 'test-only-router-password',
            'is_active' => true,
            'vpn_client_private_key' => 'private-key-must-not-reach-html',
            'vpn_preshared_key' => 'preshared-key-must-not-reach-html',
            'radius_secret' => 'radius-secret-must-not-reach-html',
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('routers.show', $router))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('private-key-must-not-reach-html', $content);
        $this->assertStringNotContainsString('preshared-key-must-not-reach-html', $content);
        $this->assertStringNotContainsString('radius-secret-must-not-reach-html', $content);
        $this->assertStringContainsString('credentials_configured', $content);
    }
}
