<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowDigest;
use App\Services\Outage\OutageShadowNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Roadmap 2.4: customer names in the shadow log WA message (flag outage_shadow_wa_include_names, default 0).
 * Names only reach the shadow log group itself; flag off = text byte-identical to the counts-only message.
 */
#[Group('blocking-core')]
class OutageShadowWaNamesTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = '120363000000000003@g.us';

    private int $seq = 0;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
        config(['app.url' => 'https://ebilling.example.test']);
        Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00 UTC')); // 12:00 WIB: outside quiet hours
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'WAN-PKG'], ['name' => 'WA names pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'SKY04'.$n,
            'name' => 'Budi Contoh',
            'phone' => '0812000'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'user.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
        ], $o));
    }

    private function capture(int $expectedSends = 1): MockInterface
    {
        $this->sent = [];
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->times($expectedSends)->andReturnUsing(function (string $jid, string $text) {
            $this->sent[] = $jid.'|'.$text;

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $mock);

        return $mock;
    }

    private function entries(?int $customerId = null): array
    {
        return [
            ['id' => 1, 'type' => 'mass_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/3', 'affected_count' => 6, 'would_action' => 'ticket_mass', 'cause_unknown' => false],
            ['id' => 2, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/4', 'customer_id' => $customerId, 'affected_count' => 1, 'would_action' => 'ticket_single', 'cause_unknown' => false],
        ];
    }

    private const HEAD = "[SHADOW 2.4] Deteksi gangguan baru (hanya dicatat; tidak ada tiket dan tidak ada WA ke pelanggan)\n";

    public function test_flag_defaults_to_off_is_whitelisted_and_in_the_snapshot(): void
    {
        $this->assertFalse(OutageSettings::shadowWaIncludeNames());
        $this->assertFalse(OutageSettings::snapshot()['shadow_wa_include_names']);

        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_wa_include_names', 'value' => '1'])->assertExitCode(0);
        Setting::flushRequestMemo();
        $this->assertTrue(OutageSettings::shadowWaIncludeNames());
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_wa_include_names', 'value' => 'maybe'])->assertExitCode(1);
    }

    public function test_flag_off_text_is_byte_identical_to_the_counts_only_message(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        $c = $this->customer(['pppoe_user' => 'budi.krian']);
        $this->capture(1);
        $entries = $this->entries($c->id);
        // Per-event entries that exist for the names format only must not leak into the off text.
        $entries[] = ['id' => 3, 'type' => 'weak_rx', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/4', 'customer_id' => $c->id, 'affected_count' => 1, 'level' => 'customer'];
        $entries[] = ['id' => 4, 'type' => 'pppoe_down', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/5', 'customer_id' => $c->id, 'affected_count' => 1, 'level' => 'customer'];

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $entries]));

        $expected = self::HEAD."- Massal: OLT RA PON 1/3/3 (6 pelanggan)\n- LOS individual (>= batas waktu): OLT RA 1 pelanggan";
        $this->assertSame([self::LOG.'|'.$expected], $this->sent);
        $this->assertSame($expected, app(OutageShadowNotifier::class)->message($this->entries($c->id)));
        foreach (['Budi', 'SKY04', 'budi.krian', 'Detail:', 'http'] as $needle) {
            $this->assertStringNotContainsString($needle, $this->sent[0]);
        }
    }

    public function test_names_on_individual_event_and_mass_detail_link_exact_body(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $c = $this->customer(['pppoe_user' => 'budi.krian']);
        $this->capture(1);

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $this->entries($c->id)]));

        $expected = self::HEAD
            ."- Massal: OLT RA PON 1/3/3 (6 pelanggan)\n  Detail: https://ebilling.example.test/monitoring?tab=deteksi&episode=1\n"
            .'- LOS individual: OLT RA PON 1/3/4 - SKY041 Budi Contoh (budi.krian)';
        $this->assertSame([self::LOG.'|'.$expected], $this->sent);
    }

    public function test_names_on_mass_only_has_no_customer_names(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $this->customer();
        $this->capture(1);

        $entries = [$this->entries()[0]];
        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $entries]));

        $this->assertSame([self::LOG.'|'.self::HEAD."- Massal: OLT RA PON 1/3/3 (6 pelanggan)\n  Detail: https://ebilling.example.test/monitoring?tab=deteksi&episode=1"], $this->sent);
    }

    public function test_weak_rx_and_pppoe_down_lines_and_router_level_mass_line(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $c = $this->customer(['name' => 'Siti Contoh', 'code' => 'SKY0388']);
        $sig = OutageSignal::create([
            'signal_type' => 'weak_rx', 'scope_key' => 'customer:'.$c->id.':rx', 'olt_id' => 1, 'customer_id' => $c->id,
            'state' => 'confirmed', 'opened_at' => now(), 'affected_count' => 1, 'payload' => ['worst_dbm' => -28.4],
        ]);
        $this->capture(1);

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => [
            ['id' => $sig->id, 'type' => 'weak_rx', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/4', 'customer_id' => $c->id, 'affected_count' => 1, 'level' => 'customer'],
            ['id' => 51, 'type' => 'pppoe_down', 'olt_id' => 1, 'olt_name' => 'OLT Gadang', 'pon_port' => null, 'customer_id' => $c->id, 'affected_count' => 1, 'level' => 'customer'],
            ['id' => 52, 'type' => 'pppoe_down', 'olt_id' => null, 'olt_name' => '', 'pon_port' => null, 'customer_id' => null, 'affected_count' => 7, 'level' => 'router'],
        ]]));

        $expected = self::HEAD
            .'- PPPoE mati massal (7 pelanggan)'."\n".'  Detail: https://ebilling.example.test/monitoring?tab=deteksi&episode=52'."\n"
            ."- Redaman: OLT RA PON 1/1/4 - SKY0388 Siti Contoh (-28.4 dBm)\n"
            .'- PPPoE mati: OLT Gadang - SKY0388 Siti Contoh';
        $this->assertSame([self::LOG.'|'.$expected], $this->sent);
    }

    public function test_phone_like_pppoe_is_dropped_and_names_are_sanitised_and_capped(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $phone = $this->customer(['pppoe_user' => '081200000039', 'name' => "Ani *Bold*\n_x_ ~y~ `z`"]);
        $long = $this->customer(['pppoe_user' => 'this.is.longer.than.twenty', 'name' => str_repeat('Abcdefghij ', 8)]);
        $numeric = $this->customer(['name' => 'Telp 081200000039', 'pppoe_user' => 'ok.user']);
        $this->capture(1);

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => [
            ['id' => 11, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/1', 'customer_id' => $phone->id, 'affected_count' => 1],
            ['id' => 12, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/2', 'customer_id' => $long->id, 'affected_count' => 1],
            ['id' => 13, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/3', 'customer_id' => $numeric->id, 'affected_count' => 1],
        ]]));

        $text = explode('|', $this->sent[0], 2)[1];
        $lines = explode("\n", $text);
        $this->assertSame('- LOS individual: OLT RA PON 1/1/1 - SKY041 Ani Bold x y z', $lines[1]);
        $this->assertStringNotContainsString('this.is.longer', $lines[2]);
        $this->assertLessThanOrEqual(mb_strlen('- LOS individual: OLT RA PON 1/1/2 - SKY042 ') + 40, mb_strlen($lines[2]));
        $this->assertSame('- LOS individual: OLT RA PON 1/1/3 - SKY043 (ok.user)', $lines[3]);
        $this->assertDoesNotMatchRegularExpression('/\d{8,}/', $text);
        $this->assertCount(4, $lines); // head + 3 lines: no injected line breaks
    }

    public function test_separated_phone_numbers_are_dropped_from_pppoe_and_name(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $a = $this->customer(['pppoe_user' => '0812-0000-0039', 'name' => 'Rina']);
        $b = $this->customer(['pppoe_user' => '0812 0000 0039', 'name' => 'Dewi']);
        $c = $this->customer(['pppoe_user' => 'ok.user', 'name' => 'Telp 0812-0000-0039']);
        $d = $this->customer(['pppoe_user' => '+62 812 0000 0039', 'name' => 'Sari']);
        $this->capture(1);
        $entries = [];
        foreach ([$a, $b, $c, $d] as $i => $cust) {
            $entries[] = ['id' => 20 + $i, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/'.$i, 'customer_id' => $cust->id, 'affected_count' => 1];
        }

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $entries]));

        $text = explode('|', $this->sent[0], 2)[1];
        $this->assertStringContainsString('SKY041 Rina', $text);
        $this->assertStringContainsString('Dewi', $text);
        $this->assertStringContainsString('SKY043 (ok.user)', $text);
        $this->assertStringNotContainsString('Telp', $text);
        $this->assertStringNotContainsString('(', explode("\n", $text)[1]);
        $this->assertDoesNotMatchRegularExpression('/\d{4}[\s.\-]\d{4}/', $text);
    }

    public function test_mass_lines_come_first_and_never_fall_into_dan_n_lagi_and_link_date_uses_the_passed_now(): void
    {
        $entries = [];
        $customers = ['c' => [], 'rx' => []];
        for ($i = 1; $i <= 9; $i++) {
            $c = $this->customer();
            $customers['c'][$c->id] = ['code' => $c->code, 'name' => $c->name, 'pppoe_user' => $c->pppoe_user];
            $entries[] = ['id' => 100 + $i, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/'.$i, 'customer_id' => $c->id, 'affected_count' => 1];
        }
        $entries[] = ['id' => 1, 'type' => 'mass_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/3', 'affected_count' => 6];
        $entries[] = ['id' => 2, 'type' => 'olt_wide', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'affected_count' => 50];
        $entries[] = ['id' => 3, 'type' => 'pppoe_down', 'olt_id' => null, 'olt_name' => '', 'customer_id' => null, 'affected_count' => 7, 'level' => 'router'];
        $n = app(OutageShadowNotifier::class);

        $text = $n->message($entries, 0, $customers, true, Carbon::parse('2026-11-02 18:00:00 UTC')); // 03 Nov WIB
        $lines = explode("\n", $text);

        $this->assertStringStartsWith('- Massal: OLT RA PON 1/3/3', $lines[1]);
        $this->assertStringContainsString('Perubahan massal status ONU', $text);
        $this->assertStringContainsString('PPPoE mati massal (7 pelanggan)', $text);
        $this->assertStringContainsString('- dan 4 lagi (lihat halaman): https://ebilling.example.test/monitoring?tab=deteksi&from=2026-11-03', $text);
        $this->assertStringNotContainsString('LOS individual: OLT RA PON 1/1/9', $text);
    }

    public function test_more_than_eight_lines_end_with_a_link_to_the_page(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $this->capture(1);
        $entries = [];
        for ($i = 1; $i <= 10; $i++) {
            $c = $this->customer();
            $entries[] = ['id' => 100 + $i, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/'.$i, 'customer_id' => $c->id, 'affected_count' => 1];
        }

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $entries]));

        $lines = explode("\n", explode('|', $this->sent[0], 2)[1]);
        $this->assertCount(1 + 8 + 1, $lines);
        $this->assertSame('- dan 2 lagi (lihat halaman): https://ebilling.example.test/monitoring?tab=deteksi&from=2026-10-15', $lines[9]);
    }

    public function test_dan_n_lagi_link_starts_at_the_day_of_the_oldest_held_entry(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $this->capture(1);
        $n = app(OutageShadowNotifier::class);
        $entries = [];
        for ($i = 1; $i <= 10; $i++) {
            $c = $this->customer();
            $entries[] = ['id' => 100 + $i, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/'.$i, 'customer_id' => $c->id, 'affected_count' => 1];
        }

        // 23:30 WIB on 15 Oct (quiet hours): held.
        $held = Carbon::parse('2026-10-15 16:30:00 UTC');
        $this->assertFalse($n->send(['new_confirmed' => $entries], $held));
        // 12:00 WIB on 16 Oct: sent with the held entries; the link starts at the held day, not today.
        $this->assertTrue($n->send(['new_confirmed' => []], Carbon::parse('2026-10-16 05:00:00 UTC')));

        $lines = explode("\n", explode('|', $this->sent[0], 2)[1]);
        $this->assertSame('- dan 2 lagi (lihat halaman): https://ebilling.example.test/monitoring?tab=deteksi&from=2026-10-15', end($lines));
    }

    public function test_names_never_go_to_psb_attendance_technician_input_or_a_non_log_jid(): void
    {
        $n = app(OutageShadowNotifier::class);
        Setting::set('outage_shadow_log_jid', self::LOG);

        $this->assertTrue($n->namesAllowed(self::LOG));
        $this->assertFalse($n->namesAllowed('120363000000000004@g.us'), 'not the shadow log group');
        $this->assertFalse($n->namesAllowed('628123456789@s.whatsapp.net'), 'not a group');
        $this->assertFalse($n->namesAllowed(''));

        Setting::set('psb_wa_group_ids', [self::LOG]);
        $this->assertFalse($n->namesAllowed(self::LOG), 'PSB/migration group');
        Setting::set('psb_wa_group_ids', []);
        $this->assertTrue($n->namesAllowed(self::LOG));

        Setting::set('attendance_group_id', self::LOG);
        $this->assertFalse($n->namesAllowed(self::LOG), 'attendance group');
        Setting::set('attendance_group_id', '');
        Setting::set('wa_ticket_progress_input_group_jid', self::LOG);
        $this->assertFalse($n->namesAllowed(self::LOG), 'technician input group');
    }

    public function test_psb_personal_and_refused_targets_get_nothing_even_with_names_on(): void
    {
        Setting::set('outage_shadow_wa_include_names', '1');
        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us']);
        Setting::set('report_wa_group_ids', ['111111111111@g.us']);
        $c = $this->customer();
        $this->capture(0);

        // report group without the allow flag, PSB group: refused before any send
        foreach (['120363000000000001@g.us', '111111111111@g.us'] as $jid) {
            Setting::set('outage_shadow_log_jid', $jid);
            $this->assertFalse(app(OutageShadowNotifier::class)->send(['new_confirmed' => $this->entries($c->id)]), $jid);
        }
        Http::assertNothingSent();
    }

    public function test_report_group_with_allow_flag_gets_names_only_when_it_is_the_log_jid(): void
    {
        Setting::set('report_wa_group_ids', ['111111111111@g.us']);
        Setting::set('outage_shadow_allow_report_group', '1');
        Setting::set('outage_shadow_log_jid', '111111111111@g.us');
        Setting::set('outage_shadow_wa_include_names', '1');
        $c = $this->customer();
        $this->capture(1);

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $this->entries($c->id)]));
        $this->assertStringContainsString('Budi Contoh', $this->sent[0]);
        $this->assertStringStartsWith('111111111111@g.us|', $this->sent[0]);
    }

    public function test_held_cache_keeps_ids_only(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        $c = $this->customer();
        Carbon::setTestNow(Carbon::parse('2026-10-15 16:00:00 UTC')); // 23:00 WIB: quiet hours, entries are held
        $this->capture(0);

        $this->assertFalse(app(OutageShadowNotifier::class)->send(['new_confirmed' => $this->entries($c->id)]));

        $held = (string) json_encode(Cache::get('outage:shadow-wa:held'));
        $this->assertStringContainsString('"customer_id":'.$c->id, $held);
        foreach (['Budi', 'Contoh', 'SKY04', 'budi.krian', '0812000'] as $needle) {
            $this->assertStringNotContainsString($needle, $held);
        }
    }

    public function test_held_cap_keeps_mass_entries_and_drops_oldest_non_mass_first(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        Carbon::setTestNow(Carbon::parse('2026-10-15 16:00:00 UTC')); // quiet hours: everything is held
        $this->capture(0);
        $n = app(OutageShadowNotifier::class);

        $mass = ['id' => 9001, 'type' => 'mass_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/3', 'affected_count' => 6];
        $router = ['id' => 9002, 'type' => 'pppoe_down', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'level' => 'router', 'affected_count' => 40];
        $n->send(['new_confirmed' => [$mass, $router]]);
        $burst = [];
        for ($i = 1; $i <= 250; $i++) {
            $burst[] = ['id' => $i, 'type' => 'weak_rx', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/1', 'customer_id' => $i, 'level' => 'customer'];
        }
        $n->send(['new_confirmed' => $burst]);

        $held = Cache::get('outage:shadow-wa:held');
        $this->assertCount(200, $held);
        $ids = array_column($held, 'id');
        $this->assertContains(9001, $ids);
        $this->assertContains(9002, $ids);
        $this->assertNotContains(1, $ids); // oldest weak_rx dropped
        $this->assertContains(250, $ids); // newest kept
    }

    public function test_header_does_not_claim_held_entries_when_the_flag_is_off_again(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Cache::put('outage:shadow-wa:held', [
            ['id' => 5, 'type' => 'weak_rx', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/4', 'customer_id' => 1, 'level' => 'customer'],
        ], now()->addHour());
        $this->capture(1);

        $this->assertTrue(app(OutageShadowNotifier::class)->send(['new_confirmed' => $this->entries()]));

        $this->assertStringContainsString('Deteksi gangguan baru', $this->sent[0]);
        $this->assertStringNotContainsString('tertahan', $this->sent[0]);
    }

    public function test_olt_name_is_loaded_once_per_detector_run(): void
    {
        Setting::set('outage_shadow_wa_include_names', '1');
        $olt = Olt::create(['name' => 'OLT RA', 'code' => 'WN-1', 'vendor' => 'zte_c300']);
        $detector = app(OutageDetector::class);
        $m = new ReflectionMethod($detector, 'confirmShadow');
        $m->setAccessible(true);
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'from `olts`') || str_contains($q->sql, 'from "olts"')) {
                $queries++;
            }
        });
        $summary = new ReflectionProperty($detector, 'summary');
        $summary->setAccessible(true);
        $summary->setValue($detector, ['confirmed' => 0, 'new_confirmed' => []]);

        for ($i = 1; $i <= 3; $i++) {
            $ep = OutageSignal::create([
                'signal_type' => 'weak_rx', 'scope_key' => 'customer:'.$i, 'olt_id' => $olt->id, 'pon_port' => '1/1/1',
                'state' => 'open', 'opened_at' => now(), 'affected_count' => 1,
            ]);
            $m->invoke($detector, $ep, 'shadow', 'none', now());
        }

        $this->assertSame(1, $queries);
        $entries = $summary->getValue($detector)['new_confirmed'];
        $this->assertCount(3, $entries);
        $this->assertSame('OLT RA', $entries[2]['olt_name']);
    }

    public function test_dedupe_and_rate_cap_still_apply_with_names_on(): void
    {
        Setting::set('outage_shadow_log_jid', self::LOG);
        Setting::set('outage_shadow_wa_include_names', '1');
        Setting::set('outage_shadow_wa_max_per_hour', '1');
        $c = $this->customer();
        $this->capture(1);
        $n = app(OutageShadowNotifier::class);

        $this->assertTrue($n->send(['new_confirmed' => $this->entries($c->id)]));
        $this->assertFalse($n->send(['new_confirmed' => $this->entries($c->id)]), 'identical text + ids within the dedupe window');
        $other = [['id' => 9, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/9/9', 'customer_id' => $c->id, 'affected_count' => 1]];
        $this->assertFalse($n->send(['new_confirmed' => $other]), 'rate cap 1/hour');
        $this->assertCount(1, $this->sent);
    }

    public function test_notifier_source_only_uses_the_ops_channel_group_send(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Outage/OutageShadowNotifier.php'));
        $this->assertSame(1, substr_count($src, "forChannel('ops')"));
        $this->assertSame(1, substr_count($src, '->sendToGroup('));
        foreach (["forChannel('billing')", "forChannel('ticket')", 'sendToCustomer', 'sendMessage(', 'Psb', 'Migration', 'Invoice'] as $needle) {
            $this->assertStringNotContainsString($needle, $src, $needle);
        }
    }

    public function test_digest_prints_one_line_for_an_olt_that_is_blind_and_stale(): void
    {
        $text = (new OutageShadowDigest)->text(
            now(),
            [
                'signals' => [], 'top_pon' => [],
                'data_blind' => ['open' => [
                    ['id' => 1, 'kind' => 'olt', 'label' => 'OLT RA', 'reason' => 'Data OLT lama'],
                    ['id' => 2, 'kind' => 'olt', 'label' => 'OLT B', 'reason' => 'Data OLT lama'],
                    ['id' => 3, 'kind' => 'router', 'label' => 'Router RA', 'reason' => 'Cek router terlambat'],
                ]],
                'stale_olts' => [['olt' => 'OLT RA', 'minutes_since_success' => 90]],
            ],
            ['days' => 14, 'signals' => [], 'recall' => ['recall' => null], 'coverage' => ['ratio' => null]],
        );

        $this->assertSame(1, substr_count($text, 'OLT RA'), 'one line for OLT RA');
        $this->assertStringContainsString('PERINGATAN data basi: OLT RA (sukses terakhir 90 menit lalu)', $text);
        $this->assertStringContainsString('Data tidak terbaca: OLT B - Data OLT lama', $text);
        $this->assertStringContainsString('Data tidak terbaca: Router RA - Cek router terlambat', $text);
    }

    public function test_digest_stays_counts_only_and_carries_an_absolute_detail_link(): void
    {
        $this->customer();
        $text = (new OutageShadowDigest)->text(
            now(),
            ['signals' => [], 'top_pon' => [], 'stale_olts' => []],
            ['days' => 14, 'signals' => [], 'recall' => ['recall' => null], 'coverage' => ['ratio' => null]],
        );

        $this->assertStringContainsString('Detail: https://ebilling.example.test/monitoring?tab=deteksi&from=2026-10-14&to=2026-10-15', $text);
        foreach (['Budi', 'SKY04', 'budi.krian'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/\d{10,}/', $text);
    }
}
