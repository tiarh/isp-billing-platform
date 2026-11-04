<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\PppoeOnlineFlagWriter;
use App\Services\UnifiedConnectivityReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Owner rule (audit 2026-10-08, Gadang + RA + Krian): ONLINE only when
 * (1) ONU online on the OLT, (2) valid optical RX and (3) PPPoE active on the
 * MikroTik — all FRESH. Stale snapshots are "tidak diketahui (data basi)".
 */
#[Group('blocking-core')]
class OnlineStatusFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_strict_customer_is_online_only_when_pppoe_onu_and_rx_are_all_fresh(): void
    {
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subMinutes(4)]);

        $row = $this->compose($customer, $this->snapshot('online', 'WORKING', -21.5, 3, 5));

        $this->assertSame('online', $row['status']);
        $this->assertSame('pppoe_onu_rx_fresh', $row['reason_code']);
        $this->assertSame('strict_pppoe_onu_rx', $row['rule']);
        $this->assertSame('high', $row['confidence']);
    }

    public function test_frozen_huawei_state_is_unknown_stale_not_online(): void
    {
        // Gadang 148195: PPP + ONU "online" frozen since 2026-09-19.
        $customer = $this->customer('huawei_ma5608t', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::parse('2026-10-02 09:27:29')]);

        $row = $this->compose($customer, $this->snapshot('online', 'normal', -16.64, 60 * 24 * 19, 60 * 47));

        $this->assertSame('unknown', $row['status']);
        $this->assertSame('stale_evidence', $row['reason_code']);
        $this->assertStringContainsString('data basi', $row['reason']);
        $this->assertTrue($row['stale']);
    }

    public function test_stale_pppoe_flag_alone_never_makes_a_strict_customer_online(): void
    {
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subDays(4)]);

        $row = $this->compose($customer, $this->snapshot('online', 'WORKING', -22.68, 3, 5));

        $this->assertSame('unknown', $row['status']);
        $this->assertStringContainsString('PPPoE cek terakhir', $row['reason']);
    }

    public function test_fresh_onu_offline_beats_stale_pppoe_online(): void
    {
        // Krian 1271: is_online=1 (stale) while ONU DYINGGASP fresh.
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subHours(20)]);

        $row = $this->compose($customer, $this->snapshot('offline', 'DYINGGASP', null, 3, null));

        $this->assertSame('offline', $row['status']);
        $this->assertSame('onu_offline', $row['reason_code']);
    }

    public function test_fresh_pppoe_inactive_is_offline_even_with_onu_up(): void
    {
        $customer = $this->customer('huawei_ma5608t', ['is_online' => false, 'pppoe_online_checked_at' => Carbon::now()->subMinutes(2)]);

        $row = $this->compose($customer, $this->snapshot('online', 'normal', -20.1, 3, 3));

        $this->assertSame('offline', $row['status']);
        $this->assertSame('pppoe_inactive_onu_online', $row['reason_code']);
    }

    public function test_missing_rx_blocks_online(): void
    {
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subMinutes(2)]);

        $row = $this->compose($customer, $this->snapshot('online', 'WORKING', null, 3, null));

        $this->assertSame('unknown', $row['status']);
        $this->assertStringContainsString('RX/redaman tidak terbaca', $row['reason']);
    }

    public function test_stale_rx_blocks_online(): void
    {
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subMinutes(2)]);

        $row = $this->compose($customer, $this->snapshot('online', 'WORKING', -19.0, 3, 90));

        $this->assertSame('unknown', $row['status']);
    }

    public function test_freshness_thresholds_are_configurable(): void
    {
        config(['services.online_freshness.ppp_max_age_minutes' => 60]);
        $customer = $this->customer('zte_c300', ['is_online' => true, 'pppoe_online_checked_at' => Carbon::now()->subMinutes(45)]);

        $row = $this->compose($customer, $this->snapshot('online', 'WORKING', -21.0, 3, 3));

        $this->assertSame('online', $row['status']);
    }

    public function test_customer_without_olt_mapping_is_never_online(): void
    {
        // Krian router 15 HTB: olt_id + onu_serial NULL, is_online fresh.
        $router = $this->router();
        $customer = $this->makeCustomer([
            'router_id' => $router->id,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinute(),
        ]);

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertSame('setup_required', $row['status']);
        $this->assertNotSame('online', $row['status']);
    }

    public function test_lenient_vendor_needs_fresh_pppoe_too(): void
    {
        $olt = $this->olt('hioso');
        $router = $this->router();
        $customer = $this->makeCustomer([
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'olt_status' => 'online',
            'olt_last_synced_at' => Carbon::now()->subMinutes(5),
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subDays(6),
        ])->load('olt');

        $row = app(UnifiedConnectivityReadModel::class)->compose($customer);

        $this->assertNotSame('online', $row['status']);
        $this->assertSame('lenient', $row['rule']);

        $customer->pppoe_online_checked_at = Carbon::now()->subMinutes(3);
        $this->assertSame('online', app(UnifiedConnectivityReadModel::class)->compose($customer)['status']);
    }

    public function test_offline_state_wins_over_leftover_rx_reading(): void
    {
        $this->assertSame('offline', OltOnu::normalizeOperationalState('offline', null, -20.0));
        $this->assertSame('offline', OltOnu::normalizeOperationalState(null, 'LOS', -20.0));
        $this->assertSame('online', OltOnu::normalizeOperationalState(null, null, -20.0));
        $this->assertFalse(OltOnu::isFreshLiveRx(-20.0, null));
        $this->assertFalse(OltOnu::isFreshLiveRx(-20.0, Carbon::now()->subHour()));
        $this->assertTrue(OltOnu::isFreshLiveRx(-20.0, Carbon::now()->subMinutes(5)));
    }

    public function test_freshest_olt_wins_when_onu_serial_moved_between_olts(): void
    {
        // Gadang: customer still mapped to HiOSO OLT 3 (data from August) but
        // the same SN is on Huawei OLT 11 now.
        $router = $this->router();
        $hioso = $this->olt('hioso');
        $huawei = $this->olt('huawei_ma5608t');
        OltOnu::create([
            'olt_id' => $hioso->id, 'onu_ref' => '0/1/1:1', 'serial_number' => 'HWTCMOVED01',
            'state' => 'online', 'state_collected_at' => Carbon::parse('2026-08-13 05:56:50'),
            'rx_power_dbm' => -18.0, 'rx_collected_at' => Carbon::parse('2026-08-13 05:56:50'),
        ]);
        OltOnu::create([
            'olt_id' => $huawei->id, 'onu_ref' => '0/0/7:27', 'serial_number' => 'HWTCMOVED01',
            'state' => 'offline', 'state_collected_at' => Carbon::now()->subMinutes(4),
            'rx_power_dbm' => null, 'rx_collected_at' => Carbon::now()->subMinutes(4),
        ]);
        $customer = $this->makeCustomer([
            'router_id' => $router->id,
            'olt_id' => $hioso->id,
            'onu_serial' => 'HWTCMOVED01',
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subDays(6),
        ])->load('olt');

        $row = app(UnifiedConnectivityReadModel::class)->composeMany([$customer])[$customer->id];

        $this->assertSame('strict_pppoe_onu_rx', $row['rule']);
        $this->assertSame('offline', $row['status']);
        $this->assertSame('onu_offline', $row['reason_code']);
    }

    public function test_map_uses_the_same_rule_and_never_paints_stale_is_online_green(): void
    {
        $area = Area::create(['code' => 'FRESHMAP', 'name' => 'Fresh map']);
        $router = $this->router();
        $this->makeCustomer([
            'area_id' => $area->id,
            'router_id' => $router->id,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subHours(10),
            'geo_lat' => -7.4,
            'geo_long' => 112.6,
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson(route('api.peta.customers', [
            'area' => 'FRESHMAP',
            'zoom' => 16,
            'limit' => 100,
        ]));

        $response->assertOk()
            ->assertJsonPath('features.0.properties.type', 'CUSTOMER')
            ->assertJsonPath('features.0.properties.online', false)
            ->assertJsonPath('features.0.properties.connectivity_status', 'setup_required');

        $cluster = $this->actingAs($user)->getJson(route('api.peta.customers', [
            'area' => 'FRESHMAP',
            'zoom' => 10,
            'limit' => 100,
        ]));
        $cluster->assertOk()
            ->assertJsonPath('features.0.properties.online_count', 0)
            ->assertJsonPath('features.0.properties.unknown_count', 1);
    }

    /** @return array<string, mixed> */
    private function compose(Customer $customer, array $snapshot): array
    {
        return app(UnifiedConnectivityReadModel::class)->compose($customer, null, $snapshot);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $state, ?string $phase, ?float $rx, int $stateAgeMinutes, ?int $rxAgeMinutes): array
    {
        return [
            'olt_id' => 1,
            'serial_number' => 'ZTEGFRESH',
            'state' => $state,
            'phase_state' => $phase,
            'rx_power_dbm' => $rx,
            'state_collected_at' => Carbon::now()->subMinutes($stateAgeMinutes),
            'rx_collected_at' => $rxAgeMinutes === null ? null : Carbon::now()->subMinutes($rxAgeMinutes),
        ];
    }

    /** In-memory customer on a strict/lenient OLT (no DB write). */
    private function customer(string $vendor, array $attributes): Customer
    {
        $customer = new Customer(array_merge([
            'status' => 'active',
            'pppoe_user' => 'fresh-'.(++$this->seq),
            'router_id' => 2,
            'olt_id' => 1,
            'onu_serial' => 'ZTEGFRESH',
        ], $attributes));
        $customer->id = 9000 + $this->seq;
        $customer->setRelation('olt', new Olt(['vendor' => $vendor]));

        return $customer;
    }

    private function router(): Router
    {
        $this->seq++;

        return Router::create([
            'name' => 'Fresh router '.$this->seq,
            'ip_address' => '127.0.20.'.$this->seq,
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
    }

    private function olt(string $vendor): Olt
    {
        $this->seq++;

        return Olt::create(['name' => 'Fresh OLT '.$this->seq, 'code' => 'FRESH-OLT-'.$this->seq, 'vendor' => $vendor]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeCustomer(array $attributes): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'FRESH-PKG'], ['name' => 'Fresh', 'price' => 0]);

        return Customer::create(array_merge([
            'code' => 'FRESH-'.$this->seq,
            'name' => 'Fresh customer '.$this->seq,
            'phone' => '0813000'.$this->seq,
            'address' => 'RW 1',
            'package_id' => $package->id,
            'status' => 'active',
            'pppoe_user' => 'fresh-db-'.$this->seq,
        ], $attributes));
    }
}
