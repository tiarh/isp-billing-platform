<?php

namespace Tests\Feature;

use App\Services\MonitoringAdmissionGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The "critical_zero_since" key used to expire after a fixed 2 h, so a long
 * quiet period re-triggered a 120 s cooldown for no reason. Each clear check
 * now keeps the original timestamp and refreshes the TTL.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class MonitoringAdmissionGateZeroSinceTest extends TestCase
{
    private const KEY = 'monitoring.admission_gate.critical_zero_since';

    private int $depth = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Jakarta'));
        Cache::forget(self::KEY);
        $this->depth = 0;

        Config::set('services.monitoring.admission_gate', [
            'enabled' => true,
            'strict' => false,
            'operator_hold' => false,
            'cooldown_seconds' => 120,
            'critical_queues' => ['psb'],
        ]);

        Queue::shouldReceive('size')->andReturnUsing(fn () => $this->depth);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return app(MonitoringAdmissionGate::class)->evaluate('monitoring')['reason_codes'];
    }

    private function tick(int $seconds): void
    {
        $this->travelTo(CarbonImmutable::now()->addSeconds($seconds));
    }

    public function test_long_quiet_period_does_not_hold_again_after_ttl(): void
    {
        $this->assertSame(['critical_queue_cooldown'], $this->reasons());
        $start = Cache::get(self::KEY);

        $this->tick(120);
        $this->assertSame([], $this->reasons());

        // 3 h of clear checks every minute: crosses the old fixed 2 h expiry.
        for ($minute = 1; $minute <= 180; $minute++) {
            $this->tick(60);
            $this->assertSame([], $this->reasons(), "unexpected hold at minute {$minute}");
        }

        $this->assertSame($start, Cache::get(self::KEY));
    }

    public function test_busy_queue_forgets_and_cooldown_applies_again(): void
    {
        $this->reasons();
        $this->tick(120);
        $this->assertSame([], $this->reasons());

        $this->depth = 3;
        $this->tick(60);
        $this->assertSame(['critical_queue_busy:psb'], $this->reasons());
        $this->assertNull(Cache::get(self::KEY));

        $this->depth = 0;
        $this->tick(60);
        $this->assertSame(['critical_queue_cooldown'], $this->reasons());

        $this->tick(120);
        $this->assertSame([], $this->reasons());
    }

    public function test_future_start_time_self_heals(): void
    {
        Cache::put(self::KEY, CarbonImmutable::now()->addDay()->timestamp, now()->addHours(2));

        $this->assertSame(['critical_queue_cooldown'], $this->reasons());
        $this->assertSame(CarbonImmutable::now()->timestamp, Cache::get(self::KEY));

        $this->tick(120);
        $this->assertSame([], $this->reasons());
    }

    public function test_invalid_stored_value_self_heals(): void
    {
        Cache::put(self::KEY, 'not-a-time', now()->addHours(2));

        $this->assertSame(['critical_queue_cooldown'], $this->reasons());
        $this->assertSame(CarbonImmutable::now()->timestamp, Cache::get(self::KEY));

        $this->tick(120);
        $this->assertSame([], $this->reasons());
    }
}
