<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayEvent;
use App\Services\PaymentGateway\PaymentWriteGate;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Command;

/**
 * Credits gateway payments held while the "Payment write" kill switch was OFF.
 * Does nothing (and says so) while the switch is still OFF.
 */
class ReleaseHeldGatewayPayments extends Command
{
    protected $signature = 'payments:release-held {--limit=200 : Max held events to process}';

    protected $description = 'Credit gateway payments that were held while the Payment write kill switch was OFF';

    public function handle(PaymentGatewayService $service, PaymentWriteGate $gate): int
    {
        $held = PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_HELD)->count();

        if (! $gate->enabled()) {
            $this->warn("Kill switch Payment write is OFF. {$held} held payment(s) left untouched.");

            return self::SUCCESS;
        }

        $processed = $service->releaseHeld(max(1, (int) $this->option('limit')));
        $this->info("Processed {$processed} of {$held} held payment(s).");

        return self::SUCCESS;
    }
}
