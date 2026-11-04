<?php

namespace Tests\Feature;

use App\Services\EvolutionApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvolutionApiMediaTest extends TestCase
{
    public function test_image_broadcast_uses_fake_send_media_request_and_normalizes_recipient(): void
    {
        config([
            'services.evolution_api.api_key' => 'fake-key',
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.delay_ms' => 0,
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('campaigns/test.png', 'fake-image-bytes');

        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
            'http://evolution.test/message/sendMedia/*' => Http::response([
                'key' => ['id' => 'fake-media-message-id'],
            ], 201),
        ]);

        $result = app(EvolutionApiService::class)
            ->forInstance('fake-billing')
            ->sendImage('081234567890', 'campaigns/test.png', 'Caption dummy');

        self::assertSame([
            'success' => true,
            'message_id' => 'fake-media-message-id',
        ], $result);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/message/sendMedia/fake-billing')) {
                return false;
            }

            $payload = $request->data();

            return $payload['number'] === '6281234567890@s.whatsapp.net'
                && $payload['mediatype'] === 'image'
                && $payload['mimetype'] === 'image/png'
                && $payload['caption'] === 'Caption dummy'
                && $payload['fileName'] === 'test.png'
                && $payload['media'] === base64_encode('fake-image-bytes');
        });
    }

    public function test_image_broadcast_fails_closed_for_missing_media_without_http_send(): void
    {
        config([
            'services.evolution_api.api_key' => 'fake-key',
            'services.evolution_api.base_url' => 'http://evolution.test',
        ]);

        Storage::fake('public');
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
        ]);

        $result = app(EvolutionApiService::class)
            ->forInstance('fake-billing')
            ->sendImage('081234567890', 'campaigns/missing.png');

        self::assertFalse($result['success']);
        self::assertStringContainsString('tidak ditemukan', $result['error']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/message/sendMedia/'));
    }
}
