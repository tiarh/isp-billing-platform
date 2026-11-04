<?php

namespace Tests\Unit;

use App\Services\TrafficPppoeKeyService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class TrafficPppoeKeyServiceTest extends TestCase
{
    public function test_pppoe_hash_is_stable_case_insensitive_and_not_plaintext(): void
    {
        Config::set('services.monitoring.traffic.hash_secret', 'test-secret');

        $service = app(TrafficPppoeKeyService::class);
        $first = $service->hash(' USERCAFE_RT01_RW01 ');
        $second = $service->hash('usercafe_rt01_rw01');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first);
        $this->assertStringNotContainsString('usercafe_rt01_rw01', (string) $first);
    }

    public function test_pppoe_hash_is_unavailable_without_secret(): void
    {
        Config::set('services.monitoring.traffic.hash_secret', '');

        $this->assertNull(app(TrafficPppoeKeyService::class)->hash('usercafe_rt01_rw01'));
    }

    public function test_null_router_is_late_bound_only_for_the_observing_router(): void
    {
        $hash = str_repeat('a', 64);
        $service = Mockery::mock(TrafficPppoeKeyService::class)->makePartial();
        $service->shouldReceive('mapping')->andReturn([
            $hash => ['customer_id' => 7, 'router_id' => null],
        ]);

        $this->assertSame(
            ['customer_id' => 7, 'router_id' => 513],
            $service->resolveForRouter($hash, 513),
        );
        $this->assertSame(
            ['customer_id' => 7, 'router_id' => 512],
            $service->resolveForRouter($hash, 512),
        );
    }

    public function test_known_router_never_late_binds_to_another_router(): void
    {
        $hash = str_repeat('b', 64);
        $service = Mockery::mock(TrafficPppoeKeyService::class)->makePartial();
        $service->shouldReceive('mapping')->andReturn([
            $hash => ['customer_id' => 8, 'router_id' => 513],
        ]);

        $this->assertNull($service->resolveForRouter($hash, 512));
    }
}
