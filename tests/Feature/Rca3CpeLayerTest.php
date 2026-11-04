<?php

namespace Tests\Feature;

use App\Models\GenieAcsDevice;
use App\Models\Setting;
use App\Services\Cpe\CpeEvidence;
use App\Services\Cpe\CpeModelMap;
use App\Services\Cpe\CpeSnapshotExtractor;
use App\Services\GenieAcsService;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsCpeFixtures;
use Tests\TestCase;

/**
 * RCA-3: parser fixtures (real FiberHome parameter paths, anonymised), per-model mapping, stale gate,
 * transitions, cpe_daily and CpeEvidence.
 */
#[Group('blocking-core')]
class Rca3CpeLayerTest extends TestCase
{
    use BuildsCpeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        CpeEvents::forgetSchemaCache();
        $this->cpeSetUpAcs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ A. model fix

    public function test_model_is_the_product_class_when_model_name_was_never_fetched(): void
    {
        $service = app(GenieAcsService::class);
        $fields = (new ReflectionMethod($service, 'extractDeviceFields'))->invoke($service, [
            '_id' => '000AC2-HG6145F3-FHTTUJI00002',
            '_deviceId' => ['_SerialNumber' => 'FHTTUJI00002', '_OUI' => '000AC2', '_ProductClass' => 'hg6145f3 ', '_Manufacturer' => 'FiberHome'],
            // node exists, but GenieACS never fetched a value for it
            'InternetGatewayDevice' => ['DeviceInfo' => ['ModelName' => ['_object' => false, '_writable' => false]]],
        ]);

        $this->assertSame('HG6145F3', $fields['model']);
        $this->assertSame('hg6145f3 ', $fields['product_class']);
    }

    public function test_real_model_name_wins_over_the_fallback(): void
    {
        $service = app(GenieAcsService::class);
        $fields = (new ReflectionMethod($service, 'extractDeviceFields'))->invoke($service, [
            '_id' => 'x',
            '_deviceId' => ['_ProductClass' => 'HG6045F3'],
            'InternetGatewayDevice' => ['DeviceInfo' => ['ModelName' => ['_value' => 'HG6045F3-V2']]],
        ]);

        $this->assertSame('HG6045F3-V2', $fields['model']);
    }

    public function test_sync_backfills_model_even_when_the_cpe_layer_is_switched_off(): void
    {
        Setting::set('cpe_layer_enabled', '0', 'text', 'outage');

        $this->cpeSync([$this->cpeDoc()]);

        $row = DB::table('genieacs_devices')->first();
        $this->assertSame('HG6045F3', $row->model);
        $this->assertNull($row->wlan_values_at, 'layer off = old behaviour, no new columns written');
        $this->assertNull($row->last_boot_at);
        $this->assertSame(0, DB::table('cpe_daily')->count());
    }

    // ------------------------------------------------------------ B. per-model mapping

    public function test_band_mapping_per_model(): void
    {
        $map = new CpeModelMap;
        $this->assertSame(['band24' => 1, 'band5' => 9], $map->primaries($map->forProductClass('HG6045F3')));
        $this->assertSame(['band24' => 1, 'band5' => 9], $map->primaries($map->forProductClass('HG6145F3')));
        $this->assertSame(['band24' => 1, 'band5' => 5], $map->primaries($map->forProductClass('HG6145D2')));
        foreach (['HG6045F3', 'HG6145F3', 'HG6145D2'] as $model) {
            $this->assertTrue($map->bandVerified($map->forProductClass($model)), "band_verified after R3: {$model}");
        }
        $this->assertFalse($map->bandVerified($map->forProductClass('UNKNOWN-MODEL')), 'unknown models stay unverified');
        $this->assertSame(range(1, 8), $map->forProductClass('HG6145D2')['assoc_instances']);
        $this->assertSame(range(1, 16), $map->forProductClass('HG6145F3')['assoc_instances']);
    }

    public function test_extractor_reads_the_primary_5ghz_instance_per_model(): void
    {
        $ex = new CpeSnapshotExtractor;

        $f3 = $ex->extract($this->cpeDoc(['class' => 'HG6145F3', 's24' => 'Up', 's5' => 'Disabled', 'a24' => 4, 'a5' => 0]))['columns'];
        $this->assertSame('Up', $f3['wlan24_status']);
        $this->assertSame('Disabled', $f3['wlan5_status']);
        $this->assertSame(0, $f3['wlan5_enabled']);
        $this->assertSame(4, $f3['wlan24_assoc']);
        $this->assertSame(0, $f3['wlan5_assoc']);
        $this->assertSame('ERROR_NONE', $f3['ppp_last_error']);
        $this->assertSame('Connected', $f3['ppp_status']);
        $this->assertNotNull($f3['wlan_values_at']);
        $this->assertNotNull($f3['last_boot_at']);

        // HG6145D2: the 5 GHz primary is instance 5, instance 9 is ignored even if present
        $doc = $this->cpeDoc(['class' => 'HG6145D2', 's5' => 'Disabled', 'a5' => 0]);
        $doc['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['9'] = ['Status' => $this->leaf('Up', 5), 'TotalAssociations' => $this->leaf(9, 5)];
        $d2 = $ex->extract($doc)['columns'];
        $this->assertSame('Disabled', $d2['wlan5_status']);
        $this->assertSame(0, $d2['wlan5_assoc']);
    }

    public function test_unknown_model_and_tr181_read_no_wlan_at_all(): void
    {
        $ex = new CpeSnapshotExtractor;

        $unknown = $this->cpeDoc(['class' => 'ZXHN-X9']);
        $cols = $ex->extract($unknown)['columns'];
        $this->assertNull($cols['wlan24_status']);
        $this->assertNull($cols['wlan5_status']);
        $this->assertNull($cols['wlan_values_at']);
        $this->assertNull($cols['ppp_status']);
        $this->assertNotNull($cols['last_boot_at'], 'generic still reads the _lastBoot meta field');

        $tr181 = [
            '_id' => '00AABB-F670L-ZTEG0000A00E',
            '_lastBoot' => Carbon::now()->subHour()->toIso8601String(),
            '_deviceId' => ['_ProductClass' => 'F670L'],
            'Device' => ['WiFi' => ['SSID' => ['1' => ['Enable' => $this->leaf(true), 'Status' => $this->leaf('Up')]]]],
        ];
        $cols = $ex->extract($tr181)['columns'];
        $this->assertNull($cols['wlan24_status']);
        $this->assertNull($cols['wlan_values_at']);

        // a document with nothing but _deviceId must not break anything
        $cols = $ex->extract(['_id' => 'x', '_deviceId' => ['_ProductClass' => 'HG6045F3']])['columns'];
        $this->assertSame(array_fill_keys(CpeSnapshotExtractor::COLUMNS, null), $cols);
    }

    // ------------------------------------------------------------ D. stale gate

    public function test_values_older_than_the_limit_are_null_and_only_kept_raw(): void
    {
        $ex = new CpeSnapshotExtractor;

        $stale = $ex->extract($this->cpeDoc(['age' => 121]));
        $this->assertNull($stale['columns']['wlan24_status']);
        $this->assertNull($stale['columns']['wlan5_assoc']);
        $this->assertNull($stale['columns']['wlan_values_at']);
        $this->assertNull($stale['columns']['ppp_status']);
        $this->assertSame('Up', $stale['cpe']['raw']['wlan24']['status']);
        $this->assertSame(121, $stale['cpe']['raw']['wlan24']['age_min']);

        $fresh = $ex->extract($this->cpeDoc(['age' => 119]));
        $this->assertSame('Up', $fresh['columns']['wlan24_status']);

        // the limit is a setting
        Setting::set('cpe_value_max_age_minutes', '30', 'text', 'outage');
        $this->assertNull($ex->extract($this->cpeDoc(['age' => 45]))['columns']['wlan24_status']);
    }

    public function test_rx_power_is_only_fresh_when_its_own_source_timestamp_is_fresh(): void
    {
        $ex = new CpeSnapshotExtractor;

        $this->assertNull($ex->extract($this->cpeDoc())['cpe']['rx_fresh'], 'default fixture: source is 600 h old');
        $this->assertSame(-21.5, $ex->extract($this->cpeDoc(['rx_age' => 10]))['cpe']['rx_fresh']);
    }

    // ------------------------------------------------------------ C. projection

    public function test_sync_projection_asks_for_last_boot_and_the_leaves(): void
    {
        $this->cpeSync([$this->cpeDoc()]);

        $projection = '';
        Http::assertSent(function (Request $request) use (&$projection) {
            if (str_contains($request->url(), '/devices')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $projection = (string) ($q['projection'] ?? '');
            }

            return true;
        });
        foreach (['_lastBoot', 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.9.Status', 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.TotalAssociations',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.LastConnectionError', 'InternetGatewayDevice.DeviceInfo.ModelName'] as $path) {
            $this->assertStringContainsString($path, $projection);
        }
        // the old projection is still there
        $this->assertStringContainsString('VirtualParameters.wifiClients', $projection);
    }

    // ------------------------------------------------------------ E. transitions

    public function test_first_sync_is_a_baseline_without_events(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['boot_min' => 30, 's24' => 'Disabled', 'a24' => 0, 'a5' => 0])]);

        $row = DB::table('genieacs_devices')->first();
        $this->assertSame('Disabled', $row->wlan24_status);
        $this->assertNotNull($row->last_boot_at);
        $this->assertSame(0, DB::table('cpe_events')->count(), 'NULL -> value is a baseline, never an event');
        $this->assertSame(1, DB::table('cpe_daily')->count());
    }

    public function test_last_boot_change_gives_one_boot_event_deduped_with_uptime_reset(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['boot_min' => 3000, 'uptime' => 180000])]);

        // 10 minutes later the ONU rebooted 4 minutes ago: uptime dropped AND _lastBoot moved
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['boot_min' => 4, 'uptime' => 240])]);

        $this->assertSame(1, DB::table('cpe_events')->where('type', 'uptime_reset')->count());
        $this->assertSame(0, DB::table('cpe_events')->where('type', 'boot')->count(), 'the uptime_reset of the same restart already counts');
        $this->assertSame(1, (int) DB::table('cpe_daily')->value('boots'));
    }

    public function test_last_boot_change_alone_gives_a_boot_event(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['boot_min' => 3000, 'uptime' => 240])]);

        // uptime did not drop (inform lag) but the meta field moved
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['boot_min' => 4, 'uptime' => 900])]);

        $events = DB::table('cpe_events')->where('type', 'boot')->get();
        $this->assertCount(1, $events);
        $this->assertSame('last_boot', json_decode($events[0]->meta, true)['source']);

        // the same _lastBoot on the next sync is not a new boot
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['boot_min' => 14, 'uptime' => 1500])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'boot')->count());
    }

    public function test_up_to_disabled_on_fresh_values_gives_wlan_down_and_back_gives_wlan_up(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['s24' => 'Up', 's5' => 'Up'])]);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['s24' => 'Up', 's5' => 'Disabled', 'a5' => 0])]);
        $down = DB::table('cpe_events')->where('type', 'wlan_down')->get();
        $this->assertCount(1, $down);
        $this->assertSame(['band5'], array_keys(json_decode($down[0]->meta, true)['bands']));
        $this->assertSame(1, (int) DB::table('cpe_daily')->value('wlan5_down_samples'));

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['s24' => 'Up', 's5' => 'Up'])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'wlan_up')->count());
    }

    public function test_up_to_disabled_on_stale_values_gives_nothing(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['s24' => 'Up', 's5' => 'Up'])]);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['s24' => 'Disabled', 's5' => 'Disabled', 'age' => 400])]);

        $this->assertSame(0, DB::table('cpe_events')->whereIn('type', ['wlan_down', 'wlan_up'])->count());
        $this->assertNull(DB::table('genieacs_devices')->value('wlan24_status'), 'stale value is stored as NULL');
    }

    public function test_assoc_zero_only_when_clients_vanish_and_the_onu_kept_its_uptime(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['a24' => 3, 'a5' => 2, 'uptime' => 5000])]);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['a24' => 0, 'a5' => 0, 'uptime' => 5600])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'assoc_zero')->count());

        // after a reboot the counters are 0 for a good reason: no assoc_zero
        DB::table('cpe_events')->delete();
        DB::table('genieacs_devices')->update(['wlan24_assoc' => 3, 'wlan5_assoc' => 2, 'uptime_seconds' => 9000, 'wlan_values_at' => Carbon::now()->format('Y-m-d H:i:s')]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['a24' => 0, 'a5' => 0, 'uptime' => 200])]);
        $this->assertSame(0, DB::table('cpe_events')->where('type', 'assoc_zero')->count());
    }

    public function test_ppp_error_event_only_for_a_real_new_error(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc()]);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['ppp_error' => 'ERROR_AUTHENTICATION_FAILURE'])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'ppp_error')->count());
        $this->assertSame(1, (int) DB::table('cpe_daily')->value('ppp_error_samples'));

        // back to ERROR_NONE is not an error
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['ppp_error' => 'ERROR_NONE'])]);
        $this->assertSame(1, DB::table('cpe_events')->where('type', 'ppp_error')->count());
    }

    // ------------------------------------------------------------ E. cpe_daily

    public function test_cpe_daily_upsert_is_one_row_per_device_per_wib_day(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['a24' => 3, 'a5' => 1])]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->cpeSync([$this->cpeDoc(['a24' => 5, 'a5' => 0])]);

        $this->assertSame(1, DB::table('cpe_daily')->count());
        $row = DB::table('cpe_daily')->first();
        $this->assertSame(2, (int) $row->samples);
        $this->assertSame(2, (int) $row->fresh_wlan_samples);
        $this->assertSame(5, (int) $row->assoc24_max);
        $this->assertSame(1, (int) $row->assoc5_max);
        $this->assertSame('2026-10-17', (string) $row->day_wib);
    }

    public function test_cpe_daily_respects_the_wib_day_boundary(): void
    {
        $this->cpeCustomer();
        Carbon::setTestNow(Carbon::parse('2026-10-17 16:50:00', 'UTC')); // 23:50 WIB
        $this->cpeSync([$this->cpeDoc()]);
        Carbon::setTestNow(Carbon::parse('2026-10-17 17:10:00', 'UTC')); // 00:10 WIB next day
        $this->cpeSync([$this->cpeDoc()]);

        $this->assertSame(['2026-10-17', '2026-10-18'], DB::table('cpe_daily')->orderBy('day_wib')->pluck('day_wib')->map(fn ($d) => substr((string) $d, 0, 10))->all());
    }

    public function test_stale_devices_still_get_a_daily_row_without_fresh_wlan_samples(): void
    {
        $this->cpeSync([$this->cpeDoc(['age' => 500])]);

        $row = DB::table('cpe_daily')->first();
        $this->assertSame(1, (int) $row->samples);
        $this->assertSame(0, (int) $row->fresh_wlan_samples);
        $this->assertNull($row->assoc24_max);
    }

    // ------------------------------------------------------------ K. CpeEvidence

    public function test_evidence_exposes_fresh_state_with_source_and_age_and_nothing_stale(): void
    {
        $customer = $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['s5' => 'Disabled', 'a5' => 0, 'a24' => 2])]);

        $e = CpeEvidence::forCustomer($customer->id);
        $this->assertSame('HG6045F3', $e['device']['product_class']);
        $this->assertTrue($e['device']['supported']);
        $this->assertTrue($e['device']['band_verified']);
        $this->assertSame('Up', $e['wlan']['band24']['status']);
        $this->assertSame('Disabled', $e['wlan']['band5']['status']);
        $this->assertSame('sync', $e['wlan']['band5']['source']);
        $this->assertTrue($e['wlan']['band5']['fresh']);
        $this->assertSame(5, $e['wlan']['band5']['age_min']);
        $this->assertSame('Connected', $e['ppp']['status']);
        $this->assertSame(120, $e['max_age_minutes']);

        // time passes without a new sync: the same row is now stale
        Carbon::setTestNow(Carbon::now()->addHours(3));
        $e = CpeEvidence::forCustomer($customer->id);
        $this->assertFalse($e['wlan']['band24']['fresh']);
    }

    public function test_evidence_counts_boots_clustered_per_ten_minutes_and_handles_no_device(): void
    {
        $customer = $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc()]);
        $deviceId = (string) DB::table('genieacs_devices')->value('device_id');
        foreach ([200, 195, 100, 30] as $minAgo) { // 200+195 = one cluster
            DB::table('cpe_events')->insert(['customer_id' => $customer->id, 'device_id' => $deviceId, 'type' => $minAgo === 195 ? 'uptime_reset' : 'boot', 'at' => Carbon::now()->subMinutes($minAgo)->format('Y-m-d H:i:s')]);
        }

        $e = CpeEvidence::forCustomer($customer->id);
        $this->assertSame(3, $e['boots_24h']);

        $none = CpeEvidence::forCustomer(999999);
        $this->assertNull($none['device']);
        $this->assertSame(0, $none['boots_24h']);
    }

    // ------------------------------------------------------------ settings

    public function test_cpe_settings_defaults_and_writer_validation(): void
    {
        $this->assertTrue(OutageSettings::cpeLayerEnabled());
        $this->assertFalse(OutageSettings::cpeWatchEnabled(), 'watch is default OFF');
        $this->assertFalse(OutageSettings::cpeSummonEnabled(), 'summon is default OFF');
        $this->assertSame(120, OutageSettings::cpeValueMaxAgeMinutes());
        $this->assertSame(30, OutageSettings::cpeWatchMaxDevices());
        $this->assertSame(24, OutageSettings::cpeWatchHours());
        $this->assertSame(2, OutageSettings::cpeSummonDeviceCooldownMinutes());
        $this->assertSame(20, OutageSettings::cpeSummonPerUserHour());
        $this->assertSame(60, OutageSettings::cpeSummonGlobalHour());
        $this->assertSame(3, OutageSettings::cpeSummonConcurrent());
        $this->assertSame(8000, OutageSettings::cpeSummonTimeoutMs());
        $this->assertArrayHasKey('cpe', OutageSettings::snapshot());

        $writer = new OutageSettingsWriter;
        $this->assertTrue($writer->validate('cpe_watch_enabled', '1')['ok']);
        $this->assertFalse($writer->validate('cpe_watch_enabled', 'maybe')['ok']);
        $this->assertTrue($writer->validate('cpe_watch_max_devices', '100')['ok']);
        $this->assertFalse($writer->validate('cpe_watch_max_devices', '101')['ok']);
        $this->assertFalse($writer->validate('cpe_watch_max_devices', '0')['ok']);
        $this->assertFalse($writer->validate('cpe_summon_timeout_ms', '99999')['ok']);

        $writer->set('cpe_summon_enabled', '1', 'tester');
        $this->assertTrue(OutageSettings::cpeSummonEnabled());
        $this->assertSame('cpe_summon_enabled', Activity::query()->latest('id')->first()->properties['key']);
    }

    // ------------------------------------------------------------ retention + schedule

    public function test_prune_keeps_samples_14_days_and_daily_400_days(): void
    {
        $old = now()->subDays(15)->format('Y-m-d H:i:s');
        $new = now()->subDays(13)->format('Y-m-d H:i:s');
        foreach ([$old, $new] as $when) {
            DB::table('cpe_wifi_samples')->insert(['device_id' => 'D', 'at' => $when, 'source' => 'watch']);
        }
        DB::table('cpe_daily')->insert([
            ['device_id' => 'D', 'day_wib' => now()->subDays(401)->toDateString(), 'samples' => 1],
            ['device_id' => 'D', 'day_wib' => now()->subDays(399)->toDateString(), 'samples' => 1],
        ]);

        $this->artisan('outage:events-prune')->assertSuccessful();

        $this->assertSame(1, DB::table('cpe_wifi_samples')->count());
        $this->assertSame(1, DB::table('cpe_daily')->count());
        $this->assertSame($new, (string) DB::table('cpe_wifi_samples')->value('at'));
    }

    public function test_cpe_watch_is_scheduled_off_the_five_minute_boundaries(): void
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $events = array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, 'cpe:watch')));
        $this->assertCount(1, $events);
        $this->assertSame('2-59/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->runInBackground);
        $this->assertSame(4, $events[0]->expiresAt);

        // default OFF: the schedule filter keeps the process from starting
        $this->assertFalse($events[0]->filtersPass($this->app));
        Setting::set('cpe_watch_enabled', '1', 'text', 'outage');
        $this->assertTrue($events[0]->filtersPass($this->app));
    }

    public function test_a_row_without_cpe_columns_does_not_break_the_bulk_upsert(): void
    {
        $good = $this->cpeDoc();
        // a document that makes the extractor throw (non-array leaf node) next to a normal one
        $bad = $this->cpeDoc(['serial' => 'FHTTUJI00009']);
        $bad['_deviceId'] = 'broken';

        $result = $this->cpeSync([$good, $bad]);

        $this->assertSame(2, $result['synced']);
        $this->assertSame(2, DB::table('genieacs_devices')->count());
    }

    public function test_inform_ingest_does_not_bump_the_daily_counters_but_a_sync_does(): void
    {
        $this->cpeCustomer();
        $doc = $this->cpeDoc();
        $this->cpeSync([$doc]);
        $this->assertSame(1, (int) DB::table('cpe_daily')->value('samples'));

        Http::swap(new Factory);
        Http::fake(['*' => Http::response($doc)]);
        app(GenieAcsService::class)->ingestDeviceInform(['device_id' => $doc['_id']]);

        $this->assertSame(1, (int) DB::table('cpe_daily')->value('samples'));
    }

    public function test_display_block_tells_stale_from_never_read_and_the_age_is_kept(): void
    {
        $this->cpeSync([$this->cpeDoc(['age' => 500])]);
        $service = app(GenieAcsService::class);
        $device = GenieAcsDevice::query()->first();

        $display = (new ReflectionMethod($service, 'cpeDisplay'))->invoke($service, $device);
        $this->assertFalse($display['fresh']);
        $this->assertTrue($display['known']);
        $this->assertSame(500, $display['age_min']);

        $device->forceFill(['parameters' => null])->save();
        $never = (new ReflectionMethod($service, 'cpeDisplay'))->invoke($service, $device->fresh());
        $this->assertFalse($never['known']);
        $this->assertNull($never['age_min']);
    }

    public function test_device_model_and_new_columns_are_cast(): void
    {
        $this->cpeCustomer();
        $this->cpeSync([$this->cpeDoc(['s5' => 'Disabled', 'a5' => 0])]);

        $device = GenieAcsDevice::query()->first();
        $this->assertFalse($device->wlan5_enabled);
        $this->assertTrue($device->wlan24_enabled);
        $this->assertSame(3, $device->wlan24_assoc);
        $this->assertInstanceOf(Carbon::class, $device->wlan_values_at);
        $this->assertInstanceOf(Carbon::class, $device->last_boot_at);
    }
}
