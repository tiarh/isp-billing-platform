<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\PsbDraft;
use App\Services\PsbMediaOcrOrchestrator;
use App\Support\GdImageMemoryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class ProcessPsbMediaOcrJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_job_only_classifies_media_then_dispatches_psb_continuation(): void
    {
        Queue::fake();
        config([
            'services.ocrspace.media_fast_path' => false,
            'services.ocrspace.paddle_timeout' => 45,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR QUEUE TEST',
            'meta' => ['ocr_batch_key' => 'batch-1'],
        ]);
        $continuation = ['mode' => 'inbound', 'batch_key' => 'batch-1'];
        $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
        $orchestrator->expects(self::once())
            ->method('processStagedMediaOcr')
            ->with(
                self::callback(fn (PsbDraft $value): bool => $value->is($draft)),
                ['staged/path.bin'],
                [],
                self::callback(static fn (array $value): bool => ($value['defer_continuation'] ?? false) === true
                    && $value['batch_key'] === 'batch-1'),
            )
            ->willReturnCallback(function () use ($draft): PsbDraft {
                self::assertTrue(config('services.ocrspace.media_fast_path'));
                self::assertSame(30, config('services.ocrspace.paddle_timeout'));

                return $draft;
            });

        (new ProcessPsbMediaOcrJob(
            $draft->id,
            ['staged/path.bin'],
            [],
            $continuation,
        ))->handle($orchestrator);

        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class, function (ProcessPsbAfterMediaOcrJob $job) use ($draft, $continuation): bool {
            return $job->draftId === $draft->id
                && $job->continuation === $continuation;
        });
        $this->assertFalse(config('services.ocrspace.media_fast_path'));
        $this->assertSame(45, config('services.ocrspace.paddle_timeout'));
    }

    public function test_handle_raises_memory_limit_and_registers_fatal_lock_release(): void
    {
        Queue::fake();
        $original = (string) ini_get('memory_limit');
        $current = GdImageMemoryGuard::parseBytes($original);
        if ($current <= 0) {
            $this->markTestSkipped('memory_limit is unlimited here');
        }
        $target = $current + 64 * 1024 * 1024;
        config(['services.ocrspace.media_memory_limit' => (string) $target]);

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR FATAL TEST',
        ]);
        $key = 'psb-media-ocr-draft:'.$draft->id;
        $registered = [];
        ProcessPsbMediaOcrJob::fakeShutdownRegistrar(function (\Closure $fn) use (&$registered): void {
            $registered[] = $fn;
        });

        $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
        $orchestrator->expects(self::once())
            ->method('processStagedMediaOcr')
            ->willReturnCallback(function () use ($draft, &$registered, $key): PsbDraft {
                // The job holds the lock here. Simulate the process dying on a fatal.
                $this->assertCount(1, $registered);
                $this->assertFalse(Cache::lock($key, 5)->get(), 'lock is held while the job runs');
                $registered[0](['type' => E_ERROR, 'message' => 'Allowed memory size of 1 bytes exhausted']);
                $again = Cache::lock($key, 5);
                $this->assertTrue($again->get(), 'fatal handler must have freed the draft lock');
                $again->release();

                return $draft;
            });

        try {
            (new ProcessPsbMediaOcrJob($draft->id, ['staged/path.bin']))->handle($orchestrator);

            $this->assertGreaterThanOrEqual($target, GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit')));
        } finally {
            ProcessPsbMediaOcrJob::fakeShutdownRegistrar(null);
            @ini_set('memory_limit', $original);
        }
    }

    public function test_ocr_job_releases_instead_of_failing_when_draft_lock_is_busy(): void
    {
        Queue::fake();
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR LOCK TEST',
        ]);

        $held = Cache::lock('psb-media-ocr-draft:'.$draft->id, 420);
        $this->assertTrue($held->get());

        try {
            $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
            $orchestrator->expects(self::never())->method('processStagedMediaOcr');

            // Must not throw LockTimeoutException: a busy draft lock is a
            // duplicate batch, not a failed ocr-media job.
            (new ProcessPsbMediaOcrJob($draft->id, ['staged/path.bin']))->handle($orchestrator);
        } finally {
            $held->release();
        }

        Queue::assertNotPushed(ProcessPsbAfterMediaOcrJob::class);
    }

    public function test_paddle_cap_is_configurable_and_clamped(): void
    {
        config(['services.ocrspace.media_paddle_timeout' => 30]);
        $this->assertSame(30, ProcessPsbMediaOcrJob::paddleCap());
        config(['services.ocrspace.media_paddle_timeout' => 1]);
        $this->assertSame(5, ProcessPsbMediaOcrJob::paddleCap());
        config(['services.ocrspace.media_paddle_timeout' => 600]);
        $this->assertSame(45, ProcessPsbMediaOcrJob::paddleCap());
    }

    public function test_job_applies_paddle_cap_and_keeps_batch_budget_above_two_attempts_per_image(): void
    {
        Queue::fake();
        config([
            'services.ocrspace.media_paddle_timeout' => 40,
            'services.ocrspace.paddle_timeout' => 45,
            'services.ocrspace.ocr_total_budget_seconds' => 120,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR CAP TEST',
            'meta' => ['ocr_batch_key' => 'batch-1'],
        ]);
        $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
        $orchestrator->method('processStagedMediaOcr')->willReturnCallback(function () use ($draft): PsbDraft {
            self::assertSame(40, config('services.ocrspace.paddle_timeout'));
            self::assertGreaterThanOrEqual(160, config('services.ocrspace.ocr_total_budget_seconds'));
            self::assertLessThan(600, config('services.ocrspace.ocr_total_budget_seconds'));

            return $draft;
        });

        (new ProcessPsbMediaOcrJob($draft->id, ['staged/path.bin'], [], ['batch_key' => 'batch-1']))->handle($orchestrator);

        $this->assertSame(45, config('services.ocrspace.paddle_timeout'));
        $this->assertSame(120, config('services.ocrspace.ocr_total_budget_seconds'));
    }

    public function test_stale_batch_with_missing_files_is_skipped_without_exception_or_gate_write(): void
    {
        Queue::fake();
        $gate = ['status' => 'ocr_pending', 'reason' => 'queued_ocr_media'];
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR STALE TEST',
            'meta' => ['ocr_batch_key' => 'K2', 'document_gate' => $gate],
        ]);
        $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
        $orchestrator->expects(self::never())->method('processStagedMediaOcr');

        (new ProcessPsbMediaOcrJob($draft->id, ['gone/a.bin'], [], ['batch_key' => 'K1']))->handle($orchestrator);

        $this->assertEquals($gate, $draft->fresh()->meta['document_gate']);
        Queue::assertNotPushed(ProcessPsbAfterMediaOcrJob::class);
    }

    public function test_live_batch_with_missing_files_still_fails(): void
    {
        Queue::fake();
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR LIVE TEST',
            'meta' => ['ocr_batch_key' => 'K1'],
        ]);
        $orchestrator = $this->createMock(PsbMediaOcrOrchestrator::class);
        $orchestrator->method('processStagedMediaOcr')->willThrowException(new \RuntimeException('Tidak ada media OCR yang bisa dibaca.'));

        $this->expectException(\RuntimeException::class);
        (new ProcessPsbMediaOcrJob($draft->id, ['gone/a.bin'], [], ['batch_key' => 'K1']))->handle($orchestrator);
    }
}
