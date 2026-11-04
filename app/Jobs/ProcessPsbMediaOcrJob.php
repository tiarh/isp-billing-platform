<?php

namespace App\Jobs;

use App\Models\PsbDraft;
use App\Services\PsbMediaOcrOrchestrator;
use App\Support\GdImageMemoryGuard;
use App\Support\PsbOcrBatchLedger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs the expensive KTP/SN/ODP classification outside the PSB acknowledgement
 * worker. The job is deliberately resumable and never performs device I/O by
 * itself; the orchestrator decides whether the document gate allows it later.
 */
class ProcessPsbMediaOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $maxExceptions = 1;

    // 600s, under the worker's --timeout=660. A 300s budget was exceeded on a
    // loaded host (media download + OCR + post-processing), which killed the job
    // before it could dispatch the post-OCR continuation -> draft stuck at
    // 'received' with document_gate=ocr_pending and no job left in the queue.
    public int $timeout = 600;

    public int $uniqueFor = 600;

    /**
     * @param  list<string>  $stagedPaths
     * @param  list<array<string,mixed>>  $contexts
     * @param  array<string,mixed>  $continuation
     */
    public function __construct(
        public int $draftId,
        public array $stagedPaths,
        public array $contexts = [],
        public array $continuation = [],
    ) {
        $this->onQueue('ocr-media');
    }

    public function uniqueId(): string
    {
        // The automatic re-check is dispatched from inside the first job, which
        // still holds the unique lock: it needs its own id or it would be dropped.
        return 'psb-media-ocr:'.$this->draftId.':'.(
            (string) ($this->continuation['batch_key'] ?? sha1(implode('|', $this->stagedPaths)))
        ).($this->isRetry() ? ':r1' : '');
    }

    private function isRetry(): bool
    {
        return (int) ($this->continuation['ocr_retry'] ?? 0) === 1;
    }

    /** @var (\Closure(\Closure):mixed)|null Test seam for register_shutdown_function. */
    private static ?\Closure $shutdownRegistrar = null;

    /** @param  (\Closure(\Closure):mixed)|null  $registrar */
    public static function fakeShutdownRegistrar(?\Closure $registrar): void
    {
        self::$shutdownRegistrar = $registrar;
    }

    /**
     * Raise PHP memory_limit to the configured value, only when the current
     * limit is lower. Never lowers it; -1 (unlimited) is left alone.
     */
    public static function raiseMemoryLimit(?string $configured = null): void
    {
        $target = GdImageMemoryGuard::parseBytes((string) ($configured ?? config('services.ocrspace.media_memory_limit', '512M')));
        $current = GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit'));
        if ($target > 0 && $current > 0 && $current < $target) {
            @ini_set('memory_limit', (string) $target);
        }
    }

    /**
     * Shutdown handler: when the process dies on a fatal error (for example
     * out of memory) while it holds the draft lock, free the lock so a resend is
     * not blocked until the TTL. No-op after the normal release, on a clean
     * exit, and when another owner holds the lock.
     *
     * @param  array{type?:int,message?:string}|null  $error  error_get_last() shape
     */
    public static function releaseLockOnFatal(string $lockKey, string $owner, object $state, ?array $error): bool
    {
        if (($state->done ?? false) === true) {
            return false;
        }
        $fatal = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_PARSE | E_RECOVERABLE_ERROR;
        if ($error === null || (((int) ($error['type'] ?? 0)) & $fatal) === 0) {
            return false;
        }
        $state->done = true;
        // After an out-of-memory fatal the heap is still at the limit; give the
        // lock release itself some room or it would die silently too.
        if (str_starts_with((string) ($error['message'] ?? ''), 'Allowed memory size')) {
            $current = GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit'));
            if ($current > 0) {
                @ini_set('memory_limit', (string) ($current + 32 * 1024 * 1024));
            }
        }
        try {
            return (bool) Cache::restoreLock($lockKey, $owner)->release();
        } catch (Throwable) {
            return false;
        }
    }

    public function handle(PsbMediaOcrOrchestrator $orchestrator): void
    {
        self::raiseMemoryLimit();

        $draft = PsbDraft::query()->find($this->draftId);
        if (! $draft) {
            return;
        }

        // Cache::lock()->block() throws LockTimeoutException instead of
        // returning false, so the previous guard never ran: a busy draft lock
        // became a hard failed ocr-media job, and ocr-media is a critical queue
        // for the monitoring admission gate. get() is the non-throwing acquire,
        // so a duplicate batch is re-queued instead of failed.
        // TTL above the 600 s job timeout so the lock cannot expire mid-job.
        $lock = Cache::lock('psb-media-ocr-draft:'.$draft->id, 660);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        // A fatal (out of memory) skips the finally below; this frees the lock then.
        $lockState = (object) ['done' => false];
        $lockKey = 'psb-media-ocr-draft:'.$draft->id;
        $lockOwner = $lock->owner();
        $releaser = static function (?array $error = null) use ($lockKey, $lockOwner, $lockState): void {
            self::releaseLockOnFatal($lockKey, $lockOwner, $lockState, $error ?? error_get_last());
        };
        (self::$shutdownRegistrar ?? 'register_shutdown_function')($releaser);

        $previousFastPath = config('services.ocrspace.media_fast_path');
        $previousPaddleTimeout = config('services.ocrspace.paddle_timeout');
        $previousBudget = config('services.ocrspace.ocr_total_budget_seconds');
        $previousAsync = config('services.ocrspace.ocr_async_job');
        $previousRunCap = config('services.ocrspace.tesseract_run_cap');
        $previousImageBudget = config('services.ocrspace.media_image_budget_seconds', 90);
        $previousReserve = config('services.ocrspace.paddle_reserve_seconds', 35);

        try {
            if ($this->isStaleBatchWithoutFiles($draft)) {
                Log::info('[PSB] stale OCR batch skipped', [
                    'draft' => $draft->id,
                    'batch_key' => (string) ($this->continuation['batch_key'] ?? ''),
                ]);
                PsbOcrBatchLedger::close($this->draftId, (string) ($this->continuation['batch_key'] ?? ''), PsbOcrBatchLedger::STATE_SKIPPED);

                return;
            }

            // A re-check whose batch was replaced (new photo) or already ended
            // has nothing to do; its files belong to whoever owns the new batch.
            if ($this->isRetry() && $this->isStaleRetry($draft)) {
                Log::info('[PSB] stale OCR retry skipped', [
                    'draft' => $draft->id,
                    'batch_key' => (string) ($this->continuation['batch_key'] ?? ''),
                ]);
                $orchestrator->cleanupSupersededRetryFiles($draft, $this->stagedPaths);
                PsbOcrBatchLedger::close($this->draftId, (string) ($this->continuation['batch_key'] ?? ''), PsbOcrBatchLedger::STATE_SKIPPED);

                return;
            }

            // Tesseract is available in the app image and avoids blocking the
            // PSB queue on an overloaded optional PaddleOCR dependency. The
            // Paddle cap must allow a warm call (12-20 s on VM102).
            $paddleCap = self::paddleCap();
            $runCap = max(5, min(60, (int) $previousRunCap));
            if ($this->isRetry()) {
                // The automatic re-check gets 1.5x the per-call caps.
                $paddleCap = min(45, (int) round($paddleCap * 1.5));
                $runCap = min(60, (int) round($runCap * 1.5));
            }
            $imageSeconds = max(15, (int) $previousImageBudget);
            if ($this->isRetry()) {
                // Same 1.5x for the per-image budget, so Paddle still gets its
                // lock wait + full call after a 60 s Tesseract timeout.
                $imageSeconds = (int) round($imageSeconds * 1.5);
            }
            config([
                'services.ocrspace.media_image_budget_seconds' => $imageSeconds,
                'services.ocrspace.media_fast_path' => true,
                // The re-check's Paddle cap is 1.5x: the Paddle reserve follows it.
                'services.ocrspace.paddle_reserve_seconds' => $this->isRetry()
                    ? (int) round(((int) $previousReserve) * 1.5)
                    : (int) $previousReserve,
                'services.ocrspace.ocr_async_job' => true,
                'services.ocrspace.tesseract_run_cap' => $runCap,
                'services.ocrspace.paddle_timeout' => min($paddleCap, max($this->isRetry() ? $paddleCap : 1, (int) $previousPaddleTimeout)),
                // Total time for the batch: one image budget per photo, at least
                // 150 s, at most 480 s (the job timeout is 600 s and Paddle
                // fallbacks run inside the per-image budget).
                'services.ocrspace.ocr_total_budget_seconds' => min(
                    480,
                    max((int) $previousBudget, 4 * self::paddleCap(), 150, count($this->stagedPaths) * $imageSeconds)
                ),
            ]);
            $orchestrator->processStagedMediaOcr(
                $draft,
                $this->stagedPaths,
                $this->contexts,
                array_merge($this->continuation, ['defer_continuation' => true]),
            );

            // The orchestrator parked the batch for one automatic re-check (the
            // server was too slow to read the photo): queue that re-check instead
            // of continuing the PSB flow, which would end in "KTP belum terbaca".
            if (! $this->isRetry() && $this->retryParked($draft)) {
                self::dispatch(
                    $draft->id,
                    $this->stagedPaths,
                    $this->contexts,
                    array_merge($this->continuation, ['ocr_retry' => 1]),
                )->delay(max(30, (int) config('services.ocrspace.media_ocr_retry_delay_seconds', 120)) + random_int(0, 15));

                return;
            }
            ProcessPsbAfterMediaOcrJob::dispatch($draft->id, $this->continuation);
        } catch (Throwable $e) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['ocr_error_code'] = class_basename($e);
            // A draft past the photo stage keeps its gate and note; only the error code is stored.
            if (! in_array($draft->status, PsbDraft::PHOTO_LOCKED_STATUSES, true)) {
                $meta['document_gate'] = [
                    'status' => 'ocr_pending',
                    'reason' => 'ocr_worker_error',
                    'updated_at' => now('Asia/Jakarta')->toIso8601String(),
                ];
                $draft->last_error = 'OCR tertunda #'.$draft->id.'. Foto tetap tersimpan; coba cek ulang sebentar lagi.';
            }
            $draft->meta = $meta;
            $draft->saveQuietly();
            PsbOcrBatchLedger::close($this->draftId, (string) ($this->continuation['batch_key'] ?? ''), PsbOcrBatchLedger::STATE_FAILED);
            Log::warning('[PSB] media OCR worker deferred', [
                'draft' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            config([
                'services.ocrspace.media_fast_path' => $previousFastPath,
                'services.ocrspace.paddle_timeout' => $previousPaddleTimeout,
                'services.ocrspace.ocr_total_budget_seconds' => $previousBudget,
                'services.ocrspace.ocr_async_job' => $previousAsync,
                'services.ocrspace.tesseract_run_cap' => $previousRunCap,
                'services.ocrspace.media_image_budget_seconds' => $previousImageBudget,
                'services.ocrspace.paddle_reserve_seconds' => $previousReserve,
            ]);
            $lockState->done = true;
            $lock->release();
        }
    }

    /** Per-request PaddleOCR cap for this job: config value clamped to 5-45 s. */
    public static function paddleCap(): int
    {
        return max(5, min(45, (int) config('services.ocrspace.media_paddle_timeout', 30)));
    }

    /** The draft is no longer waiting on this batch (new photo, sweeper end, or already done). */
    private function isStaleRetry(PsbDraft $draft): bool
    {
        $batchKey = (string) ($this->continuation['batch_key'] ?? '');
        $meta = is_array($draft->meta) ? $draft->meta : [];

        return $batchKey === '' || $batchKey !== (string) ($meta['ocr_batch_key'] ?? '');
    }

    /** True when the orchestrator left this batch in retry_pending (its own batch key). */
    private function retryParked(PsbDraft $draft): bool
    {
        $fresh = PsbDraft::query()->find($draft->id);
        $meta = $fresh && is_array($fresh->meta) ? $fresh->meta : [];
        $batchKey = (string) ($this->continuation['batch_key'] ?? '');

        return $batchKey !== ''
            && data_get($meta, 'photo_class.status') === 'retry_pending'
            && (string) ($meta['ocr_batch_key'] ?? '') === $batchKey;
    }

    /**
     * An older batch whose staged files were already consumed by a newer batch
     * (same bytes were OCR'd there) has nothing left to do. The live batch with
     * missing files is not skipped and still fails as before.
     */
    private function isStaleBatchWithoutFiles(PsbDraft $draft): bool
    {
        $batchKey = (string) ($this->continuation['batch_key'] ?? '');
        if ($batchKey === '') {
            return false;
        }
        $disk = Storage::disk(config('filesystems.default'));
        foreach ($this->stagedPaths as $path) {
            if (is_string($path) && $disk->exists($path)) {
                return false;
            }
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];

        return $batchKey !== (string) ($meta['ocr_batch_key'] ?? '')
            && $batchKey !== (string) data_get($meta, 'photo_class.ocr_batch_completed', '');
    }
}
