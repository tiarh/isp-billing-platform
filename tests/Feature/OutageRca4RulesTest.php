<?php

namespace Tests\Feature;

use App\Enums\OutageRootCause as Cause;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageCauseText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RCA-4 rule table (pure: context in, diagnosis out, no database). One fixture per class, plus the conflict cases:
 * router blind + OLT LOS, area power vs reboot loop, stale WiFi values never "tinggi".
 */
#[Group('blocking-core')]
class OutageRca4RulesTest extends TestCase
{
    use RefreshDatabase; // the optical rule reads weak_rx_dbm from the settings

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-18 03:00:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function diagnoser(): OutageDiagnoser
    {
        return new OutageDiagnoser(app(OutageCorrelator::class));
    }

    /** A healthy customer: ONU online, internet session up, router normal, nothing special. */
    private function ctx(array $o = []): array
    {
        $now = $this->now;
        $base = [
            'now' => $now,
            'customer' => ['id' => 1, 'status' => 'active', 'pppoe_user' => 'user.uji', 'router_id' => 1, 'olt_id' => 1, 'ref' => '1/2/3:4', 'pon' => '1/2/3', 'odp' => null, 'is_online' => true, 'online_checked_at' => $now, 'rx_dbm' => -20.0, 'mikrotik_profile' => 'default'],
            'probes' => [],
            'admin' => ['billing' => false, 'status' => 'active', 'secret_isolir' => false, 'db_isolir' => false, 'secret_disabled' => null],
            'router' => ['id' => 1, 'name' => 'RA-OLT', 'blind' => null, 'offline' => false, 'all_gone' => false, 'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => $now, 'health_fresh' => true, 'active' => null, 'probe_status' => null],
            'olt' => ['id' => 1, 'name' => 'RA', 'family' => 'zte', 'wide' => null, 'blind' => null],
            'pon' => ['mass' => null, 'los_others' => 0, 'total' => 30, 'boot_share' => null, 'down_now' => 0, 'known_total' => 30, 'recovered' => 0, 'event_at' => null],
            'cluster' => ['odp' => null, 'odp_share' => null, 'odp_count' => 0, 'odp_down_now' => 0],
            'cpe' => [
                'present' => false, 'device_id' => null, 'model' => null, 'last_inform_at' => null, 'uptime_s' => null, 'uptime_now_s' => null, 'boots_24h' => 0, 'boot_times' => [], 'last_boot_at' => null,
                'wlan' => ['24' => ['status' => null, 'enabled' => null, 'assoc' => null], '5' => ['status' => null, 'enabled' => null, 'assoc' => null]],
                'wlan_at' => null, 'wlan_fresh' => false, 'wlan_strong' => false, 'wlan_source' => null, 'band_verified' => false,
                'ppp_status' => null, 'ppp_last_error' => null, 'ppp_at' => null, 'ppp_fresh' => false, 'rssi' => [], 'lan_active' => null, 'wlan_transitions_6h' => 0, 'summoned_at' => null,
            ],
            'onu' => [
                'phase' => 'online', 'since' => null, 'collected_at' => $now, 'fresh' => true, 'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false,
                'flapping' => false, 'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null, 'events_24h' => 0, 'dg_in_24h' => false,
                'rx_dbm' => -20.0, 'rx_weak' => false, 'rx_trend' => false, 'olt_probe' => null,
            ],
            'pppoe' => ['online' => true, 'fresh' => true, 'at' => $now, 'source' => 'poll', 'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => false, 'router_session_uptime_s' => null, 'router_session' => null],
        ];

        return array_replace_recursive($base, $o);
    }

    private function cpe(array $o = []): array
    {
        return ['cpe' => array_merge(['present' => true, 'device_id' => 'dev1', 'model' => 'HG6145F3', 'last_inform_at' => $this->now->copy()->subMinutes(3), 'uptime_s' => 400000], $o)];
    }

    private function down(string $phase, int $minutesAgo, array $o = []): array
    {
        return ['onu' => array_merge(['phase' => $phase, 'since' => $this->now->copy()->subMinutes($minutesAgo)], $o)];
    }

    private function classify(array $ctx): array
    {
        return $this->diagnoser()->classify($ctx);
    }

    private function assertCause(string $cause, string $confidence, array $r, string $why = ''): void
    {
        $this->assertSame($cause, $r['cause_code'], $why.' cause');
        $this->assertSame($confidence, $r['confidence'], $why.' confidence');
    }

    // ------------------------------------------------------------------ fallbacks

    public function test_a_healthy_customer_is_unknown_with_the_normal_network_text(): void
    {
        $r = $this->classify($this->ctx());

        $this->assertCause('unknown', 'rendah', $r);
        $this->assertSame('Jaringan normal saat dicek.', $r['evidence'][0]['text']);
        $this->assertSame('belum_tahu', $r['suggested_action']);
    }

    public function test_missing_onu_data_is_unknown_not_a_fault(): void
    {
        $r = $this->classify($this->ctx(['onu' => ['phase' => null, 'fresh' => false, 'collected_at' => null]]));

        $this->assertCause('unknown', 'rendah', $r);
        $this->assertStringContainsString('belum terbaca', $r['evidence'][0]['text']);
    }

    public function test_upstream_is_listed_as_needs_data_and_never_diagnosed(): void
    {
        $table = Rules::table();

        $this->assertTrue($table['upstream']['needs_data']);
        $this->assertNull($table['upstream']['run']);
        $r = $this->classify($this->ctx(['router' => ['offline' => true, 'all_gone' => true, 'blind' => ['reason' => 'router_offline']]]));
        $this->assertNotSame('upstream', $r['cause_code']);
    }

    // ------------------------------------------------------------------ 1. billing / admin

    public function test_isolir_cuti_and_terminated_status_is_isolir_admin(): void
    {
        // terminated stays tinggi; isolated / cuti with an ONU that is down for 90 minutes and no cause: sedang + the ONU line
        foreach (['isolated', 'suspended', 'terminated'] as $status) {
            $r = $this->classify($this->ctx(['customer' => ['status' => $status], 'admin' => ['billing' => true, 'status' => $status, 'db_isolir' => true], ...$this->down('offline', 90)]));
            $this->assertCause('isolir_admin', $status === 'terminated' ? 'tinggi' : 'sedang', $r, $status);
            $this->assertSame('cek_tagihan', $r['suggested_action']);
            if ($status !== 'terminated') {
                $this->assertStringContainsString('ONU juga mati sejak', collect($r['evidence'])->pluck('text')->implode(' | '), $status);
            }
        }
    }

    public function test_a_disabled_secret_is_tinggi_and_a_database_only_isolir_profile_is_rendah(): void
    {
        $this->assertCause('isolir_admin', 'tinggi', $this->classify($this->ctx(['admin' => ['secret_isolir' => true, 'secret_state' => 'isolated']])));
        $this->assertCause('isolir_admin', 'rendah', $this->classify($this->ctx(['admin' => ['db_isolir' => true]])));
    }

    // ------------------------------------------------------------------ 2. router / 3. OLT / 4. PON

    public function test_router_offline_with_all_pppoe_gone_is_core_router_and_beats_everything_below(): void
    {
        $r = $this->classify($this->ctx(['router' => ['offline' => true, 'all_gone' => true, 'blind' => ['reason' => 'router_offline']], ...$this->down('offline', 90)]));

        $this->assertCause('core_router', 'tinggi', $r);
        $this->assertSame('gangguan_besar', $r['suggested_action']);
        $this->assertStringContainsString('RA-OLT', $r['evidence'][0]['text']);
    }

    public function test_router_offline_without_proof_that_all_sessions_are_gone_is_only_data_blind(): void
    {
        $r = $this->classify($this->ctx(['router' => ['offline' => true, 'all_gone' => false, 'blind' => ['reason' => 'router_offline']]]));

        $this->assertCause('data_blind', 'sedang', $r);
        $this->assertSame('cek_manual', $r['suggested_action']);
    }

    public function test_olt_wide_episode_is_olt_down(): void
    {
        $r = $this->classify($this->ctx(['olt' => ['wide' => ['id' => 5, 'since' => $this->now->copy()->subMinutes(10), 'seen' => $this->now->copy()->subMinutes(2)]], ...$this->down('offline', 10)]));

        $this->assertCause('olt_down', 'tinggi', $r);
    }

    public function test_olt_wide_does_not_claim_a_customer_whose_onu_is_online(): void
    {
        $r = $this->classify($this->ctx(['olt' => ['wide' => ['id' => 5, 'since' => $this->now->copy()->subMinutes(10), 'seen' => $this->now]]]));

        $this->assertNotSame('olt_down', $r['cause_code']);
    }

    public function test_olt_collector_blind_is_data_blind(): void
    {
        $r = $this->classify($this->ctx(['olt' => ['blind' => ['id' => 9, 'reason' => 'collector_stale']], 'onu' => ['phase' => 'unknown', 'fresh' => false]]));

        $this->assertCause('data_blind', 'sedang', $r);
    }

    public function test_mass_los_on_the_pon_is_pon_cut_and_odp_cut_when_limited_to_one_odp(): void
    {
        $mass = ['id' => 7, 'includes' => true, 'count' => 28, 'total' => 30, 'ratio' => 28 / 30, 'whole' => true, 'confirmed' => true, 'state' => 'confirmed', 'since' => $this->now->copy()->subMinutes(12), 'seen' => $this->now->copy()->subMinutes(1), 'ids' => [1]];
        $r = $this->classify($this->ctx(['pon' => ['mass' => $mass, 'down_now' => 28, 'known_total' => 30], ...$this->down('los', 12)]));
        $this->assertCause('pon_cut', 'tinggi', $r);
        $this->assertSame('kirim_teknisi_fo', $r['suggested_action']);

        $part = $mass + [];
        $part['count'] = 8;
        $part['ratio'] = 8 / 30;
        $part['whole'] = false;
        $this->assertCause('pon_cut', 'sedang', $this->classify($this->ctx(['pon' => ['mass' => $part, 'down_now' => 8, 'known_total' => 30], ...$this->down('los', 12)])));

        $odp = $this->classify($this->ctx(['pon' => ['mass' => $part, 'down_now' => 8, 'known_total' => 30], 'cluster' => ['odp' => 'ODP-07', 'odp_share' => 0.9, 'odp_count' => 7, 'odp_down_now' => 7], ...$this->down('los', 12)]));
        $this->assertCause('odp_cut', 'sedang', $odp);
        $this->assertStringContainsString('ODP-07', $odp['evidence'][0]['text']);
    }

    // ------------------------------------------------------------------ 5. ONU offline

    public function test_single_los_with_few_other_los_is_drop_los(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 5, ['cause' => 'los', 'cause_fresh' => true, 'cause_source' => 'olt_poll', 'cause_at' => $this->now]) + ['pon' => ['los_others' => 1, 'down_now' => 2]]));

        $this->assertCause('drop_los', 'tinggi', $r);
        $this->assertSame('kirim_teknisi_fo', $r['suggested_action']);
        $this->assertStringContainsString('LOS', $r['evidence'][0]['text']);
    }

    public function test_a_los_cause_that_is_not_fresh_is_only_sedang(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 50, ['cause' => 'los', 'cause_fresh' => false, 'cause_source' => 'olt_poll'])));

        $this->assertCause('drop_los', 'sedang', $r);
    }

    public function test_many_other_los_on_the_pon_without_an_episode_is_pon_cut_sedang(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 5, ['cause' => 'los', 'cause_fresh' => true]) + ['pon' => ['los_others' => 4, 'down_now' => 5]]));

        $this->assertCause('pon_cut', 'sedang', $r);
    }

    public function test_dying_gasp_is_power_off_and_a_day_long_one_adds_cpe_dead(): void
    {
        $fresh = $this->classify($this->ctx($this->down('dying_gasp', 20, ['cause' => 'dying_gasp', 'cause_fresh' => true, 'cause_source' => 'olt_poll'])));
        $this->assertCause('power_off', 'tinggi', $fresh);
        $this->assertSame('tanya_listrik', $fresh['suggested_action']);
        $this->assertSame([], $fresh['secondary']);

        $long = $this->classify($this->ctx($this->down('dying_gasp', 60 * 30, ['cause' => 'dying_gasp', 'cause_fresh' => true])));
        $this->assertCause('power_off', 'tinggi', $long);
        $this->assertContains('cpe_dead', $long['secondary']);
    }

    public function test_offline_without_cause_is_cpe_dead_only_after_30_minutes_and_a_silent_acs(): void
    {
        $silent = $this->classify($this->ctx($this->down('offline', 45) + $this->cpe(['last_inform_at' => $this->now->copy()->subHours(2)])));
        $this->assertCause('cpe_dead', 'sedang', $silent);
        $this->assertSame('teknisi_onu_pengganti', $silent['suggested_action']);

        $noAcs = $this->classify($this->ctx($this->down('offline', 45)));
        $this->assertCause('cpe_dead', 'rendah', $noAcs);

        $recent = $this->classify($this->ctx($this->down('offline', 10)));
        $this->assertCause('unknown', 'rendah', $recent);
        $this->assertStringContainsString('baru putus', $recent['evidence'][0]['text']);

        $talking = $this->classify($this->ctx($this->down('offline', 45) + $this->cpe(['last_inform_at' => $this->now->copy()->subMinutes(2)])));
        $this->assertNotSame('cpe_dead', $talking['cause_code'], 'an ONU that still informs the ACS is not dead');
    }

    public function test_a_huawei_onu_that_never_registered_is_unknown_with_the_plain_reason(): void
    {
        $r = $this->classify($this->ctx($this->down('offline', 600, ['never_registered' => true, 'cause' => 'never_registered', 'cause_source' => 'huawei_info'])));

        $this->assertCause('unknown', 'rendah', $r);
        $this->assertSame('ONU belum pernah aktif di OLT.', $r['evidence'][0]['text']);
    }

    // ------------------------------------------------------------------ 6. reboot loop / area power

    public function test_three_restarts_in_a_day_is_a_reboot_loop(): void
    {
        $boots = [$this->now->copy()->subHours(20), $this->now->copy()->subHours(10), $this->now->copy()->subHours(1)];
        $r = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots])));

        $this->assertCause('cpe_reboot_loop', 'tinggi', $r);
        $this->assertStringContainsString('3 kali', $r['evidence'][0]['text']);

        $withDg = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots]) + ['onu' => ['dg_in_24h' => true]]));
        $this->assertCause('cpe_reboot_loop', 'sedang', $withDg);

        $huawei = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots]) + ['onu' => ['dg_in_24h' => true, 'online_seconds' => 3600, 'system_up_seconds' => 3650]]));
        $this->assertCause('cpe_reboot_loop', 'tinggi', $huawei);

        $two = $this->classify($this->ctx($this->cpe(['boots_24h' => 2, 'boot_times' => [$boots[0], $boots[1]]])));
        $this->assertNotSame('cpe_reboot_loop', $two['cause_code']);
    }

    public function test_many_onus_of_the_same_pon_restarting_together_is_area_power_not_a_reboot_loop(): void
    {
        $boots = [$this->now->copy()->subHours(20), $this->now->copy()->subHours(10), $this->now->copy()->subHours(1)];
        $r = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots]) + ['pon' => ['boot_share' => ['count' => 9, 'total' => 30, 'share' => 0.3, 'at' => $boots[2]]]]));

        $this->assertCause('power_off', 'sedang', $r);
        $this->assertStringContainsString('9 dari 30', $r['evidence'][0]['text']);

        $few = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots]) + ['pon' => ['boot_share' => ['count' => 2, 'total' => 30, 'share' => 0.07, 'at' => $boots[2]]]]));
        $this->assertCause('cpe_reboot_loop', 'tinggi', $few);
    }

    // ------------------------------------------------------------------ 7. PPPoE

    public function test_already_active_three_times_is_pppoe_stuck_and_an_older_router_session_is_sedang(): void
    {
        $log = $this->classify($this->ctx(['pppoe' => ['online' => false, 'stuck_count' => 3, 'stuck_at' => $this->now, 'syslog' => true]]));
        $this->assertCause('pppoe_stuck', 'tinggi', $log);
        $this->assertSame('teruskan_noc', $log['suggested_action']);
        $this->assertStringContainsString('sudah aktif', $log['evidence'][0]['text']);

        $session = $this->classify($this->ctx($this->cpe(['uptime_now_s' => 3000]) + ['pppoe' => ['online' => true, 'source' => 'router_live', 'router_session_uptime_s' => 90000]]));
        $this->assertCause('pppoe_stuck', 'sedang', $session);

        $normal = $this->classify($this->ctx($this->cpe(['uptime_now_s' => 400000]) + ['pppoe' => ['online' => true, 'source' => 'router_live', 'router_session_uptime_s' => 7800]]));
        $this->assertNotSame('pppoe_stuck', $normal['cause_code']);
    }

    public function test_a_healthy_session_is_not_stuck_when_the_stored_uptime_is_stale(): void
    {
        // the ACS uptime is refreshed hourly: 40 minutes stale it says 100 s, the ONU really booted 2 h ago and the
        // session started a minute after the boot (7140 s). Without an "as of now" uptime the rule must not fire.
        $stale = $this->classify($this->ctx($this->cpe(['uptime_s' => 100]) + ['pppoe' => ['online' => true, 'source' => 'router_live', 'router_session_uptime_s' => 7140]]));
        $this->assertNotSame('pppoe_stuck', $stale['cause_code']);

        // with the boot time known (uptime as of now = 7200 s) the same session is simply younger than the ONU
        $known = $this->classify($this->ctx($this->cpe(['uptime_s' => 100, 'uptime_now_s' => 7200]) + ['pppoe' => ['online' => true, 'source' => 'router_live', 'router_session_uptime_s' => 7140]]));
        $this->assertNotSame('pppoe_stuck', $known['cause_code']);
    }

    public function test_syslog_evidence_still_counts_while_the_router_poll_is_blind(): void
    {
        $r = $this->classify($this->ctx(['router' => ['blind' => ['reason' => 'snapshot_stale']], 'pppoe' => ['online' => false, 'stuck_count' => 3, 'stuck_at' => $this->now, 'syslog' => true]]));

        $this->assertCause('pppoe_stuck', 'tinggi', $r);
    }

    public function test_a_single_los_with_no_other_los_says_so_in_plain_words(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 5, ['cause' => 'los', 'cause_fresh' => true])));

        $this->assertContains('Tidak ada ONU lain di PON ini yang baru putus.', array_column($r['evidence'], 'text'));
    }

    public function test_login_failures_are_pppoe_auth(): void
    {
        $log = $this->classify($this->ctx(['pppoe' => ['online' => false, 'auth_failed_at' => $this->now->copy()->subMinutes(5), 'syslog' => true]]));
        $this->assertCause('pppoe_auth', 'tinggi', $log);

        $cpe = $this->classify($this->ctx($this->cpe(['ppp_fresh' => true, 'ppp_at' => $this->now->copy()->subMinutes(5), 'ppp_last_error' => 'ERROR_AUTHENTICATION_FAILURE']) + ['pppoe' => ['online' => false]]));
        $this->assertCause('pppoe_auth', 'tinggi', $cpe);

        $old = $this->classify($this->ctx($this->cpe(['ppp_fresh' => true, 'ppp_at' => $this->now->copy()->subMinutes(90), 'ppp_last_error' => 'ERROR_AUTHENTICATION_FAILURE']) + ['pppoe' => ['online' => false]]));
        $this->assertCause('pppoe_auth', 'sedang', $old);
    }

    public function test_many_reconnects_with_a_stable_onu_is_pppoe_flap(): void
    {
        $r = $this->classify($this->ctx(['pppoe' => ['online' => false, 'reconnects_6h' => 5, 'syslog' => true]]));
        $this->assertCause('pppoe_flap', 'sedang', $r);

        $boot = $this->classify($this->ctx($this->cpe(['boots_24h' => 1, 'boot_times' => [$this->now->copy()->subHours(2)]]) + ['pppoe' => ['online' => false, 'reconnects_6h' => 5, 'syslog' => true]]));
        $this->assertNotSame('pppoe_flap', $boot['cause_code'], 'a restarted ONU explains the reconnects');
    }

    public function test_onu_online_but_internet_down_without_a_clue_is_unknown(): void
    {
        $r = $this->classify($this->ctx(['pppoe' => ['online' => false]]));

        $this->assertCause('unknown', 'rendah', $r);
        $this->assertStringContainsString('ONU menyala', $r['evidence'][0]['text']);
    }

    public function test_a_blind_router_never_makes_a_pppoe_fault(): void
    {
        $r = $this->classify($this->ctx(['router' => ['blind' => ['reason' => 'snapshot_stale']], 'pppoe' => ['online' => false, 'stuck_count' => 0, 'reconnects_6h' => 9]]));

        $this->assertCause('data_blind', 'sedang', $r);
    }

    // ------------------------------------------------------------------ 8. WiFi

    private function wlan(array $o = []): array
    {
        return $this->cpe(array_merge([
            'wlan' => ['24' => ['status' => 'Up', 'enabled' => true, 'assoc' => 2], '5' => ['status' => 'Disabled', 'enabled' => false, 'assoc' => 0]],
            'wlan_at' => $this->now->copy()->subMinutes(5), 'wlan_fresh' => true, 'wlan_strong' => true, 'wlan_source' => 'summon', 'band_verified' => true,
        ], $o));
    }

    public function test_a_dead_radio_with_a_live_internet_session_is_wlan_down_and_tinggi_only_from_a_fresh_verified_check(): void
    {
        $r = $this->classify($this->ctx($this->wlan()));
        $this->assertCause('wlan_down', 'tinggi', $r);
        $this->assertSame('bantu_wifi', $r['suggested_action']);
        $this->assertStringContainsString('5 GHz', $r['evidence'][0]['text']);

        $unverified = $this->classify($this->ctx($this->wlan(['band_verified' => false])));
        $this->assertCause('wlan_down', 'sedang', $unverified, 'band mapping not verified');

        $hourly = $this->classify($this->ctx($this->wlan(['wlan_strong' => false, 'wlan_source' => 'sync'])));
        $this->assertCause('wlan_down', 'sedang', $hourly, 'hourly sync value, not a check');
        $this->assertStringContainsString('bukan cek langsung', $hourly['evidence'][0]['text']);
    }

    public function test_stale_wifi_values_are_never_a_wlan_down_fault(): void
    {
        $r = $this->classify($this->ctx($this->wlan(['wlan_fresh' => false, 'wlan_strong' => false, 'wlan_at' => $this->now->copy()->subDays(20)])));

        $this->assertCause('unknown', 'rendah', $r);
        $this->assertNotSame('wlan_down', $r['cause_code']);
    }

    public function test_wifi_rules_degrade_to_unknown_when_the_rca3_data_is_absent(): void
    {
        // no CPE at all (RCA-3 tables / columns missing): only "unknown", never a guess
        $r = $this->classify($this->ctx());

        $this->assertCause('unknown', 'rendah', $r);
    }

    public function test_repeated_wifi_drops_with_a_stable_onu_is_wlan_flap(): void
    {
        $r = $this->classify($this->ctx($this->cpe(['wlan_transitions_6h' => 3])));
        $this->assertCause('wlan_flap', 'sedang', $r);
        $this->assertSame('restart_onu', $r['suggested_action']);

        $booted = $this->classify($this->ctx($this->cpe(['wlan_transitions_6h' => 3, 'boot_times' => [$this->now->copy()->subHours(1)], 'boots_24h' => 1])));
        $this->assertNotSame('wlan_flap', $booted['cause_code']);
    }

    public function test_weak_client_signal_with_a_healthy_network_is_wifi_client(): void
    {
        $up = ['24' => ['status' => 'Up', 'enabled' => true, 'assoc' => 3], '5' => ['status' => 'Up', 'enabled' => true, 'assoc' => 0]];
        $r = $this->classify($this->ctx($this->wlan(['wlan' => $up, 'rssi' => [-82, -79, -60]])));
        $this->assertCause('wifi_client', 'sedang', $r);
        $this->assertSame('masalah_hp', $r['suggested_action']);

        $good = $this->classify($this->ctx($this->wlan(['wlan' => $up, 'rssi' => [-50, -55, -60]])));
        $this->assertCause('unknown', 'rendah', $good);
    }

    // ------------------------------------------------------------------ 9. optical

    public function test_weak_optical_power_is_secondary_next_to_a_cut_and_primary_only_as_a_last_resort(): void
    {
        $withLos = $this->classify($this->ctx($this->down('los', 5, ['cause' => 'los', 'cause_fresh' => true, 'rx_weak' => true, 'rx_dbm' => -29.5])));
        $this->assertCause('drop_los', 'tinggi', $withLos);
        $this->assertContains('optical_degrade', $withLos['secondary']);
        $this->assertSame('drop_los', $withLos['cause_code']);

        // 2026-10-26.1: weak RX alone is only a note; it becomes the answer with a step / decline / repeated drops
        $weakOnly = $this->classify($this->ctx(['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5]]));
        $this->assertCause('unknown', 'rendah', $weakOnly);
        $this->assertContains('optical_degrade', $weakOnly['secondary']);
        $alone = $this->classify($this->ctx(['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5, 'rx_step' => ['from' => -22.0, 'to' => -29.5, 'drop' => 7.5, 'date' => '2026-10-19']]]));
        $this->assertCause('optical_degrade', 'sedang', $alone);
        $this->assertStringContainsString('-29,5', $alone['evidence'][0]['text']);

        $offline = $this->classify($this->ctx($this->down('offline', 10, ['rx_weak' => true])));
        $this->assertNotSame('optical_degrade', $offline['cause_code']);
    }

    // ------------------------------------------------------------------ conflicts

    public function test_a_blind_router_does_not_hide_an_explicit_olt_los(): void
    {
        $r = $this->classify($this->ctx(['router' => ['blind' => ['reason' => 'snapshot_stale']]] + $this->down('los', 5, ['cause' => 'los', 'cause_fresh' => true, 'cause_source' => 'olt_poll'])));

        $this->assertCause('drop_los', 'tinggi', $r);
    }

    public function test_confidence_drops_to_sedang_when_a_second_decisive_rule_disagrees(): void
    {
        $boots = [$this->now->copy()->subHours(20), $this->now->copy()->subHours(10), $this->now->copy()->subHours(1)];
        $r = $this->classify($this->ctx($this->cpe(['boots_24h' => 3, 'boot_times' => $boots]) + ['pppoe' => ['online' => false, 'stuck_count' => 3, 'stuck_at' => $this->now]]));

        $this->assertSame('cpe_reboot_loop', $r['cause_code']);
        $this->assertSame('sedang', $r['confidence'], 'tinggi needs "no conflict"');
        $this->assertContains('pppoe_stuck', $r['secondary']);
    }

    public function test_evidence_is_capped_ordered_and_plain_indonesian(): void
    {
        $r = $this->classify($this->ctx($this->wlan(['wlan_transitions_6h' => 3]) + ['pppoe' => ['source' => 'router_live', 'router_session_uptime_s' => 7800], 'onu' => ['rx_weak' => true, 'rx_dbm' => -29.0]]));

        $this->assertLessThanOrEqual(8, count($r['evidence']));
        foreach ($r['evidence'] as $item) {
            $this->assertSame(['layer', 'source', 'at', 'text', 'fresh'], array_keys($item));
            $this->assertNotSame('', $item['text']);
        }
        $this->assertSame('wlan_down', $r['cause_code']);
        $this->assertStringStartsWith('WiFi', $r['evidence'][0]['text'], 'the deciding evidence comes first');
        $this->assertStringContainsString('Router RA-OLT normal, sesi internet aktif sejak', collect($r['evidence'])->pluck('text')->implode(' | '));
    }

    public function test_every_taxonomy_code_has_a_suggested_action(): void
    {
        foreach (Cause::cases() as $case) {
            $action = OutageCauseText::actionFor($case->value);
            $this->assertNotSame('', OutageCauseText::actionText($action), $case->value);
        }
        $this->assertSame('Kirim teknisi FO', OutageCauseText::actionText(OutageCauseText::actionFor('drop_los')));
        $this->assertSame('Gangguan besar, NOC sudah menangani', OutageCauseText::actionText(OutageCauseText::actionFor('upstream')));
    }
}
