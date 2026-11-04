<?php

namespace Tests\Feature;

use App\Contracts\WaProvider;
use App\Jobs\SendBillingWaJob;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class BillingWaDeliveryGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_billing_stays_dry_run_when_billing_gate_is_off(): void
    {
        Setting::set('wa_broadcast_writes_enabled', '1', 'boolean', 'whatsapp');
        Setting::set('wa_billing_writes_enabled', '0', 'boolean', 'whatsapp');
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldReceive('isConfigured')->once()->andReturn(true);
        $provider->shouldNotReceive('connectionState');
        $provider->shouldNotReceive('send');
        $this->app->instance(WaProvider::class, $provider);

        (new SendBillingWaJob('081200000001', 'test message'))
            ->handle($provider);
    }

    public function test_automatic_billing_has_a_separate_live_gate_from_mass_broadcast(): void
    {
        Setting::set('wa_broadcast_writes_enabled', '0', 'boolean', 'whatsapp');
        Setting::set('wa_billing_writes_enabled', '1', 'boolean', 'whatsapp');
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldReceive('isConfigured')->once()->andReturn(true);
        $provider->shouldReceive('connectionState')->once()->andReturn('open');
        $provider->shouldReceive('send')->once()->with('6281200000001', 'test message')->andReturn([
            'success' => true,
            'message_id' => 'fake-message-id',
        ]);
        $this->app->instance(WaProvider::class, $provider);

        (new SendBillingWaJob('081200000001', 'test message'))
            ->handle($provider);
    }

    public function test_billing_job_does_not_log_message_content(): void
    {
        Setting::set('wa_billing_writes_enabled', '0', 'boolean', 'whatsapp');
        $secret = 'not-for-log-pppoe-secret';
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldReceive('isConfigured')->once()->andReturn(true);
        $provider->shouldNotReceive('connectionState');
        $provider->shouldNotReceive('send');
        $this->app->instance(WaProvider::class, $provider);
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($secret): bool {
                return ! str_contains($message, $secret)
                    && ! str_contains((string) json_encode($context), $secret);
            });

        (new SendBillingWaJob('081200000001', $secret))->handle($provider);
    }
}
