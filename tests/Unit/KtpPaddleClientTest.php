<?php

namespace Tests\Unit;

use App\Services\KtpOcrService;
use App\Support\OcrBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PaddleOCR client: one inference at a time (lock), 503 backoff inside the
 * budget, and a breaker after repeated timeouts. Fallback only: never blocks PSB.
 */
#[Group('blocking-psb')]
class KtpPaddleClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ocrspace.paddle_enabled' => true,
            'services.ocrspace.paddle_url' => 'http://paddle.test/ocr',
            'services.ocrspace.paddle_timeout' => 30,
            'services.ocrspace.paddle_lock_wait_seconds' => 1,
            'services.ocrspace.paddle_breaker_failures' => 3,
            'services.ocrspace.paddle_breaker_seconds' => 180,
            // The older tests below cover the pre-drain behaviour; the new ones set these.
            'services.ocrspace.paddle_min_timeout_seconds' => 0,
            'services.ocrspace.paddle_drain_seconds' => 0,
        ]);
        Cache::flush();
    }

    private function client(): PaddleHarness
    {
        return new PaddleHarness;
    }

    public function test_503_then_200_returns_text_after_a_backoff(): void
    {
        Http::fake(['paddle.test/*' => Http::sequence()
            ->push('busy', 503)
            ->push(['text' => 'NIK: 3507120110000002'], 200)]);
        $svc = $this->client();

        $text = $svc->paddle(OcrBudget::start(60));

        $this->assertSame('NIK: 3507120110000002', $text);
        $this->assertSame('ok', $svc->outcome());
        $this->assertCount(1, $svc->slept);
        $this->assertGreaterThanOrEqual(2.0, $svc->slept[0]);
        Http::assertSentCount(2);
    }

    public function test_503_forever_stays_busy_and_inside_the_budget(): void
    {
        Http::fake(['paddle.test/*' => Http::response('busy', 503)]);
        $svc = $this->client();

        $text = $svc->paddle(OcrBudget::start(60));

        $this->assertNull($text);
        $this->assertSame('busy', $svc->outcome());
        Http::assertSentCount(3); // first call + two backoffs, never more
        $this->assertLessThanOrEqual(8.0, array_sum($svc->slept));
    }

    public function test_503_with_a_small_budget_does_not_back_off(): void
    {
        Http::fake(['paddle.test/*' => Http::response('busy', 503)]);
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(5)));

        $this->assertSame('busy', $svc->outcome());
        $this->assertSame([], $svc->slept);
        Http::assertSentCount(1);
    }

    public function test_timeouts_open_the_breaker_and_then_paddle_is_not_called(): void
    {
        $calls = 0;
        Http::fake(['paddle.test/*' => function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        }]);

        for ($i = 0; $i < 3; $i++) {
            $svc = $this->client();
            $this->assertNull($svc->paddle(OcrBudget::start(60)));
            $this->assertSame('timeout', $svc->outcome());
        }
        $this->assertSame(3, $calls);

        $svc = $this->client();
        $this->assertNull($svc->paddle(OcrBudget::start(60)));
        $this->assertSame('breaker_open', $svc->outcome());
        $this->assertSame(3, $calls); // the 4th call never reached Paddle
    }

    public function test_a_successful_call_resets_the_failure_count(): void
    {
        Http::fake(['paddle.test/*' => Http::sequence()
            ->push('x', 500)
            ->push('x', 500)
            ->push(['text' => 'NIK'], 200)
            ->push('x', 500)
            ->push('x', 500)]);

        foreach ([null, null, 'NIK', null, null] as $expected) {
            $svc = $this->client();
            $this->assertSame($expected, $svc->paddle(OcrBudget::start(60)));
        }
        // 2 failures, success, 2 failures: the count restarted, the breaker (3) never opened.
        $this->assertNull(Cache::get('ocr:paddle:breaker_until'));
        $this->assertSame(2, (int) Cache::get('ocr:paddle:failures'));
    }

    public function test_a_held_lock_makes_the_caller_wait_a_bounded_time_and_report_busy(): void
    {
        Http::fake(['paddle.test/*' => Http::response(['text' => 'NIK'], 200)]);
        $held = Cache::lock('ocr:paddle:inference', 60);
        $this->assertTrue($held->get());
        $svc = $this->client();

        $started = microtime(true);
        $text = $svc->paddle(OcrBudget::start(120));
        $waited = microtime(true) - $started;

        $this->assertNull($text);
        $this->assertSame('busy', $svc->outcome());
        $this->assertLessThan(5.0, $waited);
        Http::assertNothingSent();

        $held->release();
        $this->assertSame('NIK', $this->client()->paddle(OcrBudget::start(120)));
    }

    public function test_no_time_left_skips_paddle(): void
    {
        Http::fake();
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(2)));

        $this->assertSame('skipped', $svc->outcome());
        Http::assertNothingSent();
    }

    private function enableDrain(): void
    {
        config([
            'services.ocrspace.paddle_min_timeout_seconds' => 15,
            'services.ocrspace.paddle_drain_seconds' => 30,
        ]);
    }

    public function test_503_then_success_on_retry_returns_the_text_and_does_not_drain(): void
    {
        $this->enableDrain();
        $health = 0;
        $ocr = 0;
        Http::fake(function ($request) use (&$health, &$ocr) {
            if (str_ends_with($request->url(), '/health')) {
                $health++;

                return Http::response(['busy' => false], 200);
            }
            $ocr++;

            return $ocr === 1 ? Http::response('busy', 503) : Http::response(['text' => 'NIK: 3507120110000002'], 200);
        });
        $svc = $this->client();

        $text = $svc->paddle(OcrBudget::start(60));

        $this->assertSame('NIK: 3507120110000002', $text);
        $this->assertSame('ok', $svc->outcome());
        $this->assertSame(2, $ocr);
        $this->assertSame(0, $health, 'a successful retry needs no drain');
        $this->assertNull(Cache::get('ocr:paddle:failures'));
    }

    public function test_timeout_keeps_the_lock_until_paddle_reports_idle_and_does_not_count_for_the_breaker(): void
    {
        $this->enableDrain();
        $healthCalls = 0;
        $lockFreeDuringDrain = [];
        Http::fake(function ($request) use (&$healthCalls, &$lockFreeDuringDrain) {
            if (str_ends_with($request->url(), '/health')) {
                $healthCalls++;
                // Probe: can a second caller take the Paddle lock right now?
                $probe = Cache::lock('ocr:paddle:inference', 5);
                $got = $probe->get();
                $lockFreeDuringDrain[] = $got;
                if ($got) {
                    $probe->release();
                }

                return Http::response(['busy' => $healthCalls < 3], 200);
            }

            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(60)));

        $this->assertSame('timeout', $svc->outcome());
        $this->assertSame(3, $healthCalls); // busy, busy, idle
        $this->assertSame([false, false, false], $lockFreeDuringDrain, 'lock must be held while Paddle still runs');
        $this->assertCount(2, $svc->slept);
        // Released only after idle.
        $probe = Cache::lock('ocr:paddle:inference', 5);
        $this->assertTrue($probe->get());
        $probe->release();
        // Drained = load, not a Paddle failure.
        $this->assertNull(Cache::get('ocr:paddle:failures'));
        $this->assertNull(Cache::get('ocr:paddle:breaker_until'));
    }

    public function test_drain_also_waits_for_queued_requests_on_the_server(): void
    {
        $this->enableDrain();
        $healthCalls = 0;
        Http::fake(function ($request) use (&$healthCalls) {
            if (str_ends_with($request->url(), '/health')) {
                $healthCalls++;

                // Not running, but one request is still queued (a caller that gave up).
                return Http::response(['busy' => false, 'waiting' => $healthCalls < 3 ? 1 : 0], 200);
            }

            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(60)));

        $this->assertSame(3, $healthCalls);
        $this->assertNull(Cache::get('ocr:paddle:failures'));
    }

    public function test_timeout_that_never_drains_counts_for_the_breaker_and_still_releases_the_lock(): void
    {
        config([
            'services.ocrspace.paddle_min_timeout_seconds' => 15,
            'services.ocrspace.paddle_drain_seconds' => 2,
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/health')) {
                return Http::response(['busy' => true], 200);
            }

            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(60)));

        $this->assertSame('timeout', $svc->outcome());
        $this->assertSame(1, (int) Cache::get('ocr:paddle:failures'));
        $probe = Cache::lock('ocr:paddle:inference', 5);
        $this->assertTrue($probe->get());
        $probe->release();
    }

    public function test_less_time_than_the_minimum_makes_no_call_and_is_skipped_short(): void
    {
        $this->enableDrain();
        Http::fake();
        $svc = $this->client();

        $this->assertNull($svc->paddle(OcrBudget::start(4)));

        $this->assertSame('skipped_short', $svc->outcome());
        Http::assertNothingSent();
        $this->assertNull(Cache::get('ocr:paddle:failures'));
    }

    public function test_the_lock_is_released_after_a_call(): void
    {
        Http::fake(['paddle.test/*' => Http::response(['text' => 'NIK'], 200)]);
        $this->client()->paddle(OcrBudget::start(60));

        $probe = Cache::lock('ocr:paddle:inference', 5);
        $this->assertTrue($probe->get());
        $probe->release();
    }
}

class PaddleHarness extends KtpOcrService
{
    /** @var list<float> */
    public array $slept = [];

    public function paddle(?OcrBudget $budget): ?string
    {
        $this->activeBudget = $budget;
        $this->paddleOutcome = 'skipped';

        try {
            return $this->callPaddleOcr('bytes', 'ktp.jpg');
        } finally {
            $this->activeBudget = null;
        }
    }

    public function outcome(): string
    {
        return $this->paddleOutcome;
    }

    protected function sleepFor(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
