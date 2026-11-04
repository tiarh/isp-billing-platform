<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OltOperationLog;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\OltCollectorService;
use App\Services\OltHiosoService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageScheduleGate;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowNotifier;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Roadmap 2.4 PR-2: the shadow detector records, it never acts.
 */
#[Group('blocking-core')]
class OutageDetectorTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** UTC; 05:00 UTC = 12:00 WIB (outside the 22-07 quiet window). */
    private string $base = '2026-10-15 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function olt(string $vendor = 'zte_c300', string $name = 'OLT RA'): Olt
    {
        $olt = Olt::create(['name' => $name, 'code' => 'OUT-'.++$this->seq, 'vendor' => $vendor]);
        $this->monitor($olt);

        return $olt;
    }

    private function monitor(Olt ...$olts): void
    {
        config(['outage.olt_ids' => array_map(fn (Olt $o) => $o->id, $olts)]);
        config(['outage.serial_fallback_olt_ids' => array_map(fn (Olt $o) => $o->id, $olts)]);
    }

    private function customer(Olt $olt, string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'OUT-PKG'], ['name' => 'Outage pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'OUT-C'.$n,
            'name' => 'Outage Customer '.$n,
            'phone' => '0812000'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'out.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'olt_port_label' => $ref,
        ], $o));
    }

    private function at(int $minutes): string
    {
        // Explicit UTC offset: the app timezone is UTC, so test clocks must be UTC instants.
        return Carbon::parse($this->base.' UTC')->addMinutes($minutes)->toIso8601String();
    }

    /**
     * One collector snapshot + one detector run at $minutes after base.
     *
     * @param  array<string,string>  $phases  ref => online|los|dg|offline
     * @return array<string,mixed>
     */
    private function tick(Olt $olt, int $minutes, array $phases, bool $dry = false): array
    {
        $t = Carbon::parse($this->at($minutes), 'Asia/Jakarta');
        Carbon::setTestNow($t);
        $huawei = $olt->vendor === 'huawei_ma5608t';

        foreach ($phases as $ref => $p) {
            // *_stale = the 5-minute state tick moved on but phase_state is an old inventory reading.
            [$state, $phase] = match (true) {
                $p === 'online' => $huawei ? ['online', 'normal'] : ['online', 'working'],
                $p === 'online_stale_los' => ['online', 'LOS'],
                $p === 'offline_stale_los' => ['offline', 'LOS'],
                $p === 'los', $p === 'los_tickphase' => ['offline', 'LOS'],
                $p === 'dg' => ['offline', 'DyingGasp'],
                default => $huawei ? ['offline', 'offline'] : ['offline', 'OffLine'],
            };
            // los_tickphase = LOS written by the 5-minute tick (phase_collected_at fresh) while the last
            // inventory run is 45 min old.
            $tickPhase = $p === 'los_tickphase';
            $stalePhase = str_ends_with($p, '_stale_los') || $tickPhase;
            OltOnu::updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                [
                    'state' => $state,
                    'phase_state' => $phase,
                    'serial_number' => 'ZTEG'.md5($ref),
                    'distance_m' => ((int) substr($ref, strrpos($ref, ':') + 1)) * 100,
                    'state_collected_at' => $t,
                    'inventory_collected_at' => $stalePhase ? $t->copy()->subMinutes(45) : $t,
                    'phase_collected_at' => $tickPhase ? $t : null,
                ],
            );
        }
        OltCollectRun::create([
            'olt_id' => $olt->id,
            'tier' => $huawei ? 'huawei_optical' : 'state',
            'status' => 'success',
            'started_at' => $t,
            'finished_at' => $t,
        ]);

        return app(OutageDetector::class)->run(null, $dry);
    }

    // ------------------------------------------------------------------- signals

    public function test_zte_los_for_30_minutes_becomes_a_would_have_ticketed_record_only(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        Queue::fake();
        Http::fake();

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach ([5, 10, 15, 20, 25, 30] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        $this->assertSame(OutageSignal::STATE_OPEN, OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail()->state);

        $summary = $this->tick($olt, 35, ['1/1/1:1' => 'los']);

        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('ticket_single', $sig->would_action);
        $this->assertSame('shadow', $sig->mode_at_confirm);
        $this->assertSame('none', $sig->action_taken);
        $this->assertNull($sig->ticket_id);
        $this->assertSame($c->id, $sig->customer_id);
        $this->assertSame('1/1/1', $sig->pon_port);
        $this->assertCount(1, $summary['new_confirmed']);
        $this->assertSame(0, SupportTicket::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_single_los_confirmed_in_quiet_hours_is_marked_but_still_recorded(): void
    {
        $this->base = '2026-10-15 15:00:00'; // 22:00 WIB
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach ([5, 10, 15, 20, 25, 30, 35] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }

        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('quiet_hours', $sig->suppressed_reason);
        $this->assertSame('ticket_single', $sig->would_action);
    }

    public function test_dying_gasp_is_only_recorded_and_never_a_ticket_action(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach ([5, 35, 65] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'dg']);
        }

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'single_los')->count());
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $dg = OutageSignal::query()->where('signal_type', 'dying_gasp')->firstOrFail();
        $this->assertSame('log_only', $dg->would_action);
        $this->assertSame(OutageSignal::STATE_OPEN, $dg->state);

        // Long power-off: confirmed for the report, still log only.
        $this->tick($olt, 25 * 60, ['1/1/1:1' => 'dg']);
        $dg->refresh();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $dg->state);
        $this->assertSame('log_only', $dg->would_action);
        $this->assertSame('none', $dg->action_taken);
    }

    public function test_four_new_los_on_one_pon_make_one_mass_episode_with_individual_children(): void
    {
        $olt = $this->olt();
        $base = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->customer($olt, '1/1/1:'.$i);
            $this->customer($olt, '1/1/2:'.$i);
            $base['1/1/1:'.$i] = 'online';
            $base['1/1/2:'.$i] = 'online';
        }
        $this->tick($olt, 0, $base);

        $los = $base;
        foreach ([5, 6, 7, 8] as $i) {
            $los['1/1/1:'.$i] = 'los';
        }
        $this->tick($olt, 5, $los);

        $mass = OutageSignal::query()->where('signal_type', 'mass_los')->get();
        $this->assertCount(1, $mass);
        $m = $mass->first();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $m->state);
        $this->assertSame('ticket_mass', $m->would_action);
        $this->assertSame('1/1/1', $m->pon_port);
        $this->assertSame(4, $m->affected_count);
        $this->assertEquals(['from_m' => 400, 'to_m' => 500], $m->payload['segment_estimate_m']);

        // Keep the outage going for 40 minutes: still one mass row, children never confirm.
        foreach ([10, 15, 20, 25, 30, 35, 40] as $min) {
            $this->tick($olt, $min, $los);
        }
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $children = OutageSignal::query()->where('signal_type', 'single_los')->get();
        $this->assertCount(4, $children);
        foreach ($children as $child) {
            $this->assertSame($m->id, $child->parent_signal_id);
            $this->assertSame('inside_mass', $child->suppressed_reason);
            $this->assertSame(OutageSignal::STATE_OPEN, $child->state);
        }

        // Recovery: two snapshots with fewer than 2 LOS resolve the mass episode and free open_key.
        $this->tick($olt, 45, $base);
        $this->tick($olt, 50, $base);
        $m->refresh();
        $this->assertSame(OutageSignal::STATE_RESOLVED, $m->state);
        $this->assertNull($m->open_key);
    }

    public function test_twelve_old_offline_onus_open_no_episode(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/3/3:'.$i);
            $phases['1/3/3:'.$i] = $i <= 12 ? 'offline' : 'online';
        }
        foreach ([0, 5, 10, 40] as $m) {
            $this->tick($olt, $m, $phases);
        }

        // RCA-1: they are recorded as baseline down_unknown (age unknown) but never confirm and never form a mass.
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $this->assertSame(0, OutageSignal::query()->whereNotNull('confirmed_at')->count());
        $this->assertSame(12, OutageSignal::query()->where('signal_type', 'single_los')->where('suppressed_reason', 'baseline')->count());
    }

    public function test_baseline_los_of_unknown_age_never_counts_as_a_mass_outage(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/3/3:'.$i);
            $phases['1/3/3:'.$i] = $i <= 5 ? 'los' : 'online';
        }
        foreach ([0, 5, 40, 60] as $m) {
            $this->tick($olt, $m, $phases);
        }

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $singles = OutageSignal::query()->where('signal_type', 'single_los')->get();
        $this->assertCount(5, $singles);
        // Day-1 baseline: already LOS when first seen => recorded but never "would have ticketed".
        foreach ($singles as $s) {
            $this->assertTrue($s->payload['baseline']);
            $this->assertSame(OutageSignal::STATE_OPEN, $s->state);
            $this->assertSame('baseline', $s->suppressed_reason);
        }
    }

    public function test_online_state_wins_over_a_stale_los_phase(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);
        // Recovered (state online) while phase_state still says LOS from an old inventory run.
        foreach (range(10, 60, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online_stale_los']);
        }

        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertNull($sig->confirmed_at, 'a recovered ONU never "would ticket"');
        $this->assertSame(OutageSignal::STATE_RESOLVED, $sig->state);
        $this->assertSame('online', OutageOnuTracker::query()->firstOrFail()->phase);
    }

    public function test_new_offline_with_a_stale_phase_is_offline_cause_unknown_not_los(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 45, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'offline_stale_los']);
        }

        // RCA-1: offline without a fresh cause is never LOS, but after 30 minutes it is a down_unknown single (shadow).
        $this->assertSame('offline', OutageOnuTracker::query()->firstOrFail()->phase);
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $sig = OutageSignal::query()->firstOrFail();
        $this->assertSame('single_los', $sig->signal_type);
        $this->assertSame('down_unknown', $sig->payload['cause']);
        $this->assertNotSame('los', $sig->payload['cause']);

        Setting::set('outage_down_unknown_enabled', '0');
        Setting::flushRequestMemo();
        OutageSignal::query()->delete();
        OutageOnuTracker::query()->delete();
        $this->tick($olt, 50, ['1/1/1:1' => 'online']);
        foreach (range(55, 100, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'offline_stale_los']);
        }
        $this->assertSame(0, OutageSignal::query()->count(), 'with the flag off an offline ONU without a cause is ignored as before');
    }

    public function test_los_continues_while_offline_even_if_the_phase_reading_ages_out(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);
        foreach (range(10, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'offline_stale_los']);
        }

        $this->assertSame(OutageSignal::STATE_CONFIRMED, OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail()->state);
    }

    public function test_fresh_tick_phase_code_confirms_los_even_when_inventory_is_stale(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los_tickphase']);
        }

        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('los', OutageOnuTracker::query()->firstOrFail()->phase);
    }

    public function test_detector_runs_before_the_phase_column_migration_and_falls_back_to_inventory_stamp(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        // Code deployed, `migrate` not yet run: the column does not exist.
        Schema::table('olt_onus', fn ($table) => $table->dropColumn('phase_collected_at'));
        $this->assertFalse(Schema::hasColumn('olt_onus', 'phase_collected_at'));

        $t = Carbon::parse($this->at(5), 'Asia/Jakarta');
        Carbon::setTestNow($t);
        OltOnu::query()->where('onu_ref', '1/1/1:1')->update([
            'state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => $t, 'inventory_collected_at' => $t,
        ]);
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => $t, 'finished_at' => $t]);

        app(OutageDetector::class)->run(null, false);

        $this->assertSame('los', OutageOnuTracker::query()->firstOrFail()->phase, 'phase age falls back to inventory_collected_at');
    }

    public function test_online_state_still_wins_over_a_fresh_tick_los_phase(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $t = Carbon::parse($this->at(5), 'Asia/Jakarta');
        Carbon::setTestNow($t);
        OltOnu::query()->where('onu_ref', '1/1/1:1')->update([
            'state' => 'online', 'phase_state' => 'LOS', 'state_collected_at' => $t, 'phase_collected_at' => $t, 'inventory_collected_at' => $t,
        ]);
        app(OutageDetector::class)->run(null, false);

        $this->assertSame(0, OutageSignal::query()->count());
        $this->assertSame('online', OutageOnuTracker::query()->firstOrFail()->phase);
    }

    public function test_first_snapshot_after_a_stale_period_never_confirms(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.(($i <= 10) ? 1 : 2).':'.$i);
            $phases['1/1/'.(($i <= 10) ? 1 : 2).':'.$i] = 'online';
        }
        $this->tick($olt, 0, $phases);
        // Two LOS on one PON: a mass record is opened (below the confirm threshold) plus singles.
        $los = $phases;
        $los['1/1/1:1'] = 'los';
        $los['1/1/1:2'] = 'los';
        $this->tick($olt, 5, $los);
        $this->assertSame(OutageSignal::STATE_OPEN, OutageSignal::query()->where('signal_type', 'mass_los')->firstOrFail()->state);

        // Collector stale for a while (no run), the open single episode is older than 30 minutes.
        Carbon::setTestNow(Carbon::parse($this->at(45)));
        $this->assertSame('stale', app(OutageDetector::class)->run()['olts'][$olt->id]['status']);

        // First fresh snapshot, now 4 LOS: re-baseline only, nothing confirms.
        $los['1/1/1:3'] = 'los';
        $los['1/1/1:4'] = 'los';
        $this->tick($olt, 50, $los);
        $this->assertSame(0, OutageSignal::query()->whereNotNull('confirmed_at')->count());

        // The following snapshot may confirm the pre-existing single episodes again.
        $this->tick($olt, 55, $los);
        $this->assertGreaterThan(0, OutageSignal::query()->where('signal_type', 'single_los')->whereNotNull('confirmed_at')->count());
    }

    public function test_a_cut_concentrated_in_one_pon_is_mass_los_not_olt_wide(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->customer($olt, '1/1/1:'.$i);
            $this->customer($olt, '1/1/2:'.$i);
            $phases['1/1/1:'.$i] = 'online';
            $phases['1/1/2:'.$i] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (range(1, 8) as $i) {
            $phases['1/1/1:'.$i] = 'los'; // 8 of 20 eligible = 40%, all in one PON
        }
        $summary = $this->tick($olt, 5, $phases);

        $this->assertSame('ok', $summary['olts'][$olt->id]['status']);
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'olt_wide')->count());
        $mass = OutageSignal::query()->where('signal_type', 'mass_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $mass->state);
        $this->assertSame(8, $mass->affected_count);
    }

    public function test_olt_wide_is_accepted_after_two_consecutive_snapshots(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.$i.':1');
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'los'; // 7 PONs: dispersed, so not a feeder cut
        }

        $first = $this->tick($olt, 5, $phases);
        $this->assertSame('olt_wide', $first['olts'][$olt->id]['status']);
        $this->assertSame(20, OutageOnuTracker::query()->where('phase', 'online')->count());

        $second = $this->tick($olt, 10, $phases);
        $this->assertSame('olt_wide_accepted', $second['olts'][$olt->id]['status']);
        $this->assertSame(7, OutageOnuTracker::query()->where('phase', 'los')->count(), 'the 2nd wide snapshot is accepted (re-baselined)');
        $this->assertSame(0, OutageSignal::query()->whereNotNull('confirmed_at')->where('signal_type', '!=', 'olt_wide')->count());

        $third = $this->tick($olt, 15, $phases);
        $this->assertSame('ok', $third['olts'][$olt->id]['status']);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'olt_wide')->count(), 'one olt_wide record for the whole event');
        $this->assertSame(OutageSignal::STATE_RESOLVED, OutageSignal::query()->where('signal_type', 'olt_wide')->firstOrFail()->state);
    }

    public function test_every_pon_dropping_at_once_is_olt_wide_even_with_two_pons(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 5; $i++) {
            $this->customer($olt, '1/1/1:'.$i);
            $this->customer($olt, '1/1/2:'.$i);
            $phases['1/1/1:'.$i] = 'online';
            $phases['1/1/2:'.$i] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach ([1, 2, 3] as $i) {
            $phases['1/1/1:'.$i] = 'los';
            $phases['1/1/2:'.$i] = 'los';
        }
        $summary = $this->tick($olt, 5, $phases);

        $this->assertSame('olt_wide', $summary['olts'][$olt->id]['status'], 'both PONs dropped: not a single-PON cut');
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
    }

    public function test_olt_wide_hold_applies_even_when_its_recording_mode_is_off(): void
    {
        Setting::set('outage_mode_olt_wide', 'off');
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.$i.':1');
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'los';
        }

        $first = $this->tick($olt, 5, $phases);
        $this->assertSame('olt_wide', $first['olts'][$olt->id]['status']);
        $this->assertSame(20, OutageOnuTracker::query()->where('phase', 'online')->count());
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'olt_wide')->count(), 'mode off: held but not recorded');

        $second = $this->tick($olt, 10, $phases);
        $this->assertSame('olt_wide_accepted', $second['olts'][$olt->id]['status'], 'the 2nd snapshot is still accepted');
    }

    public function test_mass_los_after_an_accepted_olt_wide_carries_the_olt_wide_id(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 6; $i++) {
            $this->customer($olt, '1/1/1:'.$i);
            $phases['1/1/1:'.$i] = 'online';
        }
        foreach ([2, 3, 4, 5] as $p) {
            $this->customer($olt, '1/1/'.$p.':1');
            $phases['1/1/'.$p.':1'] = 'online';
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->customer($olt, '1/1/9:'.$i);
            $phases['1/1/9:'.$i] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (['1/1/1:1', '1/1/1:2', '1/1/1:3', '1/1/1:4', '1/1/2:1', '1/1/3:1', '1/1/4:1'] as $ref) {
            $phases[$ref] = 'los'; // 4 PONs changed: held / accepted, then PON 1/1/1 is a real mass
        }
        $this->assertSame('olt_wide', $this->tick($olt, 5, $phases)['olts'][$olt->id]['status']);
        $this->assertSame('olt_wide_accepted', $this->tick($olt, 10, $phases)['olts'][$olt->id]['status']);
        $this->tick($olt, 15, $phases);

        $wide = OutageSignal::query()->where('signal_type', 'olt_wide')->firstOrFail();
        $mass = OutageSignal::query()->where('signal_type', 'mass_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $mass->state);
        $this->assertSame($wide->id, $mass->payload['after_olt_wide']);
    }

    public function test_baseline_flag_is_cleared_once_the_onu_was_seen_online(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'los']);
        $this->assertTrue(OutageSignal::query()->firstOrFail()->payload['baseline']);

        $this->tick($olt, 5, ['1/1/1:1' => 'online']);
        $this->assertArrayNotHasKey('baseline', OutageSignal::query()->firstOrFail()->payload);
        foreach (range(10, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        // N1: the 30 minutes count from the new LOS (t=10), not from the day-1 opened_at.
        $this->assertSame(OutageSignal::STATE_OPEN, OutageSignal::query()->firstOrFail()->state, 'LOS since t=10 is only 25 minutes old at t=35');

        $this->tick($olt, 40, ['1/1/1:1' => 'los']);

        $this->assertSame(OutageSignal::STATE_CONFIRMED, OutageSignal::query()->firstOrFail()->state, 'a real new LOS can confirm once it is 30 minutes old');
    }

    public function test_dry_run_does_not_write_the_olt_wide_streak_cache(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.$i.':1');
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'los';
        }

        $dry = $this->tick($olt, 5, $phases, true);
        $this->assertSame('olt_wide', $dry['olts'][$olt->id]['status']);
        $this->assertNull(Cache::get('outage:olt-wide-streak:'.$olt->id), 'dry-run leaves no streak behind');

        // The next real run is the FIRST wide snapshot (held), not the accepted second one.
        $real = $this->tick($olt, 6, $phases);
        $this->assertSame('olt_wide', $real['olts'][$olt->id]['status']);
        $this->assertSame(1, Cache::get('outage:olt-wide-streak:'.$olt->id));
    }

    public function test_pons_whose_customers_were_all_already_offline_do_not_count_as_pons_with_customers(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 6; $i++) {
            foreach (['1/1/1', '1/1/2'] as $pon) {
                $this->customer($olt, $pon.':'.$i);
                $phases[$pon.':'.$i] = 'online';
            }
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->customer($olt, '1/1/3:'.$i);
            $phases['1/1/3:'.$i] = 'offline'; // long dead PON, nothing left to drop
        }
        $this->tick($olt, 0, $phases);
        foreach (['1/1/1', '1/1/2'] as $pon) {
            for ($i = 1; $i <= 6; $i++) {
                $phases[$pon.':'.$i] = 'los';
            }
        }

        $summary = $this->tick($olt, 5, $phases);

        $this->assertSame('olt_wide', $summary['olts'][$olt->id]['status'], 'both live PONs dropped at once: held, not a 2-of-3 PON cut');
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
    }

    public function test_streak_cache_failures_are_logged_with_the_exception_class_only(): void
    {
        Log::spy();
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.$i.':1');
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 0, $phases);
        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'los';
        }
        // Only the streak keys fail; settings reads keep working (catch-all expectations below).
        $streak = \Mockery::pattern('/^outage:olt-wide-streak:/');
        Cache::shouldReceive('get')->with($streak, \Mockery::any())->andThrow(new \RuntimeException('secret detail'));
        Cache::shouldReceive('put')->with($streak, \Mockery::any(), \Mockery::any())->andThrow(new \RuntimeException('secret detail'));
        Cache::shouldReceive('forget')->with($streak)->andThrow(new \RuntimeException('secret detail'));
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put', 'forever', 'forget')->andReturn(true);

        $summary = $this->tick($olt, 5, $phases);

        $this->assertSame('olt_wide', $summary['olts'][$olt->id]['status']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => str_contains((string) $msg, 'streak cache read failed') && ($ctx['exception_class'] ?? null) === \RuntimeException::class,
        )->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => str_contains((string) $msg, 'streak cache write failed') && ! str_contains(json_encode($ctx), 'secret'),
        )->atLeast()->once();
    }

    // ------------------------------------------------- Gadang (Huawei) mapping

    /** Realistic Huawei collector rows (OltHuaweiMa5608tService / HuaweiOltSnmpOpticalService formats). */
    private function huaweiOnu(Olt $olt, string $ref, string $serial, string $state = 'online'): void
    {
        $t = Carbon::parse($this->at(0), 'Asia/Jakarta');
        OltOnu::query()->create([
            'olt_id' => $olt->id,
            'onu_ref' => $ref,
            'pon_port' => substr($ref, 0, strrpos($ref, ':')),
            'serial_number' => $serial,
            'state' => $state,
            'phase_state' => $state === 'online' ? 'normal' : 'offline',
            'state_collected_at' => $t,
        ]);
    }

    public function test_huawei_customers_with_pon_only_labels_map_by_serial_like_zte(): void
    {
        $olt = $this->olt('huawei_ma5608t', 'OLT GADANG');
        // PSB-created customers keep only the PON ("0/0/3") in olt_port_label; serials are typed by hand.
        $a = $this->customer($olt, '0/0/3', ['onu_serial' => 'fhtt-0000a046']);
        $b = $this->customer($olt, '0/0/3', ['onu_serial' => '48575443 00000001']); // hex vendor form
        $c = $this->customer($olt, '0/0/4:5', ['onu_serial' => 'ALCLFE1BBC27']);       // proper ref label
        $d = $this->customer($olt, '0/0/5', ['onu_serial' => 'SHORT1']);               // too short to join
        $this->huaweiOnu($olt, '0/0/3:12', 'FHTT0000A046');
        $this->huaweiOnu($olt, '0/0/3:13', 'HWTC00000001');
        $this->huaweiOnu($olt, '0/0/4:5', 'ALCLFE1BBC27');
        $this->huaweiOnu($olt, '0/0/5:1', 'SHORT1');
        $this->huaweiOnu($olt, '0/0/6:1', 'ZZZZ99990000'); // not a customer
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'huawei_optical', 'status' => 'success', 'started_at' => Carbon::parse($this->at(0)), 'finished_at' => Carbon::parse($this->at(0))]);
        Carbon::setTestNow(Carbon::parse($this->at(0)));

        $summary = app(OutageDetector::class)->run();

        $o = $summary['olts'][$olt->id];
        $this->assertSame(5, $o['onus']);
        $this->assertSame(3, $o['mapped_eligible']);
        $this->assertSame(['by_ref' => 1, 'by_serial' => 2, 'by_serial_null_olt' => 0, 'unmapped' => 2, 'ambiguous' => 0, 'not_eligible' => 0], $o['mapping']);
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            OutageOnuTracker::query()->where('olt_id', $olt->id)->whereNotNull('customer_id')->orderBy('customer_id')->pluck('customer_id')->all(),
        );
        $this->assertNotContains($d->id, OutageOnuTracker::query()->pluck('customer_id')->all());
    }

    public function test_serial_join_skips_ambiguous_serials_and_never_steals_a_customer_with_a_valid_ref(): void
    {
        $olt = $this->olt('huawei_ma5608t', 'OLT GADANG');
        // Two customers claim the same serial: ambiguous, not mapped.
        $this->customer($olt, '0/0/3', ['onu_serial' => 'FHTT0000A046']);
        $this->customer($olt, '0/0/3', ['onu_serial' => 'FHTT-0000A046']);
        // Owns ref 0/0/4:5 (collected) but its serial matches another ONU row: stays with its ref.
        $owner = $this->customer($olt, '0/0/4:5', ['onu_serial' => 'HWTC00000001']);
        $this->huaweiOnu($olt, '0/0/3:12', 'FHTT0000A046');
        $this->huaweiOnu($olt, '0/0/4:5', 'ALCLFE1BBC27');
        $this->huaweiOnu($olt, '0/0/4:6', 'HWTC00000001');
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'huawei_optical', 'status' => 'success', 'started_at' => Carbon::parse($this->at(0)), 'finished_at' => Carbon::parse($this->at(0))]);
        Carbon::setTestNow(Carbon::parse($this->at(0)));

        $o = app(OutageDetector::class)->run()['olts'][$olt->id];

        $this->assertSame(['by_ref' => 1, 'by_serial' => 0, 'by_serial_null_olt' => 0, 'unmapped' => 1, 'ambiguous' => 1, 'not_eligible' => 0], $o['mapping']);
        $this->assertSame([$owner->id], OutageOnuTracker::query()->whereNotNull('customer_id')->pluck('customer_id')->all());
    }

    public function test_customers_with_null_olt_id_map_by_serial_but_other_olts_customers_never_do(): void
    {
        $olt = $this->olt('huawei_ma5608t', 'OLT GADANG');
        $other = Olt::create(['name' => 'OLT Other', 'code' => 'OUT-X'.++$this->seq, 'vendor' => 'zte_c300']);
        $own = $this->customer($olt, '0/0/3:12', ['onu_serial' => 'FHTT0000A046']);
        $nullA = $this->customer($olt, '', ['olt_id' => null, 'onu_serial' => 'fhtt-0000a047']);
        $nullB = $this->customer($olt, '', ['olt_id' => null, 'onu_serial' => 'FHTT0000A048']);
        $this->customer($other, '1/1/1:1', ['onu_serial' => 'FHTT0000A049']); // belongs to another OLT: not eligible to join
        $this->customer($olt, '', ['olt_id' => null, 'onu_serial' => 'FHTT0000A04A']); // ambiguous with the next
        $this->customer($olt, '', ['olt_id' => null, 'onu_serial' => 'FHTT0000A04A']);
        $this->huaweiOnu($olt, '0/0/3:12', 'FHTT0000A046');
        $this->huaweiOnu($olt, '0/0/3:13', 'FHTT0000A047');
        $this->huaweiOnu($olt, '0/0/3:14', 'FHTT0000A048');
        $this->huaweiOnu($olt, '0/0/3:15', 'FHTT0000A049');
        $this->huaweiOnu($olt, '0/0/3:16', 'FHTT0000A04A');
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'huawei_optical', 'status' => 'success', 'started_at' => Carbon::parse($this->at(0)), 'finished_at' => Carbon::parse($this->at(0))]);
        Carbon::setTestNow(Carbon::parse($this->at(0)));

        $o = app(OutageDetector::class)->run()['olts'][$olt->id];

        $this->assertSame(['by_ref' => 1, 'by_serial' => 0, 'by_serial_null_olt' => 2, 'unmapped' => 1, 'ambiguous' => 1, 'not_eligible' => 0], $o['mapping']);
        $this->assertSame(3, $o['mapped_eligible']);
        $this->assertEqualsCanonicalizing(
            [$own->id, $nullA->id, $nullB->id],
            OutageOnuTracker::query()->whereNotNull('customer_id')->pluck('customer_id')->all(),
        );
        $this->assertNull(Customer::query()->find($nullA->id)->olt_id, 'read-only: customers.olt_id is never written');
    }

    public function test_shadow_wa_dedupe_includes_the_episode_ids(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $this->fakeEvolution(2);
        $n = app(OutageShadowNotifier::class);
        $start = Carbon::parse($this->at(0));
        $one = fn (int $id) => ['new_confirmed' => [['id' => $id, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/3', 'affected_count' => 1, 'would_action' => 'ticket_single', 'cause_unknown' => false]]];

        $this->assertTrue($n->send($one(10), $start));
        $this->assertTrue($n->send($one(11), $start->copy()->addMinutes(10)), 'same text, different episode: sent');
        $this->assertFalse($n->send($one(11), $start->copy()->addMinutes(20)), 'same episode set: deduped');
    }

    private function gadangFixture(array $customers, array $onus): array
    {
        $olt = $this->olt('huawei_ma5608t', 'OLT GADANG');
        $made = [];
        foreach ($customers as $k => [$ref, $attrs]) {
            $made[$k] = $this->customer($olt, $ref, $attrs);
        }
        foreach ($onus as [$ref, $serial]) {
            $this->huaweiOnu($olt, $ref, $serial);
        }
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'huawei_optical', 'status' => 'success', 'started_at' => Carbon::parse($this->at(0)), 'finished_at' => Carbon::parse($this->at(0))]);
        Carbon::setTestNow(Carbon::parse($this->at(0)));

        return [$olt, $made];
    }

    public function test_null_olt_and_own_olt_customer_sharing_a_serial_are_ambiguous(): void
    {
        [$olt] = $this->gadangFixture(
            [['0/0/3:12', ['onu_serial' => 'FHTT0000A046']], ['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A047']], ['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A047']]],
            [['0/0/3:12', 'FHTT0000A046'], ['0/0/3:13', 'FHTT0000A047']],
        );
        // own-OLT customer + NULL-olt customer on the same serial
        $this->customer($olt, '', ['onu_serial' => 'FHTT0000A047']);

        $o = app(OutageDetector::class)->run()['olts'][$olt->id];

        $this->assertSame(1, $o['mapping']['ambiguous']);
        $this->assertSame(1, $o['mapping']['by_ref']);
        $this->assertSame(1, $o['mapped_eligible']);
    }

    public function test_two_claimants_map_only_when_exactly_one_is_active(): void
    {
        [$olt, $c] = $this->gadangFixture(
            [
                'old' => ['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A046', 'status' => 'terminated']],
                'cur' => ['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A046']],
            ],
            [['0/0/3:12', 'FHTT0000A046']],
        );

        $o = app(OutageDetector::class)->run()['olts'][$olt->id];

        $this->assertSame(1, $o['mapping']['by_serial_null_olt']);
        $this->assertSame(1, $o['mapped_eligible']);
        $this->assertSame([$c['cur']->id], OutageOnuTracker::query()->whereNotNull('customer_id')->pluck('customer_id')->all());
    }

    public function test_a_serial_on_two_onu_rows_is_ambiguous_and_not_mapped(): void
    {
        [$olt] = $this->gadangFixture(
            [['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A046']]],
            [['0/0/3:12', 'FHTT0000A046'], ['0/0/4:1', 'FHTT0000A046']],
        );

        $o = app(OutageDetector::class)->run()['olts'][$olt->id];

        $this->assertSame(2, $o['mapping']['ambiguous']);
        $this->assertSame(0, $o['mapped_eligible']);
    }

    public function test_a_serial_fresh_on_two_monitored_olts_is_not_mapped_for_a_null_olt_customer(): void
    {
        [$a] = $this->gadangFixture(
            [['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A046']]],
            [['0/0/3:12', 'FHTT0000A046']],
        );
        $b = Olt::create(['name' => 'OLT B', 'code' => 'OUT-B'.++$this->seq, 'vendor' => 'huawei_ma5608t']);
        $this->monitor($a, $b);
        $this->huaweiOnu($b, '0/0/1:2', 'FHTT0000A046');
        OltCollectRun::create(['olt_id' => $b->id, 'tier' => 'huawei_optical', 'status' => 'success', 'started_at' => Carbon::parse($this->at(0)), 'finished_at' => Carbon::parse($this->at(0))]);

        $summary = app(OutageDetector::class)->run();

        $this->assertSame(0, $summary['olts'][$a->id]['mapped_eligible']);
        $this->assertSame(0, $summary['olts'][$b->id]['mapped_eligible']);
        $this->assertSame(1, $summary['olts'][$a->id]['mapping']['ambiguous']);
    }

    public function test_serial_fallback_can_be_narrowed_by_setting_and_is_validated(): void
    {
        [$olt] = $this->gadangFixture(
            [['', ['olt_id' => null, 'onu_serial' => 'FHTT0000A046']]],
            [['0/0/3:12', 'FHTT0000A046']],
        );
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_serial_fallback_olt_ids', 'value' => '[99999]'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_serial_fallback_olt_ids', 'value' => '['.$olt->id.']'])->assertExitCode(0);
        Setting::flushRequestMemo();
        $this->assertSame(1, app(OutageDetector::class)->run()['olts'][$olt->id]['mapping']['by_serial_null_olt']);

        Setting::set('outage_serial_fallback_olt_ids', '[]');
        Setting::flushRequestMemo();
        $o = app(OutageDetector::class)->run()['olts'][$olt->id];
        $this->assertSame(0, $o['mapping']['by_serial_null_olt']);
        $this->assertSame(0, $o['mapped_eligible']);
    }

    public function test_n1_reset_survives_a_dying_gasp_blip_between_online_and_los(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'los']);        // day-1 LOS (baseline episode)
        $this->tick($olt, 5, ['1/1/1:1' => 'online']);     // seen online
        $this->tick($olt, 10, ['1/1/1:1' => 'dg']);        // intermediate non-LOS phase
        foreach (range(15, 40, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        $this->assertSame(OutageSignal::STATE_OPEN, OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail()->state, 'LOS since t=15 is only 25 minutes old at t=40');

        $this->tick($olt, 45, ['1/1/1:1' => 'los']);

        $this->assertSame(OutageSignal::STATE_CONFIRMED, OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail()->state);
    }

    public function test_unconfirmed_flap_reopen_restarts_the_clock(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);
        $this->tick($olt, 10, ['1/1/1:1' => 'los']);
        foreach (range(15, 45, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online']);
        }
        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_RESOLVED, $sig->state);
        $this->assertNull($sig->confirmed_at);

        $this->tick($olt, 50, ['1/1/1:1' => 'los']);

        $sig->refresh();
        $this->assertSame(1, $sig->flap_count);
        $this->assertSame(OutageSignal::STATE_OPEN, $sig->state);
        $this->assertTrue($sig->opened_at->equalTo(Carbon::parse($this->at(50))), 'the 30 minutes restart at the new LOS');
    }

    public function test_shadow_wa_keeps_entries_when_the_send_throws_and_marks_the_combined_summary(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $texts = [];
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->twice()->andReturnUsing(function (string $jid, string $text) use (&$texts) {
            $texts[] = $text;
            if (count($texts) === 1) {
                throw new \RuntimeException('gateway down');
            }

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $mock);
        $n = app(OutageShadowNotifier::class);
        $start = Carbon::parse($this->at(0));

        $this->assertFalse($n->send($this->summaryN(1), $start));
        $this->assertTrue($n->send(['new_confirmed' => []], $start->copy()->addMinutes(5)));
        $this->assertStringContainsString('PON 1/3/1 ', $texts[1]);
        $this->assertStringContainsString('Ringkasan deteksi (termasuk tertahan jam tenang)', $texts[1]);
        $this->assertStringNotContainsString('Ringkasan deteksi', $texts[0]);
    }

    public function test_serial_key_normalizes_huawei_formats(): void
    {
        $this->assertSame('HWTC00000001', OutageDetector::serialKey('48575443 00000001'));
        $this->assertSame('HWTC00000001', OutageDetector::serialKey('hwtc-0000 0001'));
        $this->assertSame('FHTT0000A046', OutageDetector::serialKey('FHTT-0000A046'));
        $this->assertNull(OutageDetector::serialKey('SHORT1'));
        $this->assertNull(OutageDetector::serialKey(''));
    }

    // -------------------------------------------------- shadow WA hardening

    private function summaryN(int $n): array
    {
        return ['new_confirmed' => [
            ['id' => $n, 'type' => 'mass_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/'.$n, 'affected_count' => 4 + $n, 'would_action' => 'ticket_mass', 'cause_unknown' => false],
        ]];
    }

    public function test_shadow_wa_is_rate_capped_per_hour_and_the_held_summary_goes_out_later(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $texts = [];
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->times(5)->andReturnUsing(function (string $jid, string $text) use (&$texts) {
            $texts[] = $text;

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $mock);
        $n = app(OutageShadowNotifier::class);
        $start = Carbon::parse($this->at(0));

        foreach ([1, 2, 3, 4] as $i) {
            $this->assertTrue($n->send($this->summaryN($i), $start->copy()->addMinutes($i * 5)), 'send '.$i);
        }
        $this->assertFalse($n->send($this->summaryN(5), $start->copy()->addMinutes(30)), 'default cap is 4 per hour');
        $this->assertFalse($n->send(['new_confirmed' => []], $start->copy()->addMinutes(40)), 'still capped, nothing lost');

        // An hour after the first send the cap has room again: one combined summary with the held entry.
        $this->assertTrue($n->send($this->summaryN(6), $start->copy()->addMinutes(66)));
        $this->assertStringContainsString('PON 1/3/5 ', $texts[4]);
        $this->assertStringContainsString('PON 1/3/6 ', $texts[4]);
    }

    public function test_shadow_wa_cap_is_configurable_and_validated(): void
    {
        $this->assertSame(4, OutageSettings::shadowWaMaxPerHour());
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_wa_max_per_hour', 'value' => '0'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_wa_max_per_hour', 'value' => '21'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_wa_max_per_hour', 'value' => '1'])->assertExitCode(0);
        Setting::flushRequestMemo();
        $this->assertSame(1, OutageSettings::shadowWaMaxPerHour());
    }

    public function test_shadow_wa_dedupes_identical_summaries(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $this->fakeEvolution(1);
        $n = app(OutageShadowNotifier::class);
        $start = Carbon::parse($this->at(0));

        $this->assertTrue($n->send($this->summaryN(1), $start));
        $this->assertFalse($n->send($this->summaryN(1), $start->copy()->addMinutes(10)), 'identical text is not re-sent');
        $this->assertFalse($n->send($this->summaryN(1), $start->copy()->addMinutes(20)));
    }

    public function test_shadow_wa_is_held_in_quiet_hours_and_sent_once_combined_afterwards(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->once()->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->once()->withArgs(
            fn (string $jid, string $text) => str_contains($text, 'PON 1/3/1 ') && str_contains($text, 'PON 1/3/2 '),
        )->andReturn(['success' => true]);
        $this->app->instance(EvolutionApiService::class, $mock);
        $n = app(OutageShadowNotifier::class);

        // 16:00 and 17:30 UTC = 23:00 and 00:30 WIB: quiet.
        $this->assertFalse($n->send($this->summaryN(1), Carbon::parse('2026-10-15 16:00:00 UTC')));
        $this->assertFalse($n->send($this->summaryN(2), Carbon::parse('2026-10-15 17:30:00 UTC')));
        // 23:59 UTC = 06:59 WIB: still quiet. 00:00 UTC next day = 07:00 WIB: allowed.
        $this->assertFalse($n->send(['new_confirmed' => []], Carbon::parse('2026-10-15 23:59:00 UTC')));
        $this->assertTrue($n->send(['new_confirmed' => []], Carbon::parse('2026-10-16 00:00:00 UTC')));
        $this->assertFalse($n->send(['new_confirmed' => []], Carbon::parse('2026-10-16 00:05:00 UTC')), 'held list was cleared');
    }

    public function test_shadow_wa_fails_closed_when_the_cache_is_down(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $this->fakeEvolution(0);
        Setting::flushRequestMemo();
        OutageSettings::shadowLogJid(); // warm the request memo while the cache still works
        Cache::shouldReceive('get')->with(\Mockery::pattern('/^outage:shadow-wa:/'))->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('get')->andReturn(null);

        $this->assertFalse(app(OutageShadowNotifier::class)->send($this->summaryN(1), Carbon::parse($this->at(0))));
    }

    public function test_flap_windows_have_per_type_defaults_and_are_validated(): void
    {
        $this->assertSame(['mass_los' => 60, 'single_los' => 120, 'dying_gasp' => 120, 'olt_wide' => 30], OutageSettings::snapshot()['flap_window_minutes']);

        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_flap_window_mass_los_minutes', 'value' => '4'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_flap_window_mass_los_minutes', 'value' => '721'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_flap_window_single_los_minutes', 'value' => 'abc'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_flap_window_single_los_minutes', 'value' => '20'])->assertExitCode(0);
        Setting::flushRequestMemo();
        $this->assertSame(20, OutageSettings::flapWindowMinutes('single_los'));
        $this->assertSame(60, OutageSettings::flapWindowMinutes('mass_los'));
    }

    public function test_single_los_flap_window_setting_decides_between_reopen_and_new_episode(): void
    {
        Setting::set('outage_flap_window_single_los_minutes', '20');
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        foreach (range(40, 70, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online']);
        }
        $this->assertSame(OutageSignal::STATE_RESOLVED, OutageSignal::query()->firstOrFail()->state);

        $this->tick($olt, 100, ['1/1/1:1' => 'los']); // 30 minutes after resolve > 20 minute window

        $this->assertSame(2, OutageSignal::query()->where('signal_type', 'single_los')->count());
    }

    public function test_day_one_dying_gasp_is_flagged_baseline(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'dg']);

        $this->assertTrue(OutageSignal::query()->where('signal_type', 'dying_gasp')->firstOrFail()->payload['baseline']);
    }

    public function test_an_expired_episode_is_reopened_as_a_flap_not_a_second_would_ticket(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        // Stale for > 2 h: expired without a conclusion.
        Carbon::setTestNow(Carbon::parse($this->at(165)));
        app(OutageDetector::class)->run();
        $this->assertSame(OutageSignal::STATE_EXPIRED, OutageSignal::query()->firstOrFail()->state);

        $this->tick($olt, 170, ['1/1/1:1' => 'los']);

        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'single_los')->count());
        $sig = OutageSignal::query()->firstOrFail();
        $this->assertSame(1, $sig->flap_count);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
    }

    public function test_customer_moved_to_another_olt_does_not_break_the_run(): void
    {
        $a = $this->olt('zte_c300', 'OLT A');
        $b = $this->olt('zte_c300', 'OLT B');
        $this->monitor($a, $b);
        $c = $this->customer($a, '1/1/1:1');
        $this->tick($a, 0, ['1/1/1:1' => 'online']);
        $this->tick($a, 5, ['1/1/1:1' => 'los']);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'single_los')->count());

        DB::table('customers')->where('id', $c->id)->update(['olt_id' => $b->id]);
        $this->tick($b, 10, ['1/1/1:1' => 'online']);
        $summary = $this->tick($b, 15, ['1/1/1:1' => 'los']);

        $this->assertNotSame('error', $summary['olts'][$b->id]['status']);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'single_los')->count());
        $this->assertSame($b->id, OutageSignal::query()->firstOrFail()->olt_id);
    }

    public function test_stale_collector_run_opens_nothing_and_closes_nothing(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);
        $before = OutageSignal::query()->firstOrFail();
        $this->assertSame(OutageSignal::STATE_OPEN, $before->state);

        // 20 minutes later and no new collector run: data is older than the 12 minute limit.
        Carbon::setTestNow(Carbon::parse($this->at(25), 'Asia/Jakarta'));
        $summary = app(OutageDetector::class)->run();

        $this->assertSame('stale', $summary['olts'][$olt->id]['status']);
        $this->assertSame(OutageSignal::STATE_OPEN, $before->fresh()->state);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', '!=', 'data_blind')->count());
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'data_blind')->count(), 'RCA-1: the stale collector is recorded as data_blind');
        $this->assertSame('unknown', OutageOnuTracker::query()->firstOrFail()->phase);
    }

    public function test_thirty_five_percent_of_onus_leaving_online_is_only_an_olt_wide_record(): void
    {
        $olt = $this->olt();
        $phases = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->customer($olt, '1/1/'.$i.':1');
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 0, $phases);

        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'los';
        }
        $summary = $this->tick($olt, 5, $phases);

        $this->assertSame('olt_wide', $summary['olts'][$olt->id]['status']);
        $this->assertSame(['olt_wide'], OutageSignal::query()->pluck('signal_type')->unique()->values()->all());
        $wide = OutageSignal::query()->firstOrFail();
        $this->assertSame('log_only', $wide->would_action);
        $this->assertSame(20, OutageOnuTracker::query()->where('phase', 'online')->count(), 'tracker is held during the glitch');

        // Back to normal: the olt_wide episode resolves.
        foreach (range(1, 7) as $i) {
            $phases['1/1/'.$i.':1'] = 'online';
        }
        $this->tick($olt, 10, $phases);
        $this->assertSame(OutageSignal::STATE_RESOLVED, $wide->fresh()->state);
    }

    public function test_exclusions_suppress_or_skip_without_losing_the_record(): void
    {
        $olt = $this->olt();
        $phases = [];
        $c = [];
        $names = ['isolated', 'isolir', 'migration', 'psb', 'olt_write', 'maintenance', 'open_ticket', 'normal'];
        foreach ($names as $i => $name) {
            $ref = '1/1/'.($i + 1).':1';
            $opts = match ($name) {
                'isolated' => ['status' => 'isolated'],
                'isolir' => ['mikrotik_profile' => 'ISOLIR-X'],
                default => [],
            };
            $c[$name] = $this->customer($olt, $ref, $opts);
            $phases[$ref] = 'online';
        }
        for ($i = 1; $i <= 30; $i++) {
            $this->customer($olt, '1/9/1:'.$i);
            $phases['1/9/1:'.$i] = 'online';
        }
        $this->tick($olt, 0, $phases);

        CustomerMigration::create(['status' => 'received', 'customer_id' => $c['migration']->id, 'full_name' => 'x', 'pppoe_user' => 'mig.x', 'new_sn' => 'FHTT1']);
        PsbDraft::create(['status' => 'waiting_sn', 'message_id' => 'out-psb-1', 'customer_id' => $c['psb']->id, 'full_name' => 'x']);
        OltOperationLog::create(['olt_id' => $olt->id, 'operation' => 'test', 'onu_ref' => '1/1/5:1', 'status' => 'ok']);
        Setting::set('outage_maintenance_windows', [['olt_id' => $olt->id, 'pon_port' => '1/1/6', 'from' => $this->at(-10), 'to' => $this->at(300)]]);
        SupportTicket::create([
            'customer_id' => $c['open_ticket']->id,
            'created_by' => User::factory()->create(['role' => 'admin'])->id,
            'gangguan' => 'Internet mati',
            'subject' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ]);

        foreach (array_slice(array_keys($phases), 0, 8) as $ref) {
            $phases[$ref] = 'los';
        }
        foreach (range(5, 40, 5) as $m) {
            $summary = $this->tick($olt, $m, $phases);
        }
        $this->assertSame(2, $summary['suppressed']['isolated'], 'isolated / isolir customers are counted, not recorded');

        $by = fn (string $name) => OutageSignal::query()->where('customer_id', $c[$name]->id)->where('signal_type', 'single_los')->first();
        $this->assertNull($by('isolated'));
        $this->assertNull($by('isolir'));
        foreach (['migration' => 'migration', 'psb' => 'psb', 'olt_write' => 'olt_write', 'maintenance' => 'maintenance', 'open_ticket' => 'open_ticket'] as $name => $reason) {
            $sig = $by($name);
            $this->assertNotNull($sig, $name);
            $this->assertSame($reason, $sig->suppressed_reason, $name);
            $this->assertSame(OutageSignal::STATE_OPEN, $sig->state, $name);
        }
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $by('normal')->state);
    }

    public function test_flap_reopens_the_same_episode(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        // Online and stable for 30 minutes: resolved.
        foreach (range(40, 70, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online']);
        }
        $this->assertSame(OutageSignal::STATE_RESOLVED, OutageSignal::query()->firstOrFail()->state);

        $this->tick($olt, 80, ['1/1/1:1' => 'los']);

        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'single_los')->count());
        $sig = OutageSignal::query()->firstOrFail();
        $this->assertSame(1, $sig->flap_count);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertNull($sig->resolved_at);
    }

    public function test_huawei_single_los_is_cause_unknown_and_has_its_own_live_gate(): void
    {
        $zte = $this->olt('zte_c300', 'OLT ZTE');
        $olt = $this->olt('huawei_ma5608t', 'OLT GADANG');
        $this->monitor($olt);
        Setting::set('outage_mode_single_los', 'live');

        $this->assertSame('live', OutageSettings::modeFor('single_los', 'zte'));
        $this->assertSame('shadow', OutageSettings::modeFor('single_los', 'huawei'));
        Setting::set('outage_huawei_single_live', '1');
        $this->assertSame('live', OutageSettings::modeFor('single_los', 'huawei'));
        Setting::set('outage_huawei_single_live', '0');
        $this->assertNotNull($zte);

        $this->customer($olt, '0/1/1:1');
        $this->tick($olt, 0, ['0/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['0/1/1:1' => 'offline']);
        }

        $sig = OutageSignal::query()->where('signal_type', 'single_los')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertTrue($sig->payload['cause_unknown']);
        $this->assertSame('shadow', $sig->mode_at_confirm, 'Huawei single_los stays shadow until its own gate is on');
        $this->assertSame('none', $sig->action_taken);
    }

    public function test_unsupported_vendor_is_skipped_by_the_detector(): void
    {
        $olt = $this->olt('hioso_x', 'OLT HIOSO');
        $this->customer($olt, '1/1/1:1');
        $summary = $this->tick($olt, 0, ['1/1/1:1' => 'los']);

        $this->assertSame('skipped_vendor', $summary['olts'][$olt->id]['status']);
        $this->assertSame(0, OutageSignal::query()->count());
        $this->assertSame(0, OutageOnuTracker::query()->count());
    }

    public function test_blank_state_is_unknown_not_offline(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'state' => null, 'phase_state' => null, 'state_collected_at' => now()]);
        Carbon::setTestNow(Carbon::parse($this->at(0), 'Asia/Jakarta'));
        OltOnu::query()->update(['state_collected_at' => now()]);
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => now(), 'finished_at' => now()]);

        app(OutageDetector::class)->run();

        $this->assertSame('unknown', OutageOnuTracker::query()->firstOrFail()->phase);
    }

    // ------------------------------------------------------ hard rules / safety

    public function test_detector_is_read_only_towards_customers_devices_queues_and_http(): void
    {
        $olt = $this->olt();
        $customer = $this->customer($olt, '1/1/1:1');
        Queue::fake();
        Http::fake();
        foreach ([OltCollectorService::class, OltZteC300Service::class, OltHuaweiMa5608tService::class, OltHiosoService::class] as $svc) {
            $this->app->bind($svc, fn () => throw new \RuntimeException('OLT I/O is forbidden for the detector'));
        }
        DB::table('customers')->where('id', $customer->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $activityBefore = Activity::query()->count();

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 40, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }

        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('customers')->where('id', $customer->id)->value('updated_at'));
        $this->assertSame($activityBefore, Activity::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(1, OutageSignal::query()->count());
    }

    public function test_psb_and_migration_code_is_not_touched_by_the_detector(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Outage/OutageDetector.php'));
        foreach (['PsbOrchestrator', 'PsbWa', 'CustomerMigrationService', 'OltHuawei', 'OltZte', 'EvolutionApiService', 'NotificationService', 'sendToGroup'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, $needle);
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);
        $signals = OutageSignal::query()->count();
        $tracker = OutageOnuTracker::query()->count();

        Carbon::setTestNow(Carbon::parse($this->at(40), 'Asia/Jakarta'));
        OltOnu::query()->update(['state_collected_at' => now()]);
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => now(), 'finished_at' => now()]);
        $summary = app(OutageDetector::class)->run(null, true);

        $this->assertSame(1, $summary['confirmed']);
        $this->assertSame($signals, OutageSignal::query()->count());
        $this->assertSame(OutageSignal::STATE_OPEN, OutageSignal::query()->firstOrFail()->state);
        $this->assertSame($tracker, OutageOnuTracker::query()->count());
    }

    public function test_bulk_expire_nulls_open_key_so_the_scope_is_not_locked(): void
    {
        config(['outage.olt_ids' => []]);
        Carbon::setTestNow(Carbon::parse($this->at(0), 'Asia/Jakarta'));
        $old = OutageSignal::query()->create([
            'signal_type' => 'single_los',
            'scope_key' => 'customer:99',
            'state' => 'confirmed',
            'opened_at' => now()->subHours(5),
            'last_seen_at' => now()->subHours(3),
        ]);
        $this->assertSame('customer:99', $old->fresh()->open_key);

        $summary = app(OutageDetector::class)->run();

        $this->assertSame(1, $summary['expired']);
        $old->refresh();
        $this->assertSame(OutageSignal::STATE_EXPIRED, $old->state);
        $this->assertNull($old->open_key);
        // The scope can be opened again (would fail on the unique key if open_key were left set).
        $new = OutageSignal::query()->create(['signal_type' => 'single_los', 'scope_key' => 'customer:99', 'opened_at' => now()]);
        $this->assertSame('customer:99', $new->open_key);
    }

    public function test_db_and_cache_failures_never_throw_into_the_scheduler(): void
    {
        config(['outage.enabled' => true]);
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        // 1) Redis/cache failure while reading settings.
        Setting::flushRequestMemo();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        $this->assertFalse(OutageSettings::enabled());
        $this->assertFalse(OutageScheduleGate::shouldRun());
        $summary = app(OutageDetector::class)->runSafely();
        $this->assertArrayNotHasKey('error', $summary);
        $this->assertSame([], $summary['olts']);
    }

    public function test_database_failure_is_swallowed_by_settings_gate_detector_and_command(): void
    {
        config(['outage.enabled' => true]);
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        Setting::flushRequestMemo();
        Cache::flush();

        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed): void {
            if ($armed) {
                throw new \RuntimeException('database down');
            }
        });

        try {
            $this->assertFalse(OutageSettings::enabled());
            $this->assertSame('off', OutageSettings::mode('mass_los'));
            $this->assertFalse(OutageScheduleGate::shouldRun());

            $summary = app(OutageDetector::class)->runSafely();
            $this->assertArrayHasKey('error', $summary);
            $this->assertSame([], $summary['new_confirmed']);

            $exit = $this->artisan('outage:detect')->run();
            $this->assertSame(0, $exit, 'the scheduled command never fails the scheduler');
        } finally {
            $armed = false;
        }
    }

    public function test_settings_warning_is_time_based_not_once_per_process(): void
    {
        Log::spy();
        Carbon::setTestNow(Carbon::parse($this->at(0), 'Asia/Jakarta'));
        Setting::flushRequestMemo();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));

        OutageSettings::enabled();
        OutageSettings::enabled();          // same moment: rate limited
        Carbon::setTestNow(Carbon::parse($this->at(11), 'Asia/Jakarta'));
        OutageSettings::enabled();          // 11 minutes later: logs again

        Log::shouldHaveReceived('warning')->times(2);
    }

    // ---------------------------------------------------------- settings write path

    public function test_settings_command_validates_and_writes_an_activity_log_row(): void
    {
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_automation_enabled', 'value' => '1', '--actor' => 'tester'])->assertExitCode(0);

        $this->assertTrue(OutageSettings::enabled());
        $row = Activity::query()->where('log_name', 'outage')->latest('id')->firstOrFail();
        $this->assertSame('outage_automation_enabled', $row->properties['key']);
        $this->assertSame('1', $row->properties['new']);
        $this->assertSame('tester', $row->properties['actor']);

        // Live modes, customer WA and junk values are refused and write nothing.
        $count = Activity::query()->count();
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_mode_single_los', 'value' => 'live'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_customer_wa_mode', 'value' => 'live'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_mass_min_customers', 'value' => '0'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'bogus_key', 'value' => '1'])->assertExitCode(1);
        $this->assertSame($count, Activity::query()->count());
        $this->assertSame('shadow', OutageSettings::mode('single_los'));

        // OLT list limited to the approved ids; freshness and mass window bounded.
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_olt_ids', 'value' => '[1,2]'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_olt_ids', 'value' => '[1,9]'])->assertExitCode(0);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_state_max_age_minutes', 'value' => '120'])->assertExitCode(1);
        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_mass_window_minutes', 'value' => '5'])->assertExitCode(1);

        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_mode_weak_rx', 'value' => 'off'])->assertExitCode(0);
        $this->assertSame('off', OutageSettings::mode('weak_rx'));
    }

    public function test_settings_command_refuses_report_ops_and_psb_groups_as_shadow_target(): void
    {
        Setting::set('report_wa_group_ids', ['111111111111@g.us']);
        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us']);
        Setting::set('wa_report_group_jid', '120363000000000002@g.us');

        foreach (['111111111111@g.us', '120363000000000001@g.us', '120363000000000002@g.us'] as $jid) {
            $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_log_jid', 'value' => $jid])->assertExitCode(1);
        }
        $this->assertSame('', OutageSettings::shadowLogJid());

        $this->artisan('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_log_jid', 'value' => '120363000000000003@g.us'])->assertExitCode(0);
        $this->assertSame('120363000000000003@g.us', OutageSettings::shadowLogJid());
        $this->assertStringNotContainsString('120363000000000003', json_encode(Activity::query()->where('log_name', 'outage')->get()->pluck('properties')));
    }

    // ------------------------------------------------------------ shadow WA

    private function summaryWithMass(): array
    {
        Carbon::setTestNow(Carbon::parse($this->at(0))); // 12:00 WIB: outside quiet hours

        return ['new_confirmed' => [
            ['id' => 1, 'type' => 'mass_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/3', 'affected_count' => 6, 'would_action' => 'ticket_mass', 'cause_unknown' => false],
            ['id' => 2, 'type' => 'single_los', 'olt_id' => 1, 'olt_name' => 'OLT RA', 'pon_port' => '1/3/4', 'affected_count' => 1, 'would_action' => 'ticket_single', 'cause_unknown' => false],
        ]];
    }

    private function fakeEvolution(int $expectedSends): MockInterface
    {
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->times($expectedSends)->andReturn(['success' => true]);
        $this->app->instance(EvolutionApiService::class, $mock);

        return $mock;
    }

    public function test_shadow_wa_is_off_by_default(): void
    {
        Http::fake();
        $this->fakeEvolution(0);

        $this->assertFalse(app(OutageShadowNotifier::class)->send($this->summaryWithMass()));
        Http::assertNothingSent();
    }

    public function test_shadow_wa_goes_to_the_log_group_with_counts_and_labels_only(): void
    {
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->once()->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->once()->withArgs(function (string $jid, string $text) {
            return $jid === '120363000000000003@g.us'
                && str_contains($text, 'OLT RA PON 1/3/3 (6 pelanggan)')
                && str_contains($text, 'tidak ada WA ke pelanggan')
                && ! preg_match('/\d{10,}/', $text);
        })->andReturn(['success' => true]);
        $this->app->instance(EvolutionApiService::class, $mock);

        $this->assertTrue(app(OutageShadowNotifier::class)->send($this->summaryWithMass()));
        // Nothing new confirmed => nothing sent (the mock allows one send only).
        $this->assertFalse(app(OutageShadowNotifier::class)->send(['new_confirmed' => []]));
    }

    public function test_shadow_wa_is_refused_for_report_ops_and_psb_groups_unless_explicitly_allowed(): void
    {
        Log::spy();
        Setting::set('report_wa_group_ids', ['111111111111@g.us']);
        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us']);
        Setting::set('wa_report_group_jid', '120363000000000002@g.us');
        $this->fakeEvolution(0);

        foreach (['111111111111@g.us', '120363000000000002@g.us', '120363000000000001'] as $jid) {
            Setting::set('outage_shadow_log_jid', $jid);
            $this->assertFalse(app(OutageShadowNotifier::class)->send($this->summaryWithMass()), $jid);
        }
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_report_group_refusal_is_bypassable_only_by_the_explicit_setting_and_psb_never(): void
    {
        Setting::set('report_wa_group_ids', ['111111111111@g.us']);
        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us']);
        Setting::set('outage_shadow_allow_report_group', '1');
        $this->fakeEvolution(1);

        Setting::set('outage_shadow_log_jid', '111111111111@g.us');
        $this->assertTrue(app(OutageShadowNotifier::class)->send($this->summaryWithMass()));

        Setting::set('outage_shadow_log_jid', '120363000000000001@g.us');
        $this->assertFalse(app(OutageShadowNotifier::class)->send($this->summaryWithMass()));
    }

    public function test_detect_command_sends_one_shadow_summary_only_when_enabled_and_new_detections_exist(): void
    {
        config(['outage.enabled' => true]);
        Setting::set('outage_shadow_log_jid', '120363000000000003@g.us');
        $this->fakeEvolution(1);

        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 30, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        // Run the command at +35 minutes with a fresh collector snapshot.
        Carbon::setTestNow(Carbon::parse($this->at(35), 'Asia/Jakarta'));
        OltOnu::query()->update(['state_collected_at' => now()]);
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => now(), 'finished_at' => now()]);

        $this->artisan('outage:detect')->assertExitCode(0);
        $this->artisan('outage:detect')->assertExitCode(0); // second run: nothing new, no second WA

        $this->assertSame(1, OutageSignal::query()->whereNotNull('confirmed_at')->count());
    }

    public function test_detect_command_is_a_no_op_while_the_master_switch_is_off(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'los']);
        OutageSignal::query()->delete();

        $this->artisan('outage:detect')->assertExitCode(0);
        $this->assertSame(0, OutageSignal::query()->count());
    }

    public function test_shadow_list_is_read_only(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los']);
        }
        $before = OutageSignal::query()->get()->toArray();

        $this->artisan('outage:shadow-list', ['--confirmed' => true])->assertExitCode(0);
        $this->artisan('outage:shadow-list', ['--json' => true, '--type' => 'single_los'])->assertExitCode(0);

        $this->assertSame($before, OutageSignal::query()->get()->toArray());
    }

    // ------------------------------------------------------------------ schedule

    /** @return list<Event> */
    private function scheduleEvents(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return $schedule->events();
    }

    public function test_schedule_entry_is_top_level_safe_and_flag_gated(): void
    {
        $found = array_values(array_filter(
            $this->scheduleEvents('monitoring'),
            fn (Event $e) => str_contains((string) $e->command, 'outage:detect'),
        ));
        $this->assertCount(1, $found);
        $event = $found[0];

        $this->assertSame('3-59/5 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertFalse($event->filtersPass($this->app), 'flag defaults OFF: the schedule filter blocks the run');

        config(['outage.enabled' => true]);
        Setting::flushRequestMemo();
        $this->assertTrue($event->filtersPass($this->app));

        // An operator hold on monitoring beats the outage flag.
        config(['services.monitoring.admission_gate.enabled' => true, 'services.monitoring.admission_gate.operator_hold' => true]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['services.monitoring.admission_gate.operator_hold' => false]);

        $safe = array_filter($this->scheduleEvents('safe'), fn (Event $e) => str_contains((string) $e->command, 'outage:detect'));
        $this->assertCount(0, $safe, 'safe scheduler mode never runs the detector');
    }
}
