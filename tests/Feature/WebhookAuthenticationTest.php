<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbWaJob;
use App\Models\Setting;
use App\Services\MidtransService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_evolution_webhook_rejects_invalid_secret_before_processing(): void
    {
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Queue::fake();

        $response = $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
        ]);

        $response->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_psb_webhook_uses_db_group_allowlist_before_dispatch(): void
    {
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);
        Queue::fake();

        $response = $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => '120363-activation@g.us',
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => 'WA-PSB-ALLOWLIST-1',
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'message' => [
                    'conversation' => "#psb\nNama: Pelanggan Test\nODP: ODP-1\nRT: 01\nRW: 02\nTikor: -7.9,112.6\nDaerah: Randuagung\nRouter: SKYNET-RA-OLT\nPaket: STARTLITE\nSN: FHTT12345678\nHP: 081234567890",
                ],
            ],
        ], ['apikey' => 'known-webhook-secret']);

        $response->assertOk();
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class, function (ProcessPsbWaJob $job): bool {
            return ($job->payload['message_id'] ?? null) === 'WA-PSB-ALLOWLIST-1';
        });
    }

    public function test_psb_webhook_returns_retryable_response_when_queue_dispatch_fails(): void
    {
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => '120363-activation@g.us',
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => 'WA-PSB-RETRY-1',
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'message' => ['conversation' => '/psb'],
            ],
        ], ['apikey' => 'known-webhook-secret']);

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'retry',
                'processed' => 1,
                'failed' => 1,
            ]);
    }

    public function test_psb_webhook_does_not_dispatch_from_unlisted_group(): void
    {
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);
        Queue::fake();

        $response = $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => '120363-unlisted@g.us',
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => 'WA-PSB-BLOCKED-1',
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'message' => [
                    'conversation' => '#psb\nNama: Pelanggan Tidak Diizinkan\nSN: FHTT0000A003',
                ],
            ],
        ], ['apikey' => 'known-webhook-secret']);

        $response->assertOk();
        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_midtrans_rejects_invalid_signature_before_handler(): void
    {
        $midtrans = $this->mock(MidtransService::class);
        $midtrans->shouldReceive('isConfigured')->once()->andReturnTrue();
        $midtrans->shouldReceive('verifyNotificationSignature')->once()->andReturnFalse();

        Queue::fake();

        $response = $this->postJson('/webhooks/payment-gateway/midtrans', [
            'order_id' => 'INV1-test',
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'signature_key' => 'invalid',
        ]);

        $response->assertUnauthorized();
        Queue::assertNothingPushed();
    }
}
