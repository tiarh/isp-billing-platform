<?php

namespace Tests\Feature;

use App\Services\EvolutionApiService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class EvolutionSendDocumentTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution_api.api_key' => 'fake-key',
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.delay_ms' => 0,
        ]);

        $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'recap-test-'.uniqid().'.xlsx';
        File::put($this->file, 'fake-xlsx-bytes');
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    public function test_send_document_posts_send_media_with_mediatype_document_and_xlsx_mimetype(): void
    {
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            'http://evolution.test/message/sendMedia/*' => Http::response(['key' => ['id' => 'DOC-MSG-1']], 201),
        ]);

        $result = app(EvolutionApiService::class)
            ->forInstance('fake-ops')
            ->sendDocument('120363-activation@g.us', $this->file, 'Rekap PSB KLAMPOK', 'rekap-psb-klampok.xlsx');

        $this->assertSame(['success' => true, 'message_id' => 'DOC-MSG-1'], $result);
        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/message/sendMedia/fake-ops')) {
                return false;
            }
            $payload = $request->data();

            return $payload['number'] === '120363-activation@g.us'
                && $payload['mediatype'] === 'document'
                && $payload['mimetype'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                && $payload['caption'] === 'Rekap PSB KLAMPOK'
                && $payload['fileName'] === 'rekap-psb-klampok.xlsx'
                && $payload['media'] === base64_encode('fake-xlsx-bytes');
        });
    }

    public function test_file_name_defaults_to_the_basename(): void
    {
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            'http://evolution.test/message/sendMedia/*' => Http::response(['key' => ['id' => 'DOC-MSG-2']], 201),
        ]);

        app(EvolutionApiService::class)->forInstance('fake-ops')->sendDocument('120363-activation@g.us', $this->file);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/message/sendMedia/')
            && $request->data()['fileName'] === basename($this->file));
    }

    public function test_a_gateway_error_is_returned_as_failure_without_any_text_fallback(): void
    {
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            'http://evolution.test/message/sendMedia/*' => Http::response(['response' => ['message' => 'Connection Closed']], 500),
        ]);

        $result = app(EvolutionApiService::class)->forInstance('fake-ops')->sendDocument('120363-activation@g.us', $this->file);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection Closed', $result['error']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/message/sendText/'));
    }

    public function test_fails_closed_when_the_session_is_closed(): void
    {
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']]),
        ]);

        $result = app(EvolutionApiService::class)->forInstance('fake-ops')->sendDocument('120363-activation@g.us', $this->file);

        $this->assertFalse($result['success']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/message/send'));
    }

    public function test_fails_closed_for_a_missing_or_empty_file_without_http_send(): void
    {
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
        ]);
        File::put($this->file, '');

        $empty = app(EvolutionApiService::class)->forInstance('fake-ops')->sendDocument('120363-activation@g.us', $this->file);
        $missing = app(EvolutionApiService::class)->forInstance('fake-ops')->sendDocument('120363-activation@g.us', $this->file.'.nope');

        $this->assertFalse($empty['success']);
        $this->assertFalse($missing['success']);
        $this->assertStringContainsString('tidak ditemukan', $missing['error']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/message/sendMedia/'));
    }
}
