<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ProcessReportPppoeCariJob;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Webhook routing for "#pppoe cari" in the Skynet Report group (R) next to the Aktivasi/PSB group (P).
 */
#[Group('blocking-psb')]
class ReportGroupPppoeCariRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const P = '120363-activation@g.us';

    private const R = '120363-skynetreport@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', [self::P]);
        Setting::set('psb_recap_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_recap_cmd_enabled', '1');
        Setting::set('report_pppoe_cmd_enabled', '1');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function item(string $id, array $message, string $group = self::R, bool $fromMe = false): array
    {
        return [
            'key' => ['remoteJid' => $group, 'participant' => '6281111111111@s.whatsapp.net', 'id' => $id, 'fromMe' => $fromMe],
            'pushName' => 'Teknisi',
            'message' => $message,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function postBatch(array $items)
    {
        return $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => ['messages' => $items],
        ], ['apikey' => 'known-webhook-secret']);
    }

    private function assertNoBotJobs(): void
    {
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        Queue::assertNotPushed(ProcessReportPppoeCariJob::class);
    }

    public function test_report_group_pppoe_cari_goes_to_the_report_job_on_the_wa_queue_only(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RP-1', ['conversation' => '#pppoe cari djoko comboran'])])->assertOk();

        Queue::assertPushedOn('wa', ProcessReportPppoeCariJob::class, function (ProcessReportPppoeCariJob $job): bool {
            return $job->payload === [
                'group_jid' => self::R,
                'sender_jid' => '6281111111111@s.whatsapp.net',
                'sender_name' => 'Teknisi',
                'message_id' => 'RP-1',
                'text' => '#pppoe cari djoko comboran',
            ];
        });
        Queue::assertPushed(ProcessReportPppoeCariJob::class, 1);
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_case_and_spacing_variants_of_the_exact_command_match(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RP-2a', ['conversation' => '  #PPPoE   Cari  0812345  ']),
            $this->item('RP-2b', ['extendedTextMessage' => ['text' => '# pppoe cari budi']]),
        ])->assertOk();

        Queue::assertPushed(ProcessReportPppoeCariJob::class, 2);
    }

    public function test_every_other_psb_or_migration_trigger_stays_silent_in_the_report_group(): void
    {
        Queue::fake();

        $commands = [
            '#pppoe', '#pppoe cek', '#pppoe carian budi', '/pppoe cari budi', '/pppoe', '#psb bantu', '#psb tes',
            '/psb hari ini', '#psb ktp #12', '#migrasi cek #5', '/migrasi', '#help', 'done',
            'tolong #pppoe cari budi', '#pppoe cari budi #psb', '#pppoe cari budi #migrasi cek #5',
            "#pppoe cari budi\nterima kasih", "#pppoe cari\nbudi",
        ];
        $items = [];
        foreach ($commands as $i => $text) {
            $items[] = $this->item('RP-3-'.$i, ['conversation' => $text]);
        }

        $this->postBatch($items)->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_image_with_pppoe_cari_caption_does_nothing_and_downloads_nothing(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 500)]);
        Setting::set('attendance_group_id', '120363-attendance@g.us');

        $this->postBatch([$this->item('RP-4', ['imageMessage' => ['caption' => '#pppoe cari budi', 'mimetype' => 'image/jpeg']])])->assertOk();

        $this->assertNoBotJobs();
        Http::assertNothingSent();
    }

    public function test_flag_off_default_or_empty_report_list_means_no_job(): void
    {
        Queue::fake();

        Setting::set('report_pppoe_cmd_enabled', '0');
        $this->postBatch([$this->item('RP-5-1', ['conversation' => '#pppoe cari budi'])])->assertOk();

        Setting::set('report_pppoe_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', []);
        $this->postBatch([$this->item('RP-5-2', ['conversation' => '#pppoe cari budi'])])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_flag_is_independent_of_the_recap_flags(): void
    {
        Queue::fake();
        Setting::set('report_recap_cmd_enabled', '0');
        Setting::set('psb_recap_cmd_enabled', '0');

        $this->postBatch([
            $this->item('RP-6-1', ['conversation' => '#pppoe cari budi']),
            $this->item('RP-6-2', ['conversation' => '#rekap psb semua']),
        ])->assertOk();

        Queue::assertPushed(ProcessReportPppoeCariJob::class, 1);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_from_me_and_unlisted_groups_get_no_job(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RP-7-1', ['conversation' => '#pppoe cari budi'], self::R, true),
            $this->item('RP-7-2', ['conversation' => '#pppoe cari budi'], '120363-unlisted@g.us'),
        ])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_aktivasi_group_pppoe_cari_keeps_the_existing_psb_path(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RP-8', ['conversation' => '#pppoe cari budi'], self::P)])->assertOk();

        Queue::assertPushedOn('psb', ProcessPsbWaJob::class, function (ProcessPsbWaJob $job): bool {
            return ($job->payload['text'] ?? null) === '#pppoe cari budi' && ($job->payload['group_jid'] ?? null) === self::P;
        });
        Queue::assertNotPushed(ProcessReportPppoeCariJob::class);
    }

    public function test_jid_in_both_lists_keeps_the_psb_path(): void
    {
        Setting::set('report_wa_group_ids', [self::P]);
        Queue::fake();

        $this->postBatch([$this->item('RP-9', ['conversation' => '#pppoe cari budi'], self::P)])->assertOk();

        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessReportPppoeCariJob::class);
    }

    public function test_report_recap_command_still_routes_to_the_recap_job(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RP-10', ['conversation' => '#rekap psb semua kemarin'])])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class);
        Queue::assertNotPushed(ProcessReportPppoeCariJob::class);
    }

    public function test_job_contract_wa_queue_unique_per_message_id(): void
    {
        $a = new ProcessReportPppoeCariJob(['group_jid' => self::R, 'message_id' => 'RP-11', 'text' => '#pppoe cari x']);
        $b = new ProcessReportPppoeCariJob(['group_jid' => self::R, 'message_id' => 'RP-11', 'text' => '#pppoe cari y']);

        $this->assertSame('wa', $a->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, $a);
        $this->assertSame('report-pppoe-cari-msg:RP-11', $a->uniqueId());
        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }
}
