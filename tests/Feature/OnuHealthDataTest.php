<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\HuaweiOltSnmpOpticalService;
use App\Services\MikrotikService;
use App\Services\OltCollectorService;
use App\Services\OltHiosoService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\RadiusUserService;
use App\Services\Recap\OnuHealthClassifier;
use App\Services\Recap\OnuHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * ONU health numbers: mapping, classifier, weak RX edges, population, exclusions, duration,
 * and the read-only guarantee (device services bound to throw, only SELECT statements).
 */
#[Group('blocking-psb')]
class OnuHealthDataTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Area $area;

    private Package $package;

    private Olt $zte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta'));
        $this->area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        $this->zte = Olt::create(['name' => 'RA', 'code' => 'RA-1', 'vendor' => 'zte_c300']);

        foreach ([MikrotikService::class, OltCollectorService::class, OltHiosoService::class, OltHuaweiMa5608tService::class, OltZteC300Service::class, HuaweiOltSnmpOpticalService::class, RadiusUserService::class] as $class) {
            $this->app->bind($class, function () use ($class): never {
                throw new \LogicException('ONU recap must stay read-only, touched '.$class);
            });
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function cust(array $o = []): Customer
    {
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'ONU-C'.$n, 'name' => 'Pelanggan '.$n, 'address' => 'Dusun', 'package_id' => $this->package->id,
            'area_id' => $this->area->id, 'pppoe_user' => 'user'.$n, 'status' => 'active', 'join_date' => '2026-01-01',
            'olt_id' => $this->zte->id,
        ], $o));
    }

    /** @param array<string, mixed> $o */
    private function onu(string $ref, array $o = [], ?Olt $olt = null): OltOnu
    {
        return OltOnu::create(array_merge([
            'olt_id' => ($olt ?? $this->zte)->id, 'onu_ref' => $ref, 'serial_number' => 'ZTEG'.str_pad((string) (++$this->seq), 8, '0', STR_PAD_LEFT),
            'state' => 'online', 'phase_state' => 'working', 'rx_power_dbm' => -20.0,
            'state_collected_at' => now()->subMinutes(3), 'rx_collected_at' => now()->subMinutes(3), 'inventory_collected_at' => now()->subMinutes(10),
        ], $o));
    }

    /** @return array<string, mixed> */
    private function collect(?int $areaId = null): array
    {
        return app(OnuHealthService::class)->collect($areaId, $areaId === null ? 'SEMUA AREA' : 'KLAMPOK');
    }

    public function test_mapping_by_port_by_serial_ambiguous_and_unmapped(): void
    {
        // by port (label has the gpon-onu_ prefix, onu_ref does not)
        $this->cust(['olt_port_label' => 'gpon-onu_1/3/3:19']);
        $this->onu('1/3/3:19');
        // by unique serial
        $this->cust(['onu_serial' => 'ZTEG0000A004', 'olt_port_label' => null]);
        $this->onu('1/3/3:20', ['serial_number' => 'ZTEG0000A004']);
        // ambiguous: the same serial on two OLTs, customer without olt_id
        $other = Olt::create(['name' => 'KRIAN', 'code' => 'K-1', 'vendor' => 'zte_c300']);
        $this->cust(['onu_serial' => 'ZTEGDUP00001', 'olt_id' => null, 'olt_port_label' => null]);
        $this->onu('1/3/3:21', ['serial_number' => 'ZTEGDUP00001']);
        $this->onu('1/2/1:1', ['serial_number' => 'ZTEGDUP00001'], $other);
        // unmapped
        $this->cust(['olt_port_label' => null, 'onu_serial' => null]);

        $c = $this->collect()['counts'];

        $this->assertSame(4, $c['aktif']);
        $this->assertSame(2, $c['terpantau']);
        $this->assertSame(1, $c['ambigu']);
        $this->assertSame(1, $c['belum_terpetakan']);
    }

    public function test_a_serial_already_claimed_by_a_port_mapping_is_not_reused(): void
    {
        $this->cust(['olt_port_label' => '1/3/3:19', 'onu_serial' => null]);
        $onu = $this->onu('1/3/3:19');
        // second customer carries the serial of that same ONU but no port label: claimed -> unmapped
        $this->cust(['olt_port_label' => null, 'onu_serial' => $onu->serial_number]);

        $c = $this->collect()['counts'];

        $this->assertSame(1, $c['terpantau']);
        $this->assertSame(1, $c['belum_terpetakan']);
    }

    public function test_population_is_active_non_isolir_non_deleted_and_area_filtered(): void
    {
        $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1');
        $this->cust(['status' => 'inactive']);
        $this->cust(['mikrotik_profile' => 'ISOLIR-10M']);
        $gone = $this->cust();
        $gone->delete();
        $otherArea = Area::create(['name' => 'SKYNET-GADANG', 'code' => 'gadang']);
        $this->cust(['area_id' => $otherArea->id]);

        $this->assertSame(2, $this->collect()['counts']['aktif']);
        $this->assertSame(1, $this->collect($this->area->id)['counts']['aktif']);
    }

    public function test_classifier_rules(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta');
        $fresh = $now->subMinutes(3);
        $oldInv = $now->subMinutes(200);
        $base = ['vendor' => 'zte_c300', 'state_collected_at' => $fresh, 'inventory_collected_at' => $fresh];
        $k = fn (array $o): string => OnuHealthClassifier::classify(array_merge($base, $o), $now);

        $this->assertSame('online', $k(['state' => 'online', 'phase_state' => 'working']));
        $this->assertSame('los', $k(['state' => 'offline', 'phase_state' => 'LOS']));
        $this->assertSame('mati_listrik', $k(['state' => 'offline', 'phase_state' => 'DyingGasp']));
        $this->assertSame('mati_listrik', $k(['state' => 'offline', 'phase_state' => 'DGI']));
        // explicit online beats a stale LOS phase
        $this->assertSame('online', $k(['state' => 'online', 'phase_state' => 'LOS']));
        // LOS with a stale inventory reading is not trusted
        $this->assertSame('lain', $k(['state' => 'offline', 'phase_state' => 'LOS', 'inventory_collected_at' => $oldInv]));
        // Huawei only knows offline
        $this->assertSame('lain', $k(['vendor' => 'huawei_ma5608t', 'state' => 'offline', 'phase_state' => 'LOS']));
        // stale data is never offline
        $this->assertSame('data_basi', $k(['state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => $oldInv, 'inventory_collected_at' => $oldInv]));
        $this->assertSame('data_basi', $k(['state' => 'offline', 'state_collected_at' => null, 'inventory_collected_at' => null]));
        // blank state + phase
        $this->assertSame('tidak_diketahui', $k(['state' => '', 'phase_state' => '']));
    }

    public function test_classifier_uses_the_newer_of_phase_and_inventory_stamps(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta');
        $old = $now->subMinutes(200);
        $base = ['vendor' => 'zte_c300', 'state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => $now->subMinutes(3)];
        $k = fn (array $o): string => OnuHealthClassifier::classify(array_merge($base, $o), $now);

        // fresh 5-min phase tick + stale hourly inventory -> LOS is trusted (matches the outage detector)
        $this->assertSame('los', $k(['inventory_collected_at' => $old, 'phase_collected_at' => $now->subMinutes(3)]));
        $this->assertSame('mati_listrik', $k(['phase_state' => 'DyingGasp', 'inventory_collected_at' => $old, 'phase_collected_at' => $now->subMinutes(3)]));
        // phase column null (pre-#513 rows) falls back to inventory
        $this->assertSame('los', $k(['inventory_collected_at' => $now->subMinutes(10), 'phase_collected_at' => null]));
        $this->assertSame('lain', $k(['inventory_collected_at' => $old, 'phase_collected_at' => null]));
        // both stale -> cause not trusted
        $this->assertSame('lain', $k(['inventory_collected_at' => $old, 'phase_collected_at' => $old]));
        // no state stamp and both phase stamps stale -> data basi, never offline
        $this->assertSame('data_basi', $k(['state_collected_at' => null, 'inventory_collected_at' => $old, 'phase_collected_at' => $old]));
    }

    public function test_weak_rx_edges(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta');
        $at = $now->subMinutes(5);
        $weak = fn (string $kind, ?float $rx, $when = null): bool => OnuHealthClassifier::isWeakRx($kind, $rx, $when ?? $at, -27.0, $now);

        $this->assertFalse($weak('online', -27.0), 'exactly -27.00 is not weak');
        $this->assertTrue($weak('online', -27.01));
        $this->assertTrue($weak('online', -40.0));
        $this->assertFalse($weak('online', -41.0), 'outside the valid range');
        $this->assertFalse($weak('online', 0.5));
        $this->assertFalse($weak('online', null));
        $this->assertFalse($weak('online', -30.0, $now->subHours(7)), 'reading older than 6 h');
        $this->assertTrue($weak('online', -30.0, $now->subHours(5)));
        $this->assertFalse($weak('los', -30.0), 'only online ONUs can be weak');
    }

    public function test_weak_rx_count_and_worst_and_threshold_setting_clamp(): void
    {
        foreach ([-26.0, -28.0, -31.2] as $i => $rx) {
            $this->cust(['olt_port_label' => '1/1/1:'.($i + 1)]);
            $this->onu('1/1/1:'.($i + 1), ['rx_power_dbm' => $rx]);
        }

        $data = $this->collect();
        $this->assertSame(2, $data['counts']['weak']);
        $this->assertEqualsWithDelta(-31.2, $data['counts']['weak_worst'], 0.001);

        Setting::set('recap_onu_weak_rx_dbm', '-99');
        $this->assertSame(-35.0, OnuHealthService::weakRxDbm());
        Setting::set('recap_onu_weak_rx_dbm', '-5');
        $this->assertSame(-20.0, OnuHealthService::weakRxDbm());
        Setting::set('recap_onu_long_offline_days', '99');
        $this->assertSame(30, OnuHealthService::longOfflineDays());
    }

    public function test_offline_split_stale_and_exclusions(): void
    {
        $mk = function (string $ref, array $onu, array $cust = []): Customer {
            $c = $this->cust(array_merge(['olt_port_label' => $ref], $cust));
            $this->onu($ref, $onu);

            return $c;
        };
        $off = ['state' => 'offline', 'phase_state' => 'LOS'];
        $mk('1/1/1:1', $off);
        $mk('1/1/1:2', ['state' => 'offline', 'phase_state' => 'DyingGasp']);
        $mk('1/1/1:3', ['state' => 'offline', 'phase_state' => 'SyncMib']);
        // stale
        $mk('1/1/1:4', ['state_collected_at' => now()->subHours(5), 'inventory_collected_at' => now()->subHours(5)]);
        // open migration
        $migrating = $mk('1/1/1:5', $off);
        CustomerMigration::create(['status' => CustomerMigration::STATUS_WAITING_SN, 'customer_id' => $migrating->id, 'full_name' => 'X', 'pppoe_user' => 'x']);
        // open recovery case
        $pulled = $mk('1/1/1:6', $off);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['customer_id' => $pulled->id, 'area_id' => $this->area->id, 'created_by' => $admin->id, 'gangguan' => 'Dismantle', 'subject' => 'Dismantle', 'status' => 'assigned', 'priority' => 'normal', 'type' => 'dismantle']);
        DeviceRecoveryCase::create(['ticket_id' => $ticket->id, 'customer_id' => $pulled->id, 'area_id' => $this->area->id, 'status' => DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, 'created_by' => $admin->id]);
        // offline but PPPoE online: mapping check
        $mk('1/1/1:7', $off, ['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(5)]);
        // offline, PPPoE "online" but the check is old: still offline
        $mk('1/1/1:8', $off, ['is_online' => true, 'pppoe_online_checked_at' => now()->subHours(3)]);
        // open ticket on an offline ONU
        $ticketed = $mk('1/1/1:9', $off);
        SupportTicket::create(['customer_id' => $ticketed->id, 'area_id' => $this->area->id, 'created_by' => $admin->id, 'gangguan' => 'Mati', 'subject' => 'Mati', 'status' => 'open', 'priority' => 'normal', 'type' => 'incident']);

        $c = $this->collect()['counts'];

        $this->assertSame(9, $c['aktif']);
        $this->assertSame(1, $c['data_basi']);
        $this->assertSame(8, $c['terpantau']);
        $this->assertSame(5, $c['offline']); // 1,2,3,8,9
        $this->assertSame(3, $c['los']);     // 1,8,9
        $this->assertSame(1, $c['mati_listrik']);
        $this->assertSame(1, $c['lain']);
        $this->assertSame(1, $c['ex_migrasi']);
        $this->assertSame(1, $c['ex_penarikan']);
        $this->assertSame(1, $c['ex_cek_pemetaan']);
        $this->assertSame(1, $c['tiket']);
    }

    public function test_offline_duration_from_tracker_then_usage_then_none(): void
    {
        $off = ['state' => 'offline', 'phase_state' => 'LOS'];
        $a = $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1', $off);
        OutageOnuTracker::create(['olt_id' => $this->zte->id, 'onu_ref' => '1/1/1:1', 'customer_id' => $a->id, 'phase' => 'los', 'phase_since_at' => now()->subDays(4), 'last_state_collected_at' => now()->subMinutes(3)]);

        $b = $this->cust(['olt_port_label' => '1/1/1:2']);
        $this->onu('1/1/1:2', $off);
        $c = $this->cust(['olt_port_label' => '1/1/1:3']);
        $this->onu('1/1/1:3', $off);
        $d = $this->cust(['olt_port_label' => '1/1/1:4']);
        $this->onu('1/1/1:4', $off);

        $usage = fn (int $cid, string $date, int $bytes, string $q = 'ok') => DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => $date, 'router_id' => 1, 'pppoe_user' => 'u'.$cid.$date, 'customer_id' => $cid,
            'download_bytes' => $bytes, 'upload_bytes' => 0, 'is_final' => true, 'quality' => $q, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // b: last real traffic 5 days ago (tiny traffic later and an invalid row later are ignored)
        $usage($b->id, '2026-10-10', 50 * 1048576);
        $usage($b->id, '2026-10-13', 1024);
        $usage($b->id, '2026-10-14', 80 * 1048576, 'invalid');
        // c: traffic yesterday (<3 days), d: none at all
        $usage($c->id, '2026-10-14', 50 * 1048576);
        // anchor: fresh usage data exists for yesterday
        $usage($a->id, '2026-10-14', 50 * 1048576);

        $data = $this->collect();
        $byId = [];
        foreach ($data['priority'] as $it) {
            $byId[$it['customer_id']] = $it;
        }

        $this->assertSame('tracker', $byId[$a->id]['days_basis']);
        $this->assertSame(4, $byId[$a->id]['days']);
        $this->assertSame('perkiraan', $byId[$b->id]['days_basis']);
        $this->assertSame(5, $byId[$b->id]['days']);
        $this->assertSame(1, $byId[$c->id]['days']);
        $this->assertFalse($byId[$c->id]['long']);
        $this->assertSame(OnuHealthService::USAGE_LOOKBACK_DAYS + 1, $byId[$d->id]['days']);
        $this->assertSame(3, $data['counts']['offline_lama']); // a, b, d
        $this->assertFalse($data['usage_stale']);
    }

    public function test_stale_usage_data_hides_the_duration_and_the_long_offline_count(): void
    {
        $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1', ['state' => 'offline', 'phase_state' => 'LOS']);
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => '2026-10-01', 'router_id' => 1, 'pppoe_user' => 'old', 'customer_id' => null,
            'download_bytes' => 10, 'upload_bytes' => 0, 'is_final' => true, 'quality' => 'ok', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->collect();

        $this->assertTrue($data['usage_stale']);
        $this->assertSame(0, $data['counts']['offline_lama']);
        $this->assertNull($data['priority'][0]['days']);
    }

    public function test_service_trusts_fresh_phase_tick_with_stale_inventory(): void
    {
        $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1', ['state' => 'offline', 'phase_state' => 'LOS', 'inventory_collected_at' => now()->subMinutes(200), 'phase_collected_at' => now()->subMinutes(3)]);

        $counts = $this->collect()['counts'];

        $this->assertSame(1, $counts['los']);
        $this->assertSame(0, $counts['lain']);
    }

    public function test_stale_usage_data_excludes_tracker_based_items_from_long_offline_count(): void
    {
        $off = ['state' => 'offline', 'phase_state' => 'LOS'];
        $a = $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1', $off);
        OutageOnuTracker::create(['olt_id' => $this->zte->id, 'onu_ref' => '1/1/1:1', 'customer_id' => $a->id, 'phase' => 'los', 'phase_since_at' => now()->subDays(4), 'last_state_collected_at' => now()->subMinutes(3)]);
        $this->cust(['olt_port_label' => '1/1/1:2']);
        $this->onu('1/1/1:2', $off);
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => '2026-10-01', 'router_id' => 1, 'pppoe_user' => 'old', 'customer_id' => null,
            'download_bytes' => 10, 'upload_bytes' => 0, 'is_final' => true, 'quality' => 'ok', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->collect();

        $this->assertTrue($data['usage_stale']);
        $this->assertSame(2, $data['counts']['offline']);
        $this->assertSame(0, $data['counts']['offline_lama']);
    }

    public function test_priority_order_per_olt_and_per_odp(): void
    {
        $mk = function (string $ref, array $onu, array $cust = []): Customer {
            $c = $this->cust(array_merge(['olt_port_label' => $ref], $cust));
            $this->onu($ref, $onu);

            return $c;
        };
        $mk('1/1/1:1', ['rx_power_dbm' => -30.0], ['name' => 'WEAK', 'odp_name' => 'ODP-1']);
        $mk('1/1/1:2', ['state' => 'offline', 'phase_state' => 'LOS'], ['name' => 'LOSS', 'odp_name' => 'ODP-1']);
        $mk('1/1/1:3', ['state' => 'offline', 'phase_state' => 'SyncMib'], ['name' => 'LAIN', 'odp_name' => 'ODP-2']);
        $this->cust(['name' => 'UNMAPPED', 'olt_port_label' => null]);

        $data = $this->collect();
        $names = array_map(fn (array $i): string => $i['name'], $data['priority']);

        $this->assertSame(['LOSS', 'LAIN', 'WEAK', 'UNMAPPED'], $names);
        $this->assertSame(4, $data['problem_total']);
        $this->assertSame(['ODP-1' => 2, 'ODP-2' => 1], $data['by_odp']);
        $this->assertSame('RA', $data['by_olt'][0]['name']);
        $this->assertSame(2, $data['by_olt'][0]['offline']);
        $this->assertSame(1, $data['by_olt'][0]['weak']);
        $this->assertTrue($data['by_olt'][0]['last']->equalTo(CarbonImmutable::now()->subMinutes(3)));
    }

    public function test_the_whole_run_is_read_only(): void
    {
        Queue::fake();
        Http::fake();
        $this->cust(['olt_port_label' => '1/1/1:1']);
        $this->onu('1/1/1:1', ['state' => 'offline', 'phase_state' => 'LOS']);
        DB::enableQueryLog();

        $data = $this->collect();
        $xlsx = app(OnuHealthService::class)->buildXlsx($data, storage_path('framework/testing/onu-readonly.xlsx'));
        @unlink($xlsx);

        foreach (DB::getQueryLog() as $q) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $q['query'], 'only SELECT statements: '.$q['query']);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_excel_has_no_pii_columns_and_keeps_formula_like_names_as_text(): void
    {
        $this->cust(['olt_port_label' => '1/1/1:1', 'name' => '=HYPERLINK("http://x")', 'phone' => '081234567890', 'nik' => '3301010110000001', 'address' => 'Jl Rahasia 99', 'pppoe_password' => Canary::of('pw')]);
        $this->onu('1/1/1:1', ['state' => 'offline', 'phase_state' => 'LOS']);
        $data = $this->collect();
        $path = storage_path('framework/testing/onu-pii.xlsx');

        app(OnuHealthService::class)->buildXlsx($data, $path);

        $zip = new \ZipArchive;
        $zip->open($path);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        foreach (['081234567890', '3301010110000001', 'Jl Rahasia 99', 'canary-pw'] as $canary) {
            $this->assertStringNotContainsString($canary, $all);
        }
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=HYPERLINK', $all);
        $this->assertStringNotContainsString('<f>HYPERLINK', $all);
    }
}
