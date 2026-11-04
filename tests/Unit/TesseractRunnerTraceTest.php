<?php

namespace Tests\Unit;

use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * extractBestTrace: a timed-out run is reported, never repeated, and stops the
 * PSM loop; a fast empty run only continues when the time left covers another.
 */
#[Group('blocking-psb')]
class TesseractRunnerTraceTest extends TestCase
{
    /** @var list<array{cmd:list<string>,timeout:int}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        TesseractRunner::fakeRunner(null);
        parent::tearDown();
    }

    /** @param list<array{text:?string,timed_out:bool,ms:int}> $tesseractRuns */
    private function fake(array $tesseractRuns): void
    {
        $this->calls = [];
        TesseractRunner::fakeRunner(function (array $cmd, int $timeout) use (&$tesseractRuns): array {
            $this->calls[] = ['cmd' => $cmd, 'timeout' => $timeout];
            if ($cmd[0] === 'tesseract') {
                return array_shift($tesseractRuns) ?? ['text' => null, 'timed_out' => false, 'ms' => 10];
            }

            // magick -version / the resize pass: report "no output" so the raw image is used.
            return ['text' => $cmd[1] === '-version' ? 'ImageMagick 7' : null, 'timed_out' => false, 'ms' => 5];
        });
    }

    private function tesseractCalls(): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c['cmd'][0] === 'tesseract'));
    }

    public function test_a_timed_out_run_is_flagged_and_not_repeated(): void
    {
        $this->fake([
            ['text' => null, 'timed_out' => true, 'ms' => 45000],
            ['text' => 'NIK 3507120110000002', 'timed_out' => false, 'ms' => 1000],
        ]);

        $trace = TesseractRunner::extractBestTrace('bytes-not-an-image', 'ind+eng', 45, [3, 6, 11]);

        $this->assertTrue($trace['timed_out']);
        $this->assertSame('', $trace['text']);
        $this->assertCount(1, $trace['runs']);
        $this->assertTrue($trace['runs'][0]['timed_out']);
        $this->assertSame(3, $trace['runs'][0]['psm']);
        $this->assertSame(1, $this->tesseractCalls());
    }

    public function test_a_fast_empty_run_moves_on_to_the_next_psm(): void
    {
        $this->fake([
            ['text' => null, 'timed_out' => false, 'ms' => 800],
            ['text' => 'REPUBLIK INDONESIA NIK 3507120110000002', 'timed_out' => false, 'ms' => 900],
            ['text' => 'never reached', 'timed_out' => false, 'ms' => 900],
        ]);

        $trace = TesseractRunner::extractBestTrace('bytes-not-an-image', 'ind+eng', 45, [3, 6, 11]);

        $this->assertFalse($trace['timed_out']);
        $this->assertStringContainsString('NIK 3507120110000002', $trace['text']);
        $this->assertSame([3, 6], array_column($trace['runs'], 'psm'));
        $this->assertSame(2, $this->tesseractCalls());
    }

    public function test_the_empty_run_is_not_repeated_when_time_left_is_short(): void
    {
        $this->fake([
            ['text' => null, 'timed_out' => false, 'ms' => 40000],
            ['text' => 'late', 'timed_out' => false, 'ms' => 100],
        ]);
        // 50 s left, the last run took 40 s: 1.5 x 40 = 60 s would not fit.
        $trace = TesseractRunner::extractBestTrace('bytes-not-an-image', 'ind+eng', 45, [3, 6, 11], null, microtime(true) + 50);

        $this->assertTrue($trace['deadline_hit']);
        $this->assertSame('', $trace['text']);
        $this->assertSame(1, $this->tesseractCalls());
    }

    public function test_nothing_starts_after_the_deadline(): void
    {
        $this->fake([['text' => 'x', 'timed_out' => false, 'ms' => 1]]);

        $trace = TesseractRunner::extractBestTrace('bytes-not-an-image', 'ind+eng', 45, [3, 6, 11], null, microtime(true) - 1);

        $this->assertTrue($trace['deadline_hit']);
        $this->assertSame([], $trace['runs']);
        $this->assertSame(0, $this->tesseractCalls());
        // No image preparation once the budget is spent.
        $this->assertSame([], $this->calls);
    }

    public function test_run_timeout_is_clamped_and_follows_the_deadline(): void
    {
        $this->fake([['text' => 'NIK', 'timed_out' => false, 'ms' => 10]]);
        TesseractRunner::extractBestTrace('bytes-not-an-image', 'eng', 1, [3]);
        $this->assertSame(5, $this->tesseractCallTimeout());

        $this->fake([['text' => 'NIK', 'timed_out' => false, 'ms' => 10]]);
        TesseractRunner::extractBestTrace('bytes-not-an-image', 'eng', 500, [3]);
        $this->assertSame(60, $this->tesseractCallTimeout());

        $this->fake([['text' => 'NIK', 'timed_out' => false, 'ms' => 10]]);
        TesseractRunner::extractBestTrace('bytes-not-an-image', 'eng', 45, [3], null, microtime(true) + 12.2);
        $this->assertSame(13, $this->tesseractCallTimeout());
    }

    private function tesseractCallTimeout(): int
    {
        foreach ($this->calls as $c) {
            if ($c['cmd'][0] === 'tesseract') {
                return $c['timeout'];
            }
        }

        return -1;
    }

    public function test_image_is_prepared_once_for_all_psms(): void
    {
        $this->fake([
            ['text' => null, 'timed_out' => false, 'ms' => 100],
            ['text' => null, 'timed_out' => false, 'ms' => 100],
            ['text' => null, 'timed_out' => false, 'ms' => 100],
        ]);

        TesseractRunner::extractBestTrace('bytes-not-an-image', 'ind+eng', 45, [3, 6, 11]);

        $prep = array_filter($this->calls, static fn (array $c): bool => $c['cmd'][0] === 'magick' && $c['cmd'][1] !== '-version');
        $this->assertCount(1, $prep);
        $this->assertSame(3, $this->tesseractCalls());
    }

    public function test_early_stop_on_a_strong_signal_and_old_wrapper_contract(): void
    {
        $this->fake([
            ['text' => 'PROVINSI JAWA TIMUR', 'timed_out' => false, 'ms' => 100],
            ['text' => 'more', 'timed_out' => false, 'ms' => 100],
        ]);

        $this->assertSame('PROVINSI JAWA TIMUR', TesseractRunner::extractBest('bytes-not-an-image', 'eng', 20));
        $this->assertSame(1, $this->tesseractCalls());

        $this->fake([['text' => null, 'timed_out' => true, 'ms' => 20000]]);
        $this->assertNull(TesseractRunner::extractBest('bytes-not-an-image', 'eng', 20, [3, 6]));
    }

    public function test_ocr_budget_with_injected_clock(): void
    {
        $now = 1000.0;
        $budget = OcrBudget::start(90, function () use (&$now): float {
            return $now;
        });

        $this->assertSame(1090.0, $budget->deadline());
        $this->assertEqualsWithDelta(90.0, $budget->remaining(), 0.001);
        $now = 1060.0;
        $this->assertEqualsWithDelta(30.0, $budget->remaining(), 0.001);
        $this->assertEqualsWithDelta(30.0, $budget->forImage(90)->remaining(), 0.001);
        $this->assertEqualsWithDelta(20.0, $budget->forImage(20)->remaining(), 0.001);
        $this->assertFalse($budget->expired());
        $now = 1091.0;
        $this->assertTrue($budget->expired());
        $this->assertSame(0.0, $budget->remaining());
        $this->assertSame(1, $budget->remainingSeconds());
    }
}
