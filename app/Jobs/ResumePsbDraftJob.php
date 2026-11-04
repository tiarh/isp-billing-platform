<?php

namespace App\Jobs;

use App\Exceptions\PsbLockBusyException;
use App\Services\PsbOrchestratorService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic "#psb lanjut" for a draft whose OLT/SN lock was busy.
 *
 * Token based (meta.olt_busy_resume.token): only the latest token may act, so
 * duplicate or superseded jobs are harmless no-ops. No ShouldBeUnique on
 * purpose; the token is the uniqueness.
 */
class ResumePsbDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $draftId,
        public string $token,
        public int $attempt = 1,
        public int $lockRetries = 0,
    ) {
        $this->onQueue('psb');
    }

    public function handle(PsbOrchestratorService $psb): void
    {
        try {
            $psb->resumeDraftAfterOltBusy($this->draftId, $this->token);
        } catch (LockTimeoutException|PsbLockBusyException) {
            // Draft lock held by another run: retry the same token shortly.
            if ($this->lockRetries >= PsbOrchestratorService::OLT_BUSY_MAX_ATTEMPTS + 2) {
                $psb->giveUpOltBusyResume($this->draftId, $this->token, 'draft_lock_busy');

                return;
            }
            self::dispatch($this->draftId, $this->token, $this->attempt, $this->lockRetries + 1)
                ->delay(30);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        Log::warning('[PSB] olt busy resume job failed', [
            'draft' => $this->draftId,
            'exception_class' => $exception ? $exception::class : null,
        ]);

        app(PsbOrchestratorService::class)->giveUpOltBusyResume($this->draftId, $this->token, 'job_failed');
    }
}
