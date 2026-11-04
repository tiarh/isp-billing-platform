<?php

namespace Tests\Feature;

use App\Models\DeviceActionLog;
use App\Models\User;
use App\Services\ProductionControlPlaneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint9AProductionControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_view_control_plane_snapshot(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($user)->get(route('production-control-plane.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ProductionControlPlane/Index')
            ->has('control_plane.source_of_truth')
            ->has('control_plane.state_machines.psb')
            ->has('control_plane.wa_command_contracts')
            ->has('control_plane.device_action_policy')
            ->has('control_plane.kill_switches')
            ->where('control_plane.ml_guard.manual_approval_required', true)
        );
    }

    public function test_non_superadmin_cannot_view_control_plane(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('production-control-plane.index'))
            ->assertForbidden();
    }

    public function test_device_writes_are_disabled_by_default(): void
    {
        $service = app(ProductionControlPlaneService::class);

        $this->assertFalse($service->writeEnabled('mikrotik'));
        $this->assertFalse($service->writeEnabled('olt'));
        $this->assertFalse($service->writeEnabled('genieacs'));
    }

    public function test_monitoring_canary_flags_are_off_by_default(): void
    {
        $flags = collect(app(ProductionControlPlaneService::class)->featureFlags())
            ->keyBy('key');

        foreach ([
            'monitoring_unified_read_model_v2',
            'monitoring_incident_correlation_enabled',
            'monitoring_noc_cockpit_enabled',
            'monitoring_p1p2_notification_enabled',
        ] as $key) {
            $this->assertArrayHasKey($key, $flags->all());
            $this->assertFalse((bool) $flags[$key]['value']);
        }
    }

    public function test_device_action_ledger_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $service = app(ProductionControlPlaneService::class);

        $first = $service->ledgerRequested(
            domain: 'mikrotik',
            targetType: 'router',
            targetId: 'RA',
            action: 'rename-secret',
            payload: ['username_from' => 'USERC_6', 'username_to' => 'USERC_7.1'],
            actor: $user,
            preflight: ['secret_live' => true],
            idempotencyKey: 'sprint9a:test:rename-secret',
            rollbackHint: 'rename secret back to USERC_6'
        );
        $second = $service->ledgerRequested(
            domain: 'mikrotik',
            targetType: 'router',
            targetId: 'RA',
            action: 'rename-secret',
            payload: ['username_from' => 'USERC_6', 'username_to' => 'USERC_7.1'],
            actor: $user,
            preflight: ['secret_live' => true],
            idempotencyKey: 'sprint9a:test:rename-secret',
            rollbackHint: 'rename secret back to USERC_6'
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, DeviceActionLog::count());
        $this->assertSame('requested', $first->status);
        $this->assertSame(64, strlen($first->payload_hash));
    }
}
