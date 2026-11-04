<?php

namespace Tests\Feature;

use App\Jobs\RunMonitoringCollectorJob;
use App\Services\MonitoringAdmissionGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 2026-10-08: the PPPoE session lanes (#329 freshness) sat behind the full
 * host-load admission gate. On VM102 normalized_load_high is red most of the
 * day, so customers.pppoe_online_checked_at was never stamped. The session
 * lanes now use a host-load exempt gate that still yields to PSB/migration.
 */
#[Group('blocking-core')]
class RouterSessionLaneAdmissionTest extends TestCase
{
    private function enableStrictGateWithHighLoad(): void
    {
        Config::set('services.monitoring.admission_gate', [
            'enabled' => true,
            'strict' => true,
            'operator_hold' => false,
            'cooldown_seconds' => 120,
            'heartbeat_required' => true,
            'heartbeat_max_age_seconds' => 900,
            'resource_checks_enabled' => true,
            'critical_queues' => ['psb', 'migration-command'],
            'worker_heartbeat_keys' => ['psb' => 'test.hb.psb', 'migration' => 'test.hb.migration'],
            // Any load at all is "high": mirrors VM102 where load/8 >= 0.70.
            'load_threshold' => 0.0,
            'cpu_cores' => 1,
            'available_memory_bytes' => 1,
            'root_free_bytes' => 1,
            'redis_used_ratio' => 0.70,
        ]);
    }

    public function test_session_lane_is_allowed_while_host_load_gate_is_red(): void
    {
        $this->enableStrictGateWithHighLoad();
        Queue::shouldReceive('size')->andReturn(0);

        $this->assertTrue(app(MonitoringAdmissionGate::class)->allowsRouterSessionCollector());
    }

    public function test_session_lane_yields_to_busy_psb_queue(): void
    {
        $this->enableStrictGateWithHighLoad();
        Queue::shouldReceive('size')->with('psb')->andReturn(2);
        Queue::shouldReceive('size')->with('migration-command')->andReturn(0);

        $this->assertFalse(app(MonitoringAdmissionGate::class)->allowsRouterSessionCollector());
    }

    public function test_session_lane_fails_closed_when_queue_depth_is_unreadable(): void
    {
        $this->enableStrictGateWithHighLoad();
        Queue::shouldReceive('size')->andThrow(new \RuntimeException('redis down'));

        $this->assertFalse(app(MonitoringAdmissionGate::class)->allowsRouterSessionCollector());
    }

    public function test_session_lane_honors_operator_hold(): void
    {
        $this->enableStrictGateWithHighLoad();
        Config::set('services.monitoring.admission_gate.operator_hold', true);

        $this->assertFalse(app(MonitoringAdmissionGate::class)->allowsRouterSessionCollector());
    }

    public function test_session_lane_is_open_while_gate_feature_is_off(): void
    {
        Config::set('services.monitoring.admission_gate.enabled', false);

        $this->assertTrue(app(MonitoringAdmissionGate::class)->allowsRouterSessionCollector());
    }

    public function test_session_collector_job_uses_session_gate_not_host_load(): void
    {
        $gate = $this->mock(MonitoringAdmissionGate::class, function ($mock): void {
            $mock->shouldReceive('allowsRouterSessionCollector')->once()->andReturn(true);
            $mock->shouldNotReceive('evaluate');
        });

        Artisan::shouldReceive('call')
            ->once()
            ->with('routers:mt-session-sync', ['--only-large' => true, '--read-only' => true, '--write-online-flags' => true])
            ->andReturn(0);

        (new RunMonitoringCollectorJob('router', 'routers:mt-session-sync', [
            '--only-large' => true,
            '--read-only' => true,
            '--write-online-flags' => true,
        ], 'router-sessions-large'))->handle($gate);
    }

    public function test_session_collector_job_skips_when_session_gate_holds(): void
    {
        $gate = $this->mock(MonitoringAdmissionGate::class, function ($mock): void {
            $mock->shouldReceive('allowsRouterSessionCollector')->once()->andReturn(false);
            $mock->shouldNotReceive('evaluate');
        });

        Artisan::shouldReceive('call')->never();

        (new RunMonitoringCollectorJob('router', 'routers:mt-session-sync', [], 'router-sessions-small'))
            ->handle($gate);
    }

    public function test_router_health_job_still_uses_full_gate(): void
    {
        $gate = $this->mock(MonitoringAdmissionGate::class, function ($mock): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->with('collector:router')
                ->andReturn(['allowed' => false, 'reason_codes' => ['normalized_load_high']]);
            $mock->shouldNotReceive('allowsRouterSessionCollector');
        });

        Artisan::shouldReceive('call')->never();

        (new RunMonitoringCollectorJob('router', 'routers:live-health', [], 'router-health'))->handle($gate);
    }

    public function test_schedule_wires_both_session_lanes_to_session_gate(): void
    {
        $schedule = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('->allowsRouterSessionCollector()', $schedule);

        foreach (['router-sessions-small', 'router-sessions-large'] as $target) {
            $start = strpos($schedule, "'{$target}'))");
            $this->assertNotFalse($start, $target);
            $entry = substr($schedule, $start, (int) strpos($schedule, '->description(', $start) - $start);
            $this->assertStringContainsString('->when($routerSessionCollectorSchedule)', $entry, $target);
            $this->assertStringNotContainsString('$monitoringQueuesClear', $entry, $target);
        }

        $health = strpos($schedule, "'router-health'))");
        $this->assertNotFalse($health);
        $healthEntry = substr($schedule, $health, (int) strpos($schedule, '->description(', $health) - $health);
        $this->assertStringContainsString('->when($monitoringQueuesClear)', $healthEntry);
    }
}
