<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\Cpe\CpeSnapshotExtractor;
use App\Services\Cpe\CpeTransitions;
use App\Services\MikrotikService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\OltCauseEnricher;
use App\Services\Outage\OnuStateEvents;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use App\Services\Outage\ZteDetailInfoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RouterOS\Query;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsCpeFixtures;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-4 history writers: the ZTE history is kept while the ONU is online, the router secret is read with its last
 * logout, and the CPE's own PPP uptime makes `ppp_reconnect` events (kill switch, baseline, restarts excluded).
 */
#[Group('blocking-olt-mysql')] // not blocking-core: that job peaks at 249 of 256 MB (GenieAcsService lowers memory_limit)
class DiagnosisHistoryWritersTest extends TestCase
{
    use BuildsCpeFixtures;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        Setting::flushRequestMemo();
        OnuStateEvents::forgetSchemaCache();
        CpeEvents::forgetSchemaCache();
        $this->cpeSetUpAcs();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ ZTE history kept

    private function zteText(string $phase, array $rows): string
    {
        $lines = ['ONU interface:            gpon-onu_1/2/11:9', 'Phase state:              '.$phase, 'Online Duration:          10h 0m 0s', 'Authpass Time             OfflineTime               Cause'];
        foreach (array_values($rows) as $i => [$offline, $cause]) {
            $lines[] = sprintf('%4d   %s    %s     %s', $i + 1, $offline->copy()->subMinutes(2)->format('Y-m-d H:i:s'), $offline->format('Y-m-d H:i:s'), $cause);
        }
        for ($i = count($rows) + 1; $i <= 10; $i++) {
            $lines[] = sprintf('%4d   0000-00-00 00:00:00    0000-00-00 00:00:00', $i);
        }

        return implode("\n", $lines)."\n";
    }

    private function zte(string $text, ?string $clock): OltZteC300Service
    {
        return new class($text, $clock) extends OltZteC300Service
        {
            public function __construct(public string $text, public ?string $clock) {}

            public function onuDetailAndClock(Olt $olt, string $onuRef, int $timeoutSeconds = 25): array
            {
                return ['detail' => $this->text, 'clock' => $this->clock];
            }
        };
    }

    private function huawei(): OltHuaweiMa5608tService
    {
        return new class extends OltHuaweiMa5608tService
        {
            public function __construct() {}
        };
    }

    private function oltAndCustomer(): array
    {
        $olt = Olt::create(['name' => 'OLT UJI', 'code' => 'WR-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::firstOrCreate(['code' => 'WR-PKG'], ['name' => 'Writer pkg', 'price' => 100000]);
        $c = Customer::create(['code' => 'WR-C1', 'name' => 'Pelanggan Writer', 'phone' => '0812999', 'address' => 'Jl. Contoh', 'pppoe_user' => 'WR_USER', 'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => '1/2/11:9']);

        return [$olt, $c];
    }

    public function test_an_online_zte_onu_keeps_every_drop_of_its_history_of_the_last_24_hours_with_the_drift_removed(): void
    {
        [$olt, $c] = $this->oltAndCustomer();
        // the OLT clock runs 2696 s (45 min) ahead of the app: a drop shows 2696 s later than it really happened
        $drift = 2696;
        $real = fn (int $minutesAgo) => now()->copy()->subMinutes($minutesAgo);
        $shown = fn (Carbon $t) => $t->copy()->addSeconds($drift);
        $rows = [
            [$shown($real(30 * 60)), 'LOS'],        // 30 h ago: outside the window
            [$shown($real(600)), 'LOS'],
            [$shown($real(300)), 'DyingGasp'],
            [$shown($real(90)), 'LOS'],
        ];
        $clock = $shown(now())->format('H:i:s').' Tue Oct 20 2026 UTC';
        $enricher = new OltCauseEnricher($this->zte($this->zteText('working', $rows), $clock), $this->huawei());

        $r = $enricher->enrich($olt->id, '1/2/11:9', $c->id, null, now(), OltCauseEnricher::MODE_MANUAL);

        $this->assertSame('ok', $r['status']);
        $stored = DB::table('onu_state_events')->where('olt_id', $olt->id)->where('source', 'olt_detail')->orderBy('occurred_at')->get(['to_phase', 'cause', 'occurred_at', 'customer_id']);
        $this->assertSame(
            [['los', '2026-10-19 21:20:00'], ['dying_gasp', '2026-10-20 02:20:00'], ['los', '2026-10-20 05:50:00']],
            $stored->map(fn ($e) => [$e->to_phase, $e->occurred_at])->all(),
            'three drops of the last 24 h, in real UTC (drift removed BEFORE the 24 h filter)'
        );
        $this->assertSame($c->id, (int) $stored[0]->customer_id);
        $this->assertCount(3, $r['detail']['history_drops']);
        $this->assertSame('2026-10-20T05:50:00+00:00', $r['detail']['history_drops'][2]['at']);

        // a second read of the same history writes nothing new ...
        Cache::flush();
        $enricher->enrich($olt->id, '1/2/11:9', $c->id, null, now(), OltCauseEnricher::MODE_MANUAL);
        $this->assertSame(3, DB::table('onu_state_events')->where('olt_id', $olt->id)->count());

        // ... also when the OLT clock was read 3 s later (every corrected time moves by 3 s): still the same drops
        Cache::flush();
        $later = $shown(now()->copy()->addSeconds(3))->format('H:i:s').' Tue Oct 20 2026 UTC';
        (new OltCauseEnricher($this->zte($this->zteText('working', $rows), $later), $this->huawei()))->enrich($olt->id, '1/2/11:9', $c->id, null, now(), OltCauseEnricher::MODE_MANUAL);
        $this->assertSame(3, DB::table('onu_state_events')->where('olt_id', $olt->id)->count(), 'jittered drift = same rows (+- 120 s)');
    }

    public function test_a_drop_the_drift_pushes_just_across_the_24_hour_line_is_decided_after_the_correction(): void
    {
        // raw OLT time is 23 h 30 min ago (inside), the real drop 24 h 15 min ago (outside once the 45 min drift is removed)
        $rows = [[now()->copy()->subMinutes(23 * 60 + 30), 'LOS']];
        $parsed = ZteDetailInfoParser::parse($this->zteText('working', $rows));

        $this->assertCount(1, ZteDetailInfoParser::drops($parsed['history'], now()->copy()->subHours(24), 0));
        $this->assertCount(0, ZteDetailInfoParser::drops($parsed['history'], now()->copy()->subHours(24), 2700));
        $this->assertSame('los', ZteDetailInfoParser::rowCause('LOS'));
        $this->assertSame('dying_gasp', ZteDetailInfoParser::rowCause('DyingGasp'));
        $this->assertNull(ZteDetailInfoParser::rowCause(null));
    }

    // ------------------------------------------------------------------ router secret

    public function test_the_secret_read_also_asks_for_the_last_logout_and_stays_a_read_only_print(): void
    {
        $queries = [];
        $svc = new class($queries) extends MikrotikService
        {
            public function __construct(public array &$queries) {}

            protected function ensureConnected(): void {}

            protected function readQueryRows(Query $query): array
            {
                $this->queries[] = $query;

                return [['disabled' => 'false', 'profile' => 'default', 'last-logged-out' => '2026-10-20 14:03:11', 'last-disconnect-reason' => 'hung-up']];
            }
        };

        $row = $svc->secretStateFor('USER_X');

        $this->assertSame('hung-up', $row['last-disconnect-reason']);
        $this->assertCount(1, $queries);
        $this->assertSame('/ppp/secret/print', $queries[0]->getEndpoint());
        $attributes = implode('|', $queries[0]->getAttributes());
        $this->assertStringContainsString('.proplist=disabled,profile,last-logged-out,last-disconnect-reason', $attributes);
        $this->assertStringNotContainsString('password', $attributes);
    }

    public function test_the_live_router_logout_time_is_read_in_router_time_and_future_or_garbage_times_are_dropped(): void
    {
        $olt = Olt::create(['name' => 'OLT UJI', 'code' => 'WR-2', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $router = Router::create(['name' => 'RA-OLT', 'ip_address' => '198.18.6.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => now()]);
        $package = Package::firstOrCreate(['code' => 'WR-PKG'], ['name' => 'Writer pkg', 'price' => 100000]);
        $c = Customer::create(['code' => 'WR-C2', 'name' => 'Pelanggan Router', 'phone' => '0812998', 'address' => 'Jl. Contoh', 'pppoe_user' => 'WR_USER2', 'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => '1/2/3:4', 'router_id' => $router->id]);
        DB::table('customers')->where('id', $c->id)->update(['is_online' => 1, 'pppoe_online_checked_at' => now()]);

        // router time (WIB) 14:03:11 = 07:03:11 UTC; the live session is 5 h 2 min old
        $probes = ['router' => ['status' => 'ok', 'active' => ['uptime' => '5h2m'], 'secret' => ['disabled' => 'false', 'profile' => 'default', 'last-logged-out' => '2026-10-20 14:03:11', 'last-disconnect-reason' => 'hung-up']]];
        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now(), $probes);
        $times = array_map(fn (array $x) => $x['at']->format('H:i:s'), $ctx['history']['ppp_drops']);
        $this->assertContains('02:18:00', $times, 'the live session started 5 h 2 min ago (07:20 - 5:02)');
        $this->assertContains('07:03:11', $times, 'last-logged-out 14:03:11 router time = 07:03:11 UTC');

        $future = $probes;
        $future['router']['secret']['last-logged-out'] = '2026-10-20 19:00:00';
        $junk = $probes;
        $junk['router']['secret']['last-logged-out'] = 'never';
        foreach ([$future, $junk] as $p) {
            $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now(), $p);
            $times = array_map(fn (array $x) => $x['at']->format('H:i:s'), $ctx['history']['ppp_drops']);
            $this->assertNotContains('07:03:11', $times);
            $this->assertNotContains('12:00:00', $times);
        }
        $v6 = $probes;
        $v6['router']['secret']['last-logged-out'] = 'oct/20/2026 14:08:00';
        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now(), $v6);
        $this->assertContains('07:08:00', array_map(fn (array $x) => $x['at']->format('H:i:s'), $ctx['history']['ppp_drops']));
    }

    // ------------------------------------------------------------------ CPE ppp_reconnect

    /** One sync $minutes after 2026-10-20 05:00 UTC; the document is built AT that time (leaf ages) and keeps the same boot time. */
    private function syncAt(int $minutes, array $opts): array
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 05:00:00', 'UTC')->addMinutes($minutes));

        return $this->cpeSync([$this->cpeDoc($opts + ['age' => 1, 'boot_min' => 5000 + $minutes])]);
    }

    public function test_a_drop_of_the_cpe_ppp_uptime_without_a_restart_is_one_ppp_reconnect_at_now_minus_the_new_uptime(): void
    {
        $this->cpeCustomer();

        $this->syncAt(0, ['ppp_uptime' => 90000]); // the device row is created
        $this->assertNull(DB::table('genieacs_devices')->value('ppp_uptime_s'));
        $this->syncAt(10, ['ppp_uptime' => 90600]); // baseline: the value is stored
        $this->assertSame(0, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count(), 'baseline: no event');
        $this->assertSame(90600, (int) DB::table('genieacs_devices')->value('ppp_uptime_s'));

        // 50 minutes later the session is 600 s old. The CPE reported it 1 minute before the sync (06:00 - 1 min),
        // so it reconnected at 05:59 - 10 min = 05:49 (the leaf's own time, not the sync time)
        $this->syncAt(60, ['ppp_uptime' => 600]);

        $events = DB::table('cpe_events')->where('type', 'ppp_reconnect')->get();
        $this->assertCount(1, $events);
        $this->assertSame('2026-10-20 05:49:00', $events[0]->at);
        $this->assertSame(600, (int) DB::table('genieacs_devices')->value('ppp_uptime_s'));

        // a normal increase and the same value again: nothing
        $this->syncAt(120, ['ppp_uptime' => 4200]);
        $this->syncAt(130, ['ppp_uptime' => 4200]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count());
    }

    public function test_a_restart_of_the_onu_is_not_a_ppp_reconnect(): void
    {
        $this->cpeCustomer();
        $this->syncAt(0, ['ppp_uptime' => 90000, 'uptime' => 500000]);
        $this->syncAt(10, ['ppp_uptime' => 90600, 'uptime' => 500000]);

        // the ONU rebooted 10 minutes ago: its uptime fell, and so did the PPP uptime
        $this->syncAt(60, ['ppp_uptime' => 700, 'uptime' => 800, 'boot_min' => 10]);

        $this->assertSame(0, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count());
        $this->assertGreaterThan(0, DB::table('cpe_events')->whereIn('type', ['boot', 'uptime_reset'])->count(), 'the restart itself is still recorded');
    }

    public function test_a_stale_ppp_uptime_value_is_never_compared(): void
    {
        $this->cpeCustomer();
        $this->syncAt(0, ['ppp_uptime' => 90000]);
        $this->syncAt(10, ['ppp_uptime' => 90600]);

        // the new value is 5 hours old (limit 120 minutes): stored as NULL, no event
        $this->syncAt(60, ['ppp_uptime' => 600, 'ppp_uptime_age' => 300]);

        $this->assertSame(0, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count());
        $this->assertNull(DB::table('genieacs_devices')->value('ppp_uptime_s'));
    }

    public function test_the_kill_switch_stops_the_recording_but_not_the_sync(): void
    {
        $this->cpeCustomer();
        $this->assertTrue(OutageSettings::cpePppReconnectEnabled(), 'default on');
        Setting::set('cpe_ppp_reconnect_enabled', '0', 'text', 'outage');
        Setting::flushRequestMemo();

        $this->syncAt(0, ['ppp_uptime' => 90000]);
        $this->syncAt(10, ['ppp_uptime' => 90600]);
        $result = $this->syncAt(60, ['ppp_uptime' => 600]);

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count());
        $this->assertNull(DB::table('genieacs_devices')->value('ppp_uptime_s'), 'switched off = nothing recorded');
    }

    public function test_without_the_column_the_sync_and_the_upsert_work_as_before_the_migration(): void
    {
        $this->cpeCustomer();
        CpeTransitions::forcePppUptimeColumn(false); // new code, `migrate` not run yet (deploy window)
        try {
            $this->syncAt(0, ['ppp_uptime' => 90000]);
            $result = $this->syncAt(60, ['ppp_uptime' => 600, 'a24' => 9]);
        } finally {
            CpeTransitions::forcePppUptimeColumn(null);
        }

        $this->assertSame(1, $result['synced']);
        $row = DB::table('genieacs_devices')->first();
        $this->assertSame(9, (int) $row->wlan24_assoc, 'the upsert landed');
        $this->assertNull($row->ppp_uptime_s);
        $this->assertSame(0, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count());
        // and the extractor puts nothing new into the upsert payload (no unknown column can reach the database)
        $this->assertNotContains('ppp_uptime_s', CpeSnapshotExtractor::COLUMNS);
        $this->assertArrayNotHasKey('ppp_uptime_s', (new CpeSnapshotExtractor)->extract($this->cpeDoc(['ppp_uptime' => 5]))['columns']);
    }

    public function test_the_flag_is_written_through_the_activity_logged_settings_writer(): void
    {
        $writer = new OutageSettingsWriter;

        $this->assertContains('cpe_ppp_reconnect_enabled', OutageSettingsWriter::cpeKeys());
        $this->assertTrue($writer->validate('cpe_ppp_reconnect_enabled', '0')['ok']);
        $this->assertFalse($writer->validate('cpe_ppp_reconnect_enabled', 'maybe')['ok']);

        $writer->set('cpe_ppp_reconnect_enabled', '0', 'tester');

        $this->assertFalse(OutageSettings::cpePppReconnectEnabled());
        $this->assertSame('cpe_ppp_reconnect_enabled', Activity::query()->latest('id')->first()->properties['key']);
        $this->assertArrayHasKey('cpe_ppp_reconnect_enabled', OutageSettings::cpeSnapshot());
    }
}
