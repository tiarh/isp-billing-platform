<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageOutcomeLabel;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\Outcome\OutcomeLabeler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Outcome labels (learn & measure section 2.2): what the stored data says fixed a closed incident. One fixture per
 * signature, built from the real patterns of 22-24 Oct (ids only, synthetic data): the PON 1/2/10 repair, the
 * Gadang repair, a serial swap with and without a migration row, an unisolate, the Krian 24-ONU same-run dying gasp
 * (circular power_only, NOT co_recovery) and a LOS that simply came back (no label).
 */
#[Group('blocking-core')]
class OutageOutcomeLabelerTest extends TestCase
{
    use RefreshDatabase;

    /** Ticket opened Thu 22 Oct 10:00 WIB, closed 16:00 WIB. */
    private Carbon $open;

    private Carbon $close;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->open = Carbon::parse('2026-10-22 03:00:00', 'UTC');
        $this->close = $this->open->copy()->addHours(6);
        Carbon::setTestNow($this->close->copy()->addHours(27));
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fmt(Carbon $c): string
    {
        return $c->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<array{date:string,dbm:float,n:int}>|null  $rxDaily
     * @return array{olt:Olt,customer:Customer,ticket:SupportTicket,diagnosis:OutageDiagnosis}
     */
    private function incident(string $ref = '1/2/10:6', ?array $rxDaily = null, ?float $liveRx = null): array
    {
        $n = ++$this->seq;
        $olt = Olt::firstOrCreate(['code' => 'OC-OLT'], ['name' => 'OLT outcome', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::firstOrCreate(['code' => 'OC-PKG'], ['name' => 'OC pkg', 'price' => 100000]);
        $c = Customer::create([
            'code' => 'OC-C'.$n, 'name' => 'Pelanggan OC '.$n, 'phone' => '0812888'.$n, 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_OC_'.$n,
            'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => $ref,
        ]);
        OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c->id, 'phase' => 'online', 'rx_daily' => $rxDaily ?? [
            ['date' => '2026-10-20', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-21', 'dbm' => -27.9, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -21.0, 'n' => 20],
        ]]);
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'pon_port' => substr($ref, 0, (int) strrpos($ref, ':')), 'state' => 'working', 'phase_state' => 'working',
            'rx_power_dbm' => $liveRx ?? -20.7, 'state_collected_at' => $this->close->copy()->addHour(), 'phase_collected_at' => $this->close->copy()->addHour(), 'rx_collected_at' => $this->close->copy()->addHour(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => 'resolved', 'type' => 'incident', 'priority' => 'normal']);
        DB::table('support_tickets')->where('id', $ticket->id)->update(['created_at' => $this->fmt($this->open), 'resolved_at' => $this->fmt($this->close)]);
        $diagnosis = OutageDiagnosis::create([
            'customer_id' => $c->id, 'ticket_id' => $ticket->id, 'trigger' => 'ticket_open', 'state' => 'done', 'cause_code' => 'drop_los', 'confidence' => 'sedang', 'layer' => 'onu',
            'evidence' => [], 'secondary' => [], 'rules_version' => 'test',
        ]);
        DB::table('outage_diagnoses')->where('id', $diagnosis->id)->update(['created_at' => $this->fmt($this->open->copy()->addMinute())]);

        return ['olt' => $olt, 'customer' => $c, 'ticket' => $ticket->fresh(), 'diagnosis' => $diagnosis];
    }

    private function onuEvent(Olt $olt, string $ref, Carbon $at, string $to, ?string $from = null): void
    {
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => null, 'from_phase' => $from, 'to_phase' => $to, 'cause' => in_array($to, ['los', 'dying_gasp'], true) ? $to : null,
            'source' => 'olt_poll', 'occurred_at' => $this->fmt($at), 'collected_at' => $this->fmt($at),
        ]);
    }

    private function label(string $signature, int $subjectId): ?OutageOutcomeLabel
    {
        return OutageOutcomeLabel::query()->where('subject_type', 'ticket')->where('subject_id', $subjectId)->where('signature', $signature)->first();
    }

    /** @return array{scanned:int,written:int,final:int,removed:int} */
    private function runLabeler(?Carbon $now = null): array
    {
        return app(OutcomeLabeler::class)->run(500, OutcomeLabeler::SLICE_HOURS, $now);
    }

    /** Move an incident() ticket (and its diagnosis) to another open / close time. */
    private function moveTicket(SupportTicket $ticket, string $opened, string $closed): void
    {
        DB::table('support_tickets')->where('id', $ticket->id)->update(['created_at' => $opened, 'resolved_at' => $closed]);
        DB::table('outage_diagnoses')->where('ticket_id', $ticket->id)->update(['created_at' => Carbon::parse($opened, 'UTC')->addMinute()->format('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------ M2: a repair before the subject opened is not its fix

    public function test_a_ticket_opened_the_day_after_the_repair_gets_no_rx_jump(): void
    {
        // the 22 Oct repair: -28 / -27.9 before, -21.0 on the 22nd; this ticket opens on the 23rd and closes the same day
        $x = $this->incident('1/2/10:6', [
            ['date' => '2026-10-20', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-21', 'dbm' => -27.9, 'n' => 20],
            ['date' => '2026-10-22', 'dbm' => -21.0, 'n' => 20], ['date' => '2026-10-24', 'dbm' => -20.9, 'n' => 20],
        ]);
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-21 20:00:00', 'UTC'), 'los', 'online');
        $this->moveTicket($x['ticket'], '2026-10-23 03:00:00', '2026-10-23 09:00:00');

        $r = $this->runLabeler(Carbon::parse('2026-10-24 12:00:00', 'UTC'));

        $this->assertSame(1, $r['scanned']);
        $this->assertNull($this->label('rx_jump', $x['ticket']->id), 'a repair a day earlier is not the fix of a later ticket');
        $this->assertSame(0, OutageOutcomeLabel::query()->count());

        // the preview of the same ticket: no chip either
        Carbon::setTestNow(Carbon::parse('2026-10-23 05:00:00', 'UTC'));
        DB::table('support_tickets')->where('id', $x['ticket']->id)->update(['status' => 'assigned', 'resolved_at' => null]);
        DB::table('olt_onus')->update(['rx_collected_at' => '2026-10-23 04:00:00']);
        $this->assertNull(app(OutcomeLabeler::class)->preview($x['ticket']->fresh()));
    }

    public function test_an_episode_the_same_evening_after_the_onu_came_back_gets_no_rx_jump(): void
    {
        // LOS until 09:00 UTC (the repair), then a ticket at 12:00 UTC while the ONU is online: the RX history has no
        // earlier good day, but the ONU events say it was healthy when this ticket opened
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 00:00:00', 'UTC'), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 09:00:00', 'UTC'), 'online', 'los');
        $this->moveTicket($x['ticket'], '2026-10-22 12:00:00', '2026-10-22 15:00:00');

        $this->runLabeler(Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNull($this->label('rx_jump', $x['ticket']->id));
        // and the same ticket opened BEFORE the recovery keeps its label
        $this->moveTicket($x['ticket'], '2026-10-22 03:00:00', '2026-10-22 09:30:00');
        $this->runLabeler(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        $this->assertNotNull($this->label('rx_jump', $x['ticket']->id));
    }

    public function test_an_episode_that_opens_after_the_onu_came_back_gets_no_rx_jump_but_the_first_one_does(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 00:00:00', 'UTC'), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 09:00:00', 'UTC'), 'online', 'los'); // the repair
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 12:00:00', 'UTC'), 'dying_gasp', 'online'); // a later, separate drop
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 15:00:00', 'UTC'), 'online', 'dying_gasp');
        $episode = fn (string $key, string $open, string $close) => OutageSignal::create([
            'signal_type' => 'single_los', 'scope_key' => $key, 'olt_id' => $x['olt']->id, 'pon_port' => '1/2/10', 'customer_id' => $x['customer']->id,
            'state' => 'resolved', 'opened_at' => $open, 'resolved_at' => $close, 'affected_count' => 1,
        ]);
        $first = $episode('ep:first', '2026-10-22 01:00:00', '2026-10-22 09:00:00');
        $later = $episode('ep:later', '2026-10-22 12:00:00', '2026-10-22 15:00:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $rx = fn (OutageSignal $s) => OutageOutcomeLabel::query()->where('subject_type', 'signal')->where('subject_id', $s->id)->where('signature', 'rx_jump')->first();
        $this->assertNotNull($rx($first), 'the first drop of the outage owns the repair');
        $this->assertNull($rx($later), 'an episode that opens after the ONU was back online does not');
    }

    /** An episode of one customer on 22 Oct (UTC times). */
    private function episode(array $x, string $type, string $key, string $open, string $close): OutageSignal
    {
        return OutageSignal::create([
            'signal_type' => $type, 'scope_key' => $key, 'olt_id' => $x['olt']->id, 'pon_port' => '1/2/10', 'customer_id' => $x['customer']->id,
            'state' => 'resolved', 'opened_at' => $open, 'resolved_at' => $close, 'affected_count' => 1,
        ]);
    }

    private function episodeRx(OutageSignal $s): ?OutageOutcomeLabel
    {
        return OutageOutcomeLabel::query()->where('subject_type', 'signal')->where('subject_id', $s->id)->where('signature', 'rx_jump')->first();
    }

    public function test_a_short_flap_before_a_single_los_episode_does_not_drop_its_rx_jump(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-21 20:00:00', 'UTC'), 'los', 'online'); // 5 min flap
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-21 20:05:00', 'UTC'), 'online', 'los');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 01:00:00', 'UTC'), 'los', 'online'); // the real outage
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 09:00:00', 'UTC'), 'online', 'los');
        $ep = $this->episode($x, 'single_los', 'ep:flap', '2026-10-22 01:00:00', '2026-10-22 09:00:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNotNull($this->episodeRx($ep), 'a flap shorter than 30 min is not a repaired outage');
    }

    public function test_a_weak_rx_episode_whose_onu_goes_los_later_keeps_its_rx_jump(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 00:00:00', 'UTC'), 'los', 'online'); // an earlier long outage, repaired
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 02:00:00', 'UTC'), 'online', 'los');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 05:00:00', 'UTC'), 'los', 'online'); // the weak ONU dies inside the episode
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 09:00:00', 'UTC'), 'online', 'los');
        $ep = $this->episode($x, 'weak_rx', 'ep:weak', '2026-10-22 03:00:00', '2026-10-22 09:30:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNotNull($this->episodeRx($ep), 'the later LOS is not the episode own drop');
    }

    public function test_a_single_los_episode_only_loses_the_label_for_its_own_drop_within_10_minutes_of_the_open(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 00:00:00', 'UTC'), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 09:00:00', 'UTC'), 'online', 'los'); // repaired after 9 h
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 12:05:00', 'UTC'), 'dying_gasp', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 18:00:00', 'UTC'), 'online', 'dying_gasp');
        $own = $this->episode($x, 'dying_gasp', 'ep:own', '2026-10-22 12:00:00', '2026-10-22 15:00:00');   // drop 5 min after the open
        $far = $this->episode($x, 'single_los', 'ep:far', '2026-10-22 11:00:00', '2026-10-22 15:00:00');   // drop 65 min after the open

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNull($this->episodeRx($own), 'its own drop 5 min after the open, after a 9 h repaired outage: not its fix');
        $this->assertNotNull($this->episodeRx($far), 'the drop is not this episode own drop: keep the label');
    }

    // ------------------------------------------------------------------ L1: slices, newest first, no hourly rescan

    public function test_each_run_reads_only_the_two_close_slices_newest_first(): void
    {
        $x = $this->incident();

        $old = $this->runLabeler($this->close->copy()->addHours(10));
        $this->assertSame(0, $old['scanned'], 'closed 10 h ago: neither the 2 h slice nor the 26 h slice');

        $this->assertSame(1, $this->runLabeler($this->close->copy()->addHours(2)->addMinutes(30))['scanned']);
        $this->assertSame(1, $this->runLabeler($this->close->copy()->addHours(26)->addMinutes(30))['scanned']);
        $this->assertSame(0, $this->runLabeler($this->close->copy()->addHours(30))['scanned'], 'settled subjects are not read again');
        $this->assertNotNull($x['ticket']);

        // a catch-up run reads wider slices, but a subject with a final label is not read again
        $this->assertSame(0, app(OutcomeLabeler::class)->run(500, 48, $this->close->copy()->addHours(30))['scanned']);
        OutageOutcomeLabel::query()->delete();
        $this->assertSame(1, app(OutcomeLabeler::class)->run(500, 48, $this->close->copy()->addHours(30))['scanned']);
    }

    public function test_a_skipped_hourly_run_still_finalises_the_label(): void
    {
        $x = $this->incident();

        $this->runLabeler($this->close->copy()->addHours(3)); // provisional
        $this->assertFalse($this->label('rx_jump', $x['ticket']->id)->final);
        // the runs at +26 h and +27 h never happened; the first run after them is at +28 h
        $r = $this->runLabeler($this->close->copy()->addHours(28));

        $this->assertTrue($this->label('rx_jump', $x['ticket']->id)->final, 'a 3 h wide pass catches up one skipped run');
        $this->assertSame(1, $r['final']);
        // two skipped hours is the edge of the slice: run() with a wider slice (the --slice option) still catches up
        OutageOutcomeLabel::query()->delete();
        $this->assertSame(0, $this->runLabeler($this->close->copy()->addHours(32))['scanned'], 'beyond the slice: needs --slice');
        $this->assertSame(1, app(OutcomeLabeler::class)->run(500, 12, $this->close->copy()->addHours(32))['final']);
    }

    public function test_the_oldest_subjects_go_first_when_more_than_the_cap_closed_at_once(): void
    {
        $a = $this->incident('1/2/10:6');
        $b = $this->incident('1/2/10:7');
        DB::table('support_tickets')->where('id', $a['ticket']->id)->update(['resolved_at' => $this->fmt($this->close->copy()->addMinutes(30))]);

        $r = app(OutcomeLabeler::class)->run(1, 1, $this->close->copy()->addHours(3));

        $this->assertSame(1, $r['scanned']);
        $this->assertNotNull($this->label('rx_jump', $b['ticket']->id), 'the older close (+0 min) goes first');
        $this->assertNull($this->label('rx_jump', $a['ticket']->id), 'the newer close waits for the next hourly run');
    }

    public function test_a_burst_over_the_cap_is_worked_off_over_the_final_passes_and_the_oldest_is_not_starved(): void
    {
        $a = $this->incident('1/2/10:6');
        $b = $this->incident('1/2/10:7');
        $c = $this->incident('1/2/10:8');
        DB::table('support_tickets')->where('id', $b['ticket']->id)->update(['resolved_at' => $this->fmt($this->close->copy()->addMinutes(10))]);
        DB::table('support_tickets')->where('id', $c['ticket']->id)->update(['resolved_at' => $this->fmt($this->close->copy()->addMinutes(20))]);
        $labeler = app(OutcomeLabeler::class);

        $first = $labeler->run(2, 3, $this->close->copy()->addHours(26)->addMinutes(30));
        $this->assertSame(2, $first['scanned']);
        $this->assertTrue($this->label('rx_jump', $a['ticket']->id)->final);
        $this->assertTrue($this->label('rx_jump', $b['ticket']->id)->final);
        $this->assertNull($this->label('rx_jump', $c['ticket']->id));

        // the next hourly pass: A and B are settled and do not use up the cap, so C gets its turn
        $second = $labeler->run(2, 3, $this->close->copy()->addHours(27)->addMinutes(30));
        $this->assertSame(1, $second['scanned']);
        $this->assertTrue($this->label('rx_jump', $c['ticket']->id)->final);
    }

    // ------------------------------------------------------------------ L-a3: a repair older than the 24 h window

    public function test_an_episode_after_a_repair_of_an_outage_older_than_the_window_gets_no_rx_jump(): void
    {
        $x = $this->incident();
        // LOS since 20 Oct 18:00, repaired 22 Oct 10:00 (40 h). The window of the later episode starts 21 Oct 12:00,
        // so its first event is the repair "online" and the down event is outside the window.
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-20 18:00:00', 'UTC'), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 10:00:00', 'UTC'), 'online', 'los'); // the repair
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 12:02:00', 'UTC'), 'los', 'online'); // the later episode own drop
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 15:00:00', 'UTC'), 'online', 'los');
        $first = $this->episode($x, 'single_los', 'ep:long', '2026-10-20 18:00:00', '2026-10-22 10:00:00');
        $later = $this->episode($x, 'single_los', 'ep:later', '2026-10-22 12:00:00', '2026-10-22 15:00:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNotNull($this->episodeRx($first), 'the first episode of the outage owns the repair');
        $this->assertNull($this->episodeRx($later), 'the repair 2 h earlier belongs to the long outage, even if its down event is out of the window');
    }

    public function test_a_first_online_event_with_no_known_down_before_it_is_not_a_repair(): void
    {
        $x = $this->incident();
        // the ONU was first seen online 6 h before the episode (no earlier phase): that is no repaired outage
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 06:00:00', 'UTC'), 'online', null);
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 12:02:00', 'UTC'), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 15:00:00', 'UTC'), 'online', 'los');
        $ep = $this->episode($x, 'single_los', 'ep:seen', '2026-10-22 12:00:00', '2026-10-22 15:00:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        $this->assertNotNull($this->episodeRx($ep));
    }

    // ------------------------------------------------------------------ L-a4: online at open (known gap, pinned)

    public function test_a_weak_rx_episode_opened_online_after_a_short_flap_keeps_losing_its_rx_jump_known_gap(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 01:00:00', 'UTC'), 'los', 'online'); // 5 min flap
        $this->onuEvent($x['olt'], '1/2/10:6', Carbon::parse('2026-10-22 01:05:00', 'UTC'), 'online', 'los');
        $ep = $this->episode($x, 'weak_rx', 'ep:weakonline', '2026-10-22 03:00:00', '2026-10-22 09:30:00');

        app(OutcomeLabeler::class)->run(500, 48, Carbon::parse('2026-10-23 18:00:00', 'UTC'));

        // Pinned on purpose, not wanted forever: the label is lost (never wrong). outage:outcome-replay counts these as
        // healthy_dropped so the owner can see the size before diagnosis_calibrated_labels_enabled goes on.
        $this->assertNull($this->episodeRx($ep));
    }

    // ------------------------------------------------------------------ L-b4: no final label before a read after the close exists

    /** A ticket with a dying gasp and no RX read after the close: power_only if nothing else shows up. */
    private function powerOnlyWithoutRead(): array
    {
        $x = $this->incident('1/2/10:6', [['date' => '2026-10-21', 'dbm' => -20.0, 'n' => 20], ['date' => '2026-10-22', 'dbm' => -20.2, 'n' => 20]]);
        DB::table('olt_onus')->update(['rx_collected_at' => $this->fmt($this->open->copy()->subHour())]); // stale: older than the close
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->addHour(), 'dying_gasp', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->addHours(2), 'online', 'dying_gasp');

        return $x;
    }

    public function test_the_first_final_pass_without_any_read_after_the_close_stays_provisional_and_the_rx_jump_can_still_come(): void
    {
        $x = $this->powerOnlyWithoutRead();

        $this->runLabeler($this->close->copy()->addHours(26)->addMinutes(30)); // first final pass
        $power = $this->label('power_only', $x['ticket']->id);
        $this->assertNotNull($power);
        $this->assertFalse($power->final, 'no next daily point and no live read yet: not final');

        // the next daily point arrives with a clear improvement
        OutageOnuTracker::query()->update(['rx_daily' => [['date' => '2026-10-21', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-22', 'dbm' => -27.9, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -21.0, 'n' => 20]]]);
        $this->runLabeler($this->close->copy()->addHours(27)->addMinutes(30));

        $jump = $this->label('rx_jump', $x['ticket']->id);
        $this->assertNotNull($jump);
        $this->assertTrue($jump->final);
        $this->assertNull($this->label('power_only', $x['ticket']->id), 'the provisional power_only is replaced');
    }

    public function test_the_last_final_pass_finalises_the_label_even_without_a_read(): void
    {
        $x = $this->powerOnlyWithoutRead();

        $this->runLabeler($this->close->copy()->addHours(26)->addMinutes(30));
        $this->assertFalse($this->label('power_only', $x['ticket']->id)->final);
        $this->runLabeler($this->close->copy()->addHours(27)->addMinutes(30));
        $this->assertFalse($this->label('power_only', $x['ticket']->id)->final);
        $r = $this->runLabeler($this->close->copy()->addHours(28)->addMinutes(30)); // the third and last final pass

        $this->assertTrue($this->label('power_only', $x['ticket']->id)->final);
        $this->assertSame(1, $r['final']);
    }

    public function test_a_catch_up_slice_of_one_hour_finalises_at_the_first_pass(): void
    {
        $x = $this->powerOnlyWithoutRead();

        $this->assertSame(1, app(OutcomeLabeler::class)->run(500, 1, $this->close->copy()->addHours(26)->addMinutes(30))['final']);
        $this->assertTrue($this->label('power_only', $x['ticket']->id)->final, 'a 1 h slice has only one final pass');
    }

    public function test_a_wide_catch_up_slice_finalises_a_subject_that_still_has_no_read(): void
    {
        $x = $this->powerOnlyWithoutRead();

        $r = app(OutcomeLabeler::class)->run(500, 12, $this->close->copy()->addHours(32));

        $this->assertSame(1, $r['final']);
        $this->assertTrue($this->label('power_only', $x['ticket']->id)->final, '--slice=12 at +32 h must not leave it provisional forever');
    }

    public function test_a_missed_last_hourly_run_is_repaired_by_a_catch_up(): void
    {
        $x = $this->powerOnlyWithoutRead();

        $this->runLabeler($this->close->copy()->addHours(26)->addMinutes(30));
        $this->runLabeler($this->close->copy()->addHours(27)->addMinutes(30));
        // the +28 h run never happened: the hourly runs no longer select it, a catch-up does
        $this->assertSame(0, $this->runLabeler($this->close->copy()->addHours(31))['scanned']);
        $this->assertFalse($this->label('power_only', $x['ticket']->id)->final);

        app(OutcomeLabeler::class)->run(500, 48, $this->close->copy()->addHours(31));

        $this->assertTrue($this->label('power_only', $x['ticket']->id)->final);
    }

    // ------------------------------------------------------------------ replay (read-only)

    public function test_the_replay_command_counts_what_would_be_written_and_writes_nothing(): void
    {
        $x = $this->incident();

        $this->assertSame(0, Artisan::call('outage:outcome-replay', ['--from' => '2026-10-22', '--to' => '2026-10-22']));
        $out = Artisan::output();
        $this->assertStringContainsString('subjects: 1', $out);
        $this->assertMatchesRegularExpression('/rx_jump\s*\|\s*1\s*\|\s*0/', $out);
        $this->assertSame(0, OutageOutcomeLabel::query()->count(), 'the replay never writes');

        $this->runLabeler();
        $this->assertSame(1, OutageOutcomeLabel::query()->count());
        Artisan::call('outage:outcome-replay', ['--from' => '2026-10-22', '--to' => '2026-10-22']);
        $this->assertMatchesRegularExpression('/rx_jump\s*\|\s*1\s*\|\s*1/', Artisan::output());
        $this->assertSame(1, OutageOutcomeLabel::query()->count());
        $this->assertNotNull($x['ticket']);

        $this->assertSame(1, Artisan::call('outage:outcome-replay', ['--from' => 'not-a-date']));
    }

    public function test_the_replay_counts_the_rx_jumps_dropped_because_the_onu_was_online_at_the_open(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->subHours(5), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->subHour(), 'online', 'los'); // back online before the ticket opened

        Artisan::call('outage:outcome-replay', ['--from' => '2026-10-22', '--to' => '2026-10-22']);

        $this->assertStringContainsString('ONU already online at open: 1', Artisan::output());
        $this->assertSame(0, OutageOutcomeLabel::query()->count());
    }

    public function test_a_subject_with_a_final_label_is_not_labelled_again_by_the_later_final_passes(): void
    {
        $x = $this->incident();
        $this->runLabeler($this->close->copy()->addHours(26)->addMinutes(30));
        $this->assertTrue($this->label('rx_jump', $x['ticket']->id)->final);

        // the data changes: the RX no longer jumps and a dying gasp shows up
        OutageOnuTracker::query()->update(['rx_daily' => $this->steadyRx()]);
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->addHour(), 'dying_gasp', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->addHours(2), 'online', 'dying_gasp');
        $r = $this->runLabeler($this->close->copy()->addHours(28));

        $this->assertSame(0, $r['written']);
        $this->assertNull($this->label('power_only', $x['ticket']->id), 'one final answer per subject');
        $this->assertSame(1, OutageOutcomeLabel::query()->where('subject_id', $x['ticket']->id)->count());
    }

    public function test_a_burst_over_the_cap_cannot_starve_the_final_slice(): void
    {
        $a = $this->incident('1/2/10:6');
        $b = $this->incident('1/2/10:7');
        // B closed 24 h after A: with now = A + 27 h, A is in the final slice and B in the provisional one
        DB::table('support_tickets')->where('id', $b['ticket']->id)->update(['resolved_at' => $this->fmt($this->close->copy()->addHours(24))]);

        $r = app(OutcomeLabeler::class)->run(1, 3, $this->close->copy()->addHours(27));

        $this->assertSame(2, $r['scanned'], 'each slice has its own cap');
        $this->assertTrue($this->label('rx_jump', $a['ticket']->id)->final, 'the final slice is read first');
    }

    // ------------------------------------------------------------------ L5: retention

    public function test_the_prune_command_keeps_outcome_labels_400_days(): void
    {
        $row = ['subject_type' => 'ticket', 'customer_id' => 1, 'outcome' => 'drop_los', 'family' => 'fibre', 'signature' => 'rx_jump', 'independent' => 1];
        DB::table('outage_outcome_labels')->insert([$row + ['subject_id' => 1, 'labelled_at' => now()->subDays(401)->format('Y-m-d H:i:s')], $row + ['subject_id' => 2, 'labelled_at' => now()->subDays(10)->format('Y-m-d H:i:s')]]);

        $this->assertSame(0, Artisan::call('outage:events-prune'));

        $this->assertSame([2], OutageOutcomeLabel::query()->pluck('subject_id')->all());
    }

    // ------------------------------------------------------------------ rx_jump

    public function test_the_pon_1_2_10_repair_is_an_independent_rx_jump_drop_los(): void
    {
        $x = $this->incident();
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->subHours(5), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', $this->close->copy()->subMinutes(10), 'online', 'los');

        $r = $this->runLabeler();

        $this->assertSame(1, $r['scanned']);
        $label = $this->label('rx_jump', $x['ticket']->id);
        $this->assertNotNull($label);
        $this->assertSame('drop_los', $label->outcome);
        $this->assertSame('fibre', $label->family);
        $this->assertTrue($label->independent);
        $this->assertTrue($label->final);
        $this->assertSame($x['diagnosis']->id, $label->diagnosis_id);
        $this->assertSame('1/2/10:6', $label->onu_ref);
        $this->assertEquals(-28.0, $label->evidence['before']);
        $this->assertEquals(-21.0, $label->evidence['after'], 'both reads must show the improvement: the worse one counts');
        $this->assertEquals(7.0, $label->evidence['delta']);
        $this->assertLessThanOrEqual(OutcomeLabeler::EVIDENCE_MAX_BYTES, strlen((string) json_encode($label->evidence)));
    }

    public function test_the_gadang_repair_without_a_los_is_optical_degrade(): void
    {
        $x = $this->incident('2/1/3:9');

        $this->runLabeler();

        $label = $this->label('rx_jump', $x['ticket']->id);
        $this->assertNotNull($label);
        $this->assertSame('optical_degrade', $label->outcome);
        $this->assertSame('fibre', $label->family);
    }

    public function test_a_smaller_change_than_3_db_gets_no_label(): void
    {
        $x = $this->incident('1/2/10:6', [['date' => '2026-10-21', 'dbm' => -22.0, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -20.1, 'n' => 20]], -20.1);
        $this->onuEvent($x['olt'], '1/2/10:6', $this->open->copy()->subHour(), 'los', 'online');
        $this->onuEvent($x['olt'], '1/2/10:6', $this->close->copy()->subHour(), 'online', 'los');

        $this->runLabeler();

        $this->assertSame(0, OutageOutcomeLabel::query()->count(), 'LOS that came back with no RX change is an honest unknown');
    }

    public function test_the_label_is_provisional_at_3_hours_and_final_after_26_hours_in_the_same_row(): void
    {
        $x = $this->incident();

        $first = $this->runLabeler($this->close->copy()->addHours(3));
        $row = $this->label('rx_jump', $x['ticket']->id);
        $this->assertNotNull($row);
        $this->assertFalse($row->final, 'only the live read exists at +3 h');
        $this->assertSame(1, $first['written']);
        $this->assertEquals(-20.7, $row->evidence['after']);

        $second = $this->runLabeler($this->close->copy()->addHours(27));
        $this->assertSame(1, OutageOutcomeLabel::query()->count(), 'the same row is finalised');
        $this->assertTrue($this->label('rx_jump', $x['ticket']->id)->final);
        $this->assertEquals(-21.0, $this->label('rx_jump', $x['ticket']->id)->evidence['after']);
        $this->assertSame(1, $second['final']);

        // finalised labels are never rewritten
        $third = $this->runLabeler($this->close->copy()->addHours(28));
        $this->assertSame(0, $third['written']);
    }

    public function test_a_provisional_label_that_the_next_day_disagrees_with_is_removed(): void
    {
        $x = $this->incident('1/2/10:6', [['date' => '2026-10-20', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-21', 'dbm' => -27.9, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -27.6, 'n' => 20]]);

        $this->runLabeler($this->close->copy()->addHours(3));
        $this->assertNotNull($this->label('rx_jump', $x['ticket']->id), 'the live read looked fixed');

        $r = $this->runLabeler($this->close->copy()->addHours(27));

        $this->assertNull($this->label('rx_jump', $x['ticket']->id), 'the next daily point is back at -27.6');
        $this->assertSame(1, $r['removed']);
    }

    // ------------------------------------------------------------------ serial_swap

    private function serialChange(Customer $c): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'updated', 'subject_type' => Customer::class, 'subject_id' => $c->id, 'event' => 'updated',
            'properties' => json_encode(['old' => ['onu_serial' => 'ZTEGC0000001'], 'attributes' => ['onu_serial' => 'ZTEGC0000002']]),
            'created_at' => $this->fmt($this->close->copy()->subHour()), 'updated_at' => $this->fmt($this->close->copy()->subHour()),
        ]);
    }

    public function test_a_real_serial_change_is_cpe_dead_and_cancels_the_rx_jump(): void
    {
        $x = $this->incident();
        $this->serialChange($x['customer']);

        $this->runLabeler();

        $swap = $this->label('serial_swap', $x['ticket']->id);
        $this->assertNotNull($swap);
        $this->assertSame('cpe_dead', $swap->outcome);
        $this->assertTrue($swap->independent);
        $this->assertNull($this->label('rx_jump', $x['ticket']->id), 'a new ONU moves the RX: that is not a fibre repair');
    }

    public function test_a_serial_change_with_a_migration_row_is_no_label_at_all(): void
    {
        $x = $this->incident();
        $this->serialChange($x['customer']);
        DB::table('customer_migrations')->insert(['status' => 'synced', 'source' => 'wa', 'customer_id' => $x['customer']->id, 'created_at' => $this->fmt($this->close->copy()->subHours(2)), 'updated_at' => $this->fmt($this->close)]);
        $before = DB::table('customer_migrations')->count();

        $this->runLabeler();

        $this->assertSame(0, OutageOutcomeLabel::query()->count(), 'migrations (113 in 30 days) are not repairs');
        $this->assertSame($before, DB::table('customer_migrations')->count(), 'the labeler only reads the migration table');
    }

    public function test_a_psb_draft_in_the_window_also_cancels_the_swap(): void
    {
        $x = $this->incident();
        $this->serialChange($x['customer']);
        DB::table('psb_drafts')->insert(['status' => 'applied', 'customer_id' => $x['customer']->id, 'created_at' => $this->fmt($this->close->copy()->subHours(3)), 'updated_at' => $this->fmt($this->close)]);

        $this->runLabeler();

        $this->assertSame(0, OutageOutcomeLabel::query()->count());
    }

    // ------------------------------------------------------------------ unisolate

    public function test_reconnect_with_a_session_up_after_it_is_isolir_admin(): void
    {
        $x = $this->incident('1/2/10:6', [['date' => '2026-10-21', 'dbm' => -20.0, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -20.1, 'n' => 20]], -20.1);
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'customer_reconnected', 'subject_type' => Customer::class, 'subject_id' => $x['customer']->id,
            'properties' => json_encode(['mode' => 'realtime']), 'created_at' => $this->fmt($this->close->copy()->subHours(2)), 'updated_at' => $this->fmt($this->close->copy()->subHours(2)),
        ]);
        $this->runLabeler();
        $this->assertSame(0, OutageOutcomeLabel::query()->count(), 'no session after the reconnect yet');

        DB::table('pppoe_session_events')->insert(['router_id' => 1, 'pppoe_user' => 'fx', 'customer_id' => $x['customer']->id, 'event' => 'connected', 'reason' => null, 'at' => $this->fmt($this->close->copy()->subHour()), 'source' => 'syslog']);
        $this->runLabeler();

        $label = $this->label('unisolate', $x['ticket']->id);
        $this->assertNotNull($label);
        $this->assertSame('isolir_admin', $label->outcome);
        $this->assertSame('isolir_admin', $label->family);
        $this->assertTrue($label->independent);
    }

    // ------------------------------------------------------------------ co_recovery / power_only

    /** Four ONUs of PON 1/1/1 drop together and come back in the same detector run (an hour later). */
    private function groupEvent(Olt $olt, string $kind): void
    {
        $down = $this->open->copy()->subHours(2);
        $up = $this->open->copy()->subHour();
        foreach ([1, 2, 3, 4] as $k) {
            $ref = '1/1/1:'.$k;
            OutageOnuTracker::firstOrCreate(['olt_id' => $olt->id, 'onu_ref' => $ref], ['phase' => 'online']);
            $this->onuEvent($olt, $ref, $down, $kind, 'online');
            $this->onuEvent($olt, $ref, $up->copy()->addMinutes($k % 2), 'online', $kind);
        }
    }

    /** @return list<array{date:string,dbm:float,n:int}> */
    private function steadyRx(): array
    {
        return [['date' => '2026-10-21', 'dbm' => -20.0, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -20.2, 'n' => 20]];
    }

    public function test_four_onus_back_from_los_together_after_an_hour_are_a_co_recovery_pon_cut(): void
    {
        $x = $this->incident('1/1/1:1', $this->steadyRx(), -20.2);
        $this->groupEvent($x['olt'], 'los');

        $this->runLabeler();

        $label = $this->label('co_recovery', $x['ticket']->id);
        $this->assertNotNull($label);
        $this->assertSame('pon_cut', $label->outcome);
        $this->assertTrue($label->independent);
        $this->assertSame(4, $label->evidence['onus']);
        $this->assertNull($this->label('power_only', $x['ticket']->id), 'a LOS group is not a power event');
    }

    public function test_the_krian_same_run_dying_gasp_group_is_circular_power_only_and_never_co_recovery(): void
    {
        $x = $this->incident('1/1/1:1', $this->steadyRx(), -20.2);
        $this->groupEvent($x['olt'], 'dying_gasp');

        $this->runLabeler();

        $this->assertNull($this->label('co_recovery', $x['ticket']->id), 'co_recovery needs a LOS pre-state');
        $power = $this->label('power_only', $x['ticket']->id);
        $this->assertNotNull($power);
        $this->assertSame('power_off', $power->outcome);
        $this->assertFalse($power->independent, 'dying gasp -> power_off confirms itself');
    }

    public function test_a_mass_los_episode_gets_a_co_recovery_label_of_its_own(): void
    {
        $x = $this->incident('1/1/1:1', $this->steadyRx(), -20.2);
        $this->groupEvent($x['olt'], 'los');
        $signal = OutageSignal::create([
            'signal_type' => 'mass_los', 'scope_key' => 'olt:'.$x['olt']->id.':pon:1/1/1', 'olt_id' => $x['olt']->id, 'pon_port' => '1/1/1', 'state' => 'resolved',
            'opened_at' => $this->open->copy()->subHours(2), 'resolved_at' => $this->open->copy()->subHour(), 'affected_count' => 4, 'affected_customer_ids' => [$x['customer']->id],
        ]);
        DB::table('outage_signals')->where('id', $signal->id)->update(['resolved_at' => $this->fmt($this->close)]);

        $this->runLabeler();

        $label = OutageOutcomeLabel::query()->where('subject_type', 'signal')->where('subject_id', $signal->id)->where('signature', 'co_recovery')->first();
        $this->assertNotNull($label);
        $this->assertSame('pon_cut', $label->outcome);
    }

    // ------------------------------------------------------------------ preview (the close form chip)

    public function test_preview_names_the_repair_for_an_open_ticket_and_is_empty_without_one(): void
    {
        $x = $this->incident();
        Carbon::setTestNow($this->close->copy()->addHour());
        DB::table('support_tickets')->where('id', $x['ticket']->id)->update(['status' => 'assigned', 'resolved_at' => null]);
        $ticket = $x['ticket']->fresh();

        $p = app(OutcomeLabeler::class)->preview($ticket);

        $this->assertNotNull($p);
        $this->assertSame('rx_jump', $p['signature']);
        $this->assertSame('optical_degrade', $p['code'], 'no LOS in the window');
        $this->assertSame('Redaman / konektor', $p['label']);
        $this->assertSame('redaman membaik -28,0 → -20,7 setelah perbaikan', $p['text']);

        $this->serialChange($x['customer']);
        $swap = app(OutcomeLabeler::class)->preview($ticket);
        $this->assertSame('cpe_dead', $swap['code']);
        $this->assertSame('Modem rusak', $swap['label']);

        $none = $this->incident('1/2/10:7', [['date' => '2026-10-21', 'dbm' => -20.0, 'n' => 20]], -20.0);
        $this->assertNull(app(OutcomeLabeler::class)->preview($none['ticket']));
    }

    // ------------------------------------------------------------------ command

    public function test_the_command_runs_bounded_and_the_setting_turns_it_off(): void
    {
        $x = $this->incident();

        $this->assertSame(0, Artisan::call('outage:outcome-label', ['--max' => 5]));
        $this->assertStringContainsString('outcome labels: scanned 1, written 1', Artisan::output());
        $this->assertNotNull($this->label('rx_jump', $x['ticket']->id));

        OutageOutcomeLabel::query()->delete();
        Setting::set('diagnosis_outcome_labels_enabled', '0');
        Setting::flushRequestMemo();
        $this->assertSame(0, Artisan::call('outage:outcome-label'));
        $this->assertStringContainsString('outcome labels are off', Artisan::output());
        $this->assertSame(0, OutageOutcomeLabel::query()->count());
    }

    public function test_the_unique_key_is_subject_and_signature(): void
    {
        $x = $this->incident();
        $this->runLabeler();
        $this->runLabeler($this->close->copy()->addHours(28));

        $this->assertSame(1, OutageOutcomeLabel::query()->where('subject_id', $x['ticket']->id)->count());
        $this->expectException(QueryException::class);
        DB::table('outage_outcome_labels')->insert(['subject_type' => 'ticket', 'subject_id' => $x['ticket']->id, 'outcome' => 'drop_los', 'family' => 'fibre', 'signature' => 'rx_jump', 'labelled_at' => now()]);
    }
}
