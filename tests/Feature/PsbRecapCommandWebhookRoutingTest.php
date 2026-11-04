<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Models\Setting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Webhook gate for "#rekap psb ...": only the `wa` queue, only with the flag ON,
 * only in an allowlisted group, never for media / fromMe, and a dispatch failure
 * never turns into a 503 or blocks the rest of the batch.
 */
#[Group('blocking-psb')]
class PsbRecapCommandWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '120363-activation@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', [self::GROUP]);
        Setting::set('psb_recap_cmd_enabled', '1');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function item(string $id, array $message, string $group = self::GROUP, bool $fromMe = false): array
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

    public function test_a_recap_command_goes_only_to_the_wa_queue(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-A-1', ['conversation' => '#rekap psb klampok 1-14 okt'])])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, function (ProcessPsbRecapCommandJob $job): bool {
            return ($job->payload['message_id'] ?? null) === 'RC-A-1'
                && ($job->payload['group_jid'] ?? null) === self::GROUP
                && ($job->payload['text'] ?? null) === '#rekap psb klampok 1-14 okt';
        });
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
    }

    public function test_extended_text_message_is_also_accepted(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-A-2', ['extendedTextMessage' => ['text' => '#rekap psb semua']])])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class);
    }

    public function test_b_psb_command_goes_only_to_the_psb_queue(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-B-1', ['conversation' => '#psb bantu'])])->assertOk();

        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
    }

    public function test_c_migration_command_goes_only_to_migration_command_queue(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-C-1', ['conversation' => '#migrasi cek #5'])])->assertOk();

        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_recap_text_carrying_a_psb_or_migration_tag_stays_with_those_bots(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->item('RC-C-2', ['conversation' => '#rekap psb #psb bantu']),
            $this->item('RC-C-3', ['conversation' => '#rekap psb #migrasi cek #5']),
        ])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
    }

    public function test_d_recap_command_with_an_image_takes_the_old_path(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $this->postBatch([$this->item('RC-D-1', [
            'imageMessage' => ['caption' => '#rekap psb klampok 1-14 okt', 'mimetype' => 'image/jpeg'],
        ])]);

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        // Not a PSB / migration trigger either, so nothing else is queued.
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
    }

    public function test_e_flag_off_means_no_recap_job_and_nothing_else_changes(): void
    {
        Setting::set('psb_recap_cmd_enabled', '0');
        Queue::fake();

        $this->postBatch([
            $this->item('RC-E-1', ['conversation' => '#rekap psb klampok 1-14 okt']),
            $this->item('RC-E-2', ['conversation' => '#psb bantu']),
        ])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
    }

    public function test_e_flag_defaults_to_off_when_the_setting_does_not_exist(): void
    {
        Setting::query()->where('key', 'psb_recap_cmd_enabled')->delete();
        Cache::forget('setting.psb_recap_cmd_enabled');
        Queue::fake();

        $this->postBatch([$this->item('RC-E-3', ['conversation' => '#rekap psb klampok'])])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_f_group_outside_the_allowlist_gets_no_job(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-F-1', ['conversation' => '#rekap psb klampok'], '120363-unlisted@g.us')])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_g_dispatch_exception_returns_200_and_the_rest_of_the_batch_still_dispatches(): void
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
            $this->item('RC-G-1', ['conversation' => '#rekap psb klampok']),
            $this->item('RC-G-2', ['conversation' => '#psb bantu']),
        ]);

        $response->assertOk()->assertJson(['status' => 'ok', 'processed' => 2]);
        $this->assertContains(ProcessPsbWaJob::class, $dispatched);
    }

    public function test_h_from_me_is_ignored(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-H-1', ['conversation' => '#rekap psb klampok'], self::GROUP, true)])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_multi_line_message_is_not_a_recap_command(): void
    {
        Queue::fake();

        $this->postBatch([$this->item('RC-I-1', ['conversation' => "#rekap psb klampok\nterima kasih"])])->assertOk();

        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }
}
