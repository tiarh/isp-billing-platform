<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\MidtransService;
use App\Services\PsbWaHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Call sites after the env() -> config() move keep their old resolution rules:
 * Settings win, config is the fallback, and the old defaults still apply.
 */
#[Group('blocking-billing')]
class EnvToConfigBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_midtrans_falls_back_to_config_when_settings_are_empty(): void
    {
        config([
            'services.midtrans.server_key' => ' canary-pw ',
            'services.midtrans.client_key' => 'canary-client',
            'services.midtrans.merchant_id' => 'canary-merchant',
            'services.midtrans.is_production' => false,
        ]);

        $svc = new MidtransService;

        $this->assertSame('canary-pw', $svc->serverKey());
        $this->assertSame('canary-client', $svc->clientKey());
        $this->assertSame('canary-merchant', $svc->merchantId());
        $this->assertTrue($svc->isConfigured());
        $this->assertFalse($svc->isProduction());
        $this->assertSame('https://app.sandbox.midtrans.com', $svc->snapApiBase());
    }

    public function test_midtrans_empty_config_means_not_configured(): void
    {
        config([
            'services.midtrans.server_key' => '',
            'services.midtrans.client_key' => '',
            'services.midtrans.merchant_id' => '',
        ]);

        $svc = new MidtransService;

        $this->assertSame('', $svc->serverKey());
        $this->assertSame('', $svc->merchantId());
        $this->assertFalse($svc->isConfigured());
    }

    public function test_midtrans_settings_win_over_config(): void
    {
        config([
            'services.midtrans.server_key' => 'canary-config',
            'services.midtrans.merchant_id' => 'canary-config',
            'services.midtrans.is_production' => true,
        ]);
        Setting::set('midtrans_merchant_id', 'canary-setting');
        Setting::set('midtrans_is_production', false);

        $svc = new MidtransService;

        $this->assertSame('canary-setting', $svc->merchantId());
        $this->assertFalse($svc->isProduction());
    }

    public function test_midtrans_is_production_accepts_config_values(): void
    {
        $svc = new MidtransService;

        foreach ([[true, true], ['true', true], ['1', true], ['0', false], [false, false], ['', false]] as [$value, $expected]) {
            config(['services.midtrans.is_production' => $value]);
            $this->assertSame($expected, $svc->isProduction(), var_export($value, true));
        }

        $this->assertSame('https://app.midtrans.com', (function () use ($svc) {
            config(['services.midtrans.is_production' => 'yes']);

            return $svc->snapApiBase();
        })());
    }

    public function test_psb_health_token_uses_config_when_setting_is_empty(): void
    {
        config(['services.psb_wa_health.public_token' => 'canary-pw']);
        $svc = app(PsbWaHealthService::class);

        $this->assertTrue($svc->publicTokenValid('canary-pw'));
        $this->assertFalse($svc->publicTokenValid('wrong'));
        $this->assertFalse($svc->publicTokenValid(null));

        Setting::set('psb_wa_health_public_token', 'canary-setting');
        $this->assertTrue($svc->publicTokenValid('canary-setting'));
        $this->assertFalse($svc->publicTokenValid('canary-pw'));
    }

    public function test_psb_health_token_empty_everywhere_rejects_everything(): void
    {
        config(['services.psb_wa_health.public_token' => '']);

        $svc = app(PsbWaHealthService::class);

        $this->assertFalse($svc->publicTokenValid(''));
        $this->assertFalse($svc->publicTokenValid('anything'));
    }
}
