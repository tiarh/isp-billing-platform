<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OnuStateEvents;
use App\Services\Outage\OutageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-4 history through the real correlator (database fixtures shaped like the live cases of 2026-10-20, see
 * plans/rca4-flap-history.md section 1.5): #410 intermittent line, #324 nothing in the history, #415 a LOS that
 * explains the reconnects, a ticket that talks about "redaman", and the 24 hour window across the WIB midnight.
 */
#[Group('blocking-olt-mysql')] // not blocking-core: that job peaks at 249 of 256 MB (GenieAcsService lowers memory_limit)
class DiagnosisHistoryDbTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
        OnuStateEvents::forgetSchemaCache();
        CpeEvents::forgetSchemaCache();
        Cache::flush();
        config(['outage.olt_enrich_dispatch' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:Customer,1:Router,2:Olt} an online ZTE customer whose router sends syslog; RX -25.2 read 30 minutes ago */
    private function line(string $ref = '1/2/3:4', string $vendor = 'zte_c300'): array
    {
        $n = ++$this->seq;
        $olt = Olt::create(['name' => 'OLT RA '.$n, 'code' => 'HX-'.$n, 'vendor' => $vendor, 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        config(['outage.olt_ids' => [$olt->id]]);
        $router = Router::create(['name' => 'RA-OLT '.$n, 'ip_address' => '198.18.6.'.$n, 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => now()]);
        Setting::set('pppoe_syslog_sources', '198.18.6.'.$n.'='.$router->id);
        Setting::flushRequestMemo();
        $package = Package::firstOrCreate(['code' => 'HX-PKG'], ['name' => 'Riwayat pkg', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'HX-C'.$n, 'name' => 'Pelanggan Riwayat '.$n, 'phone' => '0812888'.$n, 'address' => 'Jl. Contoh', 'pppoe_user' => 'HX_USER_'.$n,
            'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => $ref, 'router_id' => $router->id,
        ]);
        DB::table('customers')->where('id', $customer->id)->update([
            'is_online' => 1, 'pppoe_online_checked_at' => now(), 'onu_rx_power_dbm' => -25.2, 'olt_last_synced_at' => now()->subMinutes(30)->format('Y-m-d H:i:s'),
        ]);
        OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $customer->id, 'phase' => 'online', 'phase_since_at' => now()->subDay(), 'last_state_collected_at' => now()]);

        return [$customer->fresh(), $router, $olt];
    }

    private function ppp(Customer $c, Router $r, string $event, Carbon $at, ?string $reason = null): void
    {
        DB::table('pppoe_session_events')->insert([
            'router_id' => $r->id, 'pppoe_user' => $c->pppoe_user, 'customer_id' => $c->id, 'event' => $event, 'reason' => $reason,
            'at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'source' => 'syslog',
        ]);
    }

    private function onuDrop(Olt $olt, Customer $c, Carbon $at, string $phase = 'los', string $source = 'olt_poll'): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => $c->olt_port_label, 'customer_id' => $c->id, 'from_phase' => 'online', 'to_phase' => $phase,
            'cause' => $phase === 'los' ? 'los' : null, 'source' => $source, 'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    private function onuUp(Olt $olt, Customer $c, Carbon $at): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => $c->olt_port_label, 'customer_id' => $c->id, 'from_phase' => 'los', 'to_phase' => 'online',
            'cause' => null, 'source' => 'olt_poll', 'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    private function device(Customer $c): string
    {
        $id = 'DEV-'.$c->id;
        DB::table('genieacs_devices')->insert(['device_id' => $id, 'customer_id' => $c->id, 'last_inform_at' => now()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function cpeEvent(Customer $c, string $device, string $type, Carbon $at): void
    {
        DB::table('cpe_events')->insert(['customer_id' => $c->id, 'device_id' => $device, 'type' => $type, 'at' => $at->copy()->utc()->format('Y-m-d H:i:s')]);
    }

    private function diagnose(Customer $c, array $opts = []): OutageDiagnosis
    {
        return app(OutageDiagnoser::class)->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_REFRESH, $opts);
    }

    private function texts(OutageDiagnosis $d): array
    {
        return array_column((array) $d->evidence, 'text');
    }

    // ------------------------------------------------------------------ calibration with the live cases

    public function test_410_like_intermittent_line_is_pppoe_flap_sedang_while_the_onu_is_online_and_stable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        // 24 h: 3 disconnects, 2 connects, 6 stuck (the last one 98 minutes ago), 2 ppp errors seen by the CPE, no ONU drop
        $this->ppp($c, $router, 'disconnected', $at('02:05:00'), 'peer is not responding');
        $this->ppp($c, $router, 'connected', $at('02:06:10'));
        $this->ppp($c, $router, 'disconnected', $at('03:40:00'), 'hung-up');
        $this->ppp($c, $router, 'connected', $at('03:41:20'));
        $this->ppp($c, $router, 'disconnected', $at('05:35:00'), 'hung-up');
        foreach (['05:36', '05:37', '05:38', '05:39', '05:41', '05:42'] as $hm) {
            $this->ppp($c, $router, 'stuck', $at($hm.':00'));
        }
        $device = $this->device($c);
        $this->cpeEvent($c, $device, 'ppp_error', $at('03:39:00'));
        $this->cpeEvent($c, $device, 'ppp_error', $at('05:34:00'));

        $d = $this->diagnose($c);

        $this->assertSame('pppoe_flap', $d->cause_code);
        $this->assertSame('sedang', $d->confidence);
        $this->assertSame('pppoe_history', $d->rule_id);
        $this->assertSame(Rules::RULES_VERSION, $d->rules_version);
        $texts = $this->texts($d);
        $this->assertSame('Internet putus-sambung 3 kali dalam 24 jam, terakhir 12:35.', $texts[0]);
        $this->assertContains('Terakhir putus di router 12:35 (dari ONU).', $texts);
        $this->assertContains('Redaman -25,2 dBm (normal), data 13:50.', $texts);
        $this->assertSame('teruskan_noc', $d->suggested_action);
    }

    public function test_324_like_line_with_no_history_stays_unknown_rendah_and_says_nothing_was_recorded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c] = $this->line();

        $d = $this->diagnose($c);

        $this->assertSame('unknown', $d->cause_code);
        $this->assertSame('rendah', $d->confidence);
        $texts = $this->texts($d);
        $this->assertSame('Jaringan normal saat dicek.', $texts[0]);
        $this->assertSame('24 jam terakhir tidak ada putus tercatat.', $texts[1]);
        $this->assertSame('Redaman -25,2 dBm (normal), data 13:50.', $texts[2]);
        $this->assertSame('belum_tahu', $d->suggested_action);
    }

    public function test_415_like_los_that_explains_the_reconnects_keeps_the_stuck_session_as_the_cause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 04:50:00', 'UTC'));
        [$c, $router, $olt] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $this->onuDrop($olt, $c, $at('04:24:00'));
        $this->onuUp($olt, $c, $at('04:29:00'));
        $device = $this->device($c);
        $this->cpeEvent($c, $device, 'boot', $at('04:28:00'));
        $this->ppp($c, $router, 'disconnected', $at('04:24:30'), 'peer is not responding');
        foreach (['04:30:00', '04:31:00', '04:33:00', '04:36:00'] as $hm) {
            $this->ppp($c, $router, 'connected', $at($hm));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->ppp($c, $router, 'stuck', $at('04:38:00')->addSeconds($i * 50));
        }
        // 2026-10-26.1: "already active" lines only mean stuck while the session is down now
        $up = $this->diagnose($c->fresh()); // line() has the session up
        $this->assertNotSame('pppoe_stuck', $up->cause_code, 'the session is up: the lines are noise');
        DB::table('customers')->where('id', $c->id)->update(['is_online' => 0, 'pppoe_online_checked_at' => now()]);

        $d = $this->diagnose($c->fresh());

        $this->assertSame('pppoe_stuck', $d->cause_code, 'the current primary cause is kept');
        $this->assertSame('tinggi', $d->confidence);
        $this->assertNotContains('pppoe_flap', (array) $d->secondary, 'the reconnects are explained by the LOS and the restart');
        $texts = $this->texts($d);
        $this->assertContains('ONU putus karena kabel 1 kali dalam 24 jam, terakhir 11:24.', $texts);
        $this->assertContains('ONU restart 1 kali dalam 24 jam.', $texts);
    }

    public function test_the_four_reconnects_of_the_6_hour_rule_do_not_count_when_a_los_explains_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router, $olt] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $this->onuDrop($olt, $c, $at('04:24:00'));
        $this->onuUp($olt, $c, $at('04:29:00'));
        foreach (['04:30:00', '04:31:00', '04:33:00', '04:36:00'] as $hm) {
            $this->ppp($c, $router, 'connected', $at($hm));
        }

        $d = $this->diagnose($c);

        $this->assertNotSame('pppoe_flap', $d->cause_code);
        $this->assertSame('unknown', $d->cause_code, 'one LOS alone is no cause');
    }

    // ------------------------------------------------------------------ the ONU history

    public function test_two_los_drops_in_24_hours_while_the_onu_is_online_is_drop_los_and_an_area_outage_is_not_counted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, , $olt] = $this->line();
        $this->onuDrop($olt, $c, now()->subHours(9));
        $this->onuUp($olt, $c, now()->subHours(9)->addMinutes(3));
        $this->onuDrop($olt, $c, now()->subHours(2));

        $d = $this->diagnose($c);
        $this->assertSame(['drop_los', 'sedang'], [$d->cause_code, $d->confidence]);
        $this->assertSame('onu_history', $d->rule_id);
        $this->assertSame('ONU putus karena kabel 2 kali dalam 24 jam, terakhir 12:20.', $this->texts($d)[0]);

        // the drop at the start of a CONFIRMED mass_los of the PON is the PON's fault, not this line's (RCA-5: only the
        // first 30 minutes of the episode count, so the drop 7 hours later is this line's own)
        OutageSignal::create([
            'signal_type' => 'mass_los', 'scope_key' => 'pon:'.$olt->id.':1/2/3', 'state' => 'resolved', 'olt_id' => $olt->id, 'pon_port' => '1/2/3',
            'opened_at' => now()->subHours(9)->subMinutes(2), 'confirmed_at' => now()->subHours(9), 'resolved_at' => now()->subHours(1), 'last_seen_at' => now()->subHours(1), 'observations' => 3, 'affected_count' => 4,
            'affected_customer_ids' => [$c->id], 'payload' => ['pon_total' => 10],
        ]);
        $area = $this->diagnose($c);
        $this->assertSame('unknown', $area->cause_code);
        $this->assertStringContainsString('Putus bersamaan dengan pelanggan lain satu PON', implode(' | ', $this->texts($area)));
    }

    public function test_the_window_is_24_hours_across_the_wib_midnight(): void
    {
        // 17:10 UTC = 00:10 WIB on the 22nd
        Carbon::setTestNow(Carbon::parse('2026-10-21 17:10:00', 'UTC'));
        [$c, , $olt] = $this->line();
        $this->onuDrop($olt, $c, now()->subHours(24)->subMinute());   // 24 h + 1 min ago: outside
        $this->onuDrop($olt, $c, now()->subHours(24)->addMinute());   // 23 h 59 min ago, 00:11 WIB yesterday: inside
        $this->onuDrop($olt, $c, now()->subMinutes(30));              // 23:40 WIB on the 21st: inside

        $d = $this->diagnose($c);

        $this->assertSame('drop_los', $d->cause_code);
        $this->assertSame('ONU putus karena kabel 2 kali dalam 24 jam, terakhir 21/10 23:40.', $this->texts($d)[0], 'the drop of the day before reads with its date');
        $this->assertContains('Redaman -25,2 dBm (normal), data 21/10 23:40.', $this->texts($d));
        $this->assertSame('2026-10-21T23:40:00+07:00', collect($d->evidence)->firstWhere('text', 'Redaman -25,2 dBm (normal), data 21/10 23:40.')['at']);
    }

    // ------------------------------------------------------------------ RX line and the "redaman" complaint

    public function test_rx_data_older_than_a_day_is_marked_old_and_a_ticket_about_redaman_gets_a_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c] = $this->line();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Redaman tinggi, internet lambat', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);
        $plain = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Internet mati', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);

        $with = $this->diagnose($c, ['ticket_id' => $ticket->id]);
        $without = $this->diagnose($c, ['ticket_id' => $plain->id]);
        $this->assertContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($with));
        $this->assertNotContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($without));

        DB::table('customers')->where('id', $c->id)->update(['olt_last_synced_at' => now()->subDays(3)->format('Y-m-d H:i:s')]);
        $old = $this->diagnose($c->fresh(), ['ticket_id' => $ticket->id]);
        $this->assertContains('Redaman -25,2 dBm, data lama (17/10 14:20).', $this->texts($old));
        $this->assertNotContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($old), 'old data cannot say "sekarang normal"');
    }

    public function test_a_router_without_a_log_and_no_cpe_says_the_history_is_not_recorded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c] = $this->line();
        Setting::set('pppoe_syslog_sources', '');
        Setting::flushRequestMemo();

        $d = $this->diagnose($c);

        $this->assertSame('unknown', $d->cause_code);
        $this->assertContains('Riwayat putus internet belum tercatat untuk router ini.', $this->texts($d));
        $this->assertNotContains('24 jam terakhir tidak ada putus tercatat.', $this->texts($d));
    }

    private function episode(Customer $c, Carbon $opened, Carbon $resolved): void
    {
        OutageSignal::create([
            'signal_type' => 'pppoe_down', 'scope_key' => 'pppoe:'.$c->id.':'.$opened->timestamp, 'state' => 'resolved', 'customer_id' => $c->id,
            'opened_at' => $opened, 'resolved_at' => $resolved, 'last_seen_at' => $resolved, 'observations' => 3, 'affected_count' => 1, 'affected_customer_ids' => [$c->id], 'payload' => [],
        ]);
    }

    public function test_one_40_minute_outage_seen_by_every_source_is_one_drop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $this->ppp($c, $router, 'disconnected', $at('05:00:00'), 'peer is not responding');
        $this->ppp($c, $router, 'connected', $at('05:40:00'));
        $this->episode($c, $at('05:10:00'), $at('06:20:00')); // the detector opens after 10 minutes and resolves 30 minutes after the return
        $this->cpeEvent($c, $this->device($c), 'ppp_reconnect', $at('05:40:30'));
        $probes = ['router' => ['status' => 'ok', 'active' => ['uptime' => '1h40m'], 'secret' => ['last-logged-out' => '2026-10-20 12:00:20']]]; // 05:00:20 UTC, 40 min before the session start

        $ctx = app(OutageCorrelator::class)->contextFor($c, now(), $probes);
        $this->assertCount(1, $ctx['history']['ppp_drops'], 'disconnect + episode + last-logged-out open it, connect + CPE + live session close it');
        $this->assertSame('2026-10-20 05:00:00', $ctx['history']['ppp_drops'][0]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 05:40:00', $ctx['history']['ppp_drops'][0]['end']->format('Y-m-d H:i:s'));

        $d = $this->diagnose($c, ['probes' => $probes]);
        $this->assertSame('unknown', $d->cause_code);
        $this->assertStringNotContainsString('putus-sambung', implode(' | ', $this->texts($d)));
    }

    public function test_two_long_outages_on_a_router_without_a_log_are_two_drops_and_no_flap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c] = $this->line();
        Setting::set('pppoe_syslog_sources', '');
        Setting::flushRequestMemo();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $device = $this->device($c);
        $this->episode($c, $at('02:20:00'), $at('03:30:00'));
        $this->cpeEvent($c, $device, 'ppp_reconnect', $at('03:00:00'));
        $this->episode($c, $at('05:10:00'), $at('06:30:00'));
        $this->cpeEvent($c, $device, 'ppp_reconnect', $at('05:50:00'));

        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now());
        $this->assertCount(2, $ctx['history']['ppp_drops'], 'an episode and the CPE reconnect that ends it are one drop');
        $this->assertSame('poll', $ctx['history']['ppp_source']);

        $d = $this->diagnose($c->fresh());
        $this->assertNotSame('pppoe_flap', $d->cause_code);
    }

    public function test_three_modem_restarts_during_network_outages_on_a_zte_line_are_not_power_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $device = $this->device($c);
        // 3 outages of 30 minutes, the customer restarted the modem 20 minutes into the first two; the OLT saw no ONU drop
        foreach ([['00:30', '01:00', '00:50'], ['03:00', '03:30', '03:20'], ['05:00', '05:30', null]] as [$off, $on, $boot]) {
            $this->ppp($c, $router, 'disconnected', $at($off.':00'), 'peer is not responding');
            $this->ppp($c, $router, 'connected', $at($on.':00'));
            if ($boot !== null) {
                $this->cpeEvent($c, $device, 'boot', $at($boot.':00'));
            }
        }

        $ctx = app(OutageCorrelator::class)->contextFor($c, now());
        $this->assertSame([false, false, false], array_column($ctx['history']['ppp_drops'], 'explained'), 'a restart in the middle of a drop does not explain it where the OLT sees power loss');

        $d = $this->diagnose($c);
        $this->assertSame('pppoe_flap', $d->cause_code);
        $this->assertSame('pppoe_history', $d->rule_id);

        // three of them: a reboot loop, never "listrik"
        $this->cpeEvent($c, $device, 'boot', $at('05:20:00'));
        $three = $this->diagnose($c);
        $this->assertNotSame('power_off', $three->cause_code);
    }

    public function test_an_unclosed_drop_and_a_restart_six_hours_later_on_zte_stays_unexplained(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router] = $this->line();
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $this->ppp($c, $router, 'disconnected', $at('00:30:00'));
        $this->cpeEvent($c, $this->device($c), 'boot', $at('06:30:00'));

        $ctx = app(OutageCorrelator::class)->contextFor($c, now());

        $this->assertCount(1, $ctx['history']['ppp_drops']);
        $this->assertNull($ctx['history']['ppp_drops'][0]['end']);
        $this->assertFalse($ctx['history']['ppp_drops'][0]['explained']);
    }

    public function test_three_night_power_cycles_without_an_olt_log_are_power_off_rendah(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c, $router] = $this->line('1/2/3:4', 'hioso_olt'); // no ONU history for this vendor
        $at = fn (string $hm) => Carbon::parse('2026-10-20 '.$hm, 'UTC');
        $device = $this->device($c);
        foreach ([['00:30', '01:32', '01:28'], ['03:00', '04:02', '03:58'], ['05:00', '06:02', '05:58']] as [$off, $on, $boot]) {
            $this->ppp($c, $router, 'disconnected', $at($off.':00'), 'peer is not responding');
            $this->ppp($c, $router, 'connected', $at($on.':00'));
            $this->cpeEvent($c, $device, 'boot', $at($boot.':00'));
        }

        $ctx = app(OutageCorrelator::class)->contextFor($c, now());
        $this->assertCount(3, $ctx['history']['ppp_drops']);
        $this->assertSame([true, true, true], array_column($ctx['history']['ppp_drops'], 'explained'), 'a restart inside the drop explains it');

        $d = $this->diagnose($c);
        $this->assertSame(['power_off', 'rendah'], [$d->cause_code, $d->confidence], 'inferred from restarts alone, no dying gasp');
        $this->assertSame('tanya_listrik', $d->suggested_action);
        $this->assertSame('ONU restart karena listrik mati 3 kali dalam 24 jam.', $this->texts($d)[0]);
    }

    public function test_the_history_adds_two_indexed_reads_and_the_log_and_onu_history_are_each_read_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:20:00', 'UTC'));
        [$c] = $this->line();
        $this->diagnose($c); // warm the schema caches

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->diagnose($c);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // only reads of the table itself (a schema check or the router health model may mention it too)
        $count = fn (string $table) => count(array_filter($log, fn (array $q) => str_contains($q['query'], 'from `'.$table.'`') && str_contains($q['query'], '`customer_id`')));
        $this->assertSame(1, count(array_filter($log, fn (array $q) => in_array('pppoe_down', $q['bindings'], true))), 'one new read: the pppoe_down episodes of the customer');
        $this->assertSame(1, $count('pppoe_session_events'), 'the router log is read once, for 24 hours');
        $this->assertSame(1, count(array_filter($log, fn (array $q) => str_contains($q['query'], 'from `onu_state_events`'))), 'the ONU history is read once (the correlator shares it)');
        // no drops: the area (mass_los / olt_wide) read is skipped
        $this->assertSame(0, count(array_filter($log, fn (array $q) => in_array('olt_wide', $q['bindings'], true) && in_array('mass_los', $q['bindings'], true))));
    }
}
