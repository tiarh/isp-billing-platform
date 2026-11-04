<?php

namespace Tests\Feature;

use App\Services\Outage\Diagnosis\Backtest\BacktestGate;
use App\Services\Outage\Diagnosis\Backtest\BacktestResult;
use App\Services\Outage\Diagnosis\Backtest\BacktestRunner;
use App\Services\Outage\Diagnosis\Backtest\CorpusLabels;
use App\Services\Outage\Diagnosis\Backtest\CorpusScrubber;
use App\Services\Outage\Diagnosis\Backtest\SnapshotHydrator;
use App\Services\Outage\Diagnosis\DiagnosisContextSnapshot;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DiagnosisSyntheticContexts;
use Tests\TestCase;

/**
 * Back-test building blocks (learn & measure, section 2.1): the snapshot is enough to replay the classifier (property
 * test over the synthetic contexts), the hydrator refuses what it cannot replay, the runner counts like the weekly
 * report and the gate fails on exactly the four conditions of the roadmap. No database, no customer data.
 */
#[Group('blocking-core')]
class DiagnosisSnapshotReplayTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-26 03:00:00', 'UTC');
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

    // ------------------------------------------------------------------ property: snapshot replay == original

    /**
     * Cause, confidence, rule and action survive the snapshot; so do the evidence lines, except for a history longer than
     * the snapshot keeps (20 newest entries): its "13 times in 24 h" counts then read lower. That is the documented cut.
     */
    public function test_replaying_a_snapshot_gives_the_same_answer_as_the_original_context(): void
    {
        $cases = DiagnosisSyntheticContexts::cases($this->now);
        $this->assertGreaterThanOrEqual(24, count($cases));
        foreach ($cases as $name => [$ctx]) {
            $original = $this->diagnoser()->classify($ctx);
            $hydrated = SnapshotHydrator::hydrate(DiagnosisContextSnapshot::of($ctx));

            $this->assertNotNull($hydrated, $name);
            $replayed = $this->diagnoser()->classify($hydrated);
            foreach (['cause_code', 'confidence', 'rule_id', 'layer', 'suggested_action', 'secondary'] as $key) {
                $this->assertSame($original[$key], $replayed[$key], "{$name}: {$key}");
            }
            if ($name !== 'long_history_los') {
                $this->assertSame(array_column($original['evidence'], 'text'), array_column($replayed['evidence'], 'text'), "{$name}: evidence text");
            }
        }
    }

    public function test_the_synthetic_cases_are_answered_as_labelled(): void
    {
        $wrong = [];
        foreach (DiagnosisSyntheticContexts::cases($this->now) as $name => [$ctx, $truth]) {
            if ($truth !== null && $this->diagnoser()->classify($ctx)['cause_code'] !== $truth) {
                $wrong[] = $name;
            }
        }

        $this->assertSame([], $wrong, 'a synthetic case no longer gets the cause it is labelled with');
    }

    public function test_a_long_history_comes_back_in_the_order_the_correlator_builds_it(): void
    {
        $ctx = DiagnosisSyntheticContexts::cases($this->now)['long_history_los'][0];
        $snap = DiagnosisContextSnapshot::of($ctx);
        $this->assertCount(DiagnosisContextSnapshot::MAX_LIST, $snap['history']['onu_drops']);

        $hydrated = SnapshotHydrator::hydrate($snap);
        $times = array_map(fn (array $d) => $d['at']->getTimestamp(), $hydrated['history']['onu_drops']);
        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times, 'oldest first');
        $this->assertInstanceOf(Carbon::class, $hydrated['now']);
        $this->assertSame('UTC', $hydrated['now']->timezoneName);
    }

    // ------------------------------------------------------------------ hydrator

    public function test_a_truncated_stub_or_an_unknown_version_cannot_be_replayed(): void
    {
        $this->assertNull(SnapshotHydrator::hydrate(['snapshot_version' => 1, 'rules_version' => 'x', 'truncated' => true]));
        $this->assertNull(SnapshotHydrator::hydrate(['snapshot_version' => 99, 'now' => '2026-10-26T03:00:00+00:00']));
        $this->assertNull(SnapshotHydrator::hydrate(['now' => '2026-10-26T03:00:00+00:00']));
        $this->assertFalse(SnapshotHydrator::canReplay(['snapshot_version' => 1, 'truncated' => true]));
        $this->assertNotNull(SnapshotHydrator::hydrate(['snapshot_version' => 2, 'now' => '2026-10-26T03:00:00+00:00']));
    }

    // ------------------------------------------------------------------ runner + result

    /** @return list<array<string,mixed>> */
    private function corpus(): array
    {
        $rows = [];
        foreach (DiagnosisSyntheticContexts::cases($this->now) as $name => [$ctx, $truth]) {
            $rows[] = [
                'id' => 'S-'.$name, 'trigger' => 'ticket_open', 'stored_cause' => $truth, 'timely' => true,
                'context' => DiagnosisContextSnapshot::of($ctx),
                'labels' => $truth === null ? [] : [['kind' => 'ticket', 'cause' => $truth, 'source' => 'technician', 'bulk' => false]],
            ];
        }

        return $rows;
    }

    public function test_the_runner_counts_predictions_correct_and_coverage_like_the_weekly_report(): void
    {
        $result = (new BacktestRunner($this->diagnoser()))->run($this->corpus());
        $s = $result->summary();

        $this->assertSame(0, $s['skipped']);
        $this->assertSame($s['evaluated'], $s['predicted'] + $s['abstained']);
        $this->assertSame($s['predicted'], $s['correct'], 'every synthetic label is the answer of the rules');
        $this->assertSame(1.0, $s['precision']);
        $this->assertGreaterThan(0.9, $s['coverage']);
        $this->assertArrayHasKey('fibre', $s['families']);
        $this->assertSame(['low', 'high'], array_keys($s['classes']['drop_los']['ci']));
        $this->assertSame([], $result->diff());
    }

    public function test_a_flag_override_replays_the_same_rows_and_is_forgotten_afterwards(): void
    {
        $runner = new BacktestRunner($this->diagnoser());
        $rows = $this->corpus();
        // rca5 is read when a context is BUILT, not when it is classified, so the replay keeps the stored value;
        // what counts here is that an override never leaks out of the run
        $runner->run($rows, ['diagnosis_rca5_enabled' => '0', 'outage_weak_rx_dbm' => '-20']);

        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        $inside = OutageSettings::withOverrides(['outage_weak_rx_dbm' => '-20'], fn () => OutageSettings::weakRxDbm());
        $this->assertSame(-20.0, $inside);
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
    }

    public function test_a_row_without_a_usable_snapshot_is_skipped_and_counted(): void
    {
        $rows = $this->corpus();
        $rows[] = ['id' => 'S-truncated', 'context' => ['snapshot_version' => 1, 'truncated' => true], 'labels' => []];

        $result = (new BacktestRunner($this->diagnoser()))->run($rows);

        $this->assertSame(['S-truncated'], $result->skipped);
        $this->assertSame(1, $result->summary()['skipped']);
    }

    public function test_labels_follow_the_weekly_report_rules(): void
    {
        $row = fn (array $label, bool $timely = true) => ['timely' => $timely, 'labels' => [$label + ['kind' => 'ticket', 'bulk' => false]]];

        $this->assertSame('drop_los', CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'technician']))['cause']);
        $this->assertTrue(CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'admin']))['strong']);
        $this->assertSame('drop_los', CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'none']))['cause'], 'a label from before the source column still scores');
        $this->assertFalse(CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'none']))['strong']);
        foreach (['unknown', 'other', 'auto_closed', 'not_fault', 'self_recovered'] as $cause) {
            $this->assertNull(CorpusLabels::truth($row(['cause' => $cause, 'source' => 'technician']))['cause'], $cause);
        }
        $this->assertNull(CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'admin', 'bulk' => true]))['cause'], 'bulk admin close');
        $this->assertNull(CorpusLabels::truth($row(['cause' => 'drop_los', 'source' => 'technician'], false))['cause'], 'a late prediction is not a prediction');

        $outcome = ['timely' => true, 'labels' => [['kind' => 'outcome', 'cause' => 'cpe_dead', 'signature' => 'serial_swap', 'independent' => true]]];
        $this->assertSame(['cause' => 'cpe_dead', 'kind' => 'outcome', 'strong' => false], CorpusLabels::truth($outcome));
        $circular = ['labels' => [['kind' => 'outcome', 'cause' => 'power_off', 'signature' => 'power_only', 'independent' => false]]];
        $this->assertNull(CorpusLabels::truth($circular)['cause'], 'circular labels never score');
    }

    // ------------------------------------------------------------------ gate

    /**
     * @param  list<array{0:string,1:string,2:?string,3?:bool}>  $items  [id, predicted, truth, strong]
     */
    private function made(array $items): BacktestResult
    {
        $entries = [];
        foreach ($items as $i) {
            $entries[] = ['id' => $i[0], 'trigger' => 'ticket_open', 'predicted' => $i[1], 'confidence' => 'sedang', 'rule_id' => 'x', 'stored_cause' => null, 'stored_version' => null, 'truth' => $i[2], 'truth_kind' => 'ticket', 'strong' => $i[3] ?? true];
        }

        return new BacktestResult($entries);
    }

    /** @return list<array{0:string,1:string,2:?string,3?:bool}> 20 rows: 15 drop_los (13 right), 5 power_off (4 right), 2 abstained */
    private function baseItems(): array
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = ["a{$i}", 'drop_los', $i <= 13 ? 'drop_los' : 'power_off'];
        }
        for ($i = 1; $i <= 5; $i++) {
            $items[] = ["b{$i}", 'power_off', $i <= 4 ? 'power_off' : 'drop_los'];
        }
        $items[] = ['c1', 'unknown', 'drop_los'];
        $items[] = ['c2', 'data_blind', 'power_off'];

        return $items;
    }

    public function test_the_gate_passes_for_the_baseline_itself(): void
    {
        $base = $this->made($this->baseItems());

        $this->assertSame([], BacktestGate::failures($base->toBaseline('t'), $base));
    }

    public function test_the_gate_fails_when_family_precision_drops_by_more_than_one_prediction(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        $items[15] = ['b1', 'power_off', 'drop_los', false]; // one more wrong (not a strong label): exactly one prediction's worth
        $this->assertSame([], BacktestGate::failures($base->toBaseline('t'), $this->made($items)));

        $items[16] = ['b2', 'power_off', 'drop_los', false]; // two more wrong
        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));
        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'Family precision')));
    }

    public function test_the_gate_fails_when_coverage_falls_by_more_than_two_points(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        $items[2] = ['a3', 'unknown', 'drop_los']; // 22 -> 20 predicted of 22: -4.5 points, precision unchanged or better

        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));

        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'Coverage')));
    }

    public function test_the_gate_fails_when_a_big_class_loses_more_than_five_points(): void
    {
        $base = $this->made($this->baseItems()); // drop_los 13 of 15 = 86.7 %
        $items = $this->baseItems();
        $items[3] = ['a4', 'drop_los', 'power_off', false];
        $items[4] = ['a5', 'drop_los', 'power_off', false]; // 11 of 15 = 73.3 %

        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));

        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'Class drop_los')));
    }

    public function test_the_gate_fails_on_a_new_wrong_answer_on_a_strong_label_only(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        $items[5] = ['a6', 'power_off', 'drop_los', false]; // a new wrong answer on a weak label: allowed while the numbers hold
        $this->assertSame([], array_values(array_filter(BacktestGate::failures($base->toBaseline('t'), $this->made($items)), fn (string $f) => str_contains($f, 'New wrong'))));

        $items[5] = ['a6', 'power_off', 'drop_los', true];
        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));
        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'New wrong answer') && str_contains($f, 'a6')));
    }

    public function test_the_gate_fails_when_baseline_rows_are_missing_from_the_corpus(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        array_splice($items, 3, 2); // a corpus file lost two rows

        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));

        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'Baseline rows missing from the corpus (2')));
    }

    public function test_changed_from_lists_the_rows_a_flag_really_changed(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        $items[0] = ['a1', 'power_off', 'drop_los'];

        $this->assertSame([['id' => 'a1', 'from' => 'drop_los', 'to' => 'power_off']], $this->made($items)->changedFrom($base));
        $this->assertSame([], $base->changedFrom($base));
    }

    public function test_the_gate_fails_for_rows_the_baseline_does_not_know(): void
    {
        $base = $this->made($this->baseItems());
        $items = $this->baseItems();
        $items[] = ['z1', 'drop_los', 'drop_los'];

        $failures = BacktestGate::failures($base->toBaseline('t'), $this->made($items));

        $this->assertNotEmpty(array_filter($failures, fn (string $f) => str_contains($f, 'missing from baseline.json')));
    }

    // ------------------------------------------------------------------ scrubber

    public function test_the_scrubber_cuts_personal_data_and_the_guard_finds_what_is_left(): void
    {
        $ctx = [
            'router' => ['id' => 5, 'name' => 'RA-OLT Pusat'], 'olt' => ['id' => 2, 'name' => 'OLT Besar'],
            'pppoe' => ['events' => [['reason' => 'user budi.santoso gone, 081234567890, aa:bb:cc:dd:ee:ff, a@b.co', 'at' => '2026-10-26T03:00:00+00:00']]],
            'customer' => ['name' => 'Budi', 'phone' => '0812', 'id' => 1],
        ];

        $clean = CorpusScrubber::scrubContext($ctx, ['budi.santoso']);

        $this->assertSame('router-5', $clean['router']['name']);
        $withOdp = CorpusScrubber::scrubContext(['customer' => ['id' => 1, 'odp' => 'ODP Bedali L1'], 'cluster' => ['odp' => 'odp bedali l1', 'odp_share' => 0.5]]);
        $this->assertMatchesRegularExpression('/^odp-[0-9a-f]{8}$/', $withOdp['customer']['odp']);
        $this->assertSame($withOdp['customer']['odp'], $withOdp['cluster']['odp'], 'the same ODP stays the same ODP');
        $this->assertSame('olt-2', $clean['olt']['name']);
        $this->assertArrayNotHasKey('name', $clean['customer']);
        $this->assertArrayNotHasKey('phone', $clean['customer']);
        $this->assertSame([], CorpusScrubber::violations($clean));
        $this->assertStringNotContainsString('budi', strtolower((string) json_encode($clean)));
        $this->assertStringNotContainsString('0812345', (string) json_encode($clean));
        $this->assertNotSame([], CorpusScrubber::violations($ctx));
        $this->assertNotSame([], CorpusScrubber::violations(['x' => 'call 081234567890']));
        $this->assertNotSame([], CorpusScrubber::violations(['x' => ['ssid' => 'WiFi-Budi']]));
    }
}
