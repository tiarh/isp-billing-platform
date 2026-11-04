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
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageCauseText;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-5 (rules_version 2026-10-22.1): isolir is a note, not the answer, while the line has a fresh physical fault;
 * a PON / ODP cut needs several ONUs down NOW. Pure classify() cases (context in, diagnosis out) plus a few database
 * cases for the correlator (P12), the history area flag (H1), the router isolation profile (I15) and the kill switch (K1).
 * Plan: ebilling-notes/plans/rca5-isolir-poncut.md (ids and counts only, no customer data).
 */
#[Group('blocking-core')]
class OutageRca5IsolirPonTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-22 03:00:00', 'UTC'); // 10:00 WIB
        Carbon::setTestNow($this->now);
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ pure fixtures

    private function ago(int $minutes): Carbon
    {
        return $this->now->copy()->subMinutes($minutes);
    }

    /** A healthy customer: ONU online, internet up, router normal, a ZTE OLT with a 30 ONU PON, nothing down. */
    private function ctx(array $o = []): array
    {
        $now = $this->now;
        $base = [
            'now' => $now,
            'rca5' => true,
            'customer' => ['id' => 1, 'status' => 'active', 'pppoe_user' => 'user.uji', 'router_id' => 1, 'olt_id' => 1, 'ref' => '1/2/3:4', 'pon' => '1/2/3', 'odp' => null, 'is_online' => true, 'online_checked_at' => $now, 'rx_dbm' => -20.0, 'mikrotik_profile' => 'default'],
            'probes' => [],
            'admin' => ['billing' => false, 'status' => 'active', 'secret_isolir' => false, 'secret_state' => 'none', 'db_isolir' => false, 'secret_disabled' => null],
            'router' => ['id' => 1, 'name' => 'RA-OLT', 'isolation_profile' => null, 'blind' => null, 'offline' => false, 'all_gone' => false, 'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => $now, 'health_fresh' => true, 'active' => null, 'probe_status' => null],
            'olt' => ['id' => 1, 'name' => 'RA', 'family' => 'zte', 'wide' => null, 'blind' => null],
            'pon' => ['mass' => null, 'los_others' => 0, 'total' => 30, 'boot_share' => null, 'down_now' => 0, 'known_total' => 30, 'recovered' => 0, 'event_at' => null],
            'cluster' => ['odp' => null, 'odp_share' => null, 'odp_count' => 0, 'odp_down_now' => 0],
            'cpe' => [
                'present' => false, 'device_id' => null, 'model' => null, 'last_inform_at' => null, 'uptime_s' => null, 'uptime_now_s' => null, 'boots_24h' => 0, 'boot_times' => [], 'last_boot_at' => null,
                'wlan' => ['24' => ['status' => null, 'enabled' => null, 'assoc' => null], '5' => ['status' => null, 'enabled' => null, 'assoc' => null]],
                'wlan_at' => null, 'wlan_fresh' => false, 'wlan_strong' => false, 'wlan_source' => null, 'band_verified' => false,
                'ppp_status' => null, 'ppp_last_error' => null, 'ppp_at' => null, 'ppp_fresh' => false, 'rssi' => [], 'lan_active' => null, 'wlan_transitions_6h' => 0, 'wlan_transitions_24h' => 0, 'ppp_reconnects' => [], 'summoned_at' => null,
            ],
            'onu' => [
                'phase' => 'online', 'since' => null, 'collected_at' => $now, 'fresh' => true, 'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false,
                'flapping' => false, 'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null, 'events_24h' => 0, 'dg_in_24h' => false,
                'rx_dbm' => -20.0, 'rx_weak' => false, 'rx_trend' => false, 'olt_probe' => null, 'drops' => [],
            ],
            'pppoe' => ['online' => true, 'fresh' => true, 'at' => $now, 'source' => 'poll', 'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => true, 'router_session_uptime_s' => null, 'router_session' => null, 'events' => []],
            'history' => [
                'onu_drops' => [], 'ppp_drops' => [], 'ppp_source' => 'none', 'ppp_last' => null, 'onu_log' => true, 'ppp_log' => true,
                'rx' => ['dbm' => -20.0, 'at' => $now->copy()->subMinutes(30), 'old' => false], 'ticket_rx_complaint' => false,
            ],
        ];

        return array_replace_recursive($base, $o);
    }

    /** ONU down for $minutes with a phase; the LOS / dying gasp cause is fresh unless overridden. */
    private function down(string $phase, int $minutes, array $o = []): array
    {
        $cause = in_array($phase, ['los', 'dying_gasp'], true) ? $phase : null;

        return ['onu' => array_merge([
            'phase' => $phase, 'since' => $this->ago($minutes), 'cause' => $cause, 'cause_at' => $this->now, 'cause_source' => 'olt_poll', 'cause_fresh' => $cause !== null,
        ], $o)];
    }

    private function isolated(string $status = 'isolated', array $admin = []): array
    {
        return ['customer' => ['status' => $status], 'admin' => array_merge(['billing' => true, 'status' => $status, 'db_isolir' => true], $admin)];
    }

    private function mass(array $o = []): array
    {
        return array_merge(['id' => 7, 'includes' => true, 'count' => 5, 'total' => 20, 'ratio' => 0.25, 'whole' => false, 'confirmed' => true, 'state' => 'confirmed', 'since' => $this->ago(15), 'seen' => $this->ago(1), 'ids' => [1]], $o);
    }

    private function classify(array $ctx): array
    {
        return (new OutageDiagnoser(app(OutageCorrelator::class)))->classify($ctx);
    }

    private function assertCause(string $cause, string $confidence, array $r, string $why = ''): void
    {
        $this->assertSame($cause, $r['cause_code'], $why.' cause');
        $this->assertSame($confidence, $r['confidence'], $why.' confidence');
    }

    /** @return list<string> */
    private function texts(array $r, ?int $limit = null): array
    {
        $texts = array_column($r['evidence'], 'text');

        return $limit === null ? $texts : array_slice($texts, 0, $limit);
    }

    // ------------------------------------------------------------------ I: isolir vs a physical fault

    public function test_i1_isolated_with_a_fresh_los_is_drop_los_and_isolir_is_a_note(): void
    {
        $history = ['history' => ['onu_drops' => [['at' => $this->ago(30), 'cause' => 'los', 'source' => 'olt_poll', 'area' => false]]]];
        $r = $this->classify($this->ctx($this->isolated() + $this->down('los', 30) + $history));

        $this->assertCause('drop_los', 'tinggi', $r);
        $this->assertContains('isolir_admin', $r['secondary']);
        $this->assertContains('Pelanggan juga sedang diisolir.', $this->texts($r, 5));
        $this->assertSame('kirim_teknisi_fo', $r['suggested_action']);
        $this->assertContains('ONU putus karena kabel 1 kali dalam 24 jam, terakhir 09:30.', $this->texts($r), 'history lines are shown for a physical answer');
    }

    public function test_i1b_the_400_shape_a_los_48_minutes_old_is_still_physical(): void
    {
        $r = $this->classify($this->ctx($this->isolated() + $this->down('los', 48)));

        $this->assertCause('drop_los', 'tinggi', $r);
        $this->assertContains('isolir_admin', $r['secondary']);
    }

    public function test_i2_isolated_with_a_fresh_dying_gasp_is_power_off(): void
    {
        $r = $this->classify($this->ctx($this->isolated() + $this->down('dying_gasp', 20)));

        $this->assertCause('power_off', 'tinggi', $r);
        $this->assertContains('isolir_admin', $r['secondary']);
        $this->assertContains('Pelanggan juga sedang diisolir.', $this->texts($r, 5));
    }

    public function test_i3_isolated_and_the_onu_is_online_is_isolir_tinggi(): void
    {
        $r = $this->classify($this->ctx($this->isolated()));

        $this->assertCause('isolir_admin', 'tinggi', $r);
        $this->assertSame('Pelanggan berstatus isolir.', $r['evidence'][0]['text']);
        $this->assertContains('ONU menyala di OLT.', $this->texts($r));
        $this->assertSame('cek_tagihan', $r['suggested_action']);
    }

    public function test_i4_isolated_without_onu_data_is_isolir_sedang_with_the_check_line(): void
    {
        $r = $this->classify($this->ctx($this->isolated() + ['onu' => ['phase' => null, 'fresh' => false, 'collected_at' => null]]));

        $this->assertCause('isolir_admin', 'sedang', $r);
        $this->assertContains('Kondisi ONU belum terbaca. Kalau sudah bayar, cek kabel dan listrik ONU.', $this->texts($r));
        $this->assertSame('cek_tagihan', $r['suggested_action']);
        $this->assertSame('Cek tagihan. Sudah bayar tapi tetap mati: kirim teknisi', OutageCauseText::actionText($r['suggested_action']));
    }

    public function test_i5_a_long_dead_onu_stays_isolir_with_the_onu_down_line(): void
    {
        // a LOS with no start (the tracker kept it) and a LOS from 5 days ago: switched off after the isolation
        foreach ([[$this->down('los', 5, ['since' => null]), 'ONU juga mati. Kalau sudah bayar, kirim teknisi.'], [$this->down('los', 60 * 24 * 5), 'ONU juga mati sejak']] as $i => [$down, $line]) {
            $r = $this->classify($this->ctx($this->isolated() + $down));

            $this->assertCause('isolir_admin', 'sedang', $r, 'case '.$i);
            $this->assertStringContainsString($line, implode(' | ', $this->texts($r)), 'case '.$i);
        }
    }

    public function test_i5c_a_live_read_of_a_dead_onu_with_a_null_tracker_start_uses_last_offline_at(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:1', ['status' => 'isolated', 'mikrotik_profile' => 'isolirebilling']);
        $this->tracker($olt, '1/2/3:1', 'los', null, $this->now, $c);
        $live = fn (Carbon $offline) => ['olt' => ['status' => 'ok', 'detail' => ['phase' => 'LOS', 'cause' => 'los', 'last_offline_at' => $offline->copy()->utc()->format('Y-m-d H:i:s')]]];

        // dead for 10 days: stays isolir, never a "fresh" fault
        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now, $live($this->now->copy()->subDays(10)));
        $this->assertNull($ctx['onu']['since']);
        $this->assertNotNull($ctx['onu']['since_hint']);
        $old = $this->classify($ctx);
        $this->assertCause('isolir_admin', 'sedang', $old);
        $this->assertStringContainsString('ONU juga mati sejak', implode(' | ', $this->texts($old)));

        // down for 30 minutes: physical, isolir is the note
        app(OutageCorrelator::class)->reset();
        $fresh = $this->classify(app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now, $live($this->ago(30))));
        $this->assertCause('drop_los', 'tinggi', $fresh);
        $this->assertContains('isolir_admin', $fresh['secondary']);

        // no last_offline_at and no own drop: the start is unknown, so it is NOT physical
        app(OutageCorrelator::class)->reset();
        $unknown = $this->classify(app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now, ['olt' => ['status' => 'ok', 'detail' => ['phase' => 'LOS', 'cause' => 'los']]]));
        $this->assertCause('isolir_admin', 'sedang', $unknown);
        $this->assertContains('ONU juga mati. Kalau sudah bayar, kirim teknisi.', $this->texts($unknown));
    }

    public function test_p12c_the_404_409_shape_with_a_null_tracker_start_uses_the_own_drop_event(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:1');
        $drop = $this->ago(300);
        $this->tracker($olt, '1/2/3:1', 'los', null, $this->now, $c);
        $this->dropEvent($olt, '1/2/3:1', $drop);
        $this->tracker($olt, '1/2/3:30', 'los', null, $this->now); // the second leftover: no start, not counted
        for ($k = 2; $k <= 11; $k++) {
            $this->tracker($olt, '1/2/3:'.$k, 'online', $this->ago(280), $this->now);
            $this->dropEvent($olt, '1/2/3:'.$k, $drop->copy()->addSeconds($k * 5 - 30));
        }

        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now);

        $this->assertNull($ctx['onu']['since']);
        $this->assertSame(1, $ctx['pon']['down_now']);
        $this->assertSame(10, $ctx['pon']['recovered']);
        $r = $this->classify($ctx);
        $this->assertCause('drop_los', 'sedang', $r);
        $this->assertContains('PON ini sempat putus 05:00, 10 ONU lain sudah pulih, ONU ini belum.', $this->texts($r));
    }

    public function test_i6_offline_without_a_cause_for_two_hours_stays_isolir(): void
    {
        $r = $this->classify($this->ctx($this->isolated() + $this->down('offline', 120)));

        $this->assertCause('isolir_admin', 'sedang', $r);
        $this->assertStringContainsString('ONU juga mati sejak', implode(' | ', $this->texts($r)));
    }

    public function test_i7_terminated_with_a_fresh_los_stays_isolir_tinggi(): void
    {
        $r = $this->classify($this->ctx($this->isolated('terminated') + $this->down('los', 30)));

        $this->assertCause('isolir_admin', 'tinggi', $r);
        $this->assertSame('Pelanggan berstatus berhenti.', $r['evidence'][0]['text']);
    }

    public function test_i8_suspended_cuti_with_a_fresh_los_is_drop_los_and_the_note_says_cuti(): void
    {
        $r = $this->classify($this->ctx($this->isolated('suspended') + $this->down('los', 30)));

        $this->assertCause('drop_los', 'tinggi', $r);
        $this->assertContains('Pelanggan juga sedang cuti.', $this->texts($r, 5));
    }

    public function test_i9_active_with_only_the_database_profile_is_isolir_rendah(): void
    {
        $r = $this->classify($this->ctx(['admin' => ['db_isolir' => true]]));

        $this->assertCause('isolir_admin', 'rendah', $r);
        $this->assertSame('Data profil isolir, status aktif (belum dicek ke router).', $r['evidence'][0]['text']);
        $this->assertContains('Kondisi ONU belum terbaca. Kalau sudah bayar, cek kabel dan listrik ONU.', $this->texts($r));
    }

    public function test_i10_active_but_the_live_secret_is_on_the_isolation_profile_offers_to_open_it(): void
    {
        $r = $this->classify($this->ctx(['admin' => ['secret_isolir' => true, 'secret_state' => 'isolated']]));

        $this->assertCause('isolir_admin', 'tinggi', $r);
        $this->assertContains('Status aktif, tapi di router masih diisolir.', $this->texts($r));
        $this->assertSame('buka_isolir', $r['suggested_action']);
        $this->assertSame('Sudah bayar? Minta NOC buka isolir', OutageCauseText::actionText($r['suggested_action']));
        $this->assertLessThanOrEqual(24, strlen($r['suggested_action']), 'outage_diagnoses.suggested_action is string(24)');
    }

    public function test_i11_isolated_in_billing_but_open_on_the_router_leaves_the_decision_to_the_other_rules(): void
    {
        $r = $this->classify($this->ctx($this->isolated('isolated', ['secret_state' => 'normal'])));

        $this->assertNotSame('isolir_admin', $r['cause_code']);
        $this->assertContains('isolir_admin', $r['secondary']);
        $this->assertContains('Di tagihan isolir, tapi di router tidak diisolir.', $this->texts($r, 5));
    }

    public function test_i12_isolated_on_a_normal_profile_is_sedang_with_the_method_unknown_line_never_router_open(): void
    {
        // "200m+ startlite": isolated in billing, the router profile is a normal one (97 customers in prod)
        // 2026-10-26.1: billing alone is not router proof; it is decisive only while a fresh poll shows the session down
        $down = ['pppoe' => ['online' => false, 'fresh' => true]];
        $r = $this->classify($this->ctx($this->isolated('isolated', ['db_isolir' => false, 'secret_state' => 'normal']) + $down));

        $this->assertCause('isolir_admin', 'sedang', $r);
        $this->assertContains('Isolir tidak lewat profil router. Cek ke NOC kalau sudah bayar.', $this->texts($r));
        $this->assertNotContains('Di tagihan isolir, tapi di router tidak diisolir.', $this->texts($r));

        $none = $this->classify($this->ctx($this->isolated('isolated', ['db_isolir' => false]) + $down));
        $this->assertCause('isolir_admin', 'sedang', $none);
        $this->assertContains('Isolir tidak lewat profil router. Cek ke NOC kalau sudah bayar.', $this->texts($none));

        // the session is up: only a note, the other rules decide
        $up = $this->classify($this->ctx($this->isolated('isolated', ['db_isolir' => false, 'secret_state' => 'normal'])));
        $this->assertNotSame('isolir_admin', $up['cause_code']);
        $this->assertContains('isolir_admin', $up['secondary']);
        $this->assertContains('Di tagihan isolir, tapi belum ada tanda isolir di router.', $this->texts($up, 5));
    }

    public function test_i13_isolated_with_repeated_los_drops_today_and_the_onu_online_is_drop_los(): void
    {
        $drops = [['at' => $this->ago(300), 'cause' => 'los', 'source' => 'olt_poll', 'area' => false], ['at' => $this->ago(90), 'cause' => 'los', 'source' => 'olt_poll', 'area' => false]];
        $r = $this->classify($this->ctx($this->isolated() + ['history' => ['onu_drops' => $drops]]));

        $this->assertCause('drop_los', 'sedang', $r);
        $this->assertSame('onu_history', $r['rule_id']);
        $this->assertContains('isolir_admin', $r['secondary']);
        $this->assertContains('Pelanggan juga sedang diisolir.', $this->texts($r, 5));
    }

    public function test_i14_weak_rx_with_the_onu_online_does_not_beat_isolir(): void
    {
        $r = $this->classify($this->ctx($this->isolated() + ['onu' => ['rx_weak' => true, 'rx_dbm' => -29.5]]));

        $this->assertCause('isolir_admin', 'tinggi', $r);
        $this->assertContains('optical_degrade', $r['secondary']);
    }

    // ------------------------------------------------------------------ P: PON / ODP cut needs several ONUs down now

    public function test_p1_the_404_409_shape_the_pon_recovered_and_two_leftovers_is_drop_los_not_pon_cut(): void
    {
        $mass = $this->mass(['count' => 2, 'total' => 18, 'ratio' => 2 / 18, 'confirmed' => false, 'state' => 'open']);
        $r = $this->classify($this->ctx($this->down('los', 300) + [
            'pon' => ['mass' => $mass, 'down_now' => 2, 'known_total' => 21, 'recovered' => 10, 'event_at' => $this->ago(300)],
            'cluster' => ['odp' => 'ODP-A', 'odp_share' => 0.5, 'odp_count' => 1, 'odp_down_now' => 1],
        ]));

        $this->assertCause('drop_los', 'sedang', $r);
        $this->assertNotContains($r['cause_code'], ['pon_cut', 'odp_cut']);
        $this->assertContains('PON ini sempat putus 05:00, 10 ONU lain sudah pulih, ONU ini belum.', $this->texts($r));
        $this->assertSame('kirim_teknisi_fo', $r['suggested_action']);
    }

    public function test_p2_two_other_onus_down_now_with_this_one_is_pon_cut_sedang(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['down_now' => 3, 'known_total' => 20]]));

        $this->assertCause('pon_cut', 'sedang', $r);
        $this->assertContains('2 ONU lain di PON ini juga putus sekarang.', $this->texts($r));
    }

    public function test_p3_leftover_los_rows_are_not_witnesses(): void
    {
        // the correlator counted only this ONU as down now; three leftovers sit in los_others
        $r = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['los_others' => 3, 'down_now' => 1, 'known_total' => 20]]));

        $this->assertCause('drop_los', 'tinggi', $r);
        $this->assertContains('Tidak ada ONU lain di PON ini yang baru putus.', $this->texts($r));
    }

    public function test_p4_a_two_onu_pon_with_both_down_is_pon_cut_tinggi_on_zte(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['mass' => $this->mass(['count' => 2, 'total' => 2, 'ratio' => 1.0, 'whole' => true]), 'down_now' => 2, 'known_total' => 2]]));

        $this->assertCause('pon_cut', 'tinggi', $r);
        $this->assertSame('2 dari 2 ONU satu PON putus sejak 09:45.', $r['evidence'][0]['text']);
    }

    public function test_p5_a_one_onu_pon_is_never_a_pon_cut(): void
    {
        $r = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['mass' => $this->mass(['count' => 1, 'total' => 1, 'ratio' => 1.0, 'whole' => true]), 'down_now' => 1, 'known_total' => 1]]));

        $this->assertCause('drop_los', 'tinggi', $r);
    }

    public function test_p6_the_small_pon_rule_four_known_two_down_yes_five_known_two_down_no(): void
    {
        $this->assertCause('pon_cut', 'sedang', $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['down_now' => 2, 'known_total' => 4]])));
        $this->assertCause('drop_los', 'tinggi', $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['down_now' => 2, 'known_total' => 5]])));
        $this->assertTrue(Rules::ponEnough(3, 40));
        $this->assertFalse(Rules::ponEnough(2, 40));
        $this->assertFalse(Rules::ponEnough(1, 1));
        $this->assertTrue(Rules::ponEnough(2, 2));
        $this->assertFalse(Rules::ponEnough(1, 3));
    }

    public function test_p7_an_online_onu_is_never_pon_cut_even_when_the_mass_lists_it(): void
    {
        $r = $this->classify($this->ctx(['pon' => ['mass' => $this->mass(), 'down_now' => 5, 'known_total' => 20]]));

        $this->assertNotSame('pon_cut', $r['cause_code']);
        $this->assertNotSame('odp_cut', $r['cause_code']);
    }

    public function test_p8_a_stale_onu_follows_only_a_confirmed_mass_with_enough_others_down_now(): void
    {
        $stale = ['onu' => ['phase' => 'los', 'fresh' => false, 'since' => $this->ago(60), 'collected_at' => $this->ago(40), 'cause' => 'los', 'cause_fresh' => false]];

        $four = $this->classify($this->ctx($stale + ['pon' => ['mass' => $this->mass(), 'down_now' => 4, 'known_total' => 20]]));
        $this->assertCause('pon_cut', 'sedang', $four);

        $one = $this->classify($this->ctx($stale + ['pon' => ['mass' => $this->mass(), 'down_now' => 1, 'known_total' => 20]]));
        $this->assertNotSame('pon_cut', $one['cause_code']);

        $open = $this->classify($this->ctx($stale + ['pon' => ['mass' => $this->mass(['confirmed' => false, 'state' => 'open']), 'down_now' => 4, 'known_total' => 20]]));
        $this->assertNotSame('pon_cut', $open['cause_code'], 'a mass_los that was never confirmed does not claim a stale ONU');
    }

    public function test_p9_an_own_dying_gasp_next_to_a_mass_is_power_off(): void
    {
        $r = $this->classify($this->ctx($this->down('dying_gasp', 10) + ['pon' => ['mass' => $this->mass(), 'down_now' => 5, 'known_total' => 20]]));

        $this->assertCause('power_off', 'tinggi', $r);
    }

    public function test_p10_a_huawei_pon_that_is_all_offline_is_pon_cut_but_never_tinggi(): void
    {
        $r = $this->classify($this->ctx($this->down('offline', 10, ['cause' => null, 'cause_fresh' => false]) + [
            'olt' => ['family' => 'huawei'],
            'pon' => ['mass' => $this->mass(['count' => 10, 'total' => 10, 'ratio' => 1.0, 'whole' => true]), 'down_now' => 10, 'known_total' => 10],
        ]));

        $this->assertCause('pon_cut', 'sedang', $r);
    }

    public function test_p11_odp_cut_needs_two_onus_down_now_on_the_same_odp(): void
    {
        $cluster = ['odp' => 'ODP-07', 'odp_share' => 1.0, 'odp_count' => 2];
        $part = $this->mass(['count' => 2, 'total' => 30, 'ratio' => 2 / 30]);

        $two = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['mass' => $part, 'down_now' => 2, 'known_total' => 30], 'cluster' => $cluster + ['odp_down_now' => 2]]));
        $this->assertCause('odp_cut', 'sedang', $two);
        $this->assertStringContainsString('ODP-07', $two['evidence'][0]['text']);

        $one = $this->classify($this->ctx($this->down('los', 10) + ['pon' => ['mass' => $part, 'down_now' => 2, 'known_total' => 30], 'cluster' => $cluster + ['odp_down_now' => 1]]));
        $this->assertCause('drop_los', 'tinggi', $one);
    }

    // ------------------------------------------------------------------ P13: PON leftover

    /** @return array<string,mixed> */
    private function leftoverCtx(int $down, int $recovered, bool $withMass): array
    {
        $pon = ['down_now' => $down, 'known_total' => 40, 'recovered' => $recovered, 'event_at' => $recovered > 0 ? $this->ago(120) : null];
        if ($withMass) {
            $pon['mass'] = $this->mass(['count' => $down, 'total' => 40, 'ratio' => $down / 40]);
        }

        return $this->ctx($this->down('los', 120) + ['pon' => $pon]);
    }

    public function test_p13_ten_recovered_and_four_down_is_drop_los_sedang_with_the_recovered_line(): void
    {
        foreach ([false, true] as $withMass) {
            $r = $this->classify($this->leftoverCtx(4, 10, $withMass));

            $this->assertCause('drop_los', 'sedang', $r, $withMass ? 'with mass' : 'no mass');
            $this->assertContains('PON ini sempat putus 08:00, 10 ONU lain sudah pulih, ONU ini belum.', $this->texts($r));
        }
    }

    public function test_p13_ten_recovered_and_five_down_is_a_big_event_and_stays_pon_cut(): void
    {
        foreach ([false, true] as $withMass) {
            $this->assertCause('pon_cut', 'sedang', $this->classify($this->leftoverCtx(5, 10, $withMass)), $withMass ? 'with mass' : 'no mass');
        }
    }

    public function test_p13_two_recovered_and_three_down_is_pon_cut_because_two_is_less_than_four(): void
    {
        foreach ([false, true] as $withMass) {
            $this->assertCause('pon_cut', 'sedang', $this->classify($this->leftoverCtx(3, 2, $withMass)), $withMass ? 'with mass' : 'no mass');
        }
    }

    public function test_p13_a_fresh_event_with_nothing_recovered_and_three_down_is_pon_cut(): void
    {
        foreach ([false, true] as $withMass) {
            $this->assertCause('pon_cut', 'sedang', $this->classify($this->leftoverCtx(3, 0, $withMass)), $withMass ? 'with mass' : 'no mass');
        }
    }

    public function test_p13_the_leftover_rule_is_off_with_the_kill_switch(): void
    {
        $ctx = $this->leftoverCtx(4, 10, false);
        $ctx['rca5'] = false;
        $ctx['pon']['los_others'] = 3;

        $this->assertSame('pon_cut', $this->classify($ctx)['cause_code'], 'the 2026-10-21.1 rule: 2+ other LOS rows');
    }

    // ------------------------------------------------------------------ DB fixtures

    private function olt(string $vendor = 'zte_c300'): Olt
    {
        $olt = Olt::create(['name' => 'OLT R5 '.($this->seq + 1), 'code' => 'R5-'.++$this->seq, 'vendor' => $vendor, 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        config(['outage.olt_ids' => [$olt->id], 'outage.serial_fallback_olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function router(?string $isolation = null): Router
    {
        $router = Router::create(['name' => 'RA-OLT '.++$this->seq, 'ip_address' => '198.18.6.'.$this->seq, 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online', 'isolation_profile' => $isolation]);
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => now()]);

        return $router->fresh();
    }

    private function customer(?Olt $olt, ?string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'R5-PKG'], ['name' => 'RCA5 pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'R5-C'.$n, 'name' => 'Pelanggan RCA5 '.$n, 'phone' => '0812555'.$n, 'address' => 'Jl. Contoh',
            'pppoe_user' => 'USER_R5_'.$n, 'package_id' => $package->id, 'status' => 'active',
            'olt_id' => $olt?->id, 'olt_port_label' => $ref,
        ], $o));
    }

    private function tracker(Olt $olt, string $ref, string $phase, ?Carbon $since, ?Carbon $collected, ?Customer $c = null): OutageOnuTracker
    {
        return OutageOnuTracker::create([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c?->id, 'phase' => $phase,
            'phase_since_at' => $since, 'last_state_collected_at' => $collected,
        ]);
    }

    private function dropEvent(Olt $olt, string $ref, Carbon $at, string $to = 'los', ?string $from = 'online'): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => null, 'from_phase' => $from, 'to_phase' => $to,
            'cause' => $to === 'los' ? 'los' : null, 'source' => 'olt_poll', 'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------------ I15 (DB): the router's isolation profile

    public function test_i15_the_isolation_profile_is_the_routers_own_name_or_anything_with_isolir_in_it(): void
    {
        $cases = [
            // [router isolation_profile, customer mikrotik_profile, secret probe profile, expected D, expected S state]
            [null, 'ISOLIREBILLING', null, true, 'none'],
            ['block', 'Block', null, true, 'none'],
            ['block', 'isolir-lama', null, true, 'none'],
            ['block', '200m+ startlite', null, false, 'none'],
            [null, '200m+ startlite', '200m+ startlite', false, 'normal'],
            ['block', '200m+ startlite', 'BLOCK', false, 'isolated'],
            [null, 'default', 'isolirebilling', false, 'isolated'],
        ];
        foreach ($cases as $i => [$isolation, $profile, $secret, $db, $state]) {
            $olt = $this->olt();
            $router = $this->router($isolation);
            $c = $this->customer($olt, '1/2/3:'.($i + 1), ['router_id' => $router->id, 'status' => 'isolated', 'mikrotik_profile' => $profile]);
            $probes = $secret === null ? [] : ['router' => ['status' => 'ok', 'active' => null, 'secret' => ['disabled' => 'false', 'profile' => $secret]]];

            $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now(), $probes);

            $this->assertSame($db, $ctx['admin']['db_isolir'], 'D, case '.$i);
            $this->assertSame($state, $ctx['admin']['secret_state'], 'S, case '.$i);
            $this->assertTrue($ctx['admin']['billing']);
            app(OutageCorrelator::class)->reset();
        }
    }

    // ------------------------------------------------------------------ P12 (DB): down now, known, recovered

    public function test_p12_the_correlator_counts_onus_down_now_known_and_recovered_from_the_tracker_and_the_events(): void
    {
        $olt = $this->olt();
        $since = $this->ago(20);
        $c = $this->customer($olt, '1/2/3:1');
        $this->tracker($olt, '1/2/3:1', 'los', $since, $this->now, $c);
        // dropped with it (-1 min / +2 min) and online again: recovered
        $this->tracker($olt, '1/2/3:2', 'online', $this->ago(10), $this->now);
        $this->tracker($olt, '1/2/3:3', 'online', $this->ago(10), $this->now);
        $this->dropEvent($olt, '1/2/3:2', $since->copy()->subMinute());
        $this->dropEvent($olt, '1/2/3:3', $since->copy()->addMinutes(2));
        // online now, but its drop was 40 minutes earlier: not together
        $this->tracker($olt, '1/2/3:10', 'online', $this->ago(10), $this->now);
        $this->dropEvent($olt, '1/2/3:10', $since->copy()->subMinutes(40));
        // another PON of the same OLT: never counted
        $this->tracker($olt, '1/2/4:1', 'los', $this->ago(5), $this->now);
        $this->dropEvent($olt, '1/2/4:1', $since);
        // witnesses: an unmapped ONU that is down now counts
        $this->tracker($olt, '1/2/3:4', 'los', $this->ago(19), $this->now);
        // not witnesses: a LOS with no start, a LOS from 10 days ago, a stale LOS (40 min old read)
        $this->tracker($olt, '1/2/3:5', 'los', null, $this->now);
        $this->tracker($olt, '1/2/3:6', 'los', $this->now->copy()->subDays(10), $this->now);
        $this->tracker($olt, '1/2/3:7', 'los', $this->ago(30), $this->ago(40));
        // known, not LOS-like for ZTE: offline since an hour; unknown phase is not known at all
        $this->tracker($olt, '1/2/3:8', 'offline', $this->ago(60), $this->now);
        $this->tracker($olt, '1/2/3:9', 'unknown', null, null);

        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now);

        $this->assertSame(2, $ctx['pon']['down_now'], 'this ONU + the unmapped one; leftovers and stale reads are not counted');
        $this->assertSame(7, $ctx['pon']['known_total'], 'this, 2 + 3 + 10 online, 4 and 7 down within 7 days, 8 offline');
        $this->assertSame(2, $ctx['pon']['recovered']);
        $this->assertTrue($since->equalTo($ctx['pon']['event_at']));
        $this->assertSame(4, $ctx['pon']['los_others'], 'los_others stays in the context (nothing reads it)');
        $this->assertSame(0, $ctx['cluster']['odp_down_now']);

        // P3 end to end: 2 of 7 down now is no PON cut
        $r = (new OutageDiagnoser(app(OutageCorrelator::class)))->classify($ctx);
        $this->assertCause('drop_los', 'sedang', $r);
        $this->assertContains('PON ini sempat putus 09:40, 2 ONU lain sudah pulih, ONU ini belum.', $this->texts($r));
    }

    public function test_p12b_the_odp_count_uses_the_customers_of_the_onus_down_now(): void
    {
        $olt = $this->olt();
        $a = $this->customer($olt, '1/2/3:1', ['odp_name' => 'ODP-1']);
        $b = $this->customer($olt, '1/2/3:2', ['odp_name' => 'ODP-1']);
        $d = $this->customer($olt, '1/2/3:3', ['odp_name' => 'ODP-2']);
        foreach ([[$a, '1/2/3:1'], [$b, '1/2/3:2'], [$d, '1/2/3:3']] as [$cust, $ref]) {
            $this->tracker($olt, $ref, 'los', $this->ago(10), $this->now, $cust);
        }
        $this->tracker($olt, '1/2/3:4', 'online', $this->ago(10), $this->now);

        $ctx = app(OutageCorrelator::class)->contextFor($a->fresh(), $this->now);

        $this->assertSame(3, $ctx['pon']['down_now']);
        $this->assertSame(2, $ctx['cluster']['odp_down_now']);
    }

    // ------------------------------------------------------------------ H1 (DB): history area flag

    public function test_h1_only_a_confirmed_or_whole_mass_los_marks_a_drop_as_area_and_only_around_its_first_minutes(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:1');
        $mass = fn (array $o) => OutageSignal::create($o + [
            'signal_type' => 'mass_los', 'scope_key' => 'pon:'.$olt->id.':1/2/3:'.++$this->seq, 'state' => 'open', 'olt_id' => $olt->id, 'pon_port' => '1/2/3',
            'last_seen_at' => $this->now, 'observations' => 3, 'affected_count' => 2, 'affected_customer_ids' => [$c->id], 'payload' => ['pon_total' => 18, 'whole_pon' => false],
        ]);

        // a never confirmed 2-ONU mass_los: the drop inside it is this line's own fault
        $never = $mass(['opened_at' => $this->ago(120), 'confirmed_at' => null]);
        $this->dropEvent($olt, '1/2/3:1', $this->ago(119));
        $drops = $this->dropsOf($c);
        $this->assertFalse($drops[0]['area'], 'never confirmed');
        $never->delete();

        // a confirmed one: the drop at its opening is area
        $confirmed = $mass(['opened_at' => $this->ago(120), 'confirmed_at' => $this->ago(100), 'state' => 'confirmed']);
        $this->assertTrue($this->dropsOf($c)[0]['area'], 'confirmed, drop 1 minute after opened_at');

        // 20 hours later, inside the same still-open episode (kept open by two leftovers): not area
        $confirmed->update(['opened_at' => $this->now->copy()->subHours(22)]);
        DB::table('onu_state_events')->delete();
        $this->dropEvent($olt, '1/2/3:1', $this->now->copy()->subHours(22)->addMinutes(5));
        $this->dropEvent($olt, '1/2/3:1', $this->now->copy()->subHours(2), 'los', 'online');
        $drops = $this->dropsOf($c);
        $this->assertCount(2, $drops);
        $this->assertTrue($drops[0]['area'], 'inside opened_at + 30 min');
        $this->assertFalse($drops[1]['area'], '20 hours into the same episode');
        $confirmed->delete();

        // an unconfirmed episode that is a whole PON counts
        $mass(['opened_at' => $this->now->copy()->subHours(2)->subMinute(), 'confirmed_at' => null, 'payload' => ['pon_total' => 2, 'whole_pon' => true]]);
        $this->assertTrue($this->dropsOf($c)[1]['area'], 'whole_pon');

        // an olt_wide episode keeps the old window
        OutageSignal::query()->delete();
        OutageSignal::create(['signal_type' => 'olt_wide', 'scope_key' => 'olt:'.$olt->id.':wide', 'state' => 'open', 'olt_id' => $olt->id, 'opened_at' => $this->now->copy()->subHours(3), 'last_seen_at' => $this->now, 'observations' => 3, 'affected_count' => 50, 'affected_customer_ids' => [], 'payload' => ['consecutive' => 3]]);
        $this->assertTrue($this->dropsOf($c)[1]['area'], 'olt_wide: from opened_at - 15 min to last_seen + 10 min');
    }

    /** @return list<array<string,mixed>> */
    private function dropsOf(Customer $c): array
    {
        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), $this->now);
        app(OutageCorrelator::class)->reset();

        return array_values($ctx['history']['onu_drops']);
    }

    // ------------------------------------------------------------------ K1 (DB): the kill switch

    public function test_k1_the_kill_switch_gives_back_the_old_answers_and_the_old_rules_version(): void
    {
        $olt = $this->olt();
        $iso = $this->customer($olt, '1/2/3:1', ['status' => 'isolated', 'mikrotik_profile' => 'isolirebilling']);
        $this->tracker($olt, '1/2/3:1', 'los', $this->ago(20), $this->now, $iso);
        // a PON of 5 ONUs: this one and one other are in LOS, three are online; the mass_los lists both
        $c = $this->customer($olt, '1/2/4:1');
        $this->tracker($olt, '1/2/4:1', 'los', $this->ago(20), $this->now, $c);
        $this->tracker($olt, '1/2/4:2', 'los', $this->ago(20), $this->now);
        foreach ([3, 4, 5] as $k) {
            $this->tracker($olt, '1/2/4:'.$k, 'online', $this->ago(60), $this->now);
        }
        OutageSignal::create([
            'signal_type' => 'mass_los', 'scope_key' => 'pon:'.$olt->id.':1/2/4', 'state' => 'confirmed', 'olt_id' => $olt->id, 'pon_port' => '1/2/4',
            'opened_at' => $this->ago(20), 'confirmed_at' => $this->ago(15), 'last_seen_at' => $this->now, 'observations' => 3, 'affected_count' => 2, 'affected_customer_ids' => [$c->id],
            'payload' => ['pon_total' => 5, 'whole_pon' => false],
        ]);
        $diagnoser = app(OutageDiagnoser::class);

        $on = $diagnoser->diagnose($iso->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('drop_los', $on->cause_code);
        $this->assertSame(Rules::RULES_VERSION, $on->rules_version);
        $onPon = $diagnoser->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('drop_los', $onPon->cause_code, '2 of 5 down now');

        $this->assertTrue((new OutageSettingsWriter)->set('diagnosis_rca5_enabled', '0', 'test')['ok']);
        Setting::flushRequestMemo();
        $this->assertFalse(OutageSettings::diagnosisRca5Enabled());

        $off = $diagnoser->diagnose($iso->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('isolir_admin', $off->cause_code);
        $this->assertSame('tinggi', $off->confidence);
        $this->assertSame('2026-10-21.1', $off->rules_version);
        $offPon = $diagnoser->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('pon_cut', $offPon->cause_code, 'the old mass_los rule');
        $this->assertSame('2026-10-21.1', $offPon->rules_version);
    }
}
