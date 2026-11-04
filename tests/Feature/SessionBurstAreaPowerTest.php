<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Services\Outage\AreaPowerDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowEpisodeReadModel;
use App\Services\Outage\SessionBurstDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * PR-D detectors (outage:session-bursts) on fixtures shaped like the real cases of 2026-10-19..22 (fake customers, no real
 * names): a 17 customer burst in 19 s with the ONUs online, the 00:0x kick without reasons, a 63-of-65 LOS event, an
 * eBilling mass kick over 30 s, and the Krian-style 24 ONU dying-gasp group.
 */
#[Group('blocking-olt-mysql')]
class SessionBurstAreaPowerTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private int $seq = 0;

    private Router $router;

    private Olt $olt;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-28 03:10:00', 'UTC');
        Carbon::setTestNow($this->now);
        $this->router = Router::create(['name' => 'RA-OLT burst', 'ip_address' => '198.18.8.1', 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        $this->olt = Olt::create(['name' => 'OLT burst', 'code' => 'BU-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $this->package = Package::create(['code' => 'BU-PKG', 'name' => 'Burst pkg', 'price' => 100000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<Customer> */
    private function customers(int $n, string $pon = '1/2/3', ?int $areaId = null, string $status = 'active'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $k = ++$this->seq;
            $out[] = Customer::create([
                'code' => 'BU-C'.$k, 'name' => 'BUDI CONTOH '.$k, 'phone' => '6281200000'.str_pad((string) ($k % 100), 2, '0', STR_PAD_LEFT), 'address' => 'Jl. Contoh '.$k,
                'pppoe_user' => 'burst.user.'.$k, 'package_id' => $this->package->id, 'status' => $status, 'olt_id' => $this->olt->id,
                'olt_port_label' => $pon.':'.$k, 'router_id' => $this->router->id, 'area_id' => $areaId,
            ]);
        }

        return $out;
    }

    private function ppp(Customer $c, string $event, Carbon $at, ?string $reason = 'peer not responding', string $source = 'syslog'): void
    {
        DB::table('pppoe_session_events')->insert([
            'router_id' => $this->router->id, 'pppoe_user' => $c->pppoe_user, 'customer_id' => $c->id, 'event' => $event, 'reason' => $reason,
            'at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'source' => $source,
        ]);
    }

    /** @param  list<Customer>  $cs */
    private function disconnects(array $cs, Carbon $start, float $spanSeconds, ?string $reason = 'peer not responding'): void
    {
        $step = count($cs) > 1 ? $spanSeconds / (count($cs) - 1) : 0;
        foreach ($cs as $i => $c) {
            $this->ppp($c, 'disconnected', $start->copy()->addSeconds((int) round($i * $step)), $reason);
        }
    }

    private function onu(Customer $c, string $toPhase, Carbon $at, string $from = 'online'): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $this->olt->id, 'onu_ref' => $c->olt_port_label, 'customer_id' => $c->id, 'from_phase' => $from, 'to_phase' => $toPhase,
            'cause' => $toPhase === 'los' || $toPhase === 'dying_gasp' ? $toPhase : null, 'source' => 'olt_poll',
            'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    private function runCommand(): string
    {
        Artisan::call('outage:session-bursts');

        return Artisan::output();
    }

    // ------------------------------------------------------------------ session bursts

    public function test_17_customers_in_19_seconds_with_the_onus_online_is_a_burst(): void
    {
        $cs = $this->customers(17);
        $this->disconnects($cs, $this->now->copy()->subMinutes(8), 19);

        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'session_burst')->sole();
        $this->assertSame('router:'.$this->router->id.':olt:'.$this->olt->id.':pon:1/2/3', $s->scope_key);
        $this->assertSame(17, (int) $s->affected_count);
        $this->assertCount(17, (array) $s->affected_customer_ids);
        $this->assertSame($this->olt->id, (int) $s->olt_id);
        $this->assertSame('1/2/3', $s->pon_port);
        $this->assertSame(0.0, (float) ((array) $s->payload)['onu_down_share']);
        $this->assertSame('none', $s->would_action);
        $this->assertNull($s->ticket_id);
    }

    public function test_a_second_run_updates_the_same_row_and_never_adds_one(): void
    {
        $this->disconnects($this->customers(10), $this->now->copy()->subMinutes(8), 15);

        $this->runCommand();
        Carbon::setTestNow($this->now->copy()->addMinutes(5));
        $this->runCommand();

        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'session_burst')->count());
        $this->assertSame(2, (int) OutageSignal::query()->where('signal_type', 'session_burst')->value('observations'));
    }

    public function test_the_00_03_kick_without_reasons_in_4_seconds_is_not_a_burst(): void
    {
        $this->disconnects($this->customers(9), $this->now->copy()->subMinutes(5), 4, null);

        $out = $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->count());
        $this->assertStringContainsString('kick_no_reason=1', $out);
    }

    public function test_a_burst_with_reasons_over_the_same_span_still_counts(): void
    {
        $this->disconnects($this->customers(9), $this->now->copy()->subMinutes(5), 4, 'peer not responding');

        $this->runCommand();

        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'session_burst')->count());
    }

    public function test_63_of_65_onus_dying_too_is_a_burst_flagged_onu_ikut_mati(): void
    {
        $cs = $this->customers(65);
        $start = $this->now->copy()->subMinutes(9);
        $this->disconnects($cs, $start, 50);
        foreach (array_slice($cs, 0, 63) as $c) {
            $this->onu($c, 'los', $start->copy()->subSeconds(30));
        }

        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'session_burst')->sole();
        $this->assertGreaterThanOrEqual(0.9, (float) ((array) $s->payload)['onu_down_share']);
    }

    public function test_fewer_than_eight_customers_or_a_slow_trickle_is_not_a_burst(): void
    {
        $this->disconnects($this->customers(7), $this->now->copy()->subMinutes(8), 10);
        $this->disconnects($this->customers(12), $this->now->copy()->subMinutes(6), 600); // 12 customers in 10 minutes

        $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->count());
    }

    public function test_poll_lines_are_not_bursts_only_syslog_is(): void
    {
        foreach ($this->customers(10) as $i => $c) {
            $this->ppp($c, 'disconnected', $this->now->copy()->subMinutes(8)->addSeconds($i), 'peer not responding', 'poll');
        }

        $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->count());
    }

    public function test_an_admin_mass_kick_over_30_seconds_of_isolated_and_ledger_customers_is_no_burst(): void
    {
        $isolated = $this->customers(6, '1/2/3', null, 'isolated');
        $ledger = $this->customers(6);
        $logged = $this->customers(5);
        $start = $this->now->copy()->subMinutes(7);
        $this->disconnects(array_merge($isolated, $ledger, $logged), $start, 30);
        foreach ($ledger as $c) {
            DB::table('billing_enforcement_actions')->insert([
                'idempotency_key' => 'bu-'.$c->id, 'customer_id' => $c->id, 'action' => 'isolate', 'policy_version' => 'test', 'status' => 'done',
                'started_at' => $start->copy()->subSeconds(20)->format('Y-m-d H:i:s'), 'completed_at' => $start->copy()->addSeconds(5)->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($logged as $c) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => 'customer_reconnected', 'subject_type' => Customer::class, 'subject_id' => $c->id,
                'properties' => '{}', 'created_at' => $start->copy()->addSeconds(10)->format('Y-m-d H:i:s'), 'updated_at' => now(),
            ]);
        }

        $out = $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->count(), $out);
        $this->assertMatchesRegularExpression('/ebilling_action=1/', $out);
    }

    public function test_a_burst_of_customers_nobody_touched_next_to_a_few_kicked_ones_still_counts(): void
    {
        $normal = $this->customers(9);
        $kicked = $this->customers(3);
        $start = $this->now->copy()->subMinutes(7);
        $this->disconnects(array_merge($normal, $kicked), $start, 20);
        foreach ($kicked as $c) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => 'updated', 'subject_type' => Customer::class, 'subject_id' => $c->id,
                'properties' => json_encode(['attributes' => ['mikrotik_profile' => 'isolirebilling'], 'old' => ['mikrotik_profile' => 'default']]),
                'created_at' => $start->copy()->addSeconds(5)->format('Y-m-d H:i:s'), 'updated_at' => now(),
            ]);
        }

        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'session_burst')->sole();
        $this->assertSame(9, (int) $s->affected_count);
    }

    public function test_the_burst_is_resolved_when_half_of_the_members_are_back(): void
    {
        $cs = $this->customers(10);
        $start = $this->now->copy()->subMinutes(8);
        $this->disconnects($cs, $start, 15);
        $this->runCommand();
        foreach (array_slice($cs, 0, 5) as $c) {
            $this->ppp($c, 'connected', $this->now->copy()->subMinutes(3), null);
        }

        Carbon::setTestNow($this->now->copy()->addMinutes(5));
        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'session_burst')->sole();
        $this->assertSame('resolved', $s->state);
        $this->assertNull($s->open_key);
    }

    public function test_dry_run_and_replay_write_nothing_and_replay_reads_old_history(): void
    {
        $this->disconnects($this->customers(10), $this->now->copy()->subDays(3), 15);
        $this->disconnects($this->customers(10), $this->now->copy()->subMinutes(8), 15);

        Artisan::call('outage:session-bursts', ['--dry-run' => true]);
        $this->assertSame(0, OutageSignal::query()->count());

        Artisan::call('outage:session-bursts', ['--since' => '-9d', '--details' => true]);
        $out = Artisan::output();
        $this->assertSame(0, OutageSignal::query()->count());
        $this->assertStringContainsString('session_burst: 2 found', $out);
        $this->assertStringContainsString('read only', $out);
    }

    public function test_the_detector_reads_at_most_5000_lines_per_hour_slice_and_says_so(): void
    {
        $this->assertSame(5000, SessionBurstDetector::MAX_ROWS);
        $this->assertSame(5000, AreaPowerDetector::MAX_ROWS);
    }

    // ------------------------------------------------------------------ area power

    private function dyingGasp(Customer $c, Carbon $at): void
    {
        $this->onu($c, 'dying_gasp', $at);
    }

    public function test_24_onus_over_3_pons_with_18_in_one_area_is_an_area_power_group(): void
    {
        $area = Area::create(['name' => 'Area Uji Satu', 'code' => 'AU1']);
        $other = Area::create(['name' => 'Area Uji Dua', 'code' => 'AU2']);
        $cs = array_merge($this->customers(8, '1/1/1', $area->id), $this->customers(5, '1/1/2', $area->id), $this->customers(5, '1/1/3', $area->id), $this->customers(6, '1/1/3', $other->id));
        $at = $this->now->copy()->subMinutes(9);
        foreach ($cs as $i => $c) {
            $this->dyingGasp($c, $at->copy()->addSeconds($i * 3));
        }

        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'area_power')->sole();
        $this->assertSame('olt:'.$this->olt->id.':area:'.$area->id, $s->scope_key);
        $this->assertSame(18, (int) $s->affected_count, 'the members of the area, what affected_customer_ids lists');
        $this->assertSame(24, (int) ((array) $s->payload)['count'], 'all ONUs of the run');
        $this->assertCount(18, (array) $s->affected_customer_ids);
        $this->assertSame($area->id, (int) $s->area_id);
    }

    public function test_4_onus_over_4_pons_or_a_spread_over_areas_is_no_group(): void
    {
        $a = Area::create(['name' => 'Area A', 'code' => 'AA1']);
        $b = Area::create(['name' => 'Area B', 'code' => 'AB1']);
        $c = Area::create(['name' => 'Area C', 'code' => 'AC1']);
        $at = $this->now->copy()->subMinutes(14); // more than one detector run before the 6 ONUs below
        foreach (['1/1/1', '1/1/2', '1/1/3', '1/1/4'] as $i => $pon) {
            $this->dyingGasp($this->customers(1, $pon, $a->id)[0], $at->copy()->addSeconds($i));
        }
        // 6 ONUs in 3 areas: no area holds 60 %
        foreach ([$a, $a, $b, $b, $c, $c] as $i => $ar) {
            $this->dyingGasp($this->customers(1, '1/2/1', $ar->id)[0], $this->now->copy()->subMinutes(4)->addSeconds($i));
        }

        $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'area_power')->count());
    }

    public function test_two_groups_of_dying_gasps_in_two_detector_runs_are_not_one_group(): void
    {
        $area = Area::create(['name' => 'Area Uji Tujuh', 'code' => 'AU7']);
        $at = $this->now->copy()->subMinutes(9);
        foreach ($this->customers(3, '1/7/1', $area->id) as $i => $c) {
            $this->dyingGasp($c, $at->copy()->addSeconds($i));
        }
        foreach ($this->customers(3, '1/7/2', $area->id) as $i => $c) {
            $this->dyingGasp($c, $at->copy()->addSeconds(300 + $i)); // the next detector stamp
        }

        $this->runCommand();

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'area_power')->count());
    }

    public function test_the_burst_payload_follows_the_reconnects_and_isolated_members_skip_the_activity_log_read(): void
    {
        $cs = $this->customers(10);
        $this->disconnects($cs, $this->now->copy()->subMinutes(8), 15);
        $this->runCommand();
        foreach (array_slice($cs, 0, 3) as $c) {
            $this->ppp($c, 'connected', $this->now->copy()->subMinutes(2), null);
        }
        Carbon::setTestNow($this->now->copy()->addMinutes(10));
        $this->runCommand();

        $s = OutageSignal::query()->where('signal_type', 'session_burst')->sole();
        $this->assertContains($s->state, ['open', 'confirmed']);
        $this->assertSame(3, (int) ((array) $s->payload)['reconnected']);

        // 12 isolated members: no activity_log query at all
        Carbon::setTestNow($this->now);
        $iso = $this->customers(12, '1/9/9', null, 'isolated');
        $this->disconnects($iso, $this->now->copy()->subMinutes(5), 30);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        app(SessionBurstDetector::class)->detect($this->now->copy()->subMinutes(6), $this->now);
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'activity_log'))));
    }

    public function test_six_on_one_pon_that_do_not_recover_stay_open_and_a_recovery_resolves(): void
    {
        $area = Area::create(['name' => 'Area Uji Tiga', 'code' => 'AU3']);
        $cs = $this->customers(6, '1/3/1', $area->id);
        $at = $this->now->copy()->subMinutes(9);
        foreach ($cs as $i => $c) {
            $this->dyingGasp($c, $at->copy()->addSeconds($i));
        }

        $this->runCommand();
        Carbon::setTestNow($this->now->copy()->addMinutes(5));
        $this->runCommand();
        $s = OutageSignal::query()->where('signal_type', 'area_power')->sole();
        $this->assertContains($s->state, ['open', 'confirmed']);
        $this->assertNotNull($s->open_key);

        foreach (array_slice($cs, 0, 3) as $c) {
            $this->onu($c, 'online', $this->now->copy()->addMinutes(6), 'dying_gasp');
        }
        Carbon::setTestNow($this->now->copy()->addMinutes(10));
        $this->runCommand();

        $this->assertSame('resolved', $s->fresh()->state);
    }

    public function test_the_min_onus_setting_is_respected(): void
    {
        $area = Area::create(['name' => 'Area Uji Empat', 'code' => 'AU4']);
        foreach ($this->customers(4, '1/4/1', $area->id) as $i => $c) {
            $this->dyingGasp($c, $this->now->copy()->subMinutes(9)->addSeconds($i));
        }
        $this->runCommand();
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'area_power')->count());

        OutageSettings::withOverrides(['area_power_min_onus' => '4'], function (): void {
            $this->runCommand();
        });
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'area_power')->count());
    }

    public function test_the_activity_log_exclusion_read_uses_the_subject_index_and_is_bounded(): void
    {
        $ids = [1, 2, 3];
        $sql = DB::table('activity_log')->where('subject_type', Customer::class)->whereIn('subject_id', $ids)
            ->whereBetween('created_at', ['2026-10-28 03:00:00', '2026-10-28 03:10:00'])->limit(3000)->toSql();
        $plan = DB::select('EXPLAIN '.$sql, [Customer::class, ...$ids, '2026-10-28 03:00:00', '2026-10-28 03:10:00']);

        // a tiny test table may be scanned by the optimizer, so the pin is: the subject index is the one on offer
        $this->assertStringContainsString('subject', (string) $plan[0]->possible_keys, 'the (subject_type, subject_id) index must serve the read');
        $this->assertStringContainsString('limit 3000', strtolower($sql));
        $src = (string) file_get_contents(base_path('app/Services/Outage/SessionBurstDetector.php'));
        $this->assertStringContainsString('array_chunk($ids, 300)', $src);
    }

    // ------------------------------------------------------------------ Deteksi Gangguan rows

    public function test_the_deteksi_page_lists_both_types_with_plain_lines_and_a_second_page(): void
    {
        $area = Area::create(['name' => 'Area Uji Enam', 'code' => 'AU6']);
        for ($i = 1; $i <= 27; $i++) {
            OutageSignal::create([
                'signal_type' => 'session_burst', 'scope_key' => 'router:1:pon:1/1/'.$i, 'olt_id' => $this->olt->id, 'pon_port' => '1/1/'.$i, 'state' => 'confirmed',
                'opened_at' => $this->now->copy()->subMinutes($i), 'confirmed_at' => $this->now->copy()->subMinutes($i), 'affected_count' => 10 + $i,
                'payload' => ['onu_down_share' => $i % 2 === 0 ? 0.9 : 0.0, 'reconnected' => 3], 'would_action' => 'none',
            ]);
        }
        OutageSignal::create([
            'signal_type' => 'area_power', 'scope_key' => 'olt:'.$this->olt->id.':area:'.$area->id, 'olt_id' => $this->olt->id, 'area_id' => $area->id, 'state' => 'confirmed',
            'opened_at' => $this->now->copy()->subMinutes(2), 'confirmed_at' => $this->now->copy()->subMinutes(2), 'affected_count' => 12, 'would_action' => 'none',
        ]);
        $model = app(OutageShadowEpisodeReadModel::class);

        $p1 = $model->page(OutageShadowEpisodeReadModel::normalizeFilters(['type' => 'session_burst']), 'admin');
        $this->assertSame(27, $p1['total']);
        $this->assertCount(25, $p1['data']);
        $this->assertSame('Banyak putus bersamaan', $p1['data'][0]['type_label']);
        $this->assertNull($p1['data'][0]['ticket']);
        $this->assertFalse($p1['data'][0]['can_ticket']);
        $details = array_column($p1['data'], 'detail');
        $this->assertContains('11 pelanggan putus bersamaan, ONU tetap menyala. 3 sudah tersambung lagi.', $details);
        $this->assertContains('12 pelanggan putus bersamaan, ONU ikut mati. 3 sudah tersambung lagi.', $details);

        $area = $model->page(OutageShadowEpisodeReadModel::normalizeFilters(['type' => 'area_power']), 'admin');
        $this->assertSame(1, $area['total']);
        $this->assertSame('Kemungkinan listrik padam di area Area Uji Enam: 12 ONU mati bersamaan.', $area['data'][0]['detail']);
        $this->assertSame('Dugaan listrik padam (area)', $area['data'][0]['type_label']);
    }

    // ------------------------------------------------------------------ isolation

    public function test_the_command_pushes_nothing_onto_any_queue_and_creates_no_ticket(): void
    {
        Queue::fake();
        $this->disconnects($this->customers(10), $this->now->copy()->subMinutes(8), 15);
        $area = Area::create(['name' => 'Area Uji Lima', 'code' => 'AU5']);
        foreach ($this->customers(6, '1/5/1', $area->id) as $i => $c) {
            $this->dyingGasp($c, $this->now->copy()->subMinutes(9)->addSeconds($i));
        }

        $this->runCommand();

        $this->assertSame(2, OutageSignal::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('support_tickets')->count());
    }
}
