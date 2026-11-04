<?php

namespace App\Jobs;

use App\Services\PsbOrchestratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollPsbSnJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        // Poller panjang (timeout 600s) — jangan pegang slot worker PSB/default.
        $this->onQueue('monitoring');
    }

    public function handle(PsbOrchestratorService $psb): void
    {
        $n = $psb->pollWaitingSn();
        if ($n > 0) {
            Log::info('[PSB] pollWaitingSn processed', ['count' => $n]);
        }
    }
}
