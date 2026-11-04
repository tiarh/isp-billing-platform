<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\Setting;
use App\Services\Cpe\CpeSummonService;
use App\Services\Cpe\CpeWatchService;
use App\Services\GenieAcsService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\OltCauseEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsCpeFixtures;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-3 / RCA-4: PSB WA + migration WA flows must stay unaffected.
 *  (a) the CPE layer never pushes a job onto psb / migration-command / migration-live / wa (nor any other queue);
 *  (c) tags / PSB-read columns / HTTP calls of the GenieACS inform path are unchanged by the CPE layer, and the new
 *      transition code inside bulkUpsertDevices never throws (an injected DB failure still lets the upsert land).
 * (b) the OLT manual probe never blocks on a lock: RCA-4 (OLT probes), tested there.
 */
#[Group('blocking-psb')]
class Rca34PsbIsolationTest extends TestCase
{
    use BuildsCpeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        CpeEvents::forgetSchemaCache();
        $this->cpeSetUpAcs();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sync_watch_and_summon_push_nothing_onto_any_queue(): void
    {
        Queue::fake();
        $customer = $this->cpeCustomer();
        Setting::set('cpe_watch_enabled', '1', 'text', 'outage');
        Setting::set('cpe_summon_enabled', '1', 'text', 'outage');

        $this->cpeSync([$this->cpeDoc()]);
        $deviceId = '000AC2-HG6045F3-FHTTUJI00001';
        DB::table('cpe_events')->insert(['customer_id' => $customer->id, 'device_id' => $deviceId, 'type' => 'boot', 'at' => now()->subHour()->format('Y-m-d H:i:s')]);
        DB::table('cpe_events')->insert(['customer_id' => $customer->id, 'device_id' => $deviceId, 'type' => 'boot', 'at' => now()->subMinutes(30)->format('Y-m-d H:i:s')]);

        Carbon::setTestNow(now()->addMinutes(10));
        Http::swap(new Factory);
        Http::fake([
            '*/devices/*/tasks*' => Http::response(['_id' => 't'], 200),
            '*/tasks*' => Http::response([]),
            '*/devices*' => Http::response([$this->cpeDoc(['age' => 1, 's5' => 'Disabled', 'a5' => 0])]),
        ]);

        $watch = app(CpeWatchService::class)->run();
        $summon = app(CpeSummonService::class)->summon($deviceId, 'test');
        $this->artisan('cpe:watch')->assertSuccessful();
        $this->cpeSync([$this->cpeDoc(['age' => 1])]);

        $this->assertSame(1, $watch['picked'], 'the paths really ran');
        $this->assertSame('fresh', $summon['status']);
        // nothing at all: so in particular nothing on psb / migration-command / migration-live / wa
        Queue::assertNothingPushed();
    }

    /** @return array{calls: list<string>, row: object} */
    private function ingestScenario(bool $layerOn): array
    {
        DB::table('genieacs_devices')->delete();
        DB::table('cpe_events')->delete();
        DB::table('cpe_daily')->delete();
        Setting::set('cpe_layer_enabled', $layerOn ? '1' : '0', 'text', 'outage');

        $doc = $this->cpeDoc();
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($doc) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/devices')) {
                return Http::response($doc);
            }

            return Http::response(['ok' => true], 200);
        });

        $result = app(GenieAcsService::class)->ingestDeviceInform(['device_id' => $doc['_id'], 'event' => '1 BOOT']);
        $this->assertTrue($result['ok']);

        $calls = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
        $row = DB::table('genieacs_devices')->first(['device_id', 'tags', 'pppoe_user', 'genieacs_customer_name', 'last_inform_at', 'serial_number', 'customer_id']);

        return ['calls' => $calls, 'row' => $row, 'result' => $result];
    }

    public function test_inform_ingest_makes_the_same_http_calls_and_keeps_the_psb_columns_with_the_layer_on_or_off(): void
    {
        $this->cpeCustomer();

        $off = $this->ingestScenario(false);
        $on = $this->ingestScenario(true);

        $this->assertSame($off['calls'], $on['calls'], 'the CPE layer must not add or remove a GenieACS call on the inform path');
        $this->assertEquals($off['row'], $on['row'], 'device_id, tags, pppoe_user, genieacs_customer_name, last_inform_at are what PSB reads');
        $this->assertNotEmpty($on['calls']);
        $this->assertStringStartsWith('GET ', $on['calls'][0]);
    }

    public function test_transition_code_never_throws_into_the_upsert(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['s5' => 'Up', 'a24' => 3])]);
        Carbon::setTestNow(now()->addMinutes(10));

        // every CPE read / write fails: the sync must still land the new device state
        DB::beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'cpe_daily') || str_contains($sql, 'cpe_events') || (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'wlan_values_at'))) {
                throw new \RuntimeException('injected CPE failure');
            }
        });

        $result = $this->cpeSync([$this->cpeDoc(['s5' => 'Disabled', 'a24' => 7, 'age' => 1])]);

        $this->assertSame(1, $result['synced']);
        $row = DB::table('genieacs_devices')->first();
        $this->assertSame('Disabled', $row->wlan5_status);
        $this->assertSame(7, (int) $row->wlan24_assoc);
        $this->assertSame('pppoe-uji', $row->pppoe_user);
    }

    // ------------------------------------------------------------ RCA-4 history (rules 2026-10-21.1)

    public function test_ppp_reconnect_recording_and_the_zte_history_write_push_nothing_onto_any_queue(): void
    {
        Queue::fake();
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90000, 'age' => 1])]);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90600, 'age' => 1, 'boot_min' => 5010])]); // baseline
        Carbon::setTestNow(now()->addMinutes(50));
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 600, 'age' => 1, 'boot_min' => 5060])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count(), 'the new writer really ran');

        // a manual ZTE probe that keeps the history of an online ONU
        $olt = Olt::create(['name' => 'OLT UJI', 'code' => 'ISO-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $drop = now()->copy()->subHours(3)->format('Y-m-d H:i:s');
        $text = "Phase state:              working\nAuthpass Time             OfflineTime               Cause\n   1   {$drop}    {$drop}     LOS\n";
        $zte = new class($text) extends OltZteC300Service
        {
            public function __construct(public string $text) {}

            public function onuDetailAndClock(Olt $olt, string $onuRef, int $timeoutSeconds = 25): array
            {
                return ['detail' => $this->text, 'clock' => null];
            }
        };
        $huawei = new class extends OltHuaweiMa5608tService
        {
            public function __construct() {}
        };
        $r = (new OltCauseEnricher($zte, $huawei))->enrich($olt->id, '1/2/11:9', null, null, now(), OltCauseEnricher::MODE_MANUAL);

        $this->assertSame('ok', $r['status']);
        $this->assertSame(1, DB::table('onu_state_events')->where('olt_id', $olt->id)->count(), 'the new writer really ran');
        Queue::assertNothingPushed(); // so nothing on psb / migration-command / migration-live / wa either
    }

    /** @return array{calls: list<string>, row: object} */
    private function ingestWithPppUptime(bool $flagOn): array
    {
        DB::table('genieacs_devices')->delete();
        DB::table('cpe_events')->delete();
        Setting::set('cpe_ppp_reconnect_enabled', $flagOn ? '1' : '0', 'text', 'outage');
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90000, 'age' => 1])]);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90600, 'age' => 1, 'boot_min' => 5010])]); // baseline
        Carbon::setTestNow(now()->addMinutes(50));

        $doc = $this->cpeDoc(['ppp_uptime' => 600, 'age' => 1, 'boot_min' => 5060]);
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($doc) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/devices')) {
                return Http::response($doc);
            }

            return Http::response(['ok' => true], 200);
        });
        $result = app(GenieAcsService::class)->ingestDeviceInform(['device_id' => $doc['_id'], 'event' => '1 BOOT']);
        $this->assertTrue($result['ok']);

        return [
            'calls' => Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all(),
            'row' => DB::table('genieacs_devices')->first(['device_id', 'tags', 'pppoe_user', 'genieacs_customer_name', 'last_inform_at', 'serial_number', 'customer_id']),
        ];
    }

    public function test_inform_ingest_keeps_its_http_calls_and_psb_columns_with_the_ppp_reconnect_writer_on_or_off(): void
    {
        $this->cpeCustomer();

        $off = $this->ingestWithPppUptime(false);
        $on = $this->ingestWithPppUptime(true);

        $this->assertSame($off['calls'], $on['calls'], 'the new writer must not add or remove a GenieACS call on the inform path');
        $this->assertEquals($off['row'], $on['row'], 'device_id, tags, pppoe_user, genieacs_customer_name, last_inform_at are what PSB reads');
        $this->assertNotEmpty($on['calls']);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'ppp_reconnect')->count(), 'the writer ran on the inform path with the flag on');
    }

    public function test_a_failing_ppp_reconnect_write_never_stops_the_upsert(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90000, 'age' => 1])]);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 90600, 'age' => 1, 'boot_min' => 5010])]);
        Carbon::setTestNow(now()->addMinutes(50));

        DB::beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'cpe_events') || str_contains($sql, 'cpe_daily') || (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'ppp_uptime_s'))) {
                throw new \RuntimeException('injected CPE failure');
            }
        });

        $result = $this->cpeSync([$this->cpeDoc(['ppp_uptime' => 600, 'age' => 1, 'a24' => 9, 'boot_min' => 5060])]);

        $this->assertSame(1, $result['synced']);
        $row = DB::table('genieacs_devices')->first();
        $this->assertSame(9, (int) $row->wlan24_assoc);
        $this->assertSame('pppoe-uji', $row->pppoe_user);
    }
}
