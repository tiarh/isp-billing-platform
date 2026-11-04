<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Outage\Diagnosis\DiagnosisPresenter;
use App\Services\Outage\Diagnosis\PrecisionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Calibration of the diagnosis (learn & measure section 2.3) and the numbers of the precision card:
 *  - "pasti" only at 90 % precision on 30 checks (10 of them tickets) with a Wilson floor of 75 %;
 *  - circular labels are shown and never counted;
 *  - the table is filled by the Monday report and read by the panel word (setting, default off);
 *  - the closed tickets of the card add up.
 */
#[Group('blocking-core')]
class DiagnosisCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-26 02:00:00', 'UTC'));
        Setting::flushRequestMemo();
        PrecisionReport::forgetLatestCalibration();
        $package = Package::firstOrCreate(['code' => 'CAL-PKG'], ['name' => 'CAL pkg', 'price' => 100000]);
        $this->customer = Customer::create(['code' => 'CAL-C1', 'name' => 'Pelanggan CAL', 'phone' => '0810000013', 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_CAL_1', 'package_id' => $package->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PrecisionReport::forgetLatestCalibration();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ thresholds

    public function test_pasti_needs_30_checks_of_which_10_are_tickets_90_percent_and_a_wilson_floor_of_75(): void
    {
        // 30 checks, 10 tickets, 28 right: 93 %, Wilson floor 78.7 %
        $this->assertSame('pasti', PrecisionReport::calibrationState('drop_los', 10, 20, 28));
        // one check short
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 10, 19, 27));
        // 9 tickets, 21 independent: 30 checks but one ticket short
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 9, 21, 29));
        // 30 checks, 27 right = 90 % exactly, but the Wilson floor is 74.4 %
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 10, 20, 27));
        // below 90 %
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 20, 20, 35));
        $this->assertGreaterThanOrEqual(0.75, PrecisionReport::wilson(28, 30)['low']);
        $this->assertLessThan(0.75, PrecisionReport::wilson(27, 30)['low']);
    }

    public function test_a_weak_class_and_an_abstention_are_belum_tahu(): void
    {
        $this->assertSame('belum_tahu', PrecisionReport::calibrationState('drop_los', 10, 0, 4), '40 % on 10 checks');
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 9, 0, 3), 'too few checks to call it weak');
        $this->assertSame('kemungkinan', PrecisionReport::calibrationState('drop_los', 10, 0, 5), '50 % is not under 50 %');
        $this->assertSame('belum_tahu', PrecisionReport::calibrationState('unknown', 40, 0, 40));
        $this->assertSame('belum_tahu', PrecisionReport::calibrationState('data_blind', 0, 0, 0));
    }

    // ------------------------------------------------------------------ the weekly report

    /**
     * A ticket closed in the week of 19 Oct 2026 with a prediction written a minute after it was opened.
     *
     * @param  array<string,mixed>  $o  created / resolved (UTC), source, diag (false = none), diag_minutes, diag_cause
     */
    private function ticket(string $rootCause, array $o = []): SupportTicket
    {
        $n = ++$this->seq;
        $created = Carbon::parse($o['created'] ?? '2026-10-20 02:00:00', 'UTC')->addMinutes($n);
        $resolved = Carbon::parse($o['resolved'] ?? '2026-10-21 03:00:00', 'UTC')->addMinutes($n);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $o['customer'] ?? $this->freshCustomer();
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'gangguan' => 'Mati', 'status' => 'closed', 'type' => 'incident', 'priority' => 'normal']);
        DB::table('support_tickets')->where('id', $ticket->id)->update([
            'created_at' => $created->format('Y-m-d H:i:s'), 'resolved_at' => $resolved->format('Y-m-d H:i:s'),
            'root_cause' => $rootCause, 'root_cause_source' => $o['source'] ?? 'technician',
        ]);
        if (($o['diag'] ?? true) !== false) {
            $this->diagnosis($o['diag_cause'] ?? 'drop_los', 'ticket_open', $created->copy()->addMinutes($o['diag_minutes'] ?? 1), $ticket->id, $customer);
        }

        return $ticket->fresh();
    }

    /** One customer per repair: the calibration counts one vote per customer and repair. */
    private function freshCustomer(): Customer
    {
        $n = ++$this->seq;

        return Customer::create(['code' => 'CAL-X'.$n, 'name' => 'Pelanggan CAL X'.$n, 'phone' => '0812999'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_CAL_X'.$n, 'package_id' => $this->customer->package_id, 'status' => 'active']);
    }

    private function diagnosis(string $cause, string $trigger, Carbon $at, ?int $ticketId = null, ?Customer $customer = null): OutageDiagnosis
    {
        $d = OutageDiagnosis::create([
            'customer_id' => ($customer ?? $this->customer)->id, 'ticket_id' => $ticketId, 'trigger' => $trigger, 'state' => 'done', 'cause_code' => $cause, 'confidence' => 'sedang', 'layer' => 'onu',
            'evidence' => [], 'secondary' => [], 'rules_version' => 'test',
        ]);
        DB::table('outage_diagnoses')->where('id', $d->id)->update(['created_at' => $at->format('Y-m-d H:i:s')]);

        return $d;
    }

    /** An outcome label of one customer; its window is [at - 24 h, at + 26 h] like the labeler's. */
    private function outcome(string $subjectType, int $subjectId, int $diagnosisId, string $outcome, string $signature, bool $independent = true, bool $final = true, ?Customer $customer = null, ?Carbon $at = null): void
    {
        $at ??= Carbon::parse('2026-10-21 05:00:00', 'UTC');
        DB::table('outage_outcome_labels')->insert([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'customer_id' => ($customer ?? $this->customer)->id, 'outcome' => $outcome, 'family' => PrecisionReport::familyOf($outcome),
            'signature' => $signature, 'independent' => $independent, 'diagnosis_id' => $diagnosisId, 'labelled_at' => '2026-10-22 03:00:00', 'final' => $final,
            'window_from' => $at->copy()->subHours(24)->format('Y-m-d H:i:s'), 'window_to' => $at->copy()->addHours(26)->format('Y-m-d H:i:s'),
        ]);
    }

    /** An episode of a customer with a prediction and an outcome label (a detector episode that the data checked). */
    private function checkedEpisode(string $cause, string $outcome, string $signature, ?Customer $customer = null, bool $independent = true, string $at = '2026-10-21 05:00:00'): OutageDiagnosis
    {
        $customer ??= $this->freshCustomer();
        $when = Carbon::parse($at, 'UTC');
        $d = $this->diagnosis($cause, 'auto_episode', $when, null, $customer);
        $this->outcome('signal', 1000 + $d->id, $d->id, $outcome, $signature, $independent, true, $customer, $when);

        return $d;
    }

    private function week(): Carbon
    {
        return Carbon::parse('2026-10-19', 'Asia/Jakarta');
    }

    public function test_the_weekly_report_calibrates_with_tickets_and_independent_labels_and_never_counts_circular_ones(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->ticket('drop_los');
        }
        // 20 detector episodes whose prediction (drop_los) was checked by the data: 19 fibre repairs, 1 power outage
        for ($i = 1; $i <= 20; $i++) {
            $this->checkedEpisode('drop_los', $i <= 19 ? 'drop_los' : 'power_off', $i <= 19 ? 'rx_jump' : 'unisolate');
        }
        // 15 dying-gasp episodes -> power_off (circular) and one provisional label: counted nowhere
        for ($i = 1; $i <= 15; $i++) {
            $this->checkedEpisode('power_off', 'power_off', 'power_only', null, false, '2026-10-21 06:00:00');
        }
        $c = $this->freshCustomer();
        $d = $this->diagnosis('drop_los', 'auto_episode', Carbon::parse('2026-10-21 07:00:00', 'UTC'), null, $c);
        $this->outcome('signal', 3000, $d->id, 'drop_los', 'rx_jump', true, false, $c);

        $r = (new PrecisionReport)->compute($this->week());

        $rows = collect($r['calibration'])->keyBy('cause');
        $drop = $rows['drop_los'];
        $this->assertSame(10, $drop['n_ticket']);
        $this->assertSame(20, $drop['n_independent']);
        $this->assertSame(29, $drop['correct'], '10 tickets + 19 fibre repairs; the power outage is wrong');
        $this->assertSame('pasti', $drop['state']);
        $this->assertGreaterThanOrEqual(0.75, $drop['wilson_low']);
        $power = $rows['power_off'];
        $this->assertSame(0, $power['n_independent']);
        $this->assertSame(15, $power['n_circular']);
        $this->assertSame('kemungkinan', $power['state'], 'circular labels never make a class "pasti" and do not count');
        $this->assertSame(['independent' => 20, 'circular' => 15], array_intersect_key($r['outcomes'], ['independent' => 1, 'circular' => 1]));
        $this->assertSame(20, collect($r['outcomes']['by_class'])->sum('independent'), 'the provisional label is not counted');
    }

    public function test_a_family_hit_with_another_cause_does_not_count_toward_pasti(): void
    {
        // owner 2026-10-28 (review-621 L3): "pasti" is scored on the EXACT cause. drop_los predicted, optical_degrade (same
        // fibre family) was the answer: 30 checks, 10 of them tickets, all family hits, none exact.
        for ($i = 0; $i < 10; $i++) {
            $this->ticket('optical_degrade');
        }
        for ($i = 1; $i <= 20; $i++) {
            $this->checkedEpisode('drop_los', 'optical_degrade', 'rx_jump');
        }

        $drop = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause')['drop_los'];

        $this->assertSame([10, 20], [$drop['n_ticket'], $drop['n_independent']]);
        $this->assertSame(0, $drop['correct'], 'a family hit is not an exact hit');
        $this->assertSame(30, $drop['correct_family'], 'the family hit stays visible as the secondary column');
        $this->assertSame(1.0, $drop['precision_family']);
        $this->assertSame(0.0, $drop['precision']);
        $this->assertNotSame('pasti', $drop['state']);
        $this->assertSame('belum_tahu', $drop['state'], '0 % exact on 30 checks is weak');

        // the same 30 checks with the exact cause: pasti
        $this->assertSame('pasti', PrecisionReport::calibrationState('drop_los', 10, 20, 30));
    }

    public function test_a_prediction_scored_through_its_ticket_is_not_counted_again_as_an_outcome(): void
    {
        $t = $this->ticket('drop_los');
        $diagnosisId = (int) DB::table('outage_diagnoses')->where('ticket_id', $t->id)->value('id');
        $this->outcome('ticket', $t->id, $diagnosisId, 'optical_degrade', 'rx_jump', true, true, Customer::find($t->customer_id), Carbon::parse('2026-10-20 03:00:00', 'UTC'));

        $row = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause')['drop_los'];

        $this->assertSame(1, $row['n_ticket']);
        $this->assertSame(0, $row['n_independent']);
    }

    public function test_one_repair_is_one_vote_however_many_episodes_and_tickets_describe_it(): void
    {
        // a flapping ONU: 3 episodes around one RX jump, no ticket => ONE independent vote
        $flapper = $this->freshCustomer();
        foreach (['2026-10-21 03:00:00', '2026-10-21 08:00:00', '2026-10-21 13:00:00'] as $at) {
            $this->checkedEpisode('drop_los', 'drop_los', 'rx_jump', $flapper, true, $at);
        }
        $row = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause')['drop_los'];
        $this->assertSame([0, 1, 1], [$row['n_ticket'], $row['n_independent'], $row['correct']]);

        // the same repair with a labelled ticket: the ticket is the vote, the episodes add nothing
        $t = $this->ticket('drop_los', ['customer' => $flapper, 'created' => '2026-10-21 04:00:00', 'resolved' => '2026-10-21 14:00:00']);
        $row = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause')['drop_los'];
        $this->assertSame([1, 0, 1], [$row['n_ticket'], $row['n_independent'], $row['correct']], 'n_ticket 1, n_independent 0');
        $this->assertNotNull($t);
    }

    public function test_repeat_tickets_of_one_customer_and_repair_are_one_vote_t400_t410_t421(): void
    {
        $c = $this->freshCustomer();
        foreach (['2026-10-19 03:00:00', '2026-10-20 01:55:00', '2026-10-21 03:00:00'] as $i => $created) {
            $this->ticket($i === 0 ? 'pppoe_stuck' : 'drop_los', ['customer' => $c, 'created' => $created, 'resolved' => Carbon::parse($created, 'UTC')->addHours(5)->format('Y-m-d H:i:s')]);
        }
        $other = $this->freshCustomer(); // a different customer is a different vote
        $this->ticket('drop_los', ['customer' => $other]);

        $rows = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause');

        $this->assertSame(2, $rows['drop_los']['n_ticket'], 'T410 and T421 style repeats chain into one vote, plus the other customer');
        $this->assertSame(1, $rows['drop_los']['correct'], 'the first ticket of the cluster is its vote (pppoe_stuck: a miss), the other customer is a hit');
    }

    public function test_a_close_made_with_the_data_chip_is_not_a_ticket_check_of_its_own(): void
    {
        $c = $this->freshCustomer();
        $t = $this->ticket('drop_los', ['customer' => $c]);
        TicketActivity::create(['ticket_id' => $t->id, 'action' => 'root_cause_set', 'new_value' => ['root_cause' => 'drop_los', 'via' => 'signature'], 'description' => 'Penyebab: Kabel drop putus']);

        $none = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause');
        $this->assertArrayNotHasKey('drop_los', $none->all(), 'the data suggested it and no outcome label exists: no check');

        $diagnosisId = (int) DB::table('outage_diagnoses')->where('ticket_id', $t->id)->value('id');
        $this->outcome('ticket', $t->id, $diagnosisId, 'optical_degrade', 'rx_jump', true, true, $c, Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        $row = collect((new PrecisionReport)->compute($this->week())['calibration'])->keyBy('cause')['drop_los'];
        $this->assertSame([0, 1], [$row['n_ticket'], $row['n_independent']], 'it counts through its outcome label only, never toward the 10 tickets');
    }

    public function test_the_label_that_differs_from_the_data_is_listed_not_changed(): void
    {
        $t = $this->ticket('cpe_dead');
        $diagnosisId = (int) DB::table('outage_diagnoses')->where('ticket_id', $t->id)->value('id');
        $this->outcome('ticket', $t->id, $diagnosisId, 'drop_los', 'rx_jump');
        $agree = $this->ticket('optical_degrade');
        $this->outcome('ticket', $agree->id, (int) DB::table('outage_diagnoses')->where('ticket_id', $agree->id)->value('id'), 'drop_los', 'rx_jump');

        $r = (new PrecisionReport)->compute($this->week());

        $this->assertCount(1, $r['disagreements'], 'optical vs drop_los is the same family: no disagreement');
        $this->assertSame($t->id, $r['disagreements'][0]['ticket_id']);
        $this->assertSame('Kabel ke rumah putus', $r['disagreements'][0]['data']);
        $this->assertNotNull($r['disagreements'][0]['label']);
        $this->assertSame('rx_jump', $r['disagreements'][0]['signature']);
        $this->assertSame('cpe_dead', $t->fresh()->root_cause, 'the label is never changed');
    }

    public function test_the_closed_tickets_of_the_card_add_up(): void
    {
        $this->ticket('drop_los');                                                              // evaluated
        $this->ticket('power_off', ['diag_cause' => 'unknown']);                                 // evaluated, abstained
        $this->ticket('auto_closed', ['source' => 'system']);                                    // closed_auto
        $this->ticket('unknown');                                                                // truth_unknown
        $this->ticket('not_fault');                                                              // truth_not_scored
        $this->ticket('self_recovered');                                                         // truth_not_scored
        $this->ticket('drop_los', ['source' => 'admin', 'created' => '2026-10-05 02:00:00']);    // bulk_closed (admin, 16 days later)
        $this->ticket('drop_los', ['diag' => false]);                                            // no_prediction
        $this->ticket('drop_los', ['diag_minutes' => 120]);                                      // backfilled (late prediction)

        $r = (new PrecisionReport)->compute($this->week());

        $this->assertSame(9, $r['tickets']);
        $this->assertSame($r['tickets'], $r['evaluated'] + $r['closed_auto'] + $r['truth_unknown'] + $r['truth_not_scored'] + $r['backfilled'] + $r['bulk_closed'] + $r['no_prediction']);
        $this->assertSame([2, 1, 1, 2, 1, 1, 1], [$r['evaluated'], $r['closed_auto'], $r['truth_unknown'], $r['truth_not_scored'], $r['backfilled'], $r['bulk_closed'], $r['no_prediction']]);
        $this->assertSame(1, $r['abstained']);
        $this->assertSame(1, $r['by_version'][0]['predicted'] ?? 0, 'per version: 2 evaluated, 1 abstained');
        $this->assertArrayHasKey('ci_family', $r['by_version'][0]);
    }

    // ------------------------------------------------------------------ stored table + the panel word

    public function test_the_monday_run_stores_the_calibration_and_the_panel_word_follows_the_setting(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->ticket('drop_los', ['diag_cause' => 'drop_los']);
        }
        for ($i = 1; $i <= 20; $i++) {
            $this->checkedEpisode('drop_los', 'drop_los', 'rx_jump');
        }

        (new PrecisionReport)->store($this->week());

        $row = DB::table('outage_diagnosis_calibration')->where('cause_code', 'drop_los')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-10-19', Carbon::parse($row->week_start)->format('Y-m-d'));
        $this->assertSame('pasti', $row->state);
        $this->assertSame(10, (int) $row->n_ticket);
        $this->assertSame(20, (int) $row->n_independent);
        // running the same week again updates in place
        (new PrecisionReport)->store($this->week());
        $this->assertSame(1, DB::table('outage_diagnosis_calibration')->where('cause_code', 'drop_los')->count());

        // the word: off by default
        $drop = new OutageDiagnosis(['cause_code' => 'drop_los', 'confidence' => 'tinggi', 'state' => 'done', 'trigger' => 'ticket_open', 'evidence' => [], 'secondary' => []]);
        $drop->id = 1;
        $this->assertNull(DiagnosisPresenter::payload($drop)['confidence_word']);

        Setting::set('diagnosis_calibrated_labels_enabled', '1');
        Setting::flushRequestMemo();
        PrecisionReport::forgetLatestCalibration();
        $this->assertSame('Pasti', DiagnosisPresenter::payload($drop)['confidence_word']);
        // a "tinggi" of a class that is not pasti, and a "sedang" of a pasti class: Kemungkinan
        $power = new OutageDiagnosis(['cause_code' => 'power_off', 'confidence' => 'tinggi', 'state' => 'done', 'trigger' => 'ticket_open', 'evidence' => [], 'secondary' => []]);
        $power->id = 2;
        $this->assertSame('Kemungkinan', DiagnosisPresenter::payload($power)['confidence_word']);
        $drop->confidence = 'sedang';
        $this->assertSame('Kemungkinan', DiagnosisPresenter::payload($drop)['confidence_word']);
        $unknown = new OutageDiagnosis(['cause_code' => 'unknown', 'confidence' => 'rendah', 'state' => 'done', 'trigger' => 'ticket_open', 'evidence' => [], 'secondary' => []]);
        $unknown->id = 3;
        $this->assertSame('Belum tahu', DiagnosisPresenter::payload($unknown)['confidence_word']);
        // the stored confidence is never rewritten
        $this->assertSame('sedang', DiagnosisPresenter::payload($drop)['confidence']);
    }

    public function test_the_calibrated_word_is_kemungkinan_for_everything_before_the_first_monday_run(): void
    {
        Setting::set('diagnosis_calibrated_labels_enabled', '1');
        Setting::flushRequestMemo();
        $this->assertSame([], PrecisionReport::latestCalibration());

        $this->assertSame('Kemungkinan', DiagnosisPresenter::calibratedWord('drop_los', 'tinggi'));
        $this->assertSame('Belum tahu', DiagnosisPresenter::calibratedWord('data_blind', 'sedang'));
    }
}
