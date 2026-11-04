<?php

namespace App\Jobs;

use App\Services\CustomerAutoCreditGate;
use App\Services\CustomerCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Applies the customer's available credits (saldo kelebihan bayar) to one newly
 * generated monthly invoice. Dispatched after commit from
 * InvoiceGenerationService::ensureForPeriod. Idempotent: the service re-checks
 * the invoice under its row lock, so a duplicate or retried job is a no-op.
 */
class ApplyCustomerCreditsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // A normal run is under 1 s (one DB transaction). Below the overlap lock
    // expireAfter (300) and redis retry_after (700): a SIGKILL at the timeout rolls
    // the open transaction back and the lock lapses by itself.
    public int $timeout = 120;

    public $backoff = [60, 180, 600];

    public $uniqueFor = 900;

    public function __construct(public int $invoiceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "billing:invoice:{$this->invoiceId}:credit-apply";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(300)];
    }

    public function handle(CustomerCreditService $credits): void
    {
        if (! CustomerAutoCreditGate::enabled()) {
            return;
        }

        $credits->autoApplyToInvoice($this->invoiceId);
    }
}
