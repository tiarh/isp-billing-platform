<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ProcessRecapTopicCommandJob;
use App\Models\Setting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Webhook routing of "#rekap onu": only the `wa` queue, only with its flags, never touching
 * the PSB / migration paths (those keep their queues and the "#rekap psb" payload stays identical).
 */
#[Group('blocking-psb')]
class RecapTopicWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const G = '120363-activation@g.us';

    private const R = '120363-skynetreport@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', [self::G]);
        Setting::set('psb_recap_cmd_enabled', '1');
        Setting::set('recap_onu_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_recap_cmd_enabled', '1');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function item(string $id, array $message, string $group = self::G, bool $fromMe = false): array
    {
        return [
            'key' => ['remoteJid' => $group, 'participant' => '6281111111111@s.whatsapp.net', 'id' => $id, 'fromMe' => $fromMe],
            'pushName' => 'Teknisi',
            'message' => $message,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function postBatch(array $items)
    {
        return $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => ['messages' => $items],
        ], ['apikey' => 'known-webhook-secret']);
    }

    private function text(string $id, string $text, string $group = self::G): array
    {
        return $this->item($id, ['conversation' => $text], $group);
    }

    private function assertNoOtherBotJobs(): void
    {
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_onu_recap_goes_only_to_the_wa_queue_with_a_plain_payload(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-1', '#rekap onu klampok')])->assertOk();

        Queue::assertPushedOn('wa', ProcessRecapTopicCommandJob::class, function (ProcessRecapTopicCommandJob $job): bool {
            return $job->payload === [
                'group_jid' => self::G,
                'sender_jid' => '6281111111111@s.whatsapp.net',
                'sender_name' => 'Teknisi',
                'message_id' => 'RT-1',
                'text' => '#rekap onu klampok',
            ];
        });
        $this->assertNoOtherBotJobs();
    }

    public function test_extended_text_is_accepted(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RT-2', ['extendedTextMessage' => ['text' => '#rekap onu semua']])])->assertOk();

        Queue::assertPushedOn('wa', ProcessRecapTopicCommandJob::class);
    }

    public function test_p_rekap_psb_payload_is_byte_identical_and_never_a_topic_job(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-P', '#rekap psb klampok 1-14 okt')])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, function (ProcessPsbRecapCommandJob $job): bool {
            return $job->payload === [
                'group_jid' => self::G,
                'sender_jid' => '6281111111111@s.whatsapp.net',
                'sender_name' => 'Teknisi',
                'message_id' => 'RT-P',
                'text' => '#rekap psb klampok 1-14 okt',
            ];
        });
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    public function test_psb_and_migration_commands_keep_their_queues(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->text('RT-B1', '#psb bantu'),
            $this->text('RT-B2', '#migrasi cek #5'),
            $this->text('RT-B3', '#migrasi rekap klampok'),
        ])->assertOk();

        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_p_migrasi_rekap_reaches_the_migration_command_only(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-M', '#migrasi rekap')])->assertOk();

        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_text_carrying_a_psb_or_migration_tag_stays_with_those_bots(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->text('RT-C1', '#rekap onu #psb bantu'),
            $this->text('RT-C2', '#rekap onu #migrasi cek #5'),
        ])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
    }

    public function test_image_caption_never_becomes_a_recap(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $this->postBatch([$this->item('RT-D', ['imageMessage' => ['caption' => '#rekap onu klampok', 'mimetype' => 'image/jpeg']])]);

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        $this->assertNoOtherBotJobs();
    }

    public function test_flag_off_or_missing_means_no_topic_job_and_nothing_else_changes(): void
    {
        Queue::fake();
        Setting::set('recap_onu_cmd_enabled', '0');

        $this->postBatch([$this->text('RT-E1', '#rekap onu klampok'), $this->text('RT-E2', '#psb bantu')])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);

        Setting::query()->where('key', 'recap_onu_cmd_enabled')->delete();
        Cache::forget('setting.recap_onu_cmd_enabled');
        $this->postBatch([$this->text('RT-E3', '#rekap onu klampok')])->assertOk();
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    public function test_unlisted_group_from_me_and_multi_line_get_no_job(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->text('RT-F1', '#rekap onu klampok', '120363-unlisted@g.us'),
            $this->item('RT-F2', ['conversation' => '#rekap onu klampok'], self::G, true),
            $this->text('RT-F3', "#rekap onu klampok\nterima kasih"),
            $this->text('RT-F4', '#rekapmigrasi'),
            $this->text('RT-F5', '#rekap onus'),
            $this->text('RT-F6', '/rekap onu klampok'),
        ])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        $this->assertNoOtherBotJobs();
    }

    public function test_dispatch_exception_returns_200_and_the_rest_of_the_batch_still_dispatches(): void
    {
        $dispatched = [];
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use (&$dispatched) {
            if ($job instanceof ProcessRecapTopicCommandJob) {
                throw new \RuntimeException('queue unavailable');
            }
            $dispatched[] = $job::class;

            return 0;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->postBatch([$this->text('RT-G1', '#rekap onu klampok'), $this->text('RT-G2', '#psb bantu')])
            ->assertOk()->assertJson(['status' => 'ok', 'processed' => 2]);

        $this->assertContains(ProcessPsbWaJob::class, $dispatched);
    }

    public function test_duplicate_key_id_shares_one_unique_id(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-DUP', '#rekap onu klampok'), $this->text('RT-DUP', '#rekap onu klampok')])->assertOk();

        $ids = [];
        foreach (Queue::pushed(ProcessRecapTopicCommandJob::class) as $job) {
            $ids[] = $job->uniqueId();
        }
        // The unique lock collapses the duplicate delivery of the same key.id into one job.
        $this->assertSame(['recap-topic-msg:RT-DUP'], $ids);
    }

    public function test_report_group_runs_the_topic_with_context_report(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-R1', '#rekap onu semua', self::R)])->assertOk();

        Queue::assertPushedOn('wa', ProcessRecapTopicCommandJob::class, fn (ProcessRecapTopicCommandJob $j): bool => ($j->payload['context'] ?? null) === 'report' && $j->payload['group_jid'] === self::R);
        $this->assertNoOtherBotJobs();
    }

    public function test_report_group_needs_both_report_and_topic_flags(): void
    {
        Queue::fake();
        Setting::set('report_recap_cmd_enabled', '0');
        $this->postBatch([$this->text('RT-R2', '#rekap onu semua', self::R)])->assertOk();
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);

        Setting::set('report_recap_cmd_enabled', '1');
        Setting::set('recap_onu_cmd_enabled', '0');
        $this->postBatch([$this->text('RT-R3', '#rekap onu semua', self::R)])->assertOk();
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    public function test_report_group_never_runs_psb_or_migration_commands(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->text('RT-R4', '#migrasi rekap', self::R),
            $this->text('RT-R5', '#psb bantu', self::R),
            $this->text('RT-R6', '#rekap onu #migrasi cek #5', self::R),
        ])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        $this->assertNoOtherBotJobs();
    }

    public function test_report_group_still_routes_rekap_psb_to_the_psb_recap_job(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('RT-R7', '#rekap psb semua', self::R)])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, fn (ProcessPsbRecapCommandJob $j): bool => ($j->payload['context'] ?? null) === 'report');
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    public function test_group_in_both_lists_is_handled_once_by_the_psb_path(): void
    {
        Queue::fake();
        Setting::set('report_wa_group_ids', [self::G]);

        $this->postBatch([$this->text('RT-O1', '#rekap onu klampok')])->assertOk();

        Queue::assertPushed(ProcessRecapTopicCommandJob::class, 1);
        Queue::assertPushed(ProcessRecapTopicCommandJob::class, fn (ProcessRecapTopicCommandJob $j): bool => ! array_key_exists('context', $j->payload));
    }
}
