<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePonAfterProvisionJob;
use App\Jobs\RefreshOltSnapshotJob;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\Package;
use App\Models\User;
use App\Services\GenieAcsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class SplitTopologyStabilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gpon_snapshot_refresh_serves_cached_snapshot_and_queues_worker_job(): void
    {
        Bus::fake([RefreshOltSnapshotJob::class]);

        $user = User::factory()->create(['role' => 'superadmin']);
        $olt = Olt::create([
            'name' => 'OLT Split Test',
            'code' => 'OLT-SPLIT',
            'vendor' => 'zte_c300',
            'last_gpon_snapshot' => [
                'onus' => [
                    [
                        'onu_ref' => '1/3/8:1',
                        'pon_port' => '1/3/8',
                        'serial_number' => 'FHTT0000A02A',
                        'onu_name' => 'USERAQ_RT2_RW7_01',
                    ],
                ],
                'matched_customers' => [],
                'meta' => ['source' => 'test-cache'],
            ],
            'last_gpon_synced_at' => now()->subMinutes(15),
        ]);

        $response = $this->actingAs($user)->getJson(route('api.olts.gpon-snapshot', $olt).'?refresh=1');

        $response->assertOk()
            ->assertJsonPath('meta.served_from_cache', true)
            ->assertJsonPath('meta.refresh_queued', true)
            ->assertJsonPath('meta.refresh_inline', false)
            ->assertJsonPath('meta.stale', true)
            ->assertJsonCount(1, 'onus');

        Bus::assertDispatched(RefreshOltSnapshotJob::class);
    }

    public function test_gpon_snapshot_refresh_without_cache_returns_structured_response_not_500(): void
    {
        Bus::fake([RefreshOltSnapshotJob::class]);

        $user = User::factory()->create(['role' => 'superadmin']);
        $olt = Olt::create([
            'name' => 'OLT Empty Snapshot',
            'code' => 'OLT-EMPTY',
            'vendor' => 'zte_c300',
        ]);

        $response = $this->actingAs($user)->getJson(route('api.olts.gpon-snapshot', $olt).'?refresh=1');

        $response->assertStatus(202)
            ->assertJsonPath('meta.served_from_cache', false)
            ->assertJsonPath('meta.refresh_queued', true)
            ->assertJsonPath('meta.refresh_inline', false)
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('onus', []);

        Bus::assertDispatched(RefreshOltSnapshotJob::class);
    }

    public function test_enterprise_snapshot_restores_customer_serial_when_inventory_serial_is_missing(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $package = Package::create([
            'code' => 'SNAPSHOT-SN',
            'name' => 'Snapshot Serial Test',
            'price' => 100000,
        ]);
        $olt = Olt::create([
            'name' => 'OLT Snapshot Serial',
            'code' => 'OLT-SNAPSHOT-SN',
            'vendor' => 'zte_c300',
        ]);

        Customer::create([
            'code' => 'C-SNAPSHOT-SN',
            'name' => 'RUMINI',
            'phone' => '081234567890',
            'address' => 'Randuagung',
            'pppoe_user' => 'USERAX_RT2_RW5_08',
            'onu_serial' => 'FHTT0000A04D',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/2/2:22',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        DB::table('olt_onus')->insert([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/2:22',
            'pon_port' => '1/2/2',
            'serial_number' => null,
            'onu_name' => null,
            'state' => 'online',
            'rx_power_dbm' => -26.58,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('api.olts.gpon-snapshot', $olt))
            ->assertOk()
            ->assertJsonFragment([
                'onu_ref' => '1/2/2:22',
                'serial_number' => 'FHTT0000A04D',
            ]);

        $this->assertDatabaseHas('customers', [
            'code' => 'C-SNAPSHOT-SN',
            'onu_serial' => 'FHTT0000A04D',
            'olt_last_synced_at' => null,
        ]);
    }

    public function test_olt_sync_metadata_and_per_olt_overlap_guard_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('olts', 'sync_status'));
        $this->assertTrue(Schema::hasColumn('olts', 'sync_started_at'));
        $this->assertTrue(Schema::hasColumn('olts', 'last_sync_error'));

        $middleware = (new RefreshOltSnapshotJob(7))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertSame('Illuminate\\Queue\\Middleware\\WithoutOverlapping', $middleware[0]::class);
    }

    public function test_pon_reconcile_is_serialized_per_olt_and_pon(): void
    {
        $middleware = (new ReconcilePonAfterProvisionJob([
            'olt_id' => 1,
            'pon_port' => '1/3/8',
        ]))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertSame('Illuminate\\Queue\\Middleware\\WithoutOverlapping', $middleware[0]::class);
    }

    public function test_monitoring_auto_reload_has_backoff_and_no_plain_interval_loop(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Monitoring/Index.tsx'));

        $this->assertStringContainsString('autoReloadInFlight', $source);
        $this->assertStringContainsString('autoReloadFailures', $source);
        $this->assertStringContainsString('tabNavigationInFlight', $source);
        $this->assertStringContainsString('preserveState: false', $source);
        $this->assertStringContainsString('onFinish', $source);
        $this->assertStringContainsString('onError', $source);
        $this->assertStringContainsString('window.setTimeout', $source);
        $this->assertStringContainsString('tabNavigationInFlight.current', $source);
        $this->assertStringNotContainsString('setActiveTab(tab);', $source);
        $this->assertStringNotContainsString('setInterval(() => {', $source);
    }

    public function test_enterprise_shell_tabs_are_idempotent_for_active_tab(): void
    {
        $source = file_get_contents(resource_path('js/Components/enterprise/EnterprisePageShell.tsx'));

        $this->assertStringContainsString('disabled={active}', $source);
        $this->assertStringContainsString('if (!active)', $source);
    }

    public function test_monitoring_month_filter_avoids_radix_select_ref_loop(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Monitoring/Index.tsx'));

        // The month filter lived in the legacy analytics tab and was removed in M-6;
        // the page must still not pull in the Radix select (ref loop).
        $this->assertStringNotContainsString('@/Components/ui/select', $source);
    }

    public function test_split_storage_media_urls_do_not_hardcode_public_storage_path(): void
    {
        $psbController = file_get_contents(app_path('Http/Controllers/PsbDraftController.php'));
        $customerModel = file_get_contents(app_path('Models/Customer.php'));

        $this->assertStringContainsString('URL::temporarySignedRoute(', $psbController);
        $this->assertStringContainsString("'psb-drafts.media'", $psbController);
        $this->assertStringContainsString('Storage::disk(config(\'filesystems.default\'))->url', $customerModel);
        $this->assertStringNotContainsString("'/storage/'.", $psbController);
        $this->assertStringNotContainsString("asset('storage/'", $customerModel);
    }

    public function test_genieacs_browser_sync_failure_returns_stale_json_not_500(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->once()->andThrow(new \RuntimeException('GenieACS timeout'));
            $mock->shouldReceive('summary')->once()->andReturn([
                'total' => 0,
                'online' => 0,
                'offline' => 0,
            ]);
        });

        $response = $this->actingAs($user)->postJson(route('api.genieacs.sync'), []);

        $response->assertAccepted()
            ->assertJsonPath('error_code', 'genieacs_sync_failed')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('synced', 0);
    }

    public function test_genieacs_browser_sync_never_runs_inline_auto_tagging(): void
    {
        Cache::forget('genieacs:snapshot-sync');
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')
                ->once()
                ->with(['auto_tag' => false, 'auto_tag_limit' => 0])
                ->andReturn(['synced' => 2, 'fetched' => 2]);
        });

        $this->actingAs($user)
            ->postJson(route('api.genieacs.sync'), [])
            ->assertOk()
            ->assertJsonPath('synced', 2);
    }

    public function test_genieacs_empty_snapshot_is_stale_when_local_devices_exist(): void
    {
        GenieAcsDevice::create(['device_id' => 'test-device-1', 'serial_number' => 'FHTTTEST01']);
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')
                ->once()
                ->with(['auto_tag' => false, 'auto_tag_limit' => 0])
                ->andReturn(['synced' => 0, 'fetched' => 0]);
            $mock->shouldReceive('summary')->once()->andReturn([
                'total' => 1,
                'online' => 0,
                'offline' => 1,
            ]);
        });

        $this->actingAs($user)
            ->postJson(route('api.genieacs.sync'), [])
            ->assertAccepted()
            ->assertJsonPath('error_code', 'genieacs_empty_snapshot')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('fetched', 0);
    }

    public function test_genieacs_scheduled_sync_command_is_bounded_by_default(): void
    {
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')
                ->once()
                ->with(['auto_tag' => false, 'auto_tag_limit' => 0])
                ->andReturn(['synced' => 1, 'fetched' => 1, 'duration_ms' => 20]);
        });

        $this->artisan('genieacs:sync')
            ->expectsOutputToContain('GenieACS snapshot selesai')
            ->assertExitCode(0);
    }

    public function test_genieacs_list_hides_devices_absent_from_latest_snapshot_by_default(): void
    {
        GenieAcsDevice::create([
            'device_id' => 'current-device',
            'serial_number' => 'FHTTCURRENT01',
            'snapshot_status' => 'active',
            'last_seen_sync_at' => now(),
        ]);
        GenieAcsDevice::create([
            'device_id' => 'removed-device',
            'serial_number' => 'FHTTREMOVED01',
            'snapshot_status' => 'not_found',
            'last_seen_sync_at' => now()->subHour(),
        ]);
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->getJson(route('api.genieacs.devices'))
            ->assertOk()
            ->assertJsonFragment(['device_id' => 'current-device'])
            ->assertJsonMissing(['device_id' => 'removed-device']);

        $this->actingAs($user)
            ->getJson(route('api.genieacs.devices', ['snapshot' => 'not_found']))
            ->assertOk()
            ->assertJsonFragment(['device_id' => 'removed-device']);
    }
}
