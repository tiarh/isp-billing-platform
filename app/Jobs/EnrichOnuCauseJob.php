<?php

namespace App\Jobs;

use App\Services\Outage\OltCauseEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RCA-2: low priority, read-only cause read of ONE ONU (see OltCauseEnricher for every safety rule).
 * Runs on the shared `monitoring` queue (last in the ops worker's list, behind PSB/migration-critical lanes).
 * When the OLT is busy with a PSB / migration / another session the job goes back to the queue a few times
 * and then gives up quietly: a missing cause is never worth delaying real work.
 */
class EnrichOnuCauseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Above the worst case of one session (Huawei: 8 s connect + 5 reads x 15 s = 83 s; ZTE: 25 s). */
    public int $timeout = 150;

    public int $uniqueFor = 1800;

    public function __construct(
        public int $oltId,
        public string $onuRef,
        public ?int $customerId = null,
        public ?int $episodeId = null,
    ) {
        $this->onQueue('monitoring');
    }

    public function uniqueId(): string
    {
        return 'outage-enrich:'.$this->oltId.':'.$this->onuRef;
    }

    public function handle(OltCauseEnricher $enricher): void
    {
        $result = $enricher->enrich($this->oltId, $this->onuRef, $this->customerId, $this->episodeId);

        if (in_array($result['status'], OltCauseEnricher::RETRY_STATUSES, true) && $this->attempts() < $this->tries) {
            $this->release(180);
        }
    }
}
