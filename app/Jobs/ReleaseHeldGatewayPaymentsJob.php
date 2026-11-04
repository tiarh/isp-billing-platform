<?php

namespace App\Jobs;

use App\Services\PaymentGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Credits gateway payments that were held while the "Payment write" kill
 * switch was OFF. No-op while the switch is still OFF.
 */
class ReleaseHeldGatewayPaymentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    // Same as the billing worker's --timeout=120; keeps the limit if the job moves queue.
    // ShouldBeUnique lock (uniqueFor 300) outlives it, so a killed run cannot block the next.
    public int $timeout = 120;

    public $backoff = [30, 120, 300];

    public $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(PaymentGatewayService $service): void
    {
        $processed = $service->releaseHeld(200);
        if ($processed > 0) {
            Log::info('[PaymentGateway] released held payments', ['processed' => $processed]);
        }
    }
}
