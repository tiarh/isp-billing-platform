<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TechnicianDailyTask;
use App\Models\TechnicianProgressReport;
use App\Models\TechnicianWaCommandLog;
use App\Services\EvolutionApiService;
use App\Services\TechnicianProgressParser;
use App\Services\TechnicianProgressService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The same WhatsApp message delivered twice (webhook retry / concurrent delivery) must
 * produce one report, no exception and no second reply.
 */
#[Group('blocking-core')]
class TechnicianProgressDuplicateMessageTest extends TestCase
{
    use RefreshDatabase;

    private const INPUT_GROUP = '120363000000000001@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('wa_ticket_progress_input_group_jid', self::INPUT_GROUP, 'text', 'wa');
        Setting::set('ticket_progress_dry_run_enabled', '0', 'text', 'wa');
        Setting::set('ticket_progress_reply_enabled', '1', 'text', 'wa');
        Setting::flushRequestMemo();
        Http::fake();
    }

    /** @return array<string, mixed> */
    private function message(string $id): array
    {
        return [
            'key' => [
                'id' => $id,
                'remoteJid' => self::INPUT_GROUP,
                'participant' => '62800000001@s.whatsapp.net',
                'fromMe' => false,
            ],
            'pushName' => 'Teknisi Test',
            'messageTimestamp' => now()->timestamp,
            'message' => ['conversation' => '#revisi umum foto kurang jelas'],
        ];
    }

    private function fakeEvolution(int $expectedReplies): void
    {
        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturnSelf();
        $evo->shouldReceive('isConfigured')->andReturn(true);
        $evo->shouldReceive('getConnectionState')->andReturn('open');
        $evo->shouldReceive('sendToGroup')->times($expectedReplies)->andReturn(['success' => true]);
        $this->app->instance(EvolutionApiService::class, $evo);
    }

    public function test_second_delivery_of_same_message_is_idempotent(): void
    {
        $this->fakeEvolution(1);
        $service = app(TechnicianProgressService::class);

        $first = $service->handleWebhookMessage($this->message('WAID-DUP-1'), 'Teknisi Test');
        $second = $service->handleWebhookMessage($this->message('WAID-DUP-1'), 'Teknisi Test');

        $this->assertTrue($first['handled']);
        $this->assertArrayNotHasKey('duplicate', $first);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, TechnicianProgressReport::query()->where('message_id', 'WAID-DUP-1')->count());
        $this->assertSame(1, TechnicianWaCommandLog::query()->where('message_id', 'WAID-DUP-1')->count());
        Http::assertNothingSent();
    }

    public function test_preinserted_report_without_command_log_is_treated_as_duplicate(): void
    {
        $this->fakeEvolution(0);
        TechnicianProgressReport::create([
            'work_date' => now('Asia/Jakarta')->toDateString(),
            'group_jid' => self::INPUT_GROUP,
            'sender_jid' => '62800000001@s.whatsapp.net',
            'message_id' => 'WAID-DUP-2',
            'raw_text' => '#revisi umum foto kurang jelas',
        ]);

        $result = app(TechnicianProgressService::class)->handleWebhookMessage($this->message('WAID-DUP-2'), 'Teknisi Test');

        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, TechnicianProgressReport::query()->where('message_id', 'WAID-DUP-2')->count());
        $this->assertSame(0, TechnicianWaCommandLog::query()->where('message_id', 'WAID-DUP-2')->count());
    }

    public function test_unique_violation_during_store_is_swallowed_without_reply(): void
    {
        $this->fakeEvolution(0);
        $service = new class(app(TechnicianProgressParser::class)) extends TechnicianProgressService
        {
            protected function storeParsedInput(array $parsed, array $ctx): array
            {
                throw new UniqueConstraintViolationException(
                    'mysql',
                    'insert into technician_progress_reports',
                    [],
                    new \Exception('Duplicate entry for key technician_progress_reports_message_id_unique')
                );
            }
        };

        $result = $service->handleWebhookMessage($this->message('WAID-DUP-3'), 'Teknisi Test');

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame(0, TechnicianWaCommandLog::query()->where('message_id', 'WAID-DUP-3')->count());
    }

    public function test_losing_concurrent_duplicate_rolls_back_rows_written_before_the_unique_violation(): void
    {
        $this->fakeEvolution(0);
        $service = new class(app(TechnicianProgressParser::class)) extends TechnicianProgressService
        {
            protected function storeParsedInput(array $parsed, array $ctx): array
            {
                // Earlier batch item already wrote its task row ...
                TechnicianDailyTask::create([
                    'work_date' => now('Asia/Jakarta')->toDateString(),
                    'task' => 'Item 1 of the losing duplicate',
                    'source' => 'manual_wa',
                    'source_ref' => 'WAID-DUP-4:1',
                ]);

                // ... then a later item hits the unique key because the winner stored it first.
                throw new UniqueConstraintViolationException(
                    'mysql',
                    'insert into technician_progress_reports',
                    [],
                    new \Exception('Duplicate entry for key technician_progress_reports_message_id_unique')
                );
            }
        };

        $result = $service->handleWebhookMessage($this->message('WAID-DUP-4'), 'Teknisi Test');

        $this->assertTrue($result['duplicate']);
        $this->assertSame(0, TechnicianDailyTask::query()->where('source_ref', 'like', 'WAID-DUP-4%')->count());
        $this->assertSame(0, TechnicianWaCommandLog::query()->where('message_id', 'WAID-DUP-4')->count());
    }

    public function test_first_delivery_commits_its_rows_inside_the_transaction(): void
    {
        $this->fakeEvolution(1);
        $result = app(TechnicianProgressService::class)->handleWebhookMessage($this->message('WAID-DUP-5'), 'Teknisi Test');

        $this->assertTrue($result['handled']);
        $this->assertArrayNotHasKey('duplicate', $result);
        $this->assertSame(1, TechnicianProgressReport::query()->where('message_id', 'WAID-DUP-5')->count());
        $this->assertSame(1, TechnicianWaCommandLog::query()->where('message_id', 'WAID-DUP-5')->count());
    }
}
