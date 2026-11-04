<?php

namespace Tests\Unit;

use App\Services\Ops\VmAlertEvaluator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-core')]
class OpsVmAlertEvaluatorTest extends TestCase
{
    private int $t;

    private int $steal = 0;

    private int $total = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = CarbonImmutable::create(2026, 10, 20, 14, 10, 0, 'Asia/Jakarta')->timestamp;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<int|float>  $pcts  steal % of each 10 minute slice
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function drive(VmAlertEvaluator $ev, array $state, array $pcts, int $stepSeconds = 600): array
    {
        $decisions = [];
        foreach ($pcts as $pct) {
            $this->t += $stepSeconds;
            $this->total += 10000;
            $this->steal += (int) round($pct * 100);
            $result = $ev->step($state, $this->t, [
                'cpu' => ['steal' => $this->steal, 'total' => $this->total],
                'inform_count' => null,
                'snapshot_at' => $this->t - 60,
                'snapshot_known' => true,
                'hold' => false,
            ]);
            $state = $result['state'];
            $decisions[] = $result['decisions']['steal'];
        }

        return [$state, $decisions];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function informStep(VmAlertEvaluator $ev, array $state, int $count, ?int $snapshotAt, bool $hold = false): array
    {
        $result = $ev->step($state, $this->t, [
            'cpu' => null,
            'inform_count' => $count,
            'snapshot_at' => $snapshotAt,
            'snapshot_known' => true,
            'hold' => $hold,
        ]);

        return [$result['state'], $result['decisions']];
    }

    /** @return array<string, mixed> */
    private function stateWithRaw(VmAlertEvaluator $ev, int $value): array
    {
        $state = $ev->newState($this->t - 7200);
        for ($minutes = 130; $minutes >= 60; $minutes -= 10) {
            $state['raw'][] = [$this->t - $minutes * 60, $value];
        }

        return $state;
    }

    // ------------------------------------------------------------------ steal

    public function test_steal_above_threshold_for_30_minutes_alerts(): void
    {
        $ev = new VmAlertEvaluator;
        [$state, $d] = $this->drive($ev, $ev->newState($this->t), [0, 31, 35, 40]);

        $this->assertSame(['learning', 'insufficient', 'insufficient', 'alert_new'], $d);
        $pending = $ev->pending($state);
        $this->assertSame(
            "eBilling ops: server eBilling (VM102) lambat.\nCPU steal 35% selama 30 menit (batas 30%).\nBiasanya karena VM lain di Proxmox sedang berat.",
            $pending['alerts']['steal']
        );
    }

    public function test_one_slice_at_29_percent_resets_the_run(): void
    {
        $ev = new VmAlertEvaluator;
        [$state, $d] = $this->drive($ev, $ev->newState($this->t), [0, 31, 29, 31]);
        $this->assertSame([], $ev->pending($state)['alerts']);
        $this->assertSame('insufficient', $d[3]);

        [$state, $d] = $this->drive($ev, $state, [31, 31]);
        $this->assertSame(['ok', 'alert_new'], $d);
        $this->assertArrayHasKey('steal', $ev->pending($state)['alerts']);
    }

    public function test_gap_longer_than_15_minutes_is_insufficient(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 31, 31]);
        [$state, $d] = $this->drive($ev, $state, [31], 1800);

        $this->assertSame(['insufficient'], $d);
        $this->assertSame([], $ev->pending($state)['alerts']);
    }

    public function test_negative_counter_delta_clears_the_ring_without_alert(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 31]);
        $this->steal = 0;
        $this->total = 0;
        [$state, $d] = $this->drive($ev, $state, [5]);

        $this->assertSame(['learning'], $d);
        $this->assertCount(1, $state['ring']);
        $this->assertSame([], $ev->pending($state)['alerts']);
    }

    public function test_recovery_needs_30_minute_average_below_25_percent(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'on');

        [$state, $d] = $this->drive($ev, $state, [27, 27, 27]);
        $this->assertSame('alert_ongoing', $d[2]);

        [$state, $d] = $this->drive($ev, $state, [24, 24, 24]);
        $this->assertSame(['alert_ongoing', 'alert_ongoing', 'recovered'], $d);
        $this->assertSame(
            'eBilling ops: VM102 pulih. CPU steal 24% (30 menit terakhir).',
            $ev->pending($state)['recoveries']['steal']
        );
    }

    public function test_retrigger_within_one_hour_is_suppressed_and_has_no_pulih(): void
    {
        $ev = new VmAlertEvaluator(['dedupe_seconds' => 7200]);
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'on');
        [$state] = $this->drive($ev, $state, [24, 24, 24]);
        $state = $ev->markRecoverySent($state, ['steal']);

        [$state, $d] = $this->drive($ev, $state, [35, 35, 35]);
        $this->assertSame('alert_suppressed', $d[2]);
        $this->assertSame([], $ev->pending($state)['alerts']);

        [$state, $d] = $this->drive($ev, $state, [24, 24, 24]);
        $this->assertSame('recovered', $d[2]);
        $this->assertSame([], $ev->pending($state)['recoveries'], 'no pulih for an alert that was never sent');
    }

    public function test_retrigger_after_one_hour_sends_again(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'on');
        [$state] = $this->drive($ev, $state, [24, 24, 24]);
        $state = $ev->markRecoverySent($state, ['steal']);

        [$state, $d] = $this->drive($ev, $state, [35, 35, 35]);
        $this->assertSame('alert_new', $d[2]);
        $this->assertArrayHasKey('steal', $ev->pending($state)['alerts']);
    }

    public function test_unsent_alert_stays_pending_until_marked_sent(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        [$state, $d] = $this->drive($ev, $state, [35]);

        $this->assertSame('alert_ongoing', $d[0]);
        $this->assertArrayHasKey('steal', $ev->pending($state)['alerts']);

        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'on');
        $this->assertSame([], $ev->pending($state)['alerts']);
    }

    // ----------------------------------------------------------------- normal

    /** @return array<string, mixed> */
    private function stateWithHours(VmAlertEvaluator $ev, array $valuesByDaysAgo): array
    {
        $state = $ev->newState($this->t - 86400 * 8);
        $local = CarbonImmutable::createFromTimestamp($this->t)->setTimezone('Asia/Jakarta');
        foreach ($valuesByDaysAgo as $daysAgo => $value) {
            $state['hours'][$local->subDays($daysAgo)->format('Y-m-d').' 14'] = $value;
        }

        return $state;
    }

    public function test_normal_is_the_same_hour_median_with_three_days(): void
    {
        $ev = new VmAlertEvaluator;
        $normal = $ev->normal($this->stateWithHours($ev, [1 => 1000, 2 => 1100, 3 => 900]), $this->t);

        $this->assertSame('same_hour', $normal['source']);
        $this->assertSame(1000.0, $normal['value']);
    }

    public function test_two_days_of_history_fall_back_to_recent_samples(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $this->stateWithHours($ev, [1 => 1000, 2 => 1100]);
        for ($minutes = 130; $minutes >= 60; $minutes -= 10) {
            $state['raw'][] = [$this->t - $minutes * 60, 800];
        }

        $normal = $ev->normal($state, $this->t);
        $this->assertSame('recent', $normal['source']);
        $this->assertSame(800.0, $normal['value']);
    }

    public function test_fewer_than_six_recent_samples_means_learning(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $ev->newState($this->t - 7200);
        for ($minutes = 100; $minutes >= 60; $minutes -= 10) {
            $state['raw'][] = [$this->t - $minutes * 60, 800];
        }

        $this->assertNull($ev->normal($state, $this->t));
    }

    public function test_one_outage_day_does_not_move_the_median(): void
    {
        $ev = new VmAlertEvaluator;
        $normal = $ev->normal($this->stateWithHours($ev, [1 => 1000, 2 => 1000, 3 => 1000, 4 => 1000, 5 => 200, 6 => 1000, 7 => 1000]), $this->t);

        $this->assertSame(1000.0, $normal['value']);
    }

    // ----------------------------------------------------------------- inform

    public function test_inform_needs_two_consecutive_low_samples(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $this->stateWithRaw($ev, 1000);

        [$state, $d] = $this->informStep($ev, $state, 850, $this->t - 60);
        $this->assertSame('bad_1', $d['inform']);
        $this->assertSame([], $ev->pending($state)['alerts']);

        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 850, $this->t - 60);
        $this->assertSame('alert_new', $d['inform']);
        $this->assertSame(
            "eBilling ops: modem yang lapor ke GenieACS turun.\n850 modem lapor ≤30 menit, normal ±1000 (turun 15%).\nBisa karena listrik padam, jaringan putus, atau GenieACS bermasalah.",
            $ev->pending($state)['alerts']['inform']
        );
    }

    public function test_a_good_sample_between_resets_the_count(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $this->stateWithRaw($ev, 1000);
        [$state] = $this->informStep($ev, $state, 850, $this->t - 60);
        $this->t += 600;
        [$state] = $this->informStep($ev, $state, 990, $this->t - 60);
        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 850, $this->t - 60);

        $this->assertSame('bad_1', $d['inform']);
    }

    public function test_inform_recovery_uses_frozen_normal_and_two_samples(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $this->stateWithRaw($ev, 1000);
        [$state] = $this->informStep($ev, $state, 850, $this->t - 60);
        $this->t += 600;
        [$state] = $this->informStep($ev, $state, 850, $this->t - 60);
        $state = $ev->markAlertSent($state, ['inform'], $this->t, 'on');

        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 949, $this->t - 60);
        $this->assertSame('alert_ongoing', $d['inform']);

        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 960, $this->t - 60);
        $this->assertSame('alert_ongoing', $d['inform']);

        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 960, $this->t - 60);
        $this->assertSame('recovered', $d['inform']);
        $this->assertSame(
            'eBilling ops: modem lapor GenieACS normal lagi (960, normal ±1000).',
            $ev->pending($state)['recoveries']['inform']
        );
    }

    // ------------------------------------------------------------------ stale

    public function test_snapshot_older_than_30_minutes_skips_inform_without_a_sample(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $ev->newState($this->t - 3700);
        [$state, $d] = $this->informStep($ev, $state, 500, $this->t - 31 * 60);

        $this->assertSame('skipped_stale', $d['inform']);
        $this->assertSame('ok', $d['stale']);
        $this->assertSame([], $state['raw']);
    }

    public function test_snapshot_older_than_60_minutes_alerts_stale_and_recovers(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $ev->newState($this->t - 3700);
        $since = $this->t - 61 * 60;
        [$state, $d] = $this->informStep($ev, $state, 500, $since);

        $this->assertSame('alert_new', $d['stale']);
        $this->assertSame('skipped_stale', $d['inform']);
        $hhmm = CarbonImmutable::createFromTimestamp($since)->setTimezone('Asia/Jakarta')->format('H:i');
        $this->assertSame(
            "eBilling ops: data GenieACS tidak update sejak {$hhmm} WIB (>60 menit).\nSync gagal atau tertahan; jumlah modem belum bisa dicek.",
            $ev->pending($state)['alerts']['stale']
        );
        $state = $ev->markAlertSent($state, ['stale'], $this->t, 'on');

        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 500, $this->t - 60);
        $this->assertSame('recovered', $d['stale']);
        $this->assertSame('eBilling ops: data GenieACS update lagi.', $ev->pending($state)['recoveries']['stale']);
    }

    public function test_stale_is_silent_during_operator_hold_and_first_hour(): void
    {
        $ev = new VmAlertEvaluator;

        [$state, $d] = $this->informStep($ev, $ev->newState($this->t - 3700), 500, $this->t - 90 * 60, true);
        $this->assertSame('hold', $d['stale']);
        $this->assertSame([], $ev->pending($state)['alerts']);

        [$state, $d] = $this->informStep($ev, $ev->newState($this->t - 1800), 500, $this->t - 90 * 60);
        $this->assertSame('learning', $d['stale']);
        $this->assertSame([], $ev->pending($state)['alerts']);
    }

    public function test_suppressed_retrigger_is_sent_once_after_the_dedupe_hour_if_still_open(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $ev->newState($this->t - 7200);
        $local = CarbonImmutable::createFromTimestamp($this->t)->setTimezone('Asia/Jakarta');
        foreach ([1, 2, 3] as $day) {
            foreach (['14', '15', '16'] as $hour) {
                $state['hours'][$local->subDays($day)->format('Y-m-d').' '.$hour] = 1000;
            }
        }
        $run = function (int $count) use ($ev, &$state): string {
            $this->t += 600;
            [$state, $d] = $this->informStep($ev, $state, $count, $this->t - 60);

            return $d['inform'];
        };

        // 14:20 / 14:30: alert goes out (flapping PLN pattern)
        $run(850);
        $this->assertSame('alert_new', $run(850));
        $this->assertCount(1, $ev->pending($state)['alerts']);
        $state = $ev->markAlertSent($state, ['inform'], $this->t, 'on');
        $firstSentAt = $this->t;

        // 14:40 / 14:50: pulih goes out
        $run(960);
        $this->assertSame('recovered', $run(960));
        $this->assertCount(1, $ev->pending($state)['recoveries']);
        $state = $ev->markRecoverySent($state, ['inform']);

        // 15:00 / 15:10: down again inside the hour -> suppressed, nothing to send
        $run(850);
        $this->assertSame('alert_suppressed', $run(850));
        $this->assertSame([], $ev->pending($state)['alerts']);
        $run(850); // 15:20
        $this->assertSame([], $ev->pending($state)['alerts']);

        // 15:30 = one hour after the first alert and still down: sent once
        $run(850);
        $this->assertGreaterThanOrEqual(3600, $this->t - $firstSentAt);
        $alerts = $ev->pending($state)['alerts'];
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('850 modem lapor ≤30 menit, normal ±1000 (turun 15%).', $alerts['inform']);
        $state = $ev->markAlertSent($state, ['inform'], $this->t, 'on');
        $run(850);
        $this->assertSame([], $ev->pending($state)['alerts'], 'no repeat while it stays down');

        // recovery after that: one pulih
        $run(960);
        $this->assertSame('recovered', $run(960));
        $this->assertSame(
            'eBilling ops: modem lapor GenieACS normal lagi (960, normal ±1000).',
            $ev->pending($state)['recoveries']['inform']
        );
    }

    public function test_open_event_older_than_six_hours_is_dropped_after_a_gap(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'on');
        $lastAlert = $state['types']['steal']['last_alert_at'];

        // short gap, event younger than 6 h: kept
        $this->t += 3600;
        $kept = $ev->step($state, $this->t, [
            'cpu' => null, 'inform_count' => null, 'snapshot_at' => null, 'snapshot_known' => false, 'hold' => false,
        ], 'on');
        $this->assertSame('alert', $kept['state']['types']['steal']['status']);

        // two days later (mode was off): dropped, no pulih for it
        $this->t += 172800;
        $result = $ev->step($state, $this->t, [
            'cpu' => null, 'inform_count' => null, 'snapshot_at' => null, 'snapshot_known' => false, 'hold' => false,
        ], 'on');
        $this->assertSame('ok', $result['state']['types']['steal']['status']);
        $this->assertSame([], $ev->pending($result['state'])['recoveries']);
        $this->assertSame([], $ev->pending($result['state'])['alerts']);
        $this->assertSame($lastAlert, $result['state']['types']['steal']['last_alert_at']);
    }

    public function test_two_runs_on_the_same_snapshot_count_as_one_sample(): void
    {
        $ev = new VmAlertEvaluator;
        $state = $this->stateWithRaw($ev, 1000);
        $snapshot = $this->t - 60;

        [$state, $d] = $this->informStep($ev, $state, 850, $snapshot);
        $this->assertSame('bad_1', $d['inform']);
        $this->t += 600;
        [$state, $d] = $this->informStep($ev, $state, 850, $snapshot);

        $this->assertSame('same_snapshot', $d['inform']);
        $this->assertSame([], $ev->pending($state)['alerts']);
        $this->assertSame(9, count($state['raw']));
    }

    public function test_log_mode_alert_is_sent_once_when_mode_becomes_on(): void
    {
        $ev = new VmAlertEvaluator;
        [$state] = $this->drive($ev, $ev->newState($this->t), [0, 35, 35, 35]);
        $state = $ev->markAlertSent($state, ['steal'], $this->t, 'log');
        $this->assertSame([], $ev->pending($state)['alerts']);

        $result = $ev->step($state, $this->t + 60, [
            'cpu' => null, 'inform_count' => null, 'snapshot_at' => null, 'snapshot_known' => false, 'hold' => false,
        ], 'on');

        $this->assertArrayHasKey('steal', $ev->pending($result['state'])['alerts']);
    }
}
