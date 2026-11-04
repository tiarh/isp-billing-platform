<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Package;
use App\Models\User;
use App\Services\Grafana\MonitoringWidgets;
use App\Services\Monitoring\CpeHealthReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-4d: GET /api/monitoring/widgets/cpe (aggregate CPE health). Dummy data only.
 */
#[Group('blocking-core')]
class MonitoringCpeHealthWidgetTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->package = Package::create(['code' => 'CPE-T', 'name' => 'CPE Test', 'price' => 0]);
    }

    private function admin(string $role = 'superadmin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function cpe(?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->getJson('/api/monitoring/widgets/cpe');
    }

    private function customer(?float $rx, array $overrides = []): Customer
    {
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'CPE-C'.$n,
            'name' => 'Pelanggan Uji '.$n,
            'phone' => '0800000000',
            'address' => 'Alamat Uji',
            'package_id' => $this->package->id,
            'pppoe_user' => 'uji-cpe-'.$n,
            'status' => 'active',
            'is_online' => true,
            'onu_rx_power_dbm' => $rx,
        ], $overrides));
    }

    private function device(?string $informAgo, ?Customer $customer = null, string $snapshotStatus = 'active', string $syncAgo = '1 minute'): GenieAcsDevice
    {
        $n = ++$this->seq;

        return GenieAcsDevice::create([
            'device_id' => 'dev-cpe-'.$n,
            'customer_id' => $customer?->id,
            'last_inform_at' => $informAgo === null ? null : now()->modify('-'.$informAgo),
            'snapshot_status' => $snapshotStatus,
            'last_seen_sync_at' => now()->modify('-'.$syncAgo),
        ]);
    }

    public function test_global_admins_get_200_and_others_are_refused(): void
    {
        $this->device('5 minutes');

        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->cpe($this->admin($role))->assertOk()->assertJsonPath('widget', 'cpe');
        }

        $scoped = $this->admin('admin');
        $scoped->areas()->attach(Area::create(['code' => 'A', 'name' => 'Area A']));
        $this->cpe($scoped)->assertForbidden();
        $this->cpe($this->admin('teknisi'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/api/monitoring/widgets/cpe')->assertRedirect('/login');
    }

    public function test_generic_widget_route_still_rejects_unknown_widgets(): void
    {
        $this->actingAs($this->admin())->getJson('/api/monitoring/widgets/cpe-nope')->assertNotFound();
        $this->assertNotContains('cpe', MonitoringWidgets::WIDGETS);
    }

    public function test_online_percentage_counts_devices_by_last_inform(): void
    {
        $this->device('5 minutes');
        $this->device('20 minutes');
        $this->device('3 hours');
        $this->device(null);

        $response = $this->cpe()->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('partial', false)
            ->assertJsonPath('data.devices.total', 4)
            ->assertJsonPath('data.devices.online', 2)
            ->assertJsonPath('data.devices.offline', 2)
            ->assertJsonPath('data.devices.online_pct', 50)
            ->assertJsonPath('range', null);
    }

    public function test_rx_power_buckets_use_online_non_isolated_customers_only(): void
    {
        foreach ([-20.0, -25.0] as $rx) {
            $this->customer($rx);
        }
        foreach ([-25.01, -26.99] as $rx) {
            $this->customer($rx);
        }
        foreach ([-27.0, -31.5] as $rx) {
            $this->customer($rx);
        }
        // Excluded: offline, isolated, no reading, soft-deleted.
        $this->customer(-40.0, ['is_online' => false]);
        $this->customer(-40.0, ['status' => 'isolated']);
        $this->customer(null);
        $this->customer(-40.0)->delete();
        $this->device('5 minutes');

        $rx = $this->cpe()->assertOk()->json('data.rx_power');

        $this->assertSame(['measured' => 6, 'good' => 2, 'fair' => 2, 'poor' => 2], $rx);
    }

    public function test_top_areas_are_ranked_by_offline_count_and_limited_to_five(): void
    {
        $offlineByArea = ['Area G' => 1, 'Area A' => 6, 'Area B' => 5, 'Area C' => 4, 'Area D' => 3, 'Area E' => 2, 'Area F' => 0];
        foreach ($offlineByArea as $name => $offline) {
            $area = Area::create(['code' => str_replace(' ', '-', $name), 'name' => $name]);
            for ($i = 0; $i < $offline; $i++) {
                $this->device('5 hours', $this->customer(null, ['area_id' => $area->id]));
            }
            $this->device('5 minutes', $this->customer(null, ['area_id' => $area->id]));
        }
        // Offline but unlinked / without an area: not part of the ranking.
        $this->device('5 hours');
        $this->device('5 hours', $this->customer(null));

        $areas = $this->cpe()->assertOk()->json('data.top_offline_areas');

        $this->assertCount(5, $areas);
        $this->assertSame(['Area A', 'Area B', 'Area C', 'Area D', 'Area E'], array_column($areas, 'area'));
        $this->assertSame([6, 5, 4, 3, 2], array_column($areas, 'offline'));
        $this->assertSame(7, $areas[0]['total']);
    }

    public function test_stale_follows_sync_freshness_not_device_inform_time(): void
    {
        // Devices informed long ago, but the sync is fresh: not stale.
        $this->device('3 hours');
        $this->device(null);

        $this->cpe()->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('partial', false)
            ->assertJsonPath('data.devices.online', 0);

        // Sync older than 2 hours: stale even though a device informed just now.
        GenieAcsDevice::query()->update(['last_seen_sync_at' => now()->subHours(3)]);
        $this->device('1 minute', null, 'active', '3 hours');
        Cache::flush();

        $this->cpe()->assertOk()
            ->assertJsonPath('status', 'stale')
            ->assertJsonPath('partial', true)
            ->assertJsonPath('data.devices.online', 1);

        Cache::flush();
        $this->device('3 hours', null, 'active', '90 minutes');

        $this->cpe()->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_devices_genieacs_no_longer_reports_are_excluded(): void
    {
        $area = Area::create(['code' => 'NF', 'name' => 'Area NF']);
        $gone = $this->customer(null, ['area_id' => $area->id]);
        $this->device('5 hours', $gone, 'not_found');
        $this->device('5 minutes', null, 'not_found');
        $this->device('5 hours', $this->customer(null, ['area_id' => $area->id]), 'active');

        $response = $this->cpe()->assertOk();

        $response->assertJsonPath('data.devices.total', 1)
            ->assertJsonPath('data.devices.online', 0)
            ->assertJsonPath('data.devices.offline', 1);
        $areas = $response->json('data.top_offline_areas');
        $this->assertSame([['area' => 'Area NF', 'offline' => 1, 'total' => 1]], $areas);
    }

    public function test_top_areas_only_count_active_customers(): void
    {
        $area = Area::create(['code' => 'ISO', 'name' => 'Area ISO']);
        $this->device('5 hours', $this->customer(null, ['area_id' => $area->id, 'status' => 'isolated']));
        $this->device('5 hours', $this->customer(null, ['area_id' => $area->id, 'status' => 'suspended']));
        $this->device('5 minutes');

        $this->cpe()->assertOk()->assertJsonPath('data.top_offline_areas', []);
    }

    public function test_no_devices_is_setup_required(): void
    {
        $this->cpe()->assertOk()
            ->assertJsonPath('status', 'setup_required')
            ->assertJsonPath('data.devices.total', 0)
            ->assertJsonPath('data.devices.online_pct', null);
    }

    public function test_payload_is_cached_for_two_minutes(): void
    {
        $this->device('5 minutes');
        $first = $this->cpe()->assertOk()->json();

        $this->device('5 minutes');
        $second = $this->cpe()->assertOk()->json();

        $this->assertSame(1, $first['data']['devices']['total']);
        $this->assertSame($first, $second);
        $this->assertTrue(Cache::has(CpeHealthReadModel::CACHE_KEY));
        $this->assertSame(120, CpeHealthReadModel::CACHE_TTL_SECONDS);

        Cache::forget(CpeHealthReadModel::CACHE_KEY);
        $this->assertSame(2, $this->cpe()->json('data.devices.total'));
    }

    public function test_payload_has_aggregates_only(): void
    {
        $area = Area::create(['code' => 'PRIV', 'name' => 'Area Privasi']);
        $customer = $this->customer(-30.0, ['area_id' => $area->id]);
        $this->device('5 hours', $customer);

        $body = $this->cpe()->assertOk()->getContent();

        $this->assertStringNotContainsString($customer->name, $body);
        $this->assertStringNotContainsString($customer->pppoe_user, $body);
        $this->assertStringNotContainsString($customer->code, $body);
        $this->assertStringNotContainsString('dev-cpe-', $body);
        $this->assertStringContainsString('Area Privasi', $body);
    }
}
