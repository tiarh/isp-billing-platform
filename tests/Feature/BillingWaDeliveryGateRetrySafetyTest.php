<?php

namespace Tests\Feature;

use App\Contracts\WaProvider;
use App\Jobs\SendBillingWaJob;
use App\Jobs\SendFinanceBulkWaJob;
use App\Models\BillingWaLog;
use App\Models\Setting;
use App\Services\BillingWaEventService;
use App\Services\EvolutionApiService;
use App\Services\FinanceBulkWaService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * PR-WA (Q5, Q11, Q13): a WA send whose outcome is unknown (read timeout) must
 * never be auto-retried, transient failures are `retrying` (not `failed`), and
 * a job re-reads its log and never re-sends a message already `sent`.
 */
class BillingWaDeliveryGateRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('wa_billing_writes_enabled', '1', 'boolean', 'whatsapp');
    }

    public function test_read_timeout_marks_log_unconfirmed_and_does_not_retry(): void
    {
        $log = $this->log('pending');
        $provider = $this->openProvider();
        $provider->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'error' => 'Timeout menunggu respons Evolution API',
            'delivery_unknown' => true,
        ]);

        $job = (new SendBillingWaJob('081200000001', 'tagihan', $log->id, 'payment'))->withFakeQueueInteractions();
        $job->handle($provider);

        $job->assertNotReleased();
        $this->assertSame('unconfirmed', $log->fresh()->status);
    }

    public function test_transient_failure_is_retrying_and_released(): void
    {
        $log = $this->log('pending');
        $provider = $this->openProvider();
        $provider->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'error' => 'Gagal konek ke Evolution API (pesan belum terkirim)',
            'delivery_unknown' => false,
        ]);

        $job = (new SendBillingWaJob('081200000001', 'tagihan', $log->id, 'payment'))->withFakeQueueInteractions();
        $job->handle($provider);

        $job->assertReleased();
        $this->assertSame('retrying', $log->fresh()->status);
    }

    public function test_job_never_resends_a_log_already_sent(): void
    {
        $log = $this->log('sent');
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldNotReceive('send');

        (new SendBillingWaJob('081200000001', 'tagihan', $log->id, 'payment'))->handle($provider);

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_attempt_that_died_mid_send_is_unconfirmed_not_resent(): void
    {
        $log = $this->log('sending');
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldNotReceive('send');

        (new SendBillingWaJob('081200000001', 'tagihan', $log->id, 'payment'))->handle($provider);

        $this->assertSame('unconfirmed', $log->fresh()->status);
    }

    public function test_failed_hook_closes_in_flight_log(): void
    {
        $log = $this->log('sending');

        (new SendBillingWaJob('081200000001', 'tagihan', $log->id, 'payment'))
            ->failed(new RuntimeException('worker killed'));

        $this->assertSame('unconfirmed', $log->fresh()->status);
    }

    public function test_dedupe_blocks_retrying_and_unconfirmed_logs(): void
    {
        foreach (['retrying', 'unconfirmed', 'sending', 'sent', 'pending'] as $status) {
            $this->assertContains($status, BillingWaEventService::IN_FLIGHT_OR_DONE);
        }
        $this->assertNotContains('failed', BillingWaEventService::IN_FLIGHT_OR_DONE);
    }

    public function test_finance_bulk_read_timeout_is_unconfirmed_and_not_retried(): void
    {
        Setting::set('finance_bulk_wa_enabled', '1', 'boolean', 'finance_bulk_wa');
        Setting::set('finance_bulk_wa_dry_run', '0', 'boolean', 'finance_bulk_wa');
        $log = $this->log('pending', 'finance_bulk_reminder');
        $provider = $this->openProvider();
        $provider->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'error' => 'timeout',
            'delivery_unknown' => true,
        ]);
        $bulk = Mockery::mock(FinanceBulkWaService::class);
        $bulk->shouldReceive('isStillEligible')->andReturn(true);

        $job = (new SendFinanceBulkWaJob($log->id, '081200000001', 'tagihan'))->withFakeQueueInteractions();
        $job->handle($provider, app(IndonesianPhoneNormalizer::class), $bulk);

        $job->assertNotReleased();
        $this->assertSame('unconfirmed', $log->fresh()->status);
    }

    public function test_finance_bulk_job_skips_log_already_sent(): void
    {
        $log = $this->log('sent', 'finance_bulk_reminder');
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldNotReceive('send');

        (new SendFinanceBulkWaJob($log->id, '081200000001', 'tagihan'))
            ->handle($provider, app(IndonesianPhoneNormalizer::class), app(FinanceBulkWaService::class));

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_evolution_send_read_timeout_is_reported_as_delivery_unknown(): void
    {
        $this->fakeEvolution(new ConnectionException('cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received'));

        $result = app(EvolutionApiService::class)->forInstance('fake-billing')->send('081234567890', 'halo');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['delivery_unknown']);
    }

    public function test_evolution_connect_failure_is_retryable(): void
    {
        $this->fakeEvolution(new ConnectionException('cURL error 28: Connection timed out after 5001 milliseconds'));

        $result = app(EvolutionApiService::class)->forInstance('fake-billing')->send('081234567890', 'halo');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['delivery_unknown']);
    }

    public function test_evolution_http_client_has_explicit_timeouts(): void
    {
        config([
            'services.evolution_api.connect_timeout' => 4,
            'services.evolution_api.admin_timeout' => 12,
        ]);
        $service = new EvolutionApiService('fake-billing');

        $admin = (fn () => $this->evoHttp())->call($service)->getOptions();
        $send = (fn () => $this->evoHttp(33))->call($service)->getOptions();

        $this->assertSame(4, $admin['connect_timeout']);
        $this->assertSame(12, $admin['timeout']);
        $this->assertSame(33, $send['timeout']);
    }

    private function fakeEvolution(?Throwable $sendException): void
    {
        config([
            'services.evolution_api.api_key' => 'fake-key',
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.delay_ms' => 0,
        ]);
        Queue::fake();

        Http::fake(function ($request) use ($sendException) {
            if (str_contains($request->url(), '/instance/connectionState/')) {
                return Http::response(['instance' => ['state' => 'open']]);
            }
            if (str_contains($request->url(), '/message/sendText/')) {
                if ($sendException) {
                    throw $sendException;
                }

                return Http::response(['key' => ['id' => 'fake-id']], 201);
            }

            return Http::response([], 404);
        });
    }

    private function openProvider(): WaProvider
    {
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldReceive('connectionState')->andReturn('open');

        return $provider;
    }

    private function log(string $status, string $event = 'payment'): BillingWaLog
    {
        return BillingWaLog::create([
            'event' => $event,
            'phone' => '08******01',
            'message' => 'tagihan',
            'status' => $status,
            'dedupe_key' => 'retry-safety:'.uniqid('', true),
        ]);
    }
}
