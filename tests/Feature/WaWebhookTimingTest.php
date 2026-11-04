<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbWaJob;
use App\Models\Setting;
use App\Services\Wa\WaWebhookTimer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Plan #7 phase 0: the webhook is only measured. Timing never leaks personal data, repeats are
 * counted but never skipped, and a failing measurement can never change the response.
 */
#[Group('blocking-psb')]
class WaWebhookTimingTest extends TestCase
{
    use RefreshDatabase;

    private const PSB_GROUP = '120363000000000001@g.us';

    private const ATT_GROUP = '120363000000000003@g.us';

    private const SENDER = '62812000001@s.whatsapp.net';

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution_api.webhook_secret' => 'canary-pw',
            'services.evolution_api.api_key' => 'canary-pw',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => 'canary-pw',
            'wa_webhook.log_ms' => 0,
        ]);
        Setting::set('psb_wa_group_ids', [self::PSB_GROUP]);
        Setting::set('attendance_group_id', self::ATT_GROUP);
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Queue::fake();
        Http::fake([
            'http://evolution.test/chat/getBase64FromMediaMessage/ops' => Http::response(['base64' => base64_encode(str_repeat('image-bytes', 20))]),
            '*' => Http::response([], 200),
        ]);

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = ['message' => $event->message, 'context' => $event->context];
        });
    }

    /** @return array<string, mixed> */
    private function textPayload(string $id, string $text, string $group = self::PSB_GROUP): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => $id, 'participant' => self::SENDER],
                'pushName' => 'Teknisi Rahasia',
                'message' => ['conversation' => $text],
                'messageTimestamp' => now()->timestamp,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function photoPayload(string $id, string $group): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => $id, 'participant' => self::SENDER],
                'pushName' => 'Teknisi Rahasia',
                'message' => ['imageMessage' => ['mimetype' => 'image/jpeg', 'caption' => 'Absen rahasia']],
                'messageTimestamp' => now()->timestamp,
            ],
        ];
    }

    private function send(array $payload, array $headers = [])
    {
        return $this->postJson('/webhooks/evolution/attendance', $payload, $headers + ['apikey' => 'canary-pw']);
    }

    /** @return list<array<string, mixed>> */
    private function timingLogs(): array
    {
        return array_values(array_map(
            fn (array $row) => $row['context'],
            array_filter($this->logged, fn (array $row) => $row['message'] === '[WA-WEBHOOK] timing')
        ));
    }

    public function test_timing_log_has_no_personal_data(): void
    {
        $this->send($this->textPayload('FAKE-TIMING-1', "#psb\nNama: Budi Rahasia\nSN: FHTT00000001"))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);

        $logs = $this->timingLogs();
        $this->assertCount(1, $logs);
        $context = $logs[0];

        $this->assertSame('psb', $context['branch']);
        $this->assertSame('psb', $context['role']);
        $this->assertSame(substr(sha1('FAKE-TIMING-1'), 0, 12), $context['mid_hash']);
        $this->assertSame(200, $context['status']);
        $this->assertFalse($context['has_proxy_header']);
        foreach (['total_ms', 'chat_ms', 'psb_branch_ms', 'technician_ms', 'attendance_ms', 'media_ms'] as $key) {
            $this->assertIsInt($context[$key], $key);
        }

        $dump = json_encode($context);
        foreach (['62812000001', '120363', '@g.us', '@s.whatsapp.net', 'Rahasia', 'Budi', 'FHTT', 'FAKE-TIMING-1', '#psb'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $dump, "timing log leaks {$needle}");
        }
    }

    public function test_fast_requests_are_not_logged_with_the_default_threshold(): void
    {
        config(['wa_webhook.log_ms' => 300]);

        $this->send($this->textPayload('FAKE-TIMING-2', '#psb Nama: Budi'))->assertOk();

        $this->assertSame([], $this->timingLogs());
        $stats = Cache::get(WaWebhookTimer::statsKey());
        $this->assertSame(1, $stats['branches']['psb']['count']);
        $this->assertSame(1, array_sum($stats['branches']['psb']['buckets']));
    }

    public function test_branch_labels_and_media_time_for_attendance_photo(): void
    {
        $this->send($this->photoPayload('FAKE-TIMING-3', self::ATT_GROUP), ['X-Forwarded-For' => '203.0.113.9'])->assertOk();

        $context = $this->timingLogs()[0];
        $this->assertSame('attendance', $context['branch']);
        $this->assertSame('attendance', $context['role']);
        $this->assertTrue($context['has_proxy_header']);
        $this->assertGreaterThanOrEqual(0, $context['media_ms']);
        $this->assertGreaterThanOrEqual($context['media_ms'], $context['attendance_ms']);
        $this->assertStringNotContainsString('203.0.113.9', (string) json_encode($context));
    }

    public function test_ktp_branches_follow_media(): void
    {
        $this->send($this->photoPayload('FAKE-TIMING-4', self::PSB_GROUP))->assertOk();
        $payload = $this->photoPayload('FAKE-TIMING-5', self::PSB_GROUP);
        $payload['data']['message']['imageMessage']['caption'] = '#psb ktp #999999';
        $this->send($payload)->assertOk();
        $this->send($this->textPayload('FAKE-TIMING-6', '#psb ktp #999999'))->assertOk();

        $branches = array_map(fn (array $c) => $c['branch'], $this->timingLogs());
        $this->assertSame(['psb', 'psb_ktp_media', 'psb_ktp_text'], $branches);
    }

    public function test_repeat_is_counted_but_never_skipped(): void
    {
        $payload = $this->textPayload('FAKE-TIMING-7', "#psb\nNama: Budi\nSN: FHTT00000007");

        $first = $this->send($payload);
        $second = $this->send($payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json(), $second->json());
        // Processing is untouched: the repeat takes the normal path (its own dedupe is not ours;
        // the golden replay "duplicate delivery" scenario proves it is byte-identical to main).
        $this->assertSame(1, $this->timingLogs()[1]['messages']);
        Queue::assertPushed(ProcessPsbWaJob::class);

        $logs = $this->timingLogs();
        $this->assertFalse($logs[0]['repeat']);
        $this->assertTrue($logs[1]['repeat']);
        $this->assertSame($logs[0]['mid_hash'], $logs[1]['mid_hash']);

        $stats = Cache::get(WaWebhookTimer::statsKey());
        $this->assertSame(1, $stats['repeats']);
        $this->assertSame(2, $stats['requests']);
        $this->assertSame(2, $stats['branches']['psb']['count']);
    }

    public function test_measurement_failure_does_not_change_the_response(): void
    {
        $store = new class extends ArrayStore
        {
            public function get($key)
            {
                $this->guard($key);

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->guard($key);

                return parent::put($key, $value, $seconds);
            }

            public function add($key, $value, $seconds)
            {
                $this->guard($key);

                return parent::add($key, $value, $seconds);
            }

            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->guard($name);

                return parent::lock($name, $seconds, $owner);
            }

            private function guard(string $key): void
            {
                if (str_contains($key, 'wa_webhook:')) {
                    throw new \RuntimeException('measurement cache is down');
                }
            }
        };
        Cache::extend('wa_throwing', fn () => new Repository($store));
        config(['cache.stores.wa_throwing' => ['driver' => 'wa_throwing'], 'cache.default' => 'wa_throwing']);

        $this->send($this->textPayload('FAKE-TIMING-8', "#psb\nNama: Budi\nSN: FHTT00000008"))
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'processed' => 1]);

        Queue::assertPushed(ProcessPsbWaJob::class, 1);
        $this->assertSame([], array_filter($this->logged, fn (array $row) => str_contains($row['message'], 'PSB webhook processing failed')));
    }

    public function test_rejected_and_ignored_requests_keep_their_responses(): void
    {
        $this->postJson('/webhooks/evolution/attendance', $this->textPayload('FAKE-TIMING-9', '#psb x'), ['apikey' => 'wrong'])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Unauthorized']);

        $this->send(['event' => 'connection.update', 'data' => ['state' => 'open']])
            ->assertOk()
            ->assertExactJson(['status' => 'ignored', 'event' => 'connection.update']);

        $stats = Cache::get(WaWebhookTimer::statsKey());
        $this->assertEquals([200 => 1, 401 => 1], $stats['status']);
        $this->assertSame(2, $stats['branches']['none']['count']);
    }

    public function test_stats_command_reports_branches_and_repeats(): void
    {
        $payload = $this->textPayload('FAKE-TIMING-10', "#psb\nNama: Budi\nSN: FHTT00000010");
        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();
        $this->send($this->photoPayload('FAKE-TIMING-11', self::ATT_GROUP))->assertOk();

        $before = Cache::get(WaWebhookTimer::statsKey());
        $this->assertSame(0, Artisan::call('wa:webhook-stats', ['--hours' => 24]));
        $output = Artisan::output();

        $this->assertStringContainsString('WA webhook stats, last 24 h (1 h with data)', $output);
        $this->assertMatchesRegularExpression('/\bpsb\b\s*\|\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/\battendance\b\s*\|\s*1\b/', $output);
        $this->assertStringContainsString('requests: 3', $output);
        $this->assertStringContainsString('repeats (same message id again): 1', $output);
        $this->assertStringContainsString('200=3', $output);
        $this->assertStringContainsString('<250ms', $output);
        // Read-only: the command did not touch the stats.
        $this->assertSame($before, Cache::get(WaWebhookTimer::statsKey()));
    }

    public function test_stats_command_without_data(): void
    {
        $this->assertSame(0, Artisan::call('wa:webhook-stats'));
        $this->assertStringContainsString('No data yet.', Artisan::output());
    }
}
