<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\PsbWaHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class PsbWaHealthLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_requires_token_but_not_login(): void
    {
        Setting::set('psb_wa_health_public_token', 'health-123');
        Cache::put('psb_wa:webhook_status', 'OK');
        Cache::put('psb_wa:last_webhook_ok_at', '2026-07-28T07:00:00+07:00');

        $this->get('/psb-wa/health-public?token=salah')->assertNotFound();

        $this->get('/psb-wa/health-public?token=health-123')
            ->assertOk()
            ->assertSee('PSB WA Health')
            ->assertSee('Webhook');
    }

    public function test_authenticated_health_json_contains_webhook_queue_and_reconciliation_shape(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Setting::set('psb_wa_group_ids', ['120363-aktivasi@g.us']);
        Cache::put('psb_wa:webhook_status', 'OK');
        Cache::put('psb_wa:last_reply_status', 'OK');

        $this->actingAs($user)
            ->getJson('/api/psb-wa/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'evolution' => ['state', 'open'],
                'webhook' => ['status'],
                'reply' => ['status'],
                'queue' => ['psb_size', 'failed_recent'],
                'groups' => ['count', 'allowed'],
                'chat_reconciliation' => ['available', 'rows'],
            ]);
    }

    public function test_webhook_unauthorized_does_not_replace_authenticated_health(): void
    {
        config(['services.evolution_api.api_key' => 'secret-real']);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [],
        ], ['apikey' => 'wrong'])->assertUnauthorized();

        Cache::put('psb_wa:webhook_status', 'OK');
        Cache::put('psb_wa:last_webhook_ok_at', now('Asia/Jakarta')->addSecond()->toIso8601String());
        $health = app(PsbWaHealthService::class)->full();

        $this->assertSame('OK', $health['webhook']['status']);
        $this->assertNotNull($health['webhook']['last_rejected_at']);
        $this->assertSame(1, $health['webhook']['rejected_count']);
        $this->assertNotContains('Webhook error', $health['alerts']);
    }

    public function test_newer_webhook_rejection_marks_health_degraded(): void
    {
        config(['services.evolution_api.api_key' => 'secret-real']);
        Cache::put('psb_wa:webhook_status', 'OK');
        Cache::put('psb_wa:last_webhook_ok_at', now('Asia/Jakarta')->subMinute()->toIso8601String());

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [],
        ], ['apikey' => 'wrong'])->assertUnauthorized();

        $health = app(PsbWaHealthService::class)->full();

        $this->assertSame('401', $health['webhook']['status']);
        $this->assertSame('degraded', $health['status']);
        $this->assertContains('Webhook error', $health['alerts']);
    }

    public function test_authenticated_health_event_marks_transport_ok_without_processing_message(): void
    {
        config(['services.evolution_api.api_key' => 'secret-real']);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'health.check',
            'data' => [],
        ], ['apikey' => 'secret-real'])
            ->assertOk()
            ->assertJson(['status' => 'ignored', 'event' => 'health.check']);

        $health = app(PsbWaHealthService::class)->full();
        $this->assertSame('OK', $health['webhook']['status']);
        $this->assertNotNull($health['webhook']['last_accepted_at']);
    }

    public function test_webhook_accepts_evolution_single_message_object_shape(): void
    {
        config(['services.evolution_api.api_key' => 'secret-real']);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [
                'messages' => [
                    'key' => [
                        'remoteJid' => '120363-unconfigured@g.us',
                        'id' => 'single-message-shape',
                        'fromMe' => false,
                    ],
                    'message' => ['conversation' => 'pesan biasa'],
                ],
            ],
        ], ['apikey' => 'secret-real'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    public function test_webhook_skips_malformed_message_envelope_without_500(): void
    {
        config(['services.evolution_api.api_key' => 'secret-real']);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => ['messages' => [['key' => 'not-an-envelope']]],
        ], ['apikey' => 'secret-real'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);
    }
}
