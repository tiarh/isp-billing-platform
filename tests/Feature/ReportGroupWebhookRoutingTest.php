<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Models\Setting;
use App\Services\Report\ReportGroupRegistry;
use App\Services\TechnicianProgressService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Routing proof for the Skynet Report group (R) next to the PSB group (P): R only ever
 * reaches the read-only "#rekap psb" job on `wa`; #psb / #migrasi never run there.
 */
#[Group('blocking-psb')]
class ReportGroupWebhookRoutingTest extends TestCase
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
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function item(string $id, array $message, string $group = self::R, bool $fromMe = false): array
    {
        return [
            'key' => [
                'remoteJid' => $group,
                'participant' => '6281111111111@s.whatsapp.net',
                'id' => $id,
                'fromMe' => $fromMe,
            ],
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
    }

    public function test_1_report_group_recap_goes_to_wa_queue_with_report_context_only(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RG-1', ['conversation' => '#rekap psb semua kemarin'])])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, function (ProcessPsbRecapCommandJob $job): bool {
            return ($job->payload['context'] ?? null) === 'report'
                && $job->payload['group_jid'] === self::R
                && $job->payload['message_id'] === 'RG-1';
        });
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
    }

    public function test_2_every_psb_command_in_the_report_group_is_ignored_and_logged(): void
    {
        Queue::fake();
        Log::spy();

        $commands = [
            '#psb bantu', '#psb tes', '/psb bantu', '#psb ktp #12', '/pppoe cek', '#pppoe cek',
            '#help', '#status', '#lanjut', '#batal', 'done', 'selesai', '#psb rekap', '/psb hari ini',
        ];
        $items = [];
        foreach ($commands as $i => $text) {
            $items[] = $this->item('RG-2-'.$i, ['conversation' => $text]);
        }

        $this->postBatch($items)->assertOk();

        $this->assertNoBotJobs();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message): bool => is_string($message) && str_contains($message, '#psb ignored'))
            ->times(count($commands));
    }

    public function test_3_migration_commands_in_the_report_group_do_nothing(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RG-3-1', ['conversation' => '#migrasi cek #5']),
            $this->item('RG-3-2', ['conversation' => '/migrasi']),
            $this->item('RG-3-3', ['conversation' => "#migrasi\nnama: x"]),
        ])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_4_image_with_psb_or_migration_caption_does_nothing_and_downloads_nothing(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 500)]);
        // Keep the unrelated attendance flow (which downloads images of any group when unset) out of this proof.
        Setting::set('attendance_group_id', '120363-attendance@g.us');

        $this->postBatch([
            $this->item('RG-4-1', ['imageMessage' => ['caption' => '#psb ktp #1', 'mimetype' => 'image/jpeg']]),
            $this->item('RG-4-2', ['imageMessage' => ['caption' => '#migrasi', 'mimetype' => 'image/jpeg']]),
        ])->assertOk();

        $this->assertNoBotJobs();
        Http::assertNothingSent();
    }

    public function test_5_recap_text_carrying_a_psb_or_migration_tag_does_nothing(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RG-5-1', ['conversation' => '#rekap psb x #psb y']),
            $this->item('RG-5-2', ['conversation' => '#rekap psb #migrasi cek #5']),
        ])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_6_recap_with_an_image_does_nothing(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RG-6', ['imageMessage' => ['caption' => '#rekap psb semua', 'mimetype' => 'image/jpeg']])])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_6b_multi_line_message_is_not_a_recap_command(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RG-6b', ['conversation' => "#rekap psb semua\nterima kasih"])])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_7_any_flag_off_or_empty_list_means_no_job(): void
    {
        Queue::fake();

        Setting::set('report_recap_cmd_enabled', '0');
        $this->postBatch([$this->item('RG-7-1', ['conversation' => '#rekap psb semua'])])->assertOk();

        Setting::set('report_recap_cmd_enabled', '1');
        Setting::set('psb_recap_cmd_enabled', '0');
        $this->postBatch([$this->item('RG-7-2', ['conversation' => '#rekap psb semua'])])->assertOk();

        Setting::set('psb_recap_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', []);
        $this->postBatch([$this->item('RG-7-3', ['conversation' => '#rekap psb semua'])])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_8_from_me_gets_no_job(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RG-8', ['conversation' => '#rekap psb semua'], self::R, true)])->assertOk();

        $this->assertNoBotJobs();
    }

    public function test_9_psb_group_is_unchanged_and_its_recap_payload_has_no_context_key(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RG-9-1', ['conversation' => '#rekap psb semua kemarin'], self::P),
            $this->item('RG-9-2', ['conversation' => '#psb bantu'], self::P),
            $this->item('RG-9-3', ['conversation' => '#migrasi cek #5'], self::P),
        ])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, function (ProcessPsbRecapCommandJob $job): bool {
            return $job->payload === [
                'group_jid' => self::P,
                'sender_jid' => '6281111111111@s.whatsapp.net',
                'sender_name' => 'Teknisi',
                'message_id' => 'RG-9-1',
                'text' => '#rekap psb semua kemarin',
            ];
        });
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
    }

    public function test_10_jid_in_both_lists_keeps_the_psb_path_and_is_not_sendable(): void
    {
        Setting::set('report_wa_group_ids', [self::P]);
        Queue::fake();

        $this->postBatch([
            $this->item('RG-10-1', ['conversation' => '#rekap psb semua'], self::P),
            $this->item('RG-10-2', ['conversation' => '#psb bantu'], self::P),
        ])->assertOk();

        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushed(ProcessPsbRecapCommandJob::class, function (ProcessPsbRecapCommandJob $job): bool {
            return ! array_key_exists('context', $job->payload);
        });
        $this->assertSame([], app(ReportGroupRegistry::class)->sendableGroupJids());
    }

    public function test_11_report_group_that_is_also_the_technician_share_group_is_still_observed_once(): void
    {
        Queue::fake();
        $progress = \Mockery::mock(TechnicianProgressService::class);
        $progress->shouldReceive('isObservedGroup')->andReturn(true);
        $progress->shouldReceive('handleWebhookMessage')->once()->andReturn(['handled' => true]);
        $this->app->instance(TechnicianProgressService::class, $progress);

        $this->postBatch([$this->item('RG-11', ['conversation' => '#rekap psb semua'])])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class);
    }

    public function test_12_dispatch_exception_returns_200_and_a_psb_command_in_the_same_batch_still_dispatches(): void
    {
        $dispatched = [];
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use (&$dispatched) {
            if ($job instanceof ProcessPsbRecapCommandJob) {
                throw new \RuntimeException('queue unavailable');
            }
            $dispatched[] = $job::class;

            return 0;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = $this->postBatch([
            $this->item('RG-12-1', ['conversation' => '#rekap psb semua']),
            $this->item('RG-12-2', ['conversation' => '#psb bantu'], self::P),
        ]);

        $response->assertOk()->assertJson(['status' => 'ok', 'processed' => 2]);
        $this->assertContains(ProcessPsbWaJob::class, $dispatched);
    }

    public function test_13_the_same_message_id_twice_is_one_unique_job(): void
    {
        $a = new ProcessPsbRecapCommandJob(['group_jid' => self::R, 'message_id' => 'RG-13', 'text' => '#rekap psb semua', 'context' => 'report']);
        $b = new ProcessPsbRecapCommandJob(['group_jid' => self::R, 'message_id' => 'RG-13', 'text' => '#rekap psb semua', 'context' => 'report']);

        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }

    public function test_unlisted_group_still_gets_nothing(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RG-14', ['conversation' => '#rekap psb semua'], '120363-unlisted@g.us')])->assertOk();

        $this->assertNoBotJobs();
    }
}
