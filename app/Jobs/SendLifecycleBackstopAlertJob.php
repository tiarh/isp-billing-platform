<?php

namespace App\Jobs;

use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Roadmap 1.3 A10 (enforce mode): ops WA alert for a backstop refusal, sent off
 * the model-save path (the save may hold DB locks; the Evolution call can take
 * up to ~50 s). One attempt, wa queue (same worker as other WA group sends).
 */
class SendLifecycleBackstopAlertJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public string $message, public string $dedupeKey)
    {
        $this->onQueue('wa');
    }

    public function handle(OpsAlertService $ops): void
    {
        try {
            $ops->send($this->message, $this->dedupeKey, 86400, '', OpsAlertSource::LIFECYCLE_BACKSTOP);
        } catch (Throwable) {
            // alert only; never fail loudly
        }
    }
}
