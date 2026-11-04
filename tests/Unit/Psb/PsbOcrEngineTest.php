<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\KtpOcrService;
use App\Services\Psb\PsbOcrEngine;
use App\Services\Psb\PsbOcrReaders;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbOrchestratorService;
use App\Support\OcrBudget;
use Closure;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\MarkerKtpOcr;
use Tests\Support\MarkerOcr;
use Tests\Support\PsbCollaborators;
use Tests\TestCase;

/**
 * The OCR engine keeps its state per instance and resets it the same way the old
 * orchestrator code did. These tests build the engine directly with fake readers.
 */
#[Group('blocking-psb')]
class PsbOcrEngineTest extends TestCase
{
    /** @var list<array{language:string,run_cap:int,deadline:?float}> */
    private array $readerCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->readerCalls = [];
        config([
            'services.ocrspace.ocr_async_job' => false,
            'services.ocrspace.media_hard_guard' => false,
            'services.ocrspace.media_image_budget_seconds' => 90,
            'services.ocrspace.ocr_total_budget_seconds' => 90,
            'services.ocrspace.tesseract_run_cap' => 45,
            'services.ocrspace.tesseract_timeout' => 30,
        ]);
    }

    /** A reader that reads marker text and records every call. */
    private function recordingReader(?Closure $trace = null): Closure
    {
        return function (string $bytes, string $language, int $runCap, array $psms, ?float $deadline) use ($trace): array {
            $this->readerCalls[] = ['language' => $language, 'run_cap' => $runCap, 'deadline' => $deadline];
            if ($trace !== null) {
                return $trace($bytes, count($this->readerCalls));
            }

            return ['text' => (string) (MarkerOcr::text($bytes) ?? ''), 'runs' => [], 'timed_out' => false, 'deadline_hit' => false];
        };
    }

    private function engine(
        ?Closure $language = null,
        ?Closure $tesseract = null,
        ?Closure $clock = null,
        ?KtpOcrService $ktp = null,
    ): PsbOcrEngine {
        $ktp ??= new MarkerKtpOcr;

        return PsbCollaborators::ocrEngine($ktp, new PsbOcrReaders(
            tesseractLanguage: $language ?? static fn (): ?string => 'eng+ind',
            tesseract: $tesseract ?? $this->recordingReader(),
            clock: $clock,
        ));
    }

    private function image(string $marker, string $tag = ''): string
    {
        return $marker.$tag.str_repeat('x', 200);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    public function test_engine_is_final_has_no_statics_and_keeps_everything_private(): void
    {
        $class = new ReflectionClass(PsbOcrEngine::class);

        $this->assertTrue($class->isFinal());
        $this->assertSame([], $class->getProperties(ReflectionProperty::IS_STATIC));
        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), $property->getName().' must be private');
        }
        $public = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            $class->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        $this->assertEqualsCanonicalizing(
            ['__construct', 'attachPhotosAndOcr', 'classifyBatch', 'applyBatch', 'classifyWaActivationPhoto'],
            $public
        );
    }

    public function test_paddle_allowed_is_true_again_after_a_batch_that_hit_the_paddle_limit(): void
    {
        config([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_max_calls_per_batch' => 1,
        ]);
        $ktp = new PaddleRecordingKtpOcr;
        $engine = $this->engine(ktp: $ktp);

        $batch = $engine->classifyBatch(new PsbDraft, [
            $this->image('KTP_MARKER', 'a'),
            $this->image('KTP_MARKER', 'b'),
            $this->image('KTP_MARKER', 'c'),
        ]);

        $this->assertNotNull($batch);
        $this->assertSame([true, false, false], $ktp->allowPaddle);
        $this->assertTrue($this->property($engine, 'paddleAllowed'));
        $this->assertNull($this->property($engine, 'imageBudget'));
    }

    public function test_run_cap_override_is_cleared_after_a_migration_photo(): void
    {
        $engine = $this->engine();

        $engine->classifyWaActivationPhoto($this->image('SN_MARKER'));
        $engine->classifyBatch(new PsbDraft, [$this->image('SN_MARKER', 'b')]);

        // Migration photo: tesseract_run_cap (45). Synchronous batch afterwards: the old 15 s clamp.
        $this->assertSame([45, 15], array_column($this->readerCalls, 'run_cap'));
        $this->assertNull($this->property($engine, 'runCapOverride'));
    }

    public function test_a_reader_that_threw_in_pass_one_does_not_leak_its_budget_into_the_next_photo(): void
    {
        config(['services.ocrspace.ocr_async_job' => true]);
        $throw = true;
        $engine = $this->engine(tesseract: $this->recordingReader(function (string $bytes, int $call) use (&$throw): array {
            if ($throw) {
                throw new \RuntimeException('reader down');
            }

            return ['text' => '', 'runs' => [], 'timed_out' => false, 'deadline_hit' => false];
        }));

        try {
            $engine->classifyBatch(new PsbDraft, [$this->image('SN_MARKER')]);
            $this->fail('the reader error must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('reader down', $e->getMessage());
        }

        // Pass-1 nuance: nothing resets the budget on a throw, so it stays set (leaked)
        // until the next read overwrites it.
        $this->assertNotNull($this->property($engine, 'imageBudget'));

        $throw = false;
        $before = microtime(true);
        $result = $engine->classifyWaActivationPhoto($this->image('SN_MARKER', 'b'));

        $this->assertSame('terminal', $result['classification_status']);
        $second = $this->readerCalls[1];
        // A fresh 90 s budget for the new photo, not what was left of the failed one.
        $this->assertGreaterThan($before + 85, (float) $second['deadline']);
        $this->assertNull($this->property($engine, 'imageBudget'));
    }

    public function test_trace_from_a_timed_out_run_does_not_leak_into_a_run_without_language(): void
    {
        $language = 'eng+ind';
        $engine = $this->engine(
            language: function () use (&$language): ?string {
                return $language;
            },
            tesseract: $this->recordingReader(fn (string $bytes, int $call): array => ['text' => '', 'runs' => [], 'timed_out' => true, 'deadline_hit' => false]),
        );

        $first = $engine->classifyWaActivationPhoto($this->image('KTP_MARKER'));
        $this->assertTrue($first['ocr_degraded']);

        $language = null;
        $second = $engine->classifyWaActivationPhoto($this->image('KTP_MARKER', 'b'));

        $this->assertFalse($second['ocr_degraded']);
        $this->assertCount(1, $this->readerCalls);
        $this->assertNull($this->property($engine, 'lastTesseractTrace'));
    }

    public function test_language_closure_is_evaluated_for_every_run(): void
    {
        $languages = ['eng', 'ind+eng'];
        $engine = $this->engine(language: function () use (&$languages): ?string {
            return array_shift($languages);
        });

        $engine->classifyBatch(new PsbDraft, [$this->image('SN_MARKER', 'a'), $this->image('SN_MARKER', 'b')]);

        $this->assertSame(['eng', 'ind+eng'], array_column($this->readerCalls, 'language'));
        $this->assertSame([], $languages);
    }

    public function test_no_language_means_no_reader_call_empty_text_and_no_trace(): void
    {
        $engine = $this->engine(language: static fn (): ?string => null);

        $batch = $engine->classifyBatch(new PsbDraft, [$this->image('SN_MARKER')]);

        $this->assertSame([], $this->readerCalls);
        $this->assertNotNull($batch);
        $this->assertSame(['no_text'], $batch['statuses']);
        $this->assertNull($batch['ktpPick']);
        $this->assertNull($batch['snPick']);
        $this->assertNull($this->property($engine, 'lastTesseractTrace'));
    }

    public function test_ktp_score_fallback_reads_the_image_with_no_deadline(): void
    {
        $engine = $this->engine();
        $method = new ReflectionMethod($engine, 'scoreKtpPhoto');

        $score = $method->invoke($engine, $this->image('SN_MARKER'), [], null);

        $this->assertIsInt($score);
        $this->assertCount(1, $this->readerCalls);
        $this->assertNull($this->readerCalls[0]['deadline']);
        $this->assertSame('eng+ind', $this->readerCalls[0]['language']);
    }

    public function test_each_container_orchestrator_gets_its_own_engine(): void
    {
        $one = app(PsbMediaOcrOrchestrator::class);
        $two = app(PsbMediaOcrOrchestrator::class);
        $method = new ReflectionMethod(PsbOrchestratorService::class, 'ocrEngine');

        $engineOne = $method->invoke($one);
        $engineTwo = $method->invoke($two);

        $this->assertInstanceOf(PsbOcrEngine::class, $engineOne);
        $this->assertNotSame($engineOne, $engineTwo);
        $this->assertSame($engineOne, $method->invoke($one));
    }

    public function test_batch_budget_uses_the_clock_from_the_readers(): void
    {
        config(['services.ocrspace.ocr_total_budget_seconds' => 100]);
        $engine = $this->engine(clock: static fn (): float => 1000.0);

        $batch = $engine->classifyBatch(new PsbDraft, [$this->image('SN_MARKER')]);

        $this->assertNotNull($batch);
        $this->assertSame(1100.0, $batch['deadline']);
    }
}

/** KTP reader double that records whether the engine allowed a Paddle call, and reports one call as made. */
class PaddleRecordingKtpOcr extends KtpOcrService
{
    /** @var list<bool> */
    public array $allowPaddle = [];

    public function parseKtpFast(
        UploadedFile $file,
        ?string $localRawText = null,
        ?OcrBudget $budget = null,
        bool $localTried = true,
        bool $allowPaddle = true,
    ): array {
        $this->allowPaddle[] = $allowPaddle;

        return ['paddle_outcome' => 'ok'];
    }
}
