<?php

namespace Tests\Feature;

use App\Models\OutageDiagnosis;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Models\WaReportDelivery;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Report\RootCauseWeeklyWa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Plan ticket-root-cause-required section 3.6: the weekly fill report (PrecisionReport "fill" block + text) and its
 * WA to the Skynet Report group (flag root_cause_weekly_wa_enabled, once per week, nothing queued, never a PSB group).
 */
#[Group('blocking-core')]
class RootCauseWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_GROUP = '120363-skynetreport@g.us';

    private const PSB_GROUP = '120363-activation@g.us';

    private bool $evolutionOk = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:15:00', 'Asia/Jakarta')); // Monday
        $this->admin = User::factory()->create(['role' => 'admin']);

        // Only the Evolution HTTP edge is faked; the real service, registry and ledger run.
        config(['services.evolution_api.base_url' => 'http://evo.test', 'services.evolution_api.api_key' => 'test-evo-key']);
        Setting::set('wa_instance_ticket', 'inst-ticket');
        Setting::set('wa_instance_ops', 'inst-ops');
        Setting::set('report_wa_channel', 'ticket');
        Http::fake(fn () => $this->evolutionOk
            ? Http::response(['key' => ['id' => 'WAMID-1']], 201)
            : Http::response(['error' => 'down'], 500));
    }

    /**
     * Message sends seen on the wire.
     *
     * @return list<array{0: string, 1: string, 2: string}> group jid, text, url
     */
    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/message/sendText/')) {
                $out[] = [(string) $request['number'], (string) $request['text'], $request->url()];
            }
        }

        return $out;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function week(): Carbon
    {
        return Carbon::parse('2026-10-12', 'Asia/Jakarta');
    }

    /** @param  array<string, mixed>  $o */
    private function closed(?string $cause, ?string $source, array $o = [], string $at = '2026-10-14 10:00:00'): SupportTicket
    {
        $t = SupportTicket::create($o + [
            'created_by' => $this->admin->id, 'gangguan' => 'Mati', 'status' => 'closed', 'type' => 'incident', 'priority' => 'normal',
            'root_cause' => $cause, 'root_cause_source' => $source,
        ]);
        DB::table('support_tickets')->where('id', $t->id)->update(['resolved_at' => Carbon::parse($at, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s')]);

        return $t;
    }

    private function prediction(SupportTicket $t, string $cause): void
    {
        OutageDiagnosis::create(['customer_id' => 1, 'ticket_id' => $t->id, 'trigger' => 'ticket_open', 'state' => 'done', 'cause_code' => $cause, 'confidence' => 'sedang', 'rules_version' => Rules::RULES_VERSION]);
    }

    /** The week used by the snapshot: 14 closes, 12 by people, 2 by the system. */
    private function seedWeek(): void
    {
        $a = $this->closed('drop_los', 'technician');
        $b = $this->closed('drop_los', 'technician');
        $this->closed('drop_los', 'technician');
        $this->closed('drop_los', 'admin');
        $c = $this->closed('optical_degrade', 'technician');
        $this->closed('optical_degrade', 'technician');
        $this->closed('power_off', 'technician');
        $this->closed('wifi_client', 'admin');
        $this->closed('unknown', 'technician');
        $this->closed('other', 'technician', ['root_cause_note' => 'Kabel LAN dalam rumah putus']);
        $this->closed('not_fault', 'technician');
        $this->closed('not_fault', 'admin');
        $this->closed('auto_closed', 'system');
        $this->closed('not_fault', 'system');
        $this->closed(null, null); // an old close nobody filled in
        // outside the week, other type, still open: never counted
        $this->closed('drop_los', 'technician', [], '2026-10-20 10:00:00');
        $this->closed('drop_los', 'technician', [], '2026-10-07 10:00:00');
        $this->closed(null, null, ['type' => 'installation']);
        SupportTicket::create(['created_by' => $this->admin->id, 'gangguan' => 'Masih buka', 'status' => 'assigned', 'type' => 'incident', 'priority' => 'normal']);

        $this->prediction($a, 'drop_los');   // right
        $this->prediction($b, 'cpe_dead');   // wrong
        $this->prediction($c, 'unknown');    // abstained
    }

    public function test_fill_block_counts_people_and_the_system_apart_and_the_text_is_plain_indonesian(): void
    {
        $this->seedWeek();

        $report = app(PrecisionReport::class)->compute($this->week());
        $fill = $report['fill'];

        $this->assertSame(15, $fill['closes']);
        $this->assertSame(['technician' => 9, 'admin' => 3, 'api' => 0, 'system' => 2, 'none' => 1], $fill['by_source']);
        $this->assertSame(13, $fill['human']);
        $this->assertSame(8, $fill['real']);
        $this->assertSame(2, $fill['not_fault']);
        $this->assertSame(0, $fill['self_recovered']);
        $this->assertSame(1, $fill['other']);
        $this->assertSame(1, $fill['unknown']);
        $this->assertSame(2, $fill['system']);
        $this->assertSame(1, $fill['empty']);
        $this->assertSame(11, $fill['filled']);
        $this->assertSame(0.8462, $fill['fill_rate']);
        $this->assertSame([['cause' => 'drop_los', 'label' => 'Kabel drop putus', 'count' => 4], ['cause' => 'optical_degrade', 'label' => 'Redaman / konektor', 'count' => 2], ['cause' => 'power_off', 'label' => 'Listrik / adaptor', 'count' => 1]], $fill['top']);

        $text = app(PrecisionReport::class)->rootCauseText($report);
        $this->assertSame(implode("\n", [
            'Penyebab tiket gangguan 12–18 Okt',
            'Ditutup: 15 (teknisi 9, admin 3, otomatis 2, tanpa sumber 1)',
            'Penyebab jelas: 11 dari 13 (85%). Target 90%.',
            'Tidak tahu 1 · Lainnya 1 · Bukan gangguan 2 · Pulih sendiri 0',
            'Belum diisi: 1',
            'Terbanyak: Kabel drop putus 4, Redaman / konektor 2, Listrik / adaptor 1',
            'Diagnosa sistem (aturan terbaru): cocok 1 dari 2 yang ditebak (50%, kisaran 9–91%)',
            'Kabel drop, redaman dan ODP dihitung satu jenis: 1 dari 2 (50%)',
            'Ditebak 2 dari 3 tiket (67%)',
        ]), $text);
        // counts only: no ticket codes, customer or technician names
        $this->assertStringNotContainsString('TCK-', (string) $text);
        $this->assertStringNotContainsString($this->admin->name, (string) $text);
        $this->assertStringNotContainsString('Kabel LAN', (string) $text);
    }

    public function test_the_new_codes_do_not_count_in_the_class_precision(): void
    {
        $a = $this->closed('drop_los', 'technician');
        $this->prediction($a, 'drop_los');
        foreach (['other', 'not_fault', 'self_recovered', 'auto_closed'] as $code) {
            $this->prediction($this->closed($code, $code === 'auto_closed' ? 'system' : 'technician'), 'drop_los');
        }

        $report = app(PrecisionReport::class)->compute($this->week());

        $this->assertSame(5, $report['tickets']);
        $this->assertSame(1, $report['evaluated']);
        $this->assertSame(1, $report['correct']);
        $this->assertSame(1, $report['closed_auto'], 'auto_closed is counted like a close without a cause');
        $this->assertSame(3, $report['truth_not_scored']);
        $this->assertSame(['drop_los'], array_column($report['classes'], 'cause'));
        $this->assertSame([], $report['confusions']);
    }

    public function test_a_week_without_closes_and_a_week_where_everything_is_filled(): void
    {
        $empty = app(PrecisionReport::class)->compute($this->week());
        $this->assertSame(0, $empty['fill']['closes']);
        $this->assertNull($empty['fill']['fill_rate']);
        $this->assertSame("Penyebab tiket gangguan 12–18 Okt\nTidak ada tiket gangguan yang ditutup.", app(PrecisionReport::class)->rootCauseText($empty));

        $this->closed('drop_los', 'technician');
        $this->closed('not_fault', 'admin');
        $full = app(PrecisionReport::class)->compute($this->week());
        $this->assertSame(0, $full['fill']['empty']);
        $this->assertSame(1.0, $full['fill']['fill_rate']);
        $text = (string) app(PrecisionReport::class)->rootCauseText($full);
        $this->assertStringContainsString('Penyebab jelas: 2 dari 2 (100%)', $text);
        $this->assertStringNotContainsString('Belum diisi', $text);
        $this->assertStringContainsString('Diagnosa sistem (aturan terbaru): belum ada yang bisa dinilai', $text);
    }

    public function test_a_week_that_spans_two_months_and_a_stored_report_without_the_fill_block(): void
    {
        $text = app(PrecisionReport::class)->rootCauseText(
            app(PrecisionReport::class)->compute(Carbon::parse('2026-09-28', 'Asia/Jakarta'))
        );
        $this->assertStringStartsWith('Penyebab tiket gangguan 28 Sep–4 Okt', (string) $text);

        $this->assertNull(app(PrecisionReport::class)->rootCauseText(['week_start' => '2026-10-12', 'week_end' => '2026-10-18']));
    }

    public function test_the_suggestion_numbers_split_by_how_the_cause_was_chosen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
        $used = $this->openTicket();
        $agreed = $this->openTicket();
        $differs = $this->openTicket();
        $none = $this->openTicket();
        foreach ([$used, $agreed, $differs] as $t) {
            $this->prediction($t, 'drop_los');
        }
        $this->actingAs($this->admin)->put(route('tickets.update', $used), ['status' => 'resolved', 'root_cause' => 'drop_los', 'root_cause_via' => 'suggestion'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('tickets.update', $agreed), ['status' => 'resolved', 'root_cause' => 'drop_los'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('tickets.update', $differs), ['status' => 'resolved', 'root_cause' => 'cpe_dead'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('tickets.update', $none), ['status' => 'resolved', 'root_cause' => 'cpe_dead'])->assertSessionHasNoErrors();

        $suggestion = app(PrecisionReport::class)->compute($this->week())['fill']['suggestion'];

        $this->assertSame(['shown' => 3, 'agreed' => 2, 'used' => 1, 'agreed_manual' => 1], $suggestion);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $used->id)->where('action', 'root_cause_set')->count());
    }

    private function openTicket(): SupportTicket
    {
        return SupportTicket::create(['created_by' => $this->admin->id, 'gangguan' => 'Mati', 'status' => 'assigned', 'type' => 'incident', 'priority' => 'normal']);
    }

    // ------------------------------------------------------------------ WA

    private function payload(): array
    {
        $this->closed('drop_los', 'technician');
        $this->closed('unknown', 'admin');

        return app(PrecisionReport::class)->compute($this->week());
    }

    public function test_flag_off_sends_nothing(): void
    {
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);

        $this->assertSame(['disabled'], app(RootCauseWeeklyWa::class)->send($this->payload()));
        $this->assertSame([], $this->sent());
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_sends_once_per_week_to_the_report_group_only_and_never_to_a_psb_group(): void
    {
        Queue::fake();
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP, self::PSB_GROUP]);
        Setting::set('psb_wa_group_ids', [self::PSB_GROUP]);
        Setting::set('report_wa_channel', 'ticket');
        $payload = $this->payload();

        $this->assertSame(['sent'], app(RootCauseWeeklyWa::class)->send($payload));
        $this->assertSame(['already_sent'], app(RootCauseWeeklyWa::class)->send($payload), 'second run in the same week');

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(self::REPORT_GROUP, $sent[0][0]);
        $this->assertStringStartsWith('Penyebab tiket gangguan 12–18 Okt', $sent[0][1]);
        // on the wire: the ticket instance only, never the ops instance (which sends PSB WA) and never a PSB group
        $this->assertStringEndsWith('/message/sendText/inst-ticket', $sent[0][2]);
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'inst-ops') || str_contains((string) json_encode($r->data()), self::PSB_GROUP));
        $row = WaReportDelivery::query()->where('report_key', 'root_cause_weekly')->firstOrFail();
        $this->assertSame('2026-10-12', $row->period_date->format('Y-m-d'));
        $this->assertSame('sent', $row->status);
        $this->assertSame(sha1($sent[0][1]), $row->message_hash);
        $this->assertSame(0, WaReportDelivery::query()->where('group_jid', self::PSB_GROUP)->count());
        // synchronous: nothing on the wa, psb or migration queues
        Queue::assertNothingPushed();
    }

    public function test_a_failed_send_is_marked_failed_and_the_next_week_is_a_new_row(): void
    {
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);
        $this->evolutionOk = false;
        $payload = $this->payload();

        $this->assertSame(['failed'], app(RootCauseWeeklyWa::class)->send($payload));
        $this->assertSame('failed', WaReportDelivery::query()->firstOrFail()->status);

        $this->evolutionOk = true;
        $this->assertSame(['sent'], app(RootCauseWeeklyWa::class)->send($payload), 'a failed row may be retried');

        $next = $payload;
        $next['week_start'] = '2026-10-19';
        $next['week_end'] = '2026-10-25';
        $this->assertSame(['sent'], app(RootCauseWeeklyWa::class)->send($next));
        $this->assertSame(2, WaReportDelivery::query()->where('status', 'sent')->count());
    }

    public function test_no_report_group_means_no_send(): void
    {
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        $this->assertSame(['no_group'], app(RootCauseWeeklyWa::class)->send($this->payload()));

        Setting::set('report_wa_group_ids', [self::PSB_GROUP]);
        Setting::set('psb_wa_group_ids', [self::PSB_GROUP]);
        $this->assertSame(['no_group'], app(RootCauseWeeklyWa::class)->send($this->payload()), 'a group that is also a PSB group is skipped');
        $this->assertSame([], $this->sent());
    }

    public function test_only_the_ticket_channel_is_used_the_ops_channel_sends_nothing(): void
    {
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);
        Setting::set('report_wa_channel', 'ops');

        $this->assertSame(['ops_channel_skipped'], app(RootCauseWeeklyWa::class)->send($this->payload()));
        $this->assertSame([], $this->sent());
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_the_command_dry_run_prints_the_text_and_sends_and_stores_nothing(): void
    {
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);
        $this->closed('drop_los', 'technician');

        Artisan::call('outage:diagnosis-precision', ['--dry-run' => true, '--week' => '2026-10-12']);
        $output = Artisan::output();

        $this->assertStringContainsString('Penyebab tiket gangguan 12–18 Okt', $output);
        $this->assertStringContainsString('Penyebab jelas: 1 dari 1 (100%)', $output);
        $this->assertSame([], $this->sent());
        $this->assertSame(0, DB::table('outage_diagnosis_reports')->count());
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_the_command_sends_the_weekly_text_after_storing_when_the_flag_is_on(): void
    {
        Setting::set(RootCauseWeeklyWa::FLAG, '1');
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);
        $this->closed('drop_los', 'technician');

        Artisan::call('outage:diagnosis-precision', ['--week' => '2026-10-12']);
        Artisan::call('outage:diagnosis-precision', ['--week' => '2026-10-12']);

        $this->assertCount(1, $this->sent());
        $this->assertSame(1, DB::table('outage_diagnosis_reports')->count());
        $this->assertStringContainsString('Penyebab jelas: 1 dari 1 (100%)', $this->sent()[0][1]);
    }

    public function test_the_command_sends_nothing_while_the_flag_is_off(): void
    {
        Setting::set('report_wa_group_ids', [self::REPORT_GROUP]);
        $this->closed('drop_los', 'technician');

        Artisan::call('outage:diagnosis-precision', ['--week' => '2026-10-12']);

        $this->assertSame([], $this->sent());
        $this->assertSame(0, WaReportDelivery::query()->count());
    }
}
