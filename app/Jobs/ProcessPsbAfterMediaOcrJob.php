<?php

namespace App\Jobs;

use App\Exceptions\PsbAlbumOcrWaitException;
use App\Exceptions\PsbLockBusyException;
use App\Models\PsbDraft;
use App\Services\PsbMediaOcrOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumes PSB after OCR classification on the normal PSB worker. Keeping this
 * separate prevents a slow OCR call from sharing a timeout with device I/O.
 */
class ProcessPsbAfterMediaOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 2;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * @param  array<string, mixed>  $continuation
     */
    public function __construct(
        public int $draftId,
        public array $continuation = [],
    ) {
        $this->onQueue('psb');
    }

    public function uniqueId(): string
    {
        $waitHop = (int) ($this->continuation['album_wait_n'] ?? 0);

        return 'psb-media-continuation:'.$this->draftId.':'.(
            (string) ($this->continuation['batch_key'] ?? '')
        ).($waitHop > 0 ? ':aw'.$waitHop : '');
    }

    /**
     * A re-run after an album wait is silent when another batch already decided:
     * the draft status moved on, or a terminal reply was sent for another batch.
     */
    private function supersededByOtherBatch(PsbDraft $draft): bool
    {
        if ((int) ($this->continuation['album_wait_since'] ?? 0) === 0) {
            return false;
        }
        if ((string) $draft->status !== (string) ($this->continuation['album_wait_status'] ?? $draft->status)) {
            return true;
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $notified = (string) ($meta['ocr_terminal_notified'] ?? '');

        return $notified !== '' && $notified !== (string) ($this->continuation['batch_key'] ?? '');
    }

    public function handle(PsbMediaOcrOrchestrator $orchestrator): void
    {
        $draft = PsbDraft::query()->find($this->draftId);
        if (! $draft) {
            return;
        }

        if ($this->supersededByOtherBatch($draft)) {
            Log::info('[PSB] waited OCR continuation superseded', ['draft' => $draft->id]);

            return;
        }

        try {
            $orchestrator->resumeAfterMediaOcr($draft, $this->continuation);
        } catch (PsbAlbumOcrWaitException $e) {
            $first = (int) ($this->continuation['album_wait_since'] ?? 0) === 0;
            $next = array_merge($this->continuation, [
                'album_wait_since' => $e->waitSince,
                'album_wait_n' => (int) ($this->continuation['album_wait_n'] ?? 0) + 1,
                'album_wait_status' => $first
                    ? (string) $draft->status
                    : (string) ($this->continuation['album_wait_status'] ?? $draft->status),
            ]);
            self::dispatch($draft->id, $next)->delay(max(1, min(15, $e->remainingSeconds)));
        } catch (PsbLockBusyException) {
            $this->release(45 + random_int(0, 20));
        } catch (Throwable $e) {
            Log::error('[PSB] post-OCR continuation failed', [
                'draft' => $draft->id,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }
}
