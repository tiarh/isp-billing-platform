<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\UnifiedConnectivityReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\Canary;
use Tests\TestCase;

class UnifiedConnectivityReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_model_filters_are_bounded_for_noc_capacity(): void
    {
        $filters = app(UnifiedConnectivityReadModel::class)->filters(Request::create('/monitoring', 'GET', [
            'connectivity_page' => 9999,
            'connectivity_per_page' => 500,
        ]));

        $this->assertSame(400, $filters['page']);
        $this->assertSame(100, $filters['per_page']);
    }

    public function test_default_page_composes_only_requested_rows(): void
    {
        Setting::set('monitoring_unified_read_model_v2', true, 'boolean', 'monitoring');
        $package = Package::create(['code' => 'MON-PAGE', 'name' => 'Monitoring page', 'price' => 0]);
        foreach (range(1, 60) as $id) {
            Customer::create([
                'code' => 'MON-PAGE-'.$id,
                'name' => 'Monitoring '.$id,
                'phone' => '08000000'.$id,
                'address' => 'NOC',
                'pppoe_user' => 'mon-page-'.$id,
                'package_id' => $package->id,
                'status' => 'active',
            ]);
        }

        $payload = app(UnifiedConnectivityReadModel::class)->payload(Request::create('/monitoring'));

        $this->assertSame(60, $payload['total_rows']);
        $this->assertCount(50, $payload['rows']);
    }

    public function test_filtered_summary_matches_filtered_rows(): void
    {
        Setting::set('monitoring_unified_read_model_v2', true, 'boolean', 'monitoring');
        $package = Package::create(['code' => 'MON-FILTER', 'name' => 'Monitoring filter', 'price' => 0]);
        $router = Router::create([
            'name' => 'MON Router',
            'ip_address' => '127.0.0.20',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $olt = Olt::create(['name' => 'MON OLT', 'code' => 'MON-OLT', 'vendor' => 'zte_c300', 'router_id' => $router->id]);
        Customer::create([
            'code' => 'MON-ONLINE',
            'name' => 'Monitoring online',
            'phone' => '080000001',
            'address' => 'NOC',
            'pppoe_user' => 'mon-online',
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'olt_status' => 'working',
            'is_online' => true,
        ]);
        Customer::create([
            'code' => 'MON-OFFLINE',
            'name' => 'Monitoring offline',
            'phone' => '080000002',
            'address' => 'NOC',
            'pppoe_user' => 'mon-offline',
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'olt_status' => 'offline',
            'olt_last_synced_at' => Carbon::now()->subMinutes(3),
            'mikrotik_sync_checked_at' => Carbon::now()->subMinutes(3),
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(3),
            'is_online' => false,
        ]);

        $payload = app(UnifiedConnectivityReadModel::class)->payload(Request::create('/monitoring', 'GET', [
            'source' => 'olt_onu',
            'connectivity_status' => 'offline',
        ]));

        $this->assertSame(1, $payload['total_rows']);
        $this->assertSame(1, $payload['summary']['total']);
        $this->assertSame(0, $payload['summary']['online']);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame('offline', $payload['rows'][0]['status']);
    }

    public function test_isolated_customer_is_not_offline_or_outage(): void
    {
        $customer = new Customer([
            'id' => 10,
            'status' => 'isolated',
            'is_online' => false,
            'pppoe_user' => 'TEST',
            'olt_status' => 'offline',
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('isolated', $row['status']);
        $this->assertStringContainsString('tidak dihitung', $row['reason']);
    }

    public function test_fiberhome_ping_failure_is_degraded_not_offline(): void
    {
        $customer = new Customer([
            'id' => 11,
            'status' => 'active',
            'is_online' => true,
            'pppoe_user' => 'TEST',
            'olt_status' => 'working',
        ]);
        $device = new GenieAcsDevice([
            'manufacturer' => 'FiberHome',
            'last_inform_at' => Carbon::now()->subMinutes(5),
            'last_ping_at' => Carbon::now()->subMinutes(5),
            'last_ping_success' => false,
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer, $device);

        $this->assertNotSame('offline', $row['status']);
        $this->assertSame('fiberhome_tr069', $row['strategy']);
    }

    public function test_fresh_pppoe_and_olt_negative_evidence_is_offline_with_high_confidence(): void
    {
        $customer = new Customer([
            'id' => 12,
            'status' => 'active',
            'is_online' => false,
            'pppoe_user' => 'TEST-OFFLINE',
            'olt_status' => 'offline',
            'router_id' => 2,
            'olt_id' => 3,
            'olt_last_synced_at' => Carbon::now()->subMinutes(3),
            'mikrotik_sync_checked_at' => Carbon::now()->subMinutes(3),
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(3),
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('offline', $row['status']);
        $this->assertSame('high', $row['confidence']);
        $this->assertSame('pppoe_and_olt_offline', $row['reason_code']);
    }

    public function test_stale_sources_are_unknown_not_offline(): void
    {
        $customer = new Customer([
            'id' => 13,
            'status' => 'active',
            'is_online' => false,
            'pppoe_user' => 'TEST-STALE',
            'olt_status' => 'offline',
            'router_id' => 2,
            'olt_id' => 3,
            'olt_last_synced_at' => Carbon::now()->subHours(3),
            'mikrotik_sync_checked_at' => Carbon::now()->subHours(3),
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('unknown', $row['status']);
        $this->assertSame('all_sources_stale', $row['reason_code']);
    }

    public function test_fresh_olt_snapshot_overrides_stale_customer_projection_read_only(): void
    {
        $customer = new Customer([
            'id' => 16,
            'status' => 'active',
            'is_online' => false,
            'pppoe_user' => 'TEST-SNAPSHOT',
            'onu_serial' => 'fhtt-snapshot-16',
            'olt_status' => 'offline',
            'router_id' => 2,
            'olt_id' => 3,
            'olt_last_synced_at' => Carbon::now()->subHours(3),
            'mikrotik_sync_checked_at' => Carbon::now()->subHours(3),
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer, null, [
            'olt_id' => 3,
            'serial_number' => 'FHTT-SNAPSHOT-16',
            'state' => 'online',
            'phase_state' => 'O5',
            'rx_power_dbm' => -21.5,
            'state_collected_at' => Carbon::now()->subMinutes(3),
        ]);

        $this->assertSame('degraded', $row['status']);
        $this->assertFalse($row['stale']);
        $this->assertSame('olt_onus', $row['evidence']['olt']['source']);
        $this->assertSame('online', $row['evidence']['olt']['state']);
        $this->assertSame('olt_onu', $row['source']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_hioso_olt_is_classified_as_hioso_htb_strategy(): void
    {
        $customer = new Customer([
            'id' => 14,
            'status' => 'active',
            'is_online' => true,
            'pppoe_user' => 'Hioso customer',
            'olt_status' => 'working',
        ]);
        $customer->setRelation('olt', new Olt(['vendor' => 'hioso']));

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('hioso_htb', $row['strategy']);
    }

    public function test_mapped_customer_without_pppoe_uses_manual_strategy(): void
    {
        $customer = new Customer([
            'id' => 15,
            'status' => 'active',
            'is_online' => true,
            'router_id' => 2,
            'olt_id' => 3,
            'olt_status' => 'working',
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('manual', $row['strategy']);
    }
}
