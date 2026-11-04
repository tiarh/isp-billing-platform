<?php

namespace Tests\Unit;

use App\Services\EvolutionApiService;
use App\Services\EvolutionBillingWaProvider;
use Mockery;
use Tests\TestCase;

class EvolutionBillingWaProviderTest extends TestCase
{
    public function test_provider_is_hard_bound_to_the_billing_channel(): void
    {
        $evolution = Mockery::mock(EvolutionApiService::class);
        $evolution->shouldReceive('forChannel')->times(3)->with('billing')->andReturnSelf();
        $evolution->shouldReceive('isConfigured')->once()->andReturn(true);
        $evolution->shouldReceive('getConnectionState')->once()->andReturn('closed');
        $evolution->shouldReceive('send')->once()->with('628120000001', 'test')->andReturn([
            'success' => false,
            'error' => 'fake provider only',
        ]);

        $provider = new EvolutionBillingWaProvider($evolution);

        $this->assertTrue($provider->isConfigured());
        $this->assertSame('closed', $provider->connectionState());
        $this->assertFalse($provider->send('628120000001', 'test')['success']);
    }
}
