<?php

namespace Tests\Feature;

use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\Rules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RCA-4 history rules (rules_version 2026-10-21.1), pure: context in, diagnosis out. The line is up NOW but the
 * last 24 hours say "putus-sambung". One fixture per rule of the plan (a) to (l) plus the calibration cases:
 * a single drop never makes a cause, an ONU drop that explains the PPP drops wins, an area outage is not this line's fault.
 */
#[Group('blocking-olt-mysql')] // not blocking-core: that job peaks at 249 of 256 MB (GenieAcsService lowers memory_limit)
class DiagnosisHistoryRulesTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        // 07:20 UTC = 14:20 WIB
        $this->now = Carbon::parse('2026-10-20 07:20:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function classify(array $ctx): array
    {
        return (new OutageDiagnoser(app(OutageCorrelator::class)))->classify($ctx);
    }

    private function ago(int $minutes): Carbon
    {
        return $this->now->copy()->subMinutes($minutes);
    }

    private function onuDrop(int $minutesAgo, ?string $cause = 'los', string $source = 'olt_poll', bool $area = false): array
    {
        return ['at' => $this->ago($minutesAgo), 'cause' => $cause, 'source' => $source, 'area' => $area];
    }

    private function pppDrop(int $minutesAgo, bool $explained = false, array $sources = ['syslog']): array
    {
        return ['at' => $this->ago($minutesAgo), 'explained' => $explained, 'sources' => $sources, 'reason' => null];
    }

    /** Healthy customer, ONU online, internet up, router normal, a ZTE OLT and a router that sends syslog, RX -25.2 read 30 minutes ago. */
    private function ctx(array $o = []): array
    {
        $now = $this->now;
        $base = [
            'now' => $now,
            'customer' => ['id' => 1, 'status' => 'active', 'pppoe_user' => 'user.uji', 'router_id' => 1, 'olt_id' => 1, 'ref' => '1/2/3:4', 'pon' => '1/2/3', 'odp' => null, 'is_online' => true, 'online_checked_at' => $now, 'rx_dbm' => -25.2, 'mikrotik_profile' => 'default'],
            'probes' => [],
            'admin' => ['billing' => false, 'status' => 'active', 'secret_isolir' => false, 'db_isolir' => false, 'secret_disabled' => null],
            'router' => ['id' => 1, 'name' => 'RA-OLT', 'blind' => null, 'offline' => false, 'all_gone' => false, 'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => $now, 'health_fresh' => true, 'active' => null, 'probe_status' => null],
            'olt' => ['id' => 1, 'name' => 'RA', 'family' => 'zte', 'wide' => null, 'blind' => null],
            'pon' => ['mass' => null, 'los_others' => 0, 'total' => 30, 'boot_share' => null],
            'cluster' => ['odp' => null, 'odp_share' => null, 'odp_count' => 0],
            'cpe' => [
                'present' => false, 'device_id' => null, 'model' => null, 'last_inform_at' => null, 'uptime_s' => null, 'uptime_now_s' => null, 'boots_24h' => 0, 'boot_times' => [], 'last_boot_at' => null,
                'wlan' => ['24' => ['status' => null, 'enabled' => null, 'assoc' => null], '5' => ['status' => null, 'enabled' => null, 'assoc' => null]],
                'wlan_at' => null, 'wlan_fresh' => false, 'wlan_strong' => false, 'wlan_source' => null, 'band_verified' => false,
                'ppp_status' => null, 'ppp_last_error' => null, 'ppp_at' => null, 'ppp_fresh' => false, 'rssi' => [], 'lan_active' => null, 'wlan_transitions_6h' => 0, 'wlan_transitions_24h' => 0, 'ppp_reconnects' => [], 'summoned_at' => null,
            ],
            'onu' => [
                'phase' => 'online', 'since' => null, 'collected_at' => $now, 'fresh' => true, 'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false,
                'flapping' => false, 'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null, 'events_24h' => 0, 'dg_in_24h' => false,
                'rx_dbm' => -25.2, 'rx_weak' => false, 'rx_trend' => false, 'olt_probe' => null, 'drops' => [],
            ],
            'pppoe' => ['online' => true, 'fresh' => true, 'at' => $now, 'source' => 'poll', 'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => true, 'router_session_uptime_s' => null, 'router_session' => null, 'events' => []],
            'history' => [
                'onu_drops' => [], 'ppp_drops' => [], 'ppp_source' => 'none', 'ppp_last' => null, 'onu_log' => true, 'ppp_log' => true,
                'rx' => ['dbm' => -25.2, 'at' => $now->copy()->subMinutes(30), 'old' => false], 'ticket_rx_complaint' => false,
            ],
        ];

        return array_replace_recursive($base, $o);
    }

    /** array_replace_recursive merges lists by index: lists of the history block are replaced whole. */
    private function withHistory(array $history, array $o = []): array
    {
        $ctx = $this->ctx($o);
        foreach ($history as $key => $value) {
            $ctx['history'][$key] = $value;
        }

        return $ctx;
    }

    private function texts(array $r): array
    {
        return array_column($r['evidence'], 'text');
    }

    private function cpe(array $o = []): array
    {
        return ['cpe' => array_merge(['present' => true, 'device_id' => 'dev1', 'model' => 'HG6145F3', 'last_inform_at' => $this->ago(3), 'uptime_s' => 400000], $o)];
    }

    // ------------------------------------------------------------------ ONU history (ONU online now)

    public function test_three_los_drops_with_one_from_the_olt_history_is_drop_los_tinggi(): void
    {
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(600), $this->onuDrop(300, 'los', 'olt_detail'), $this->onuDrop(90)]]));

        $this->assertSame('drop_los', $r['cause_code']);
        $this->assertSame('tinggi', $r['confidence']);
        $this->assertSame('onu_history', $r['rule_id']);
        $this->assertSame('ONU putus karena kabel 3 kali dalam 24 jam, terakhir 12:50.', $r['evidence'][0]['text']);
        $this->assertSame('kirim_teknisi_fo', $r['suggested_action']);
    }

    public function test_two_los_drops_from_the_poll_only_is_drop_los_sedang(): void
    {
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(300), $this->onuDrop(90)]]));

        $this->assertSame(['drop_los', 'sedang'], [$r['cause_code'], $r['confidence']]);
    }

    public function test_two_dying_gasps_with_their_restarts_is_power_off(): void
    {
        $boots = [$this->ago(300), $this->ago(90)];
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(301, 'dying_gasp'), $this->onuDrop(91, 'dying_gasp')]], $this->cpe(['boots_24h' => 2, 'boot_times' => $boots])));

        $this->assertSame(['power_off', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertStringStartsWith('ONU mati listrik 2 kali dalam 24 jam', $r['evidence'][0]['text']);

        // two restarts that no dying gasp explains: not "listrik"
        $loose = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(301, 'dying_gasp'), $this->onuDrop(91, 'dying_gasp')]], $this->cpe(['boots_24h' => 3, 'boot_times' => [$this->ago(500), $this->ago(400), $this->ago(200)]])));
        $this->assertSame('cpe_reboot_loop', $loose['cause_code']);
        $this->assertNotSame('onu_history', $loose['rule_id']);
    }

    public function test_three_restarts_that_all_came_with_a_dying_gasp_is_power_at_home_not_a_reboot_loop(): void
    {
        $boots = [$this->ago(900), $this->ago(500), $this->ago(100)];
        $drops = [$this->onuDrop(901, 'dying_gasp'), $this->onuDrop(501, 'dying_gasp'), $this->onuDrop(101, 'dying_gasp')];
        $r = $this->classify($this->withHistory(['onu_drops' => $drops], $this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));

        $this->assertSame(['power_off', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertSame('ONU restart karena listrik mati 3 kali dalam 24 jam.', $r['evidence'][0]['text']);
    }

    public function test_a_single_drop_never_makes_a_cause(): void
    {
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(90)]]));

        $this->assertSame(['unknown', 'rendah'], [$r['cause_code'], $r['confidence']]);
        $this->assertContains('ONU putus karena kabel 1 kali dalam 24 jam, terakhir 12:50.', $this->texts($r));
    }

    public function test_three_drops_without_a_recorded_cause_stay_unknown_with_a_history_line(): void
    {
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(600, null), $this->onuDrop(300, null), $this->onuDrop(90, null)]]));

        $this->assertSame(['unknown', 'rendah'], [$r['cause_code'], $r['confidence']]);
        $this->assertContains('ONU putus 3 kali dalam 24 jam, sebab tidak tercatat, terakhir 12:50.', $this->texts($r));
        $this->assertSame('belum_tahu', $r['suggested_action']);
    }

    public function test_drops_inside_an_area_outage_are_not_this_lines_fault(): void
    {
        $r = $this->classify($this->withHistory(['onu_drops' => [$this->onuDrop(300, 'los', 'olt_poll', true), $this->onuDrop(290, 'los', 'olt_poll', true), $this->onuDrop(280, 'los', 'olt_poll', true)]]));

        $this->assertSame('unknown', $r['cause_code']);
        $this->assertContains('Putus bersamaan dengan pelanggan lain satu PON (09:40).', $this->texts($r));
        $this->assertNotContains('24 jam terakhir tidak ada putus tercatat.', $this->texts($r));
    }

    // ------------------------------------------------------------------ PPPoE history

    public function test_unexplained_reconnects_from_the_router_log_with_a_stable_onu_is_pppoe_flap_sedang(): void
    {
        $r = $this->classify($this->withHistory(['ppp_source' => 'syslog', 'ppp_drops' => [$this->pppDrop(900), $this->pppDrop(500), $this->pppDrop(200), $this->pppDrop(60)]]));

        $this->assertSame(['pppoe_flap', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertSame('pppoe_history', $r['rule_id']);
        $this->assertSame('Internet putus-sambung 4 kali dalam 24 jam, terakhir 13:20.', $r['evidence'][0]['text']);
        $this->assertSame('teruskan_noc', $r['suggested_action']);
    }

    public function test_polled_pppoe_history_only_is_pppoe_flap_rendah(): void
    {
        $r = $this->classify($this->withHistory(['ppp_source' => 'poll', 'ppp_drops' => [$this->pppDrop(600, false, ['episode']), $this->pppDrop(300, false, ['episode']), $this->pppDrop(90, false, ['cpe'])]], ['pppoe' => ['syslog' => false]]));

        $this->assertSame(['pppoe_flap', 'rendah'], [$r['cause_code'], $r['confidence']]);

        $two = $this->classify($this->withHistory(['ppp_source' => 'poll', 'ppp_drops' => [$this->pppDrop(300, false, ['episode']), $this->pppDrop(90, false, ['cpe'])]]));
        $this->assertSame('unknown', $two['cause_code'], 'two rows are not enough');
    }

    public function test_pppoe_drops_that_an_onu_drop_explains_are_drop_los_not_pppoe_flap(): void
    {
        $r = $this->classify($this->withHistory([
            'onu_drops' => [$this->onuDrop(600), $this->onuDrop(300)],
            'ppp_source' => 'syslog',
            'ppp_drops' => [$this->pppDrop(600, true), $this->pppDrop(300, true), $this->pppDrop(299, true)],
        ]));

        $this->assertSame('drop_los', $r['cause_code']);
        $this->assertNotContains('pppoe_flap', $r['secondary']);
    }

    public function test_an_unrelated_onu_drop_no_longer_blocks_the_six_hour_pppoe_flap_rule(): void
    {
        $ctx = $this->withHistory(['onu_drops' => [$this->onuDrop(1000, null)]], ['onu' => ['events_24h' => 1], 'pppoe' => ['online' => false, 'reconnects_6h' => 5, 'reconnects_unexplained_6h' => 5, 'syslog' => true]]);
        $r = $this->classify($ctx);
        $this->assertSame(['pppoe_flap', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertSame('pppoe_down', $r['rule_id']);

        // the same reconnects, all explained by an ONU drop: not a flap
        $explained = $this->classify($this->withHistory([], ['onu' => ['events_24h' => 1], 'pppoe' => ['online' => false, 'reconnects_6h' => 5, 'reconnects_unexplained_6h' => 1, 'syslog' => true]]));
        $this->assertNotSame('pppoe_flap', $explained['cause_code']);
    }

    // ------------------------------------------------------------------ WiFi history

    public function test_wifi_drops_over_24_hours_without_a_restart_is_wlan_flap(): void
    {
        $r = $this->classify($this->withHistory([], $this->cpe(['wlan_transitions_24h' => 3, 'boots_24h' => 0])));

        $this->assertSame(['wlan_flap', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertContains('WiFi putus-sambung 3 kali dalam 24 jam.', $this->texts($r));

        $two = $this->classify($this->withHistory([], $this->cpe(['wlan_transitions_24h' => 2])));
        $this->assertNotSame('wlan_flap', $two['cause_code']);
    }

    // ------------------------------------------------------------------ calibration: nothing in the history

    public function test_a_normal_customer_without_history_stays_unknown_rendah_with_the_history_and_rx_lines(): void
    {
        $r = $this->classify($this->ctx());

        $this->assertSame(['unknown', 'rendah'], [$r['cause_code'], $r['confidence']]);
        $this->assertSame('belum_tahu', $r['suggested_action']);
        $texts = $this->texts($r);
        $this->assertSame('Jaringan normal saat dicek.', $texts[0]);
        $this->assertSame('24 jam terakhir tidak ada putus tercatat.', $texts[1]);
        $this->assertSame('Redaman -25,2 dBm (normal), data 13:50.', $texts[2]);
        $this->assertLessThanOrEqual(8, count($texts));
    }

    public function test_a_router_without_a_log_and_no_cpe_says_the_history_is_not_recorded(): void
    {
        $r = $this->classify($this->withHistory(['ppp_log' => false]));

        $this->assertSame('unknown', $r['cause_code']);
        $this->assertContains('Riwayat putus internet belum tercatat untuk router ini.', $this->texts($r));
        $this->assertNotContains('24 jam terakhir tidak ada putus tercatat.', $this->texts($r));

        // a CPE that is known to the ACS is a history source of its own
        $withCpe = $this->classify($this->withHistory(['ppp_log' => false], $this->cpe()));
        $this->assertContains('24 jam terakhir tidak ada putus tercatat.', $this->texts($withCpe));
    }

    public function test_an_olt_without_history_says_so(): void
    {
        $r = $this->classify($this->withHistory(['onu_log' => false], ['olt' => ['family' => null]]));

        $this->assertContains('Riwayat putus ONU belum tercatat untuk OLT ini.', $this->texts($r));
        $this->assertNotContains('24 jam terakhir tidak ada putus tercatat.', $this->texts($r));
    }

    // ------------------------------------------------------------------ RX line

    public function test_rx_data_older_than_a_day_is_marked_old_with_its_day(): void
    {
        $r = $this->classify($this->withHistory(['rx' => ['dbm' => -22.0, 'at' => $this->ago(3 * 1440), 'old' => true]]));

        $this->assertContains('Redaman -22 dBm, data lama (17/10 14:20).', $this->texts($r));
    }

    public function test_a_weak_rx_keeps_the_optical_path_and_shows_one_rx_line(): void
    {
        // 2026-10-26.1: a weak RX alone is a note; with a step in a day it is the answer
        $step = ['from' => -22.0, 'to' => -29.5, 'drop' => 7.5, 'date' => '2026-10-19'];
        $r = $this->classify($this->withHistory(['rx' => ['dbm' => -29.5, 'at' => $this->ago(30), 'old' => false]], ['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5, 'rx_step' => $step]]));

        $this->assertSame(['optical_degrade', 'sedang'], [$r['cause_code'], $r['confidence']]);
        $this->assertSame(1, count(array_keys($this->texts($r), 'Redaman -29,5 dBm, batas -27 dBm.')));
        $alone = $this->classify($this->withHistory(['rx' => ['dbm' => -29.5, 'at' => $this->ago(30), 'old' => false]], ['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5]]));
        $this->assertSame(['unknown', 'rendah'], [$alone['cause_code'], $alone['confidence']]);
        $this->assertContains('optical_degrade', $alone['secondary']);
    }

    public function test_a_redaman_complaint_with_a_normal_rx_gets_a_note_only_then(): void
    {
        $with = $this->classify($this->withHistory(['ticket_rx_complaint' => true]));
        $without = $this->classify($this->withHistory(['ticket_rx_complaint' => false]));
        $weak = $this->classify($this->withHistory(['ticket_rx_complaint' => true, 'rx' => ['dbm' => -29.5, 'at' => $this->ago(30), 'old' => false]], ['onu' => ['rx_weak' => true]]));

        $this->assertContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($with));
        $this->assertNotContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($without));
        $this->assertNotContains('Keluhan redaman, tapi redaman sekarang normal.', $this->texts($weak));
    }

    // ------------------------------------------------------------------ times: UTC stored, WIB shown

    public function test_times_are_shown_in_wib_across_the_wib_midnight(): void
    {
        // 17:10 UTC = 00:10 WIB of the 22nd; the drop at 16:40 UTC is 23:40 WIB of the 21st (yesterday for the reader)
        Carbon::setTestNow(Carbon::parse('2026-10-21 17:10:00', 'UTC'));
        $this->now = now();
        $drops = [$this->onuDrop(30), $this->onuDrop(10)];
        $r = $this->classify($this->withHistory(['onu_drops' => $drops, 'rx' => ['dbm' => -25.2, 'at' => $this->ago(40), 'old' => false]]));

        $texts = $this->texts($r);
        $this->assertContains('ONU putus karena kabel 2 kali dalam 24 jam, terakhir 00:00.', $texts);
        $this->assertContains('Redaman -25,2 dBm (normal), data 21/10 23:30.', $texts, 'a time of the day before reads with its date');
        $this->assertSame('2026-10-22T00:00:00+07:00', $r['evidence'][0]['at']);
    }

    public function test_a_weak_rx_beats_the_24_hour_wifi_rule(): void
    {
        // a STRONG optical sign (steady decline) beats it; a stable weak RX alone no longer does (2026-10-26.1)
        $r = $this->classify($this->withHistory(['rx' => ['dbm' => -29.5, 'at' => $this->ago(30), 'old' => false]], $this->cpe(['wlan_transitions_24h' => 4]) + ['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5, 'rx_decline' => ['from' => -26.0, 'to' => -29.5]]]));

        $this->assertSame('optical_degrade', $r['cause_code']);
        $stable = $this->classify($this->withHistory(['rx' => ['dbm' => -29.5, 'at' => $this->ago(30), 'old' => false]], $this->cpe(['wlan_transitions_24h' => 4]) + ['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5]]));
        $this->assertSame('wlan_flap', $stable['cause_code']);
    }

    public function test_sedang_needs_the_routers_word_on_two_drops_not_one_live_read(): void
    {
        $one = $this->classify($this->withHistory(['ppp_source' => 'poll', 'ppp_drops' => [$this->pppDrop(600, false, ['episode', 'cpe']), $this->pppDrop(300, false, ['episode']), $this->pppDrop(90, false, ['episode', 'router_live'])]]));
        $this->assertSame(['pppoe_flap', 'rendah'], [$one['cause_code'], $one['confidence']], 'a live read confirms only the newest drop');

        $two = $this->classify($this->withHistory(['ppp_source' => 'poll', 'ppp_drops' => [$this->pppDrop(600, false, ['episode', 'router_secret']), $this->pppDrop(300, false, ['episode']), $this->pppDrop(90, false, ['episode', 'router_live'])]]));
        $this->assertSame(['pppoe_flap', 'sedang'], [$two['cause_code'], $two['confidence']]);
    }

    public function test_three_restarts_that_each_follow_a_long_session_drop_are_power_off_not_a_reboot_loop_or_a_flap(): void
    {
        // night power cycles on an OLT without history (HiOSO): the session is gone 60 minutes, the ONU boots 58 minutes in
        $boots = [$this->ago(400), $this->ago(250), $this->ago(100)];
        $drops = [];
        foreach ([460, 310, 160] as $i => $startMin) {
            $drops[] = ['at' => $this->ago($startMin), 'end' => $this->ago($startMin - 62), 'explained' => true, 'sources' => ['syslog'], 'reason' => null];
        }
        $r = $this->classify($this->withHistory(['onu_log' => false, 'ppp_source' => 'syslog', 'ppp_drops' => $drops], $this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));

        $this->assertSame(['power_off', 'rendah'], [$r['cause_code'], $r['confidence']], 'inferred from restarts alone');
        $this->assertSame('tanya_listrik', $r['suggested_action']);
        $this->assertSame('ONU restart karena listrik mati 3 kali dalam 24 jam.', $r['evidence'][0]['text']);

        // the same pattern on an OLT that keeps an ONU log and recorded no ONU drop: the customer restarted the modem
        $zte = $this->classify($this->withHistory(['onu_log' => true, 'ppp_source' => 'syslog', 'ppp_drops' => $drops], $this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));
        $this->assertSame('cpe_reboot_loop', $zte['cause_code']);

        // ... unless an ONU drop was recorded between the drop and each restart
        $withOnu = [];
        foreach ([455, 305, 155] as $min) {
            $withOnu[] = $this->onuDrop($min, 'offline', 'olt_poll');
        }
        $seen = $this->classify($this->withHistory(['onu_log' => true, 'onu_drops' => $withOnu, 'ppp_source' => 'syslog', 'ppp_drops' => $drops], $this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));
        $this->assertSame(['power_off', 'rendah'], [$seen['cause_code'], $seen['confidence']]);

        // a restart AT the moment the session dropped is a real reboot loop
        $loop = [];
        foreach ([400, 250, 100] as $startMin) {
            $loop[] = ['at' => $this->ago($startMin + 1), 'end' => $this->ago($startMin - 2), 'explained' => true, 'sources' => ['syslog'], 'reason' => null];
        }
        $l = $this->classify($this->withHistory(['ppp_source' => 'syslog', 'ppp_drops' => $loop], $this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));
        $this->assertSame('cpe_reboot_loop', $l['cause_code']);
    }

    public function test_the_rules_version_is_bumped_and_the_new_rules_are_in_the_table(): void
    {
        $this->assertSame('2026-10-28.1', Rules::RULES_VERSION);
        $this->assertSame('2026-10-21.1', Rules::RULES_VERSION_LEGACY);
        $ids = array_keys(Rules::table());
        $this->assertSame(array_search('onu_reboot', $ids) + 1, array_search('onu_history', $ids));
        $this->assertSame(array_search('pppoe_down', $ids) + 1, array_search('pppoe_history', $ids));
    }
}
