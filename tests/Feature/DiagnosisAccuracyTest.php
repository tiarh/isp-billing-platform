<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Outage\Diagnosis\DiagnosisContextSnapshot;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Diagnosis accuracy (rules_version 2026-10-26.1, plan diagnosis-accuracy-20261026 sections 4.1 - 4.3):
 *  - 4.1 the ONU is read from olt_onus (fresh every ~2 min), not from the tracker stamp that only moves on a phase change;
 *  - 4.2 honest scoring: per rules version, no back-filled predictions, family next to strict, Wilson interval,
 *    bulk closes reported but not scored, a bounded context snapshot per scored prediction;
 *  - 4.3 four deterministic rule fixes and the abstain gate.
 * One fixture per ticket pattern of plan section 2 (ids only, synthetic data, no customer data).
 */
#[Group('blocking-core')]
class DiagnosisAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-26 03:00:00', 'UTC'); // Monday 10:00 WIB
        Carbon::setTestNow($this->now);
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function ago(int $minutes): Carbon
    {
        return $this->now->copy()->subMinutes($minutes);
    }

    private function olt(string $vendor = 'zte_c300'): Olt
    {
        $olt = Olt::create(['name' => 'OLT ACC '.($this->seq + 1), 'code' => 'ACC-'.++$this->seq, 'vendor' => $vendor, 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        config(['outage.olt_ids' => [$olt->id], 'outage.serial_fallback_olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function customer(?Olt $olt, ?string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'ACC-PKG'], ['name' => 'ACC pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'ACC-C'.$n, 'name' => 'Pelanggan ACC '.$n, 'phone' => '0812777'.$n, 'address' => 'Jl. Contoh',
            'pppoe_user' => 'USER_ACC_'.$n, 'package_id' => $package->id, 'status' => 'active',
            'olt_id' => $olt?->id, 'olt_port_label' => $ref,
        ], $o));
    }

    private function tracker(Olt $olt, string $ref, string $phase, ?Carbon $since, ?Carbon $collected, ?Customer $c = null, ?array $rxDaily = null): OutageOnuTracker
    {
        return OutageOnuTracker::create([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c?->id, 'phase' => $phase,
            'phase_since_at' => $since, 'last_state_collected_at' => $collected, 'rx_daily' => $rxDaily,
        ]);
    }

    /** One olt_onus row as the collectors write it. $kind: online | los | dying_gasp | offline */
    private function onuRow(Olt $olt, string $ref, string $kind, ?Carbon $collected = null): OltOnu
    {
        $collected ??= $this->now;
        $row = match ($kind) {
            'online' => ['state' => 'working', 'phase_state' => 'working'],
            'los' => ['state' => 'offline', 'phase_state' => 'LOS'],
            'dying_gasp' => ['state' => 'offline', 'phase_state' => 'DyingGasp'],
            default => ['state' => 'offline', 'phase_state' => 'OffLine'],
        };

        return OltOnu::create($row + [
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'pon_port' => substr($ref, 0, (int) strrpos($ref, ':')),
            'state_collected_at' => $collected, 'phase_collected_at' => $collected,
        ]);
    }

    private function dropEvent(Olt $olt, string $ref, Carbon $at, string $to = 'los', ?string $from = 'online', string $source = 'olt_poll'): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => null, 'from_phase' => $from, 'to_phase' => $to,
            'cause' => in_array($to, ['los', 'dying_gasp'], true) ? $to : null, 'source' => $source,
            'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    private function correlator(): OutageCorrelator
    {
        return app(OutageCorrelator::class);
    }

    private function diagnoser(): OutageDiagnoser
    {
        return new OutageDiagnoser($this->correlator());
    }

    /** A healthy customer: ONU online and fresh, internet up, router normal, a ZTE OLT with a 30 ONU PON. */
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
                'phase' => 'online', 'since' => null, 'since_hint' => null, 'collected_at' => $now, 'fresh' => true, 'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false,
                'flapping' => false, 'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null, 'events_24h' => 0, 'dg_in_24h' => false,
                'rx_dbm' => -20.0, 'rx_weak' => false, 'rx_trend' => false, 'olt_probe' => null, 'drops' => [],
                'rx_step' => null, 'rx_step_peers_pon' => 0, 'rx_step_peers_odp' => 0, 'rx_decline' => null,
            ],
            'pppoe' => ['online' => true, 'fresh' => true, 'at' => $now, 'source' => 'poll', 'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => true, 'router_session_uptime_s' => null, 'router_session' => null, 'events' => []],
            'history' => [
                'onu_drops' => [], 'ppp_drops' => [], 'ppp_source' => 'none', 'ppp_last' => null, 'onu_log' => true, 'ppp_log' => true,
                'rx' => ['dbm' => -20.0, 'at' => $now->copy()->subMinutes(30), 'old' => false], 'ticket_rx_complaint' => false,
            ],
        ];

        return array_replace_recursive($base, $o);
    }

    private function classify(array $ctx): array
    {
        return $this->diagnoser()->classify($ctx);
    }

    private function drop(int $minutesAgo, ?string $cause, string $source = 'olt_poll', bool $area = false): array
    {
        return ['at' => $this->ago($minutesAgo), 'cause' => $cause, 'source' => $source, 'area' => $area];
    }

    /** @return list<string> */
    private function texts(array $r): array
    {
        return array_column($r['evidence'], 'text');
    }

    // ------------------------------------------------------------------ 4.1 freshness (database)

    public function test_a_stale_tracker_stamp_with_a_fresh_olt_onus_row_gives_a_fresh_online_onu(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(300), $c); // moved 5 hours ago: the old 20 minute check said "unread"
        $this->onuRow($olt, '1/2/3:4', 'online', $this->ago(2));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame('online', $ctx['onu']['phase']);
        $this->assertSame('olt_onus', $ctx['onu']['phase_source']);
        $this->assertSame(2, (int) round(abs($ctx['onu']['collected_at']->diffInMinutes($this->now))));
    }

    public function test_a_collector_row_older_than_the_window_is_still_unread(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(300), $c);
        $this->onuRow($olt, '1/2/3:4', 'online', $this->ago(45));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertFalse($ctx['onu']['fresh']);
    }

    public function test_los_comes_from_olt_onus_and_the_start_comes_from_the_state_events_when_the_tracker_lost_it(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'unknown', null, $this->ago(300), $c); // a stale gap turned it "unknown" and erased the start
        $this->onuRow($olt, '1/2/3:4', 'los', $this->ago(1));
        $this->dropEvent($olt, '1/2/3:4', $this->ago(40));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertSame('los', $ctx['onu']['phase']);
        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame('los', $ctx['onu']['cause']);
        $this->assertTrue($ctx['onu']['cause_fresh']);
        $this->assertNull($ctx['onu']['since']);
        $this->assertNotNull($ctx['onu']['since_hint'], 'the start is taken from the last drop in onu_state_events');
        $this->assertSame(40, (int) round(abs($ctx['onu']['since_hint']->diffInMinutes($this->now))));
        $this->assertSame('drop_los', $this->classify($ctx)['cause_code']);
    }

    public function test_the_phase_start_from_the_tracker_is_kept_while_the_collector_agrees(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'los', $this->ago(90), $this->ago(300), $c); // detector stamp stale, phase unchanged
        $this->onuRow($olt, '1/2/3:4', 'los', $this->ago(1));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame(90, (int) round(abs($ctx['onu']['since']->diffInMinutes($this->now))));
    }

    public function test_huawei_gadang_path_uses_olt_onus_too(): void
    {
        $olt = $this->olt('huawei_ma5608t');
        $c = $this->customer($olt, '0/1/3:12');
        $this->tracker($olt, '0/1/3:12', 'offline', $this->ago(600), $this->ago(1400), $c); // tracker stale > 24 h, as on the 116 stale Gadang ONUs
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '0/1/3:12', 'state' => 'offline', 'state_collected_at' => $this->ago(2)]);

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertSame('huawei', $ctx['olt']['family']);
        $this->assertSame('offline', $ctx['onu']['phase']);
        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame(600, (int) round(abs($ctx['onu']['since']->diffInMinutes($this->now))));
    }

    public function test_without_an_olt_onus_row_the_tracker_decides_as_before(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(5), $c);

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame('tracker', $ctx['onu']['phase_source']);
    }

    public function test_pon_down_now_counts_the_witnesses_from_olt_onus_not_from_stale_tracker_stamps(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'unknown', null, $this->ago(300), $c);
        $this->onuRow($olt, '1/2/3:4', 'los', $this->ago(1));
        $this->dropEvent($olt, '1/2/3:4', $this->ago(25));
        foreach ([5, 6, 7] as $n) { // three more ONUs of the PON: down at the collector, tracker stale and without a start
            $this->tracker($olt, '1/2/3:'.$n, 'unknown', null, $this->ago(300));
            $this->onuRow($olt, '1/2/3:'.$n, 'los', $this->ago(1));
            $this->dropEvent($olt, '1/2/3:'.$n, $this->ago(26));
        }
        foreach ([8, 9] as $n) {
            $this->tracker($olt, '1/2/3:'.$n, 'online', null, $this->ago(300));
            $this->onuRow($olt, '1/2/3:'.$n, 'online', $this->ago(1));
        }
        // a leftover: down at the collector, no start anywhere = never a witness
        $this->tracker($olt, '1/2/3:10', 'unknown', null, $this->ago(300));
        $this->onuRow($olt, '1/2/3:10', 'los', $this->ago(1));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertSame(4, $ctx['pon']['down_now'], 'this ONU + three witnesses, the leftover not counted');
        $this->assertSame(6, $ctx['pon']['known_total']);
    }

    public function test_the_diagnosis_of_a_stale_tracker_onu_is_a_real_answer_not_unread(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4', ['is_online' => true, 'pppoe_online_checked_at' => $this->ago(3)]);
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(720), $c);
        $this->onuRow($olt, '1/2/3:4', 'online', $this->ago(2));

        $d = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH, ['now' => $this->now]);

        $this->assertNotContains('Status ONU belum terbaca dari OLT.', array_column((array) $d->evidence, 'text'));
        $this->assertSame(Rules::RULES_VERSION, $d->rules_version);
        $this->assertSame('2026-10-28.1', Rules::RULES_VERSION);
    }

    // ------------------------------------------------------------------ 4.2 context snapshot

    public function test_scored_diagnoses_store_a_bounded_context_without_customer_identifiers(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(5), $c);

        $open = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_TICKET_OPEN, ['now' => $this->now]);
        $manual = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_MANUAL, ['now' => $this->now]);
        $refresh = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH, ['now' => $this->now]);
        $episode = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_EPISODE, ['now' => $this->now]);

        $this->assertIsArray($open->fresh()->context);
        $this->assertIsArray($manual->fresh()->context);
        $this->assertNull($refresh->fresh()->context, 'the 15 minute refresh is never scored: no snapshot');
        // detector episodes keep a snapshot too (learn & measure, setting diagnosis_episode_snapshot_enabled, default on)
        $this->assertIsArray($episode->fresh()->context);
        $json = (string) DB::table('outage_diagnoses')->where('id', $open->id)->value('context');
        $this->assertLessThanOrEqual(DiagnosisContextSnapshot::MAX_BYTES, strlen($json));
        $this->assertStringNotContainsString('USER_ACC_', $json, 'no PPPoE username');
        $this->assertStringNotContainsString('"pppoe_user"', $json);
        $this->assertSame(Rules::RULES_VERSION, $open->fresh()->context['rules_version']);
        $this->assertSame('1/2/3:4', $open->fresh()->context['customer']['ref']);
    }

    public function test_the_snapshot_is_cut_to_a_fixed_size(): void
    {
        $big = $this->ctx([
            'history' => ['onu_drops' => array_fill(0, 400, $this->drop(30, 'los')), 'ppp_drops' => array_fill(0, 400, ['at' => $this->ago(5), 'end' => null, 'explained' => false, 'sources' => ['syslog'], 'reason' => str_repeat('x', 500)])],
            'pppoe' => ['events' => array_fill(0, 400, ['event' => 'stuck', 'source' => 'syslog', 'reason' => str_repeat('y', 500), 'at' => $this->ago(3)])],
            'probes' => ['router' => ['raw' => str_repeat('z', 50000)]],
        ]);

        $snap = DiagnosisContextSnapshot::of($big);

        $this->assertLessThanOrEqual(DiagnosisContextSnapshot::MAX_BYTES, strlen((string) json_encode($snap)));
        $this->assertArrayNotHasKey('probes', $snap);
        $this->assertArrayNotHasKey('pppoe_user', $snap['customer']);
        $this->assertSame('2026-10-26T03:00:00+00:00', $snap['now']);
    }

    // ------------------------------------------------------------------ 4.3 rule 1: pppoe_stuck only while the session is down

    public function test_t415_already_active_lines_while_the_session_is_up_are_not_stuck(): void
    {
        $r = $this->classify($this->ctx(['pppoe' => ['stuck_count' => 4, 'stuck_at' => $this->ago(24), 'online' => true]]));

        $this->assertNotSame('pppoe_stuck', $r['cause_code']);
    }

    public function test_already_active_lines_with_the_session_down_now_are_stuck(): void
    {
        $r = $this->classify($this->ctx(['pppoe' => ['stuck_count' => 4, 'stuck_at' => $this->ago(4), 'online' => false]]));

        $this->assertSame('pppoe_stuck', $r['cause_code']);
        $this->assertSame('tinggi', $r['confidence']);
    }

    public function test_already_active_lines_with_an_unknown_session_state_are_not_stuck(): void
    {
        $r = $this->classify($this->ctx(['pppoe' => ['stuck_count' => 4, 'stuck_at' => $this->ago(4), 'online' => null, 'fresh' => false]]));

        $this->assertNotSame('pppoe_stuck', $r['cause_code']);
    }

    // ------------------------------------------------------------------ 4.3 rule 2: isolir needs the router side

    private function billingOnly(array $o = []): array
    {
        return array_replace_recursive(['customer' => ['status' => 'isolated'], 'admin' => ['billing' => true, 'status' => 'isolated', 'db_isolir' => false, 'secret_state' => 'none']], $o);
    }

    public function test_t408_the_isolation_profile_in_the_database_keeps_isolir_even_when_the_real_fault_is_the_cable(): void
    {
        // The real T289/T408 customer has the isolation profile in the DB: rule 2 does not apply, so it stays
        // isolir_admin (wrong against the drop_los label). Documented, not hidden.
        $r = $this->classify($this->ctx($this->billingOnly(['admin' => ['db_isolir' => true]])));

        $this->assertSame('isolir_admin', $r['cause_code']);
    }

    public function test_billing_isolation_with_the_session_up_is_a_note_and_the_onu_rules_decide(): void
    {
        // no isolation profile in the DB, the ONU is in LOS: the cable decides, the billing status rides along
        $r = $this->classify($this->ctx($this->billingOnly() + ['onu' => ['phase' => 'los', 'since' => $this->ago(30), 'cause' => 'los', 'cause_at' => $this->now, 'cause_source' => 'olt_poll', 'cause_fresh' => true]]));

        $this->assertSame('drop_los', $r['cause_code']);
        $this->assertContains('isolir_admin', $r['secondary']);
    }

    public function test_billing_isolation_without_any_router_evidence_abstains(): void
    {
        // no OLT link, no fresh poll: only the billing status is known
        $r = $this->classify($this->ctx($this->billingOnly([
            'olt' => ['id' => null, 'name' => null, 'family' => null],
            'customer' => ['olt_id' => null, 'ref' => null, 'pon' => null],
            'onu' => ['phase' => null, 'fresh' => false, 'collected_at' => null],
            'pppoe' => ['online' => null, 'fresh' => false, 'at' => null],
        ])));

        $this->assertSame('unknown', $r['cause_code']);
        $this->assertContains('isolir_admin', $r['secondary'], 'shown as "Bisa juga"');
        $this->assertContains('Di tagihan isolir, tapi belum ada tanda isolir di router.', $this->texts($r));
        $this->assertContains('Pelanggan ini belum punya data OLT.', $this->texts($r));
    }

    public function test_billing_isolation_with_a_fresh_poll_showing_the_session_down_is_decisive(): void
    {
        $r = $this->classify($this->ctx($this->billingOnly(['pppoe' => ['online' => false, 'fresh' => true]])));

        $this->assertSame('isolir_admin', $r['cause_code']);
        $this->assertSame('sedang', $r['confidence']);
    }

    public function test_isolation_confirmed_by_the_router_profile_stays_decisive(): void
    {
        $r = $this->classify($this->ctx($this->billingOnly(['admin' => ['db_isolir' => true]])));

        $this->assertSame('isolir_admin', $r['cause_code']);
        $this->assertSame('tinggi', $r['confidence']);
        $live = $this->classify($this->ctx($this->billingOnly(['admin' => ['secret_state' => 'isolated']])));
        $this->assertSame('isolir_admin', $live['cause_code']);
    }

    // ------------------------------------------------------------------ 4.3 rule 3: one authoritative dying gasp in 6 h

    public function test_t422_still_in_dying_gasp_at_ticket_time_is_power_off_through_fresh_data(): void
    {
        // The real T422 was fixed by 4.1 (the tracker stamp was stale), not by the 6 h rule: DG 10 h earlier, still down.
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'dying_gasp', $this->ago(609), $this->ago(22), $c); // stamp 22 min old: "unread" before
        $this->onuRow($olt, '1/2/3:4', 'dying_gasp', $this->ago(2));

        $r = $this->classify($this->correlator()->contextFor($c->fresh(), $this->now));

        $this->assertSame('power_off', $r['cause_code']);
        $this->assertSame('tinggi', $r['confidence']);
    }

    public function test_a_single_dying_gasp_does_not_decide_while_the_session_is_down_on_a_fresh_read(): void
    {
        // T397 pattern: DG 88 min before, ONU online again, but the internet session is down now
        $drops = ['history' => ['onu_drops' => [$this->drop(88, 'dying_gasp')]]];
        $down = $this->classify($this->ctx($drops + ['pppoe' => ['online' => false, 'fresh' => true]]));
        $this->assertNotSame('power_off', $down['cause_code']);
        $up = $this->classify($this->ctx($drops));
        $this->assertSame('power_off', $up['cause_code']);
    }

    public function test_pon_witnesses_must_have_gone_down_with_this_onu_not_days_ago(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'los', $this->ago(10), $this->ago(300), $c);
        $this->onuRow($olt, '1/2/3:4', 'los', $this->ago(1));
        foreach ([5, 6] as $n) { // two standing dead ONUs of the PON, down for 3 days
            $this->tracker($olt, '1/2/3:'.$n, 'los', $this->ago(60 * 72), $this->ago(300));
            $this->onuRow($olt, '1/2/3:'.$n, 'los', $this->ago(1));
        }
        $this->tracker($olt, '1/2/3:7', 'los', $this->ago(12), $this->ago(300)); // went down with this one
        $this->onuRow($olt, '1/2/3:7', 'los', $this->ago(1));

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertSame(2, $ctx['pon']['down_now'], 'this ONU + the one that dropped with it, not the 3 day old ones');
        $this->assertSame(4, $ctx['pon']['known_total'], 'the old ones still count as known ONUs');
    }

    public function test_a_log_line_with_invalid_utf8_does_not_lose_the_diagnosis(): void
    {
        // invalid UTF-8 as a router log reason would carry it
        $snap = DiagnosisContextSnapshot::of($this->ctx(['pppoe' => ['events' => [['event' => 'disconnected', 'source' => 'syslog', 'reason' => "peer \xB1\xFF gone", 'at' => $this->ago(30)]]]]));

        $this->assertIsString(json_encode($snap), 'what is stored always encodes');
        $this->assertArrayNotHasKey('truncated', $snap);
        $this->assertStringStartsWith('peer', $snap['pppoe']['events'][0]['reason']);

        // and a diagnosis row with the snapshot is stored as usual
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(5), $c);
        $d = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_TICKET_OPEN, ['now' => $this->now]);
        $this->assertIsArray($d->fresh()->context);
    }

    public function test_the_snapshot_keeps_the_newest_entries_whatever_the_list_order(): void
    {
        $events = [];
        foreach (range(0, 29) as $i) { // newest first, as pppoe.events
            $events[] = ['event' => 'connected', 'source' => 'syslog', 'reason' => null, 'at' => $this->ago($i)];
        }
        $snap = DiagnosisContextSnapshot::of($this->ctx(['pppoe' => ['events' => $events]]));

        $this->assertCount(DiagnosisContextSnapshot::MAX_LIST, $snap['pppoe']['events']);
        $times = array_column($snap['pppoe']['events'], 'at');
        $this->assertContains($this->ago(0)->utc()->toIso8601String(), $times, 'the newest line is kept');
        $this->assertNotContains($this->ago(29)->utc()->toIso8601String(), $times);
    }

    public function test_one_dying_gasp_in_the_last_6_hours_is_power_off_when_the_onu_is_back(): void
    {
        $r = $this->classify($this->ctx(['history' => ['onu_drops' => [$this->drop(300, 'dying_gasp')]]]));

        $this->assertSame('power_off', $r['cause_code']);
        $this->assertSame('sedang', $r['confidence']);
        $this->assertSame('tanya_listrik', $r['suggested_action']);
        $this->assertSame('onu_history', $r['rule_id']);
    }

    public function test_one_dying_gasp_with_the_onu_offline_without_a_cause_is_power_off(): void
    {
        $r = $this->classify($this->ctx(['onu' => ['phase' => 'offline', 'since' => $this->ago(200), 'cause' => null], 'history' => ['onu_drops' => [$this->drop(200, 'dying_gasp', 'olt_detail')]]]));

        $this->assertSame('power_off', $r['cause_code']);
        $this->assertSame('sedang', $r['confidence']);
    }

    public function test_a_dying_gasp_older_than_6_hours_or_followed_by_a_los_is_not_power_off(): void
    {
        $old = $this->classify($this->ctx(['history' => ['onu_drops' => [$this->drop(400, 'dying_gasp')]]]));
        $this->assertNotSame('power_off', $old['cause_code']);

        $after = $this->classify($this->ctx(['history' => ['onu_drops' => [$this->drop(200, 'dying_gasp'), $this->drop(60, 'los')]]]));
        $this->assertNotSame('power_off', $after['cause_code']);
    }

    public function test_a_dying_gasp_without_a_restart_of_the_onu_is_not_power_off(): void
    {
        // the CPE reports an uptime of two days and no boot after the dying gasp: the ONU kept running
        $cpe = ['present' => true, 'last_inform_at' => $this->ago(3), 'uptime_now_s' => 172800, 'boot_times' => [$this->ago(60 * 48)], 'boots_24h' => 0];
        $r = $this->classify($this->ctx(['cpe' => $cpe, 'history' => ['onu_drops' => [$this->drop(200, 'dying_gasp')]]]));

        $this->assertNotSame('power_off', $r['cause_code']);
        $restarted = $this->classify($this->ctx(['cpe' => ['uptime_now_s' => 600, 'boot_times' => [$this->ago(10)]] + $cpe, 'history' => ['onu_drops' => [$this->drop(200, 'dying_gasp')]]]));
        $this->assertSame('power_off', $restarted['cause_code']);
    }

    public function test_an_isolated_customer_with_one_old_dying_gasp_is_still_isolir(): void
    {
        $r = $this->classify($this->ctx($this->billingOnly(['admin' => ['db_isolir' => true], 'history' => ['onu_drops' => [$this->drop(200, 'dying_gasp')]]])));

        $this->assertSame('isolir_admin', $r['cause_code']);
    }

    // ------------------------------------------------------------------ 4.3 rule 4: optical

    public function test_weak_rx_alone_is_a_note_not_the_answer(): void
    {
        $r = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.45, 'rx_weak' => true]]));

        $this->assertNotSame('optical_degrade', $r['cause_code']);
        $this->assertContains('optical_degrade', $r['secondary']);
    }

    public function test_t409_an_rx_step_in_a_day_is_the_optical_answer(): void
    {
        $r = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.7, 'rx_weak' => true, 'rx_step' => ['from' => -19.5, 'to' => -27.7, 'drop' => 8.2, 'date' => '2026-10-25']]]));

        $this->assertSame('optical_degrade', $r['cause_code']);
        $this->assertSame('sedang', $r['confidence']);
        $this->assertContains('Redaman turun mendadak dari -19,5 ke -27,7 dBm dalam sehari.', $this->texts($r));
    }

    public function test_t410_a_steady_decline_is_the_optical_answer(): void
    {
        $r = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.2, 'rx_weak' => true, 'rx_decline' => ['from' => -25.1, 'to' => -27.2]]]));

        $this->assertSame('optical_degrade', $r['cause_code']);
        $this->assertContains('Redaman turun terus 3 hari (-25,1 ke -27,2 dBm).', $this->texts($r));
    }

    public function test_two_los_drops_in_24_hours_with_a_weak_rx_make_an_optical_answer(): void
    {
        $drops = ['history' => ['onu_drops' => [$this->drop(600, 'los'), $this->drop(300, 'los')]]];
        $r = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.9, 'rx_weak' => true]] + $drops));

        // two LOS drops alone are already drop_los (history rule, earlier in the table); the optical rule must not break that
        $this->assertSame('drop_los', $r['cause_code']);
        $weakOnly = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.9, 'rx_weak' => true], 'history' => ['onu_drops' => [$this->drop(600, null)]]]));
        $this->assertNotSame('optical_degrade', $weakOnly['cause_code'], 'one cause-less drop with a weak RX is not enough');
        $two = $this->classify($this->ctx(['onu' => ['rx_dbm' => -27.9, 'rx_weak' => true], 'history' => ['onu_drops' => [$this->drop(600, null), $this->drop(300, null)]]]));
        $this->assertSame('optical_degrade', $two['cause_code']);
        $this->assertContains('ONU putus 2 kali dalam 24 jam dan redaman lemah.', $this->texts($two));
    }

    public function test_the_same_rx_step_on_onus_of_one_odp_adds_the_shared_fibre_line_and_odp_cut(): void
    {
        $step = ['from' => -19.5, 'to' => -27.7, 'drop' => 8.2, 'date' => '2026-10-25'];
        $odp = $this->classify($this->ctx(['onu' => ['rx_weak' => true, 'rx_step' => $step, 'rx_step_peers_odp' => 1, 'rx_step_peers_pon' => 3]]));
        $this->assertSame('optical_degrade', $odp['cause_code']);
        $this->assertContains('odp_cut', $odp['secondary']);
        $this->assertContains('2 ONU satu ODP redamannya turun bersamaan. Kemungkinan kabel bersama bermasalah.', $this->texts($odp));

        $pon = $this->classify($this->ctx(['onu' => ['rx_weak' => true, 'rx_step' => $step, 'rx_step_peers_odp' => 0, 'rx_step_peers_pon' => 2]]));
        $this->assertContains('odp_cut', $pon['secondary']);
        $this->assertContains('3 ONU satu PON redamannya turun bersamaan. Kemungkinan kabel bersama bermasalah.', $this->texts($pon));

        $alone = $this->classify($this->ctx(['onu' => ['rx_weak' => true, 'rx_step' => $step]]));
        $this->assertNotContains('odp_cut', $alone['secondary']);
    }

    public function test_t415_a_stable_weak_rx_no_longer_pre_empts_the_wifi_rules(): void
    {
        $wifi = ['cpe' => ['present' => true, 'last_inform_at' => $this->ago(2), 'wlan_transitions_24h' => 3, 'wlan_fresh' => true]];
        $r = $this->classify($this->ctx($wifi + ['onu' => ['rx_dbm' => -27.45, 'rx_weak' => true]]));

        $this->assertSame('wlan_flap', $r['cause_code']);
        // a strong optical sign still wins over the WiFi rule
        $strong = $this->classify($this->ctx($wifi + ['onu' => ['rx_dbm' => -27.45, 'rx_weak' => true, 'rx_decline' => ['from' => -25.0, 'to' => -27.45]]]));
        $this->assertSame('optical_degrade', $strong['cause_code']);
    }

    public function test_rx_step_and_decline_come_from_the_daily_medians_and_peers_from_the_pon(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4', ['odp_name' => 'ODP-UJI-1']);
        $peer = $this->customer($olt, '1/2/3:5', ['odp_name' => 'ODP-UJI-1']);
        $other = $this->customer($olt, '1/2/3:6', ['odp_name' => 'ODP-UJI-2']);
        $day = fn (int $ago) => $this->now->copy()->timezone('Asia/Jakarta')->subDays($ago)->format('Y-m-d');
        $stepDays = [['date' => $day(3), 'dbm' => -19.5, 'n' => 9], ['date' => $day(2), 'dbm' => -19.6, 'n' => 9], ['date' => $day(1), 'dbm' => -27.7, 'n' => 9]];
        $this->tracker($olt, '1/2/3:4', 'online', null, $this->ago(2), $c, $stepDays);
        $this->tracker($olt, '1/2/3:5', 'online', null, $this->ago(2), $peer, $stepDays);
        $this->tracker($olt, '1/2/3:6', 'online', null, $this->ago(2), $other, $stepDays);
        $this->tracker($olt, '1/2/3:7', 'online', null, $this->ago(2), null, [['date' => $day(3), 'dbm' => -19.5, 'n' => 9], ['date' => $day(1), 'dbm' => -20.0, 'n' => 9]]);

        $ctx = $this->correlator()->contextFor($c->fresh(), $this->now);

        $this->assertSame(['from' => -19.6, 'to' => -27.7, 'drop' => 8.1, 'date' => $day(1)], $ctx['onu']['rx_step']);
        $this->assertSame(2, $ctx['onu']['rx_step_peers_pon'], 'two other ONUs of the PON stepped on the same day');
        $this->assertSame(1, $ctx['onu']['rx_step_peers_odp'], 'one of them is on the same ODP');

        $decline = $this->customer($olt, '1/2/3:20');
        $this->tracker($olt, '1/2/3:20', 'online', null, $this->ago(2), $decline, [['date' => $day(3), 'dbm' => -25.0, 'n' => 9], ['date' => $day(2), 'dbm' => -26.2, 'n' => 9], ['date' => $day(1), 'dbm' => -27.1, 'n' => 9]]);
        $ctx = $this->correlator()->contextFor($decline->fresh(), $this->now);
        $this->assertNull($ctx['onu']['rx_step']);
        $this->assertSame(['from' => -25.0, 'to' => -27.1], $ctx['onu']['rx_decline']);
    }

    // ------------------------------------------------------------------ 4.3 abstain gate

    private function pppFlapOnStaleData(array $o = []): array
    {
        $drops = array_map(fn (int $m) => ['at' => $this->ago($m), 'end' => null, 'explained' => false, 'sources' => ['syslog'], 'reason' => null], [500, 300, 100]);

        return $this->ctx(array_replace_recursive([
            'onu' => ['fresh' => false, 'collected_at' => $this->ago(120)],
            'pppoe' => ['online' => null, 'fresh' => false, 'at' => $this->ago(120)],
            'history' => ['ppp_drops' => $drops, 'ppp_source' => 'syslog'],
        ], $o));
    }

    public function test_a_decisive_answer_without_a_fresh_hardware_item_becomes_unknown(): void
    {
        $r = $this->classify($this->pppFlapOnStaleData());

        $this->assertSame('unknown', $r['cause_code']);
        $this->assertSame('rendah', $r['confidence']);
        $this->assertSame('abstain_gate', $r['rule_id']);
        $this->assertSame(['pppoe_flap'], $r['secondary'], 'the gated answer stays as "Bisa juga"');
        $this->assertContains('Belum ada data perangkat yang baru. Cek manual.', $this->texts($r));
    }

    public function test_the_same_answer_with_a_fresh_onu_read_is_kept(): void
    {
        $r = $this->classify($this->pppFlapOnStaleData(['onu' => ['fresh' => true, 'collected_at' => $this->ago(2)]]));

        $this->assertSame('pppoe_flap', $r['cause_code']);
    }

    public function test_an_authoritative_event_of_the_last_24_hours_also_counts_as_hardware(): void
    {
        $r = $this->classify($this->pppFlapOnStaleData(['history' => ['onu_drops' => [$this->drop(900, 'dying_gasp', 'olt_detail')]]]));

        $this->assertSame('pppoe_flap', $r['cause_code']);
    }

    public function test_billing_unknown_and_data_blind_answers_pass_the_gate_untouched(): void
    {
        $iso = $this->classify($this->ctx($this->billingOnly(['admin' => ['db_isolir' => true], 'onu' => ['fresh' => false, 'phase' => null], 'pppoe' => ['online' => null, 'fresh' => false]])));
        $this->assertSame('isolir_admin', $iso['cause_code']);
        $blind = $this->classify($this->ctx(['olt' => ['blind' => ['id' => 5, 'reason' => 'collector_stale']], 'onu' => ['fresh' => false, 'phase' => null]]));
        $this->assertSame('data_blind', $blind['cause_code']);
    }

    public function test_a_customer_without_an_olt_link_says_so(): void
    {
        $r = $this->classify($this->ctx([
            'olt' => ['id' => null, 'name' => null, 'family' => null], 'customer' => ['olt_id' => null, 'ref' => null, 'pon' => null],
            'onu' => ['phase' => null, 'fresh' => false, 'collected_at' => null],
        ]));

        $this->assertSame('unknown', $r['cause_code']);
        $this->assertContains('Pelanggan ini belum punya data OLT.', $this->texts($r));
        $this->assertNotContains('Status ONU belum terbaca dari OLT.', $this->texts($r));
    }

    public function test_the_kill_switch_keeps_the_old_rules(): void
    {
        // billing only, session up: the 2026-10-21.1 rule still calls it isolir
        $r = $this->classify($this->ctx($this->billingOnly(['rca5' => false])));

        $this->assertSame('isolir_admin', $r['cause_code']);
        $this->assertSame('tinggi', $r['confidence']);
    }

    // ------------------------------------------------------------------ 4.2 scoring

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A closed incident ticket. $created and $resolved are WIB strings; the cause is set by $source.
     *
     * @param  array<string,mixed>  $o
     */
    private function closed(string $cause, string $source, string $created = '2026-10-14 08:00:00', string $resolved = '2026-10-14 12:00:00', array $o = []): SupportTicket
    {
        $t = SupportTicket::create($o + [
            'created_by' => $this->admin()->id, 'gangguan' => 'Mati', 'status' => 'closed', 'type' => 'incident', 'priority' => 'normal',
            'root_cause' => $cause, 'root_cause_source' => $source,
        ]);
        DB::table('support_tickets')->where('id', $t->id)->update([
            'created_at' => Carbon::parse($created, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s'),
            'resolved_at' => Carbon::parse($resolved, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s'),
        ]);

        return $t;
    }

    /** A prediction written $minutes after the ticket was created (WIB string of the ticket creation). */
    private function predicted(SupportTicket $t, string $cause, ?string $version, int $minutes = 0, string $trigger = 'ticket_open'): OutageDiagnosis
    {
        $at = Carbon::parse((string) DB::table('support_tickets')->where('id', $t->id)->value('created_at'), 'UTC')->addMinutes($minutes);
        $d = OutageDiagnosis::create(['customer_id' => 1, 'ticket_id' => $t->id, 'trigger' => $trigger, 'state' => 'done', 'cause_code' => $cause, 'confidence' => 'sedang', 'rules_version' => $version]);
        DB::table('outage_diagnoses')->where('id', $d->id)->update(['created_at' => $at->format('Y-m-d H:i:s')]);

        return $d;
    }

    private function report(): array
    {
        return app(PrecisionReport::class)->compute(Carbon::parse('2026-10-12', 'Asia/Jakarta'));
    }

    public function test_wilson_interval_math(): void
    {
        $ci = PrecisionReport::wilson(2, 8);
        $this->assertEqualsWithDelta(0.0715, $ci['low'], 0.0005);
        $this->assertEqualsWithDelta(0.5907, $ci['high'], 0.0005);
        $half = PrecisionReport::wilson(1, 2);
        $this->assertEqualsWithDelta(0.0945, $half['low'], 0.0005);
        $this->assertEqualsWithDelta(0.9055, $half['high'], 0.0005);
        $all = PrecisionReport::wilson(8, 8);
        $this->assertSame(1.0, $all['high']);
        $this->assertLessThan(0.7, $all['low']);
        $none = PrecisionReport::wilson(0, 5);
        $this->assertSame(0.0, $none['low']);
        $this->assertNull(PrecisionReport::wilson(0, 0));
    }

    public function test_each_prediction_is_scored_under_its_own_rules_version(): void
    {
        $old = '2026-10-18.1';
        foreach ([1, 2, 3] as $i) {
            $this->predicted($this->closed('drop_los', 'technician'), 'pon_cut', $old);
        }
        $this->predicted($this->closed('drop_los', 'technician'), 'drop_los', $old);
        $this->predicted($this->closed('drop_los', 'technician'), 'drop_los', Rules::RULES_VERSION);
        $this->predicted($this->closed('power_off', 'technician'), 'unknown', Rules::RULES_VERSION);

        $r = $this->report();
        $by = collect($r['by_version'])->keyBy('version');

        $this->assertSame([Rules::RULES_VERSION, $old], array_column($r['by_version'], 'version'), 'newest first');
        $this->assertSame(2, $by[Rules::RULES_VERSION]['evaluated']);
        $this->assertSame(1, $by[Rules::RULES_VERSION]['abstained']);
        $this->assertSame(1, $by[Rules::RULES_VERSION]['predicted']);
        $this->assertSame(1, $by[Rules::RULES_VERSION]['correct']);
        $this->assertSame(0.5, $by[Rules::RULES_VERSION]['coverage']);
        $this->assertSame(4, $by[$old]['predicted']);
        $this->assertSame(1, $by[$old]['correct']);
        $this->assertSame(0.25, $by[$old]['precision']);
        $this->assertNotNull($by[$old]['ci']);
        // the all-version totals stay for the card
        $this->assertSame(6, $r['evaluated']);
        $this->assertSame(2, $r['correct']);
    }

    public function test_a_back_filled_ticket_open_is_not_a_prediction(): void
    {
        $late = $this->closed('drop_los', 'technician', '2026-10-07 08:00:00', '2026-10-14 09:00:00'); // opened a week before the rows below
        $this->predicted($late, 'drop_los', Rules::RULES_VERSION, 60 * 24 * 3);
        $ok = $this->closed('drop_los', 'technician');
        $this->predicted($ok, 'drop_los', Rules::RULES_VERSION, 1);

        $r = $this->report();

        $this->assertSame(1, $r['backfilled']);
        $this->assertSame(1, $r['evaluated']);
        $this->assertSame(0, $r['no_prediction']);
        $this->assertSame(1, $r['correct']);
    }

    public function test_the_15_minute_limit_and_the_manual_check_before_it(): void
    {
        $edge = $this->closed('drop_los', 'technician');
        $this->predicted($edge, 'drop_los', Rules::RULES_VERSION, 15); // exactly on the limit: timely
        $late = $this->closed('drop_los', 'technician');
        $this->predicted($late, 'drop_los', Rules::RULES_VERSION, 16); // one minute over: not
        $manual = $this->closed('drop_los', 'technician');
        $this->predicted($manual, 'pon_cut', Rules::RULES_VERSION, 120); // back-filled ticket_open
        $this->predicted($manual, 'drop_los', Rules::RULES_VERSION, -3, 'manual_check'); // "Cek sekarang" on the create form, before the ticket

        $r = $this->report();

        $this->assertSame(1, $r['backfilled']);
        $this->assertSame(2, $r['evaluated']);
        $this->assertSame(2, $r['correct'], 'the edge ticket and the timely manual check');
    }

    public function test_family_scoring_counts_the_fibre_classes_together_next_to_the_strict_class(): void
    {
        $this->predicted($this->closed('drop_los', 'technician'), 'optical_degrade', Rules::RULES_VERSION);   // family hit
        $this->predicted($this->closed('optical_degrade', 'technician'), 'odp_cut', Rules::RULES_VERSION);     // family hit
        $this->predicted($this->closed('drop_los', 'technician'), 'drop_los', Rules::RULES_VERSION);           // both
        $this->predicted($this->closed('drop_los', 'technician'), 'power_off', Rules::RULES_VERSION);          // neither
        $this->predicted($this->closed('power_off', 'technician'), 'cpe_dead', Rules::RULES_VERSION);          // neither (not a family)

        $r = $this->report();

        $this->assertSame(5, $r['predicted']);
        $this->assertSame(1, $r['correct']);
        $this->assertSame(3, $r['correct_family']);
        $this->assertSame(1.0, $r['coverage']);
        $cur = $r['by_version'][0];
        $this->assertSame(3, $cur['correct_family']);
        $this->assertSame(0.6, $cur['precision_family']);
        $this->assertSame(0.2, $cur['precision']);
        $this->assertSame('fibre', PrecisionReport::familyOf('odp_cut'));
        $this->assertSame('pon_cut', PrecisionReport::familyOf('pon_cut'));
        $this->assertCount(4, $r['confusions'], 'the confusion list still shows the strict misses');
    }

    public function test_an_admin_bulk_close_is_reported_but_not_scored(): void
    {
        $bulk = $this->closed('drop_los', 'admin', '2026-10-01 08:00:00', '2026-10-14 12:00:00'); // 13 days later
        $this->predicted($bulk, 'isolir_admin', Rules::RULES_VERSION);
        $admin = $this->closed('drop_los', 'admin', '2026-10-12 08:00:00', '2026-10-14 12:00:00'); // 2 days: a real label
        $this->predicted($admin, 'drop_los', Rules::RULES_VERSION);
        $tech = $this->closed('drop_los', 'technician', '2026-10-01 08:00:00', '2026-10-14 12:00:00'); // a technician late label is still scored
        $this->predicted($tech, 'drop_los', Rules::RULES_VERSION);

        $r = $this->report();

        $this->assertSame(1, $r['bulk_closed']);
        $this->assertSame(2, $r['evaluated']);
        $this->assertSame(2, $r['correct']);
        $this->assertSame(['technician' => 1, 'admin' => 1, 'admin_bulk' => 1, 'other' => 0], $r['labels']);
        $this->assertSame([], $r['confusions'], 'the bulk close is not in the confusion list');
    }

    public function test_the_week_text_shows_the_current_rules_with_the_interval_and_what_was_left_out(): void
    {
        $this->predicted($this->closed('drop_los', 'technician'), 'drop_los', Rules::RULES_VERSION);
        $this->predicted($this->closed('drop_los', 'technician'), 'optical_degrade', Rules::RULES_VERSION);
        $this->predicted($this->closed('drop_los', 'technician'), 'unknown', Rules::RULES_VERSION);
        $this->predicted($this->closed('drop_los', 'technician'), 'pon_cut', '2026-10-18.1');
        $this->predicted($this->closed('drop_los', 'technician', '2026-10-07 08:00:00'), 'drop_los', Rules::RULES_VERSION, 60 * 24 * 3);
        $this->predicted($this->closed('drop_los', 'admin', '2026-10-01 08:00:00'), 'drop_los', Rules::RULES_VERSION);

        $text = (string) app(PrecisionReport::class)->rootCauseText($this->report());

        $this->assertStringContainsString('Diagnosa sistem (aturan terbaru): cocok 1 dari 2 yang ditebak (50%, kisaran 9–91%)', $text);
        $this->assertStringContainsString('Kabel drop, redaman dan ODP dihitung satu jenis: 2 dari 2 (100%)', $text);
        $this->assertStringContainsString('Ditebak 2 dari 3 tiket (67%)', $text);
        $this->assertStringContainsString('Aturan lama: cocok 0 dari 1', $text);
        $this->assertStringContainsString('Tidak dinilai: tebakan telat 1, ditutup massal admin 1', $text);
        $this->assertStringNotContainsString('TCK-', $text);
    }

    public function test_the_precision_report_never_reads_the_context_snapshot_column(): void
    {
        $t = $this->closed('drop_los', 'technician');
        $d = $this->predicted($t, 'drop_los', Rules::RULES_VERSION);
        DB::table('outage_diagnoses')->where('id', $d->id)->update(['context' => json_encode(['x' => str_repeat('a', 9000)])]);

        DB::enableQueryLog();
        $this->report();
        $selects = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $q) => str_contains($q, 'outage_diagnoses') && str_starts_with(strtolower(ltrim($q)), 'select'));
        DB::disableQueryLog();

        $this->assertNotEmpty($selects);
        foreach ($selects as $q) {
            $this->assertStringNotContainsString('`context`', $q);
            $this->assertStringNotContainsString('select *', $q);
        }
    }
}
