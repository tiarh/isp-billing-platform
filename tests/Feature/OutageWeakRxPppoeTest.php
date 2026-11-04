<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\OltCollectorService;
use App\Services\OltHiosoService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Roadmap 2.4 PR-3: weak_rx (3 consecutive days, one median sample per day) and pppoe_down
 * (per customer, one alarm per router). Shadow only: nothing is ticketed, sent or written to customers.
 */
#[Group('blocking-core')]
class OutageWeakRxPppoeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** UTC; 05:00 UTC = 12:00 WIB, so base + N whole days stays at noon WIB. */
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

    private function olt(): Olt
    {
        $olt = Olt::create(['name' => 'OLT RA', 'code' => 'RXP-'.++$this->seq, 'vendor' => 'zte_c300']);
        config(['outage.olt_ids' => [$olt->id], 'outage.serial_fallback_olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function customer(Olt $olt, string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'RXP-PKG'], ['name' => 'Outage pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'RXP-C'.$n,
            'name' => 'Outage Customer '.$n,
            'phone' => '0812000'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'rxp.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'olt_port_label' => $ref,
        ], $o));
    }

    private function router(): Router
    {
        return Router::create([
            'name' => 'R'.++$this->seq,
            'ip_address' => '198.18.6.'.$this->seq,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function at(int $minutes): Carbon
    {
        return Carbon::parse($this->base.' UTC')->addMinutes($minutes);
    }

    private function day(int $d, int $extra = 0): int
    {
        return $d * 1440 + $extra;
    }

    /**
     * One collector snapshot + one detector run.
     *
     * @param  array<string,string>  $phases  ref => online|los
     * @param  array<string,float|null>  $rx  ref => dBm (rx_collected_at = tick time unless $rxAt says otherwise)
     * @param  array<string,int>  $rxAt  ref => minutes offset of rx_collected_at
     * @param  array<string,string>  $ppp  ref => up|down|stale|none
     * @return array<string,mixed>
     */
    private function tick(Olt $olt, int $minutes, array $phases, array $rx = [], array $rxAt = [], array $ppp = [], bool $dry = false): array
    {
        $t = $this->at($minutes);
        Carbon::setTestNow($t);

        foreach ($phases as $ref => $p) {
            [$state, $phase] = $p === 'online' ? ['online', 'working'] : ['offline', 'LOS'];
            OltOnu::updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                [
                    'state' => $state,
                    'phase_state' => $phase,
                    'serial_number' => 'ZTEG'.md5($ref),
                    'distance_m' => 1000,
                    'state_collected_at' => $t,
                    'inventory_collected_at' => $t,
                    'phase_collected_at' => null,
                    'rx_power_dbm' => $rx[$ref] ?? null,
                    'rx_collected_at' => array_key_exists($ref, $rx) && $rx[$ref] !== null ? $this->at($rxAt[$ref] ?? $minutes) : null,
                ],
            );
        }
        foreach ($ppp as $ref => $state) {
            DB::table('customers')->where('olt_id', $olt->id)->where('olt_port_label', $ref)->update(match ($state) {
                'up' => ['is_online' => 1, 'pppoe_online_checked_at' => $t],
                'down' => ['is_online' => 0, 'pppoe_online_checked_at' => $t],
                'stale' => ['is_online' => 0, 'pppoe_online_checked_at' => $t->copy()->subMinutes(40)],
                default => ['is_online' => 0, 'pppoe_online_checked_at' => null],
            });
        }
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => $t, 'finished_at' => $t]);

        return app(OutageDetector::class)->run(null, $dry);
    }

    /** Three RX readings of one day (15 minutes apart): their median is $mid. */
    private function rxDay(Olt $olt, int $day, float $mid, string $ref = '1/1/1:1', float $spread = 0.5): void
    {
        // One sample per hour (the default sampling interval), 3 per day = the minimum for a day to count.
        foreach ([[0, $mid - $spread], [60, $mid], [120, $mid + $spread]] as [$off, $dbm]) {
            $this->tick($olt, $this->day($day, $off), [$ref => 'online'], [$ref => $dbm]);
        }
    }

    private function weak(): ?OutageSignal
    {
        return OutageSignal::query()->where('signal_type', 'weak_rx')->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------------- weak_rx

    public function test_three_consecutive_days_below_threshold_confirm_as_maintenance_and_only_record(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        Queue::fake();
        Http::fake();

        $this->tick($olt, 0, ['1/1/1:1' => 'online']); // baseline snapshot without RX
        $this->rxDay($olt, 0, -28.5);
        $this->rxDay($olt, 1, -28.4);
        // 90 minutes of weak RX per day is NOT three scans: two days are not enough.
        $this->assertSame(OutageSignal::STATE_OPEN, $this->weak()->state);
        $this->assertNull($this->weak()->would_action);

        $this->rxDay($olt, 2, -29.0);

        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('ticket_maintenance', $sig->would_action);
        $this->assertSame('shadow', $sig->mode_at_confirm);
        $this->assertSame('none', $sig->action_taken);
        $this->assertSame($c->id, $sig->customer_id);
        $this->assertSame('1/1/1', $sig->pon_port);
        $this->assertSame('days', $sig->payload['rule']);
        $this->assertCount(3, $sig->payload['daily']);
        // PR-4 contract: worst_dbm is the 3-day median that decided, strictly below the threshold.
        $this->assertEqualsWithDelta(-28.4, $sig->payload['worst_dbm'], 0.001);
        $this->assertLessThan(OutageSettings::weakRxDbm(), $sig->payload['worst_dbm']);
        $this->assertEqualsWithDelta(-29.0, $sig->payload['min_dbm'], 0.001);
        $this->assertSame(0, SupportTicket::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_daily_sample_is_the_median_so_one_outlier_does_not_make_a_day_weak(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        foreach ([0, 1, 2] as $d) {
            foreach ([[0, -35.0], [60, -20.0], [120, -21.0]] as [$off, $dbm]) {
                $this->tick($olt, $this->day($d, $off), ['1/1/1:1' => 'online'], ['1/1/1:1' => $dbm]);
            }
        }

        $t = OutageOnuTracker::query()->firstOrFail();
        $this->assertEqualsWithDelta(-21.0, $t->rx_daily[2]['dbm'], 0.001);
        // A lone early sample may open an episode, but a median of -21 never confirms it.
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'weak_rx')->where('state', OutageSignal::STATE_CONFIRMED)->count());
    }

    public function test_a_sample_with_the_same_rx_collected_at_is_not_a_new_sample(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        // The collector did not refresh RX: rx_collected_at stays at minute 5 for five detector runs.
        foreach ([5, 10, 15, 20, 25] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0], ['1/1/1:1' => 5]);
        }

        $t = OutageOnuTracker::query()->firstOrFail();
        $this->assertCount(1, $t->rx_samples);
        $this->assertSame(1, $t->rx_daily[0]['n']);
    }

    public function test_invalid_rx_and_offline_onus_give_no_sample(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->customer($olt, '1/1/1:2');
        $this->tick($olt, 0, ['1/1/1:1' => 'online', '1/1/1:2' => 'online']);

        // 5.0 dBm / -140 are sentinels, not readings; the second ONU is in LOS while RX is still stored.
        $this->tick($olt, 15, ['1/1/1:1' => 'online', '1/1/1:2' => 'los'], ['1/1/1:1' => 5.0, '1/1/1:2' => -30.0]);
        $this->tick($olt, 30, ['1/1/1:1' => 'online', '1/1/1:2' => 'los'], ['1/1/1:1' => -140.0, '1/1/1:2' => -30.0]);

        foreach (OutageOnuTracker::query()->get() as $t) {
            $this->assertEmpty($t->rx_samples);
            $this->assertEmpty($t->rx_daily);
        }
        $this->assertNull($this->weak());
    }

    public function test_stale_rx_reading_is_skipped(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        // rx_collected_at is 3 hours older than this snapshot.
        $this->tick($olt, 200, ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0], ['1/1/1:1' => 20]);

        $this->assertEmpty(OutageOnuTracker::query()->firstOrFail()->rx_samples);
    }

    public function test_a_missing_day_or_a_good_day_breaks_the_three_day_run(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        $this->rxDay($olt, 0, -28.5);
        $this->rxDay($olt, 1, -28.5);
        $this->rxDay($olt, 3, -28.5); // day 2 has no reading: not "3 days in a row"
        $this->assertSame(OutageSignal::STATE_OPEN, $this->weak()->state);

        $this->rxDay($olt, 4, -20.0); // a good day
        $this->rxDay($olt, 5, -28.5);
        $this->assertNotSame(OutageSignal::STATE_CONFIRMED, $this->weak()?->state);
    }

    public function test_a_falling_trend_is_informational_only_by_default(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        // -26.0 is above the -27 owner threshold: not weak, whatever the slope.
        foreach ([-23.5, -24.5, -25.5, -26.0] as $d => $mid) {
            $this->rxDay($olt, $d, $mid);
        }

        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_OPEN, $sig->state);
        $this->assertNull($sig->would_action);
        $this->assertEqualsWithDelta(2.5, $sig->payload['trend_db'], 0.001);
    }

    public function test_trend_confirms_only_when_the_setting_is_turned_on(): void
    {
        Setting::set('outage_weak_rx_trend_enabled', '1');
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        foreach ([-23.5, -24.5, -25.5, -26.0] as $d => $mid) {
            $this->rxDay($olt, $d, $mid);
        }

        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('trend', $sig->payload['rule']);
    }

    public function test_a_day_with_fewer_than_three_samples_does_not_count(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->rxDay($olt, 0, -29.0);
        $this->rxDay($olt, 1, -29.0);

        // Day 2: only two hourly samples so far (partial day): not enough to be the third weak day.
        $this->tick($olt, $this->day(2), ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0]);
        $this->tick($olt, $this->day(2, 60), ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0]);
        $this->assertSame(OutageSignal::STATE_OPEN, $this->weak()->state);

        $this->tick($olt, $this->day(2, 120), ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0]);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->weak()->state);
    }

    public function test_sampling_is_hourly_per_onu_even_when_rx_refreshes_every_five_minutes(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        foreach (range(5, 240, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online'], ['1/1/1:1' => -26.0 - ($m % 7) / 10]);
        }

        $t = OutageOnuTracker::query()->firstOrFail();
        $this->assertLessThanOrEqual(5, $t->rx_daily[0]['n']); // 240 min => 4-5 samples, not 48
        $this->assertGreaterThanOrEqual(4, $t->rx_daily[0]['n']);
        $this->assertLessThanOrEqual(24, count($t->rx_daily[0]['v']));
    }

    public function test_many_onus_in_one_run_give_bounded_tracker_writes_and_none_between_samples(): void
    {
        $olt = $this->olt();
        $phases = [];
        $rx = [];
        for ($i = 1; $i <= 310; $i++) {
            $ref = '1/1/'.(1 + intdiv($i, 100)).':'.$i;
            $this->customer($olt, $ref);
            $phases[$ref] = 'online';
            $rx[$ref] = -20.0;
        }
        $this->tick($olt, 0, $phases);

        $updates = 0;
        DB::listen(function ($q) use (&$updates) {
            if (stripos($q->sql, 'update `outage_onu_tracker`') === 0 || stripos($q->sql, 'update "outage_onu_tracker"') === 0) {
                $updates++;
            }
        });

        $this->tick($olt, 5, $phases, $rx);
        $this->assertLessThanOrEqual(OutageDetector::WEAK_RX_MAX_WRITES_PER_RUN, $updates);
        $this->assertGreaterThan(0, $updates);

        // 5 minutes later the RX refreshed again: every ONU is inside its sampling interval => no writes.
        $updates = 0;
        $this->tick($olt, 10, $phases, array_map(fn () => -20.5, $rx));
        $this->assertLessThanOrEqual(20, $updates); // only the 10 deferred by the cap, never all 310
    }

    public function test_hysteresis_and_flap_reopen_the_same_episode_without_a_second_would_ticket(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach ([0, 1, 2] as $d) {
            $this->rxDay($olt, $d, -28.5);
        }
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->weak()->state);

        // -26.5 is better than -27 but inside the 1 dB hysteresis band: still open.
        foreach ([3, 4, 5] as $d) {
            $this->rxDay($olt, $d, -26.5);
        }
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->weak()->state);

        // 3 days at or above -26: recovered.
        foreach ([6, 7, 8] as $d) {
            $this->rxDay($olt, $d, -22.0);
        }
        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_RESOLVED, $sig->state);
        $this->assertNull($sig->open_key);
        // PR-4 contract: the decision values are frozen at confirm; recovery is stored separately.
        $this->assertEqualsWithDelta(-28.5, $sig->payload['worst_dbm'], 0.001);
        $this->assertEqualsWithDelta(-28.5, $sig->payload['min_dbm'], 0.001);
        $this->assertEqualsWithDelta(-28.5, $sig->payload['rx_dbm'], 0.001);
        $this->assertEqualsWithDelta(-22.0, $sig->payload['recovery']['latest_dbm'], 0.001);

        // Weak again inside the 7 day window: same row re-opened (flap), still one would-ticket.
        foreach ([9, 10, 11] as $d) {
            $this->rxDay($olt, $d, -29.0);
        }
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'weak_rx')->count());
        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame(1, $sig->flap_count);
        $this->assertNotNull($sig->open_key);
    }

    public function test_open_unconfirmed_weak_rx_episode_keeps_the_worst_decision_median(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        $this->rxDay($olt, 0, -29.0);
        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_OPEN, $sig->state);
        $this->assertEqualsWithDelta(-29.0, $sig->payload['worst_dbm'], 0.001);

        // Readings improve but stay under the record level (-25) and never satisfy the 3-day rule.
        $this->rxDay($olt, 1, -26.0);
        $this->rxDay($olt, 2, -26.5);
        $sig = $this->weak();
        $this->assertSame(OutageSignal::STATE_OPEN, $sig->state);
        $this->assertEqualsWithDelta(-29.0, $sig->payload['worst_dbm'], 0.001);
        $this->assertEqualsWithDelta(-29.0, $sig->payload['min_dbm'], 0.001);
        $this->assertEqualsWithDelta(-26.5, $sig->payload['latest_dbm'], 0.001); // live info still follows
    }

    public function test_weak_rx_is_suppressed_for_a_customer_with_an_open_ticket_and_never_for_isolated_ones(): void
    {
        $olt = $this->olt();
        $withTicket = $this->customer($olt, '1/1/1:1');
        $this->customer($olt, '1/1/1:2', ['status' => 'isolated']);
        SupportTicket::create([
            'customer_id' => $withTicket->id,
            'created_by' => User::factory()->create(['role' => 'admin'])->id,
            'gangguan' => 'Internet mati',
            'subject' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ]);
        $this->tick($olt, 0, ['1/1/1:1' => 'online', '1/1/1:2' => 'online']);
        foreach ([0, 1, 2] as $d) {
            foreach ([0, 60, 120] as $off) {
                $this->tick($olt, $this->day($d, $off), ['1/1/1:1' => 'online', '1/1/1:2' => 'online'], ['1/1/1:1' => -29.0, '1/1/1:2' => -29.0]);
            }
        }

        $sig = $this->weak();
        $this->assertSame($withTicket->id, $sig->customer_id);
        $this->assertSame(OutageSignal::STATE_OPEN, $sig->state);
        $this->assertSame('open_ticket', $sig->suppressed_reason);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'weak_rx')->count()); // isolated: no episode
    }

    public function test_weak_rx_mode_off_records_nothing(): void
    {
        Setting::set('outage_mode_weak_rx', 'off');
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach ([0, 1, 2] as $d) {
            $this->rxDay($olt, $d, -29.0);
        }

        $this->assertSame(0, OutageSignal::query()->count());
        $this->assertEmpty(OutageOnuTracker::query()->firstOrFail()->rx_daily);
    }

    // ---------------------------------------------------------------- pppoe_down

    public function test_pppoe_down_for_ten_minutes_with_a_live_onu_is_a_would_alarm_record(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        Queue::fake();
        Http::fake();
        $this->tick($olt, 0, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);

        $this->tick($olt, 5, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']); // clock starts
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
        $this->tick($olt, 10, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']); // 5 minutes: recorded
        $this->assertSame(OutageSignal::STATE_OPEN, $this->pppoe()->state);
        $this->tick($olt, 15, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']); // 10 minutes: confirmed

        $sig = $this->pppoe();
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('noc_alarm', $sig->would_action);
        $this->assertSame('shadow', $sig->mode_at_confirm);
        $this->assertSame($c->id, $sig->customer_id);
        $this->assertSame('none', $sig->action_taken);
        $this->assertSame(0, SupportTicket::query()->count());

        // PPPoE back: resolved.
        $this->tick($olt, 20, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);
        $this->assertSame(OutageSignal::STATE_RESOLVED, $sig->fresh()->state);
        $this->assertNull(OutageOnuTracker::query()->firstOrFail()->pppoe_down_since);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @return list<array<string,mixed>> new_confirmed entries across the pppoe_down confirm run */
    private function pppoeConfirmEntries(): array
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);
        $this->tick($olt, 5, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']);
        $this->tick($olt, 10, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']);

        return $this->tick($olt, 15, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down'])['new_confirmed'];
    }

    public function test_confirmed_pppoe_down_adds_no_wa_entry_while_the_names_flag_is_off(): void
    {
        $this->assertSame([], $this->pppoeConfirmEntries(), 'flag off: summary unchanged (digest only)');
    }

    public function test_confirmed_pppoe_down_entry_carries_ids_but_no_names_when_the_flag_is_on(): void
    {
        Setting::set('outage_shadow_wa_include_names', '1');
        $entries = $this->pppoeConfirmEntries();

        $this->assertCount(1, $entries);
        $sig = $this->pppoe();
        $this->assertSame($sig->id, $entries[0]['id']);
        $this->assertSame('pppoe_down', $entries[0]['type']);
        $this->assertSame($sig->customer_id, $entries[0]['customer_id']);
        $this->assertSame('customer', $entries[0]['level']);
        $this->assertSame('OLT RA', $entries[0]['olt_name']);
        $this->assertStringNotContainsString('Outage Customer', json_encode($entries));
        $this->assertStringNotContainsString('rxp.', json_encode($entries));
    }

    /** @return array{0: Customer, 1: list<array<string,mixed>>} the customer and every new_confirmed entry of three weak days */
    private function weakRxConfirmEntries(): array
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $entries = [];
        foreach ([0, 1, 2] as $d) {
            foreach ([0, 60, 120] as $off) {
                $entries = array_merge($entries, $this->tick($olt, $this->day($d, $off), ['1/1/1:1' => 'online'], ['1/1/1:1' => -28.5])['new_confirmed']);
            }
        }

        return [$c, array_values(array_filter($entries, fn ($e) => $e['type'] === 'weak_rx'))];
    }

    public function test_confirmed_weak_rx_adds_no_wa_entry_while_the_names_flag_is_off(): void
    {
        [, $weak] = $this->weakRxConfirmEntries();

        $this->assertSame([], $weak);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->weak()->state);
    }

    public function test_confirmed_weak_rx_adds_an_ids_only_wa_entry_when_the_names_flag_is_on(): void
    {
        Setting::set('outage_shadow_wa_include_names', '1');
        [$c, $weak] = $this->weakRxConfirmEntries();

        $this->assertCount(1, $weak);
        $this->assertSame($this->weak()->id, $weak[0]['id']);
        $this->assertSame($c->id, $weak[0]['customer_id']);
        $this->assertSame('customer', $weak[0]['level']);
        $this->assertStringNotContainsString('Outage Customer', json_encode($weak));
    }

    public function test_router_level_pppoe_down_entry_is_mass_without_customer_id(): void
    {
        Setting::set('outage_shadow_wa_include_names', '1');
        $olt = $this->olt();
        $router = $this->router();
        $phases = [];
        $up = [];
        for ($i = 1; $i <= 5; $i++) {
            $this->customer($olt, '1/1/1:'.$i, ['router_id' => $router->id]);
            $phases['1/1/1:'.$i] = 'online';
            $up['1/1/1:'.$i] = 'up';
        }
        $this->tick($olt, 0, $phases, ppp: $up);
        $down = array_map(fn () => 'down', $up);
        $entries = [];
        foreach ([5, 10, 15, 20, 25] as $m) {
            $entries = array_merge($entries, $this->tick($olt, $m, $phases, ppp: $down)['new_confirmed']);
        }

        $routerEntries = array_values(array_filter($entries, fn ($e) => ($e['level'] ?? '') === 'router'));
        $this->assertCount(1, $routerEntries);
        $this->assertNull($routerEntries[0]['customer_id']);
        $this->assertSame(5, $routerEntries[0]['affected_count']);
    }

    public function test_stale_or_missing_pppoe_data_makes_no_event_and_restarts_the_clock(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->customer($olt, '1/1/1:2');
        $on = ['1/1/1:1' => 'online', '1/1/1:2' => 'online'];
        $this->tick($olt, 0, $on, ppp: ['1/1/1:1' => 'up', '1/1/1:2' => 'up']);

        foreach ([5, 10, 15, 20, 25] as $m) {
            $this->tick($olt, $m, $on, ppp: ['1/1/1:1' => 'stale', '1/1/1:2' => 'none']);
        }
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
        $this->assertNull(OutageOnuTracker::query()->whereNotNull('pppoe_down_since')->first());

        // An already recorded (unconfirmed) episode is marked stale while the data is unknown.
        $this->tick($olt, 26, $on, ppp: ['1/1/1:1' => 'down', '1/1/1:2' => 'up']);
        $this->tick($olt, 31, $on, ppp: ['1/1/1:1' => 'down', '1/1/1:2' => 'up']);
        $ep = OutageSignal::query()->where('signal_type', 'pppoe_down')->firstOrFail();
        $this->assertSame(OutageSignal::STATE_OPEN, $ep->state);
        $this->tick($olt, 36, $on, ppp: ['1/1/1:1' => 'stale', '1/1/1:2' => 'up']);
        $this->assertSame('stale', $ep->fresh()->suppressed_reason);
        $this->assertSame(OutageSignal::STATE_OPEN, $ep->fresh()->state);
        OutageSignal::query()->delete();
        OutageOnuTracker::query()->update(['pppoe_down_since' => null]);
        $this->tick($olt, 42, $on, ppp: ['1/1/1:1' => 'up', '1/1/1:2' => 'up']);

        // Down was seen once, then the data goes stale for a long time: that gap does not count as down.
        $this->tick($olt, 50, $on, ppp: ['1/1/1:1' => 'down', '1/1/1:2' => 'up']);
        $this->tick($olt, 55, $on, ppp: ['1/1/1:1' => 'stale', '1/1/1:2' => 'up']);
        $this->tick($olt, 100, $on, ppp: ['1/1/1:1' => 'down', '1/1/1:2' => 'up']);
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
    }

    public function test_an_onu_in_los_is_not_a_pppoe_down(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);
        foreach ([5, 10, 15, 20] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los'], ppp: ['1/1/1:1' => 'down']);
        }

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
    }

    public function test_five_customers_down_on_one_router_raise_one_router_alarm_not_five(): void
    {
        $olt = $this->olt();
        $router = $this->router();
        $other = $this->router();
        $phases = [];
        $up = [];
        for ($i = 1; $i <= 6; $i++) {
            $this->customer($olt, '1/1/1:'.$i, ['router_id' => $i <= 5 ? $router->id : $other->id]);
            $phases['1/1/1:'.$i] = 'online';
            $up['1/1/1:'.$i] = 'up';
        }
        $this->tick($olt, 0, $phases, ppp: $up);

        $down = $up;
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $down['1/1/1:'.$i] = 'down';
        }
        foreach ([5, 10, 15, 20, 25] as $m) {
            $this->tick($olt, $m, $phases, ppp: $down);
        }

        $routerAlarm = OutageSignal::query()->where('signal_type', 'pppoe_down')->where('scope_key', 'router:'.$router->id.':ppp')->get();
        $this->assertCount(1, $routerAlarm);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $routerAlarm->first()->state);
        $this->assertSame('noc_alarm', $routerAlarm->first()->would_action);
        $this->assertSame(5, $routerAlarm->first()->affected_count);
        $this->assertNull($routerAlarm->first()->customer_id);
        $this->assertCount(5, $routerAlarm->first()->payload['affected_customer_ids']);
        $col = collect($routerAlarm->first()->affected_customer_ids)->sort()->values()->all();
        $pay = collect($routerAlarm->first()->payload['affected_customer_ids'])->sort()->values()->all();
        $this->assertSame($col, $pay);

        // The 5 members are children (never their own alarm); the lone customer of the other router is.
        $confirmedIndividuals = OutageSignal::query()->where('signal_type', 'pppoe_down')->whereNotNull('customer_id')
            ->where('state', OutageSignal::STATE_CONFIRMED)->get();
        $this->assertCount(1, $confirmedIndividuals);
        $children = OutageSignal::query()->where('signal_type', 'pppoe_down')->where('parent_signal_id', $routerAlarm->first()->id)->get();
        $this->assertCount(5, $children);
        foreach ($children as $child) {
            $this->assertSame(OutageSignal::STATE_OPEN, $child->state);
            $this->assertSame('inside_mass', $child->suppressed_reason);
        }

        // Recovery for two snapshots resolves the router alarm.
        $this->tick($olt, 30, $phases, ppp: $up);
        $this->tick($olt, 35, $phases, ppp: $up);
        $this->assertSame(OutageSignal::STATE_RESOLVED, $routerAlarm->first()->fresh()->state);
    }

    public function test_four_customers_down_on_one_router_stay_individual_alarms(): void
    {
        $olt = $this->olt();
        $router = $this->router();
        $phases = [];
        $up = [];
        for ($i = 1; $i <= 4; $i++) {
            $this->customer($olt, '1/1/1:'.$i, ['router_id' => $router->id]);
            $phases['1/1/1:'.$i] = 'online';
            $up['1/1/1:'.$i] = 'up';
        }
        $this->tick($olt, 0, $phases, ppp: $up);
        foreach ([5, 10, 15, 20] as $m) {
            $this->tick($olt, $m, $phases, ppp: array_map(fn () => 'down', $up));
        }

        $this->assertSame(0, OutageSignal::query()->where('scope_key', 'like', 'router:%')->count());
        $this->assertSame(4, OutageSignal::query()->where('signal_type', 'pppoe_down')->where('state', OutageSignal::STATE_CONFIRMED)->count());
    }

    public function test_pppoe_down_is_not_recorded_for_isolated_customers_or_open_migrations(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1', ['status' => 'isolated']);
        $this->customer($olt, '1/1/1:2', ['mikrotik_profile' => 'isolirebilling']);
        $on = ['1/1/1:1' => 'online', '1/1/1:2' => 'online'];
        $this->tick($olt, 0, $on, ppp: ['1/1/1:1' => 'up', '1/1/1:2' => 'up']);
        foreach ([5, 10, 15, 20] as $m) {
            $this->tick($olt, $m, $on, ppp: ['1/1/1:1' => 'down', '1/1/1:2' => 'down']);
        }

        $this->assertSame(0, OutageSignal::query()->count());
    }

    // ------------------------------------------------------------- safety rules

    public function test_new_signals_never_touch_customers_devices_queues_http_or_tickets_and_dry_run_persists_nothing(): void
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

        $this->tick($olt, 0, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);
        foreach ([5, 10, 15, 20] as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online'], ['1/1/1:1' => -29.0], ppp: ['1/1/1:1' => 'down']);
        }
        $before = [OutageSignal::query()->count(), OutageOnuTracker::query()->count(), json_encode(OutageOnuTracker::query()->pluck('rx_daily', 'id'))];
        $this->tick($olt, 25, ['1/1/1:1' => 'online'], ['1/1/1:1' => -30.0], ppp: ['1/1/1:1' => 'down'], dry: true);

        $this->assertSame($before, [OutageSignal::query()->count(), OutageOnuTracker::query()->count(), json_encode(OutageOnuTracker::query()->pluck('rx_daily', 'id'))]);
        $this->assertGreaterThan(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('customers')->where('id', $customer->id)->value('updated_at'));
        $this->assertSame(0, (int) DB::table('customers')->where('id', $customer->id)->value('is_online'));
        $this->assertSame($activityBefore, Activity::query()->count());
        $this->assertSame(0, SupportTicket::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_stale_collector_run_clears_the_pppoe_clock_and_opens_nothing(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'up']);
        $this->tick($olt, 5, ['1/1/1:1' => 'online'], ppp: ['1/1/1:1' => 'down']);
        $this->assertNotNull(OutageOnuTracker::query()->firstOrFail()->pppoe_down_since);

        // No fresh successful run for 2 hours: data is stale, nothing opens or closes, the clock resets.
        Carbon::setTestNow($this->at(125));
        app(OutageDetector::class)->run();

        $this->assertNull(OutageOnuTracker::query()->firstOrFail()->pppoe_down_since);
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count());
    }

    private function pppoe(): ?OutageSignal
    {
        return OutageSignal::query()->where('signal_type', 'pppoe_down')->whereNotNull('customer_id')->orderByDesc('id')->first();
    }
}
