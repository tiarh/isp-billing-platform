<?php

namespace App\Jobs;

use App\Models\DeviceRecoveryCase;
use App\Services\DeviceRecoveryOltCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the customer ONU from the OLT after a dismantle recovery case is
 * complete. Enqueued by DeviceRecoveryService::receive() only when the cleanup
 * feature is enabled; the service itself is fail-closed and idempotent.
 */
class DeleteDismantledOnuJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Base delay when the OLT is busy (jitter 0-60s is added). */
    public const BUSY_RELEASE_SECONDS = 120;

    /** Overlap lock expiry (a killed worker must not hold it for hours). */
    public const OVERLAP_EXPIRE_SECONDS = 900;

    /**
     * No fixed $tries: a busy OLT (active PSB/migration run, another delete)
     * re-queues the job any number of times without it ever landing in
     * failed_jobs. The job is bounded by retryUntil() and by $maxExceptions
     * (real exceptions only).
     */
    public int $maxExceptions = 3;

    /** OLT SSH delete; a busy OLT releases itself. Must stay < redis retry_after (700). */
    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 21600;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function __construct(public DeviceRecoveryCase $case)
    {
        $this->onQueue('network-enforcement');
    }

    public function uniqueId(): string
    {
        return 'device-recovery-onu-delete:'.$this->case->getKey();
    }

    public function middleware(): array
    {
        // releaseAfter: an overlapping run is re-queued after a delay (counted
        // against retryUntil, never against a tries limit).
        // expireAfter: a killed worker cannot leave the overlap lock stuck
        // until the retryUntil window ends.
        return [(new WithoutOverlapping($this->uniqueId()))
            ->releaseAfter(self::BUSY_RELEASE_SECONDS)
            ->expireAfter(self::OVERLAP_EXPIRE_SECONDS)];
    }

    public function handle(DeviceRecoveryOltCleanupService $cleanup): void
    {
        $result = $cleanup->deleteForCase($this->case->fresh(['customer']) ?? $this->case);

        if (($result['status'] ?? null) === 'olt_busy') {
            // Another OLT operation (PSB provisioning / operator delete) holds
            // the per-OLT lock. Nothing was written; try again later.
            Log::info('[device-recovery] ONU cleanup deferred: OLT busy', ['case_id' => $this->case->getKey()]);
            $this->release(self::BUSY_RELEASE_SECONDS + random_int(0, 60));

            return;
        }

        Log::info('[device-recovery] ONU cleanup outcome', [
            'case_id' => $this->case->getKey(),
            'status' => $result['status'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);
    }
}
