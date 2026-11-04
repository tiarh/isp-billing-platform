<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\InvoiceGenerationService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnsureCustomerCurrentInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // One customer, one DB transaction. expireAfter (300) > timeout: a SIGKILL at the
    // timeout cannot leave the overlap lock stuck forever (same lesson as IsolateCustomerJob).
    public int $timeout = 120;

    public $backoff = [60, 180, 600];

    public $uniqueFor = 900;

    public function __construct(public int $customerId, public string $period)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "billing:customer:{$this->customerId}:invoice:{$this->period}:reactivation";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(300)];
    }

    public function handle(InvoiceGenerationService $generator): void
    {
        $customer = Customer::ebilling()
            ->with('package')
            ->find($this->customerId);

        if (! $customer || $customer->status !== 'active') {
            Log::info('[billing] activation invoice skipped', [
                'customer_id' => $this->customerId,
                'period' => $this->period,
                'reason' => ! $customer ? 'customer_not_found' : 'customer_not_active',
            ]);

            return;
        }

        // Current period only (owner 2026-10-08): never arrears, never the
        // join month, no Rp0 invoice, nothing without a join date.
        $period = CarbonImmutable::createFromFormat('!Y-m-d', $this->period, OpsTime::TZ);
        $current = OpsTime::now()->startOfMonth();
        $skip = $period->format('Y-m') !== $current->format('Y-m')
            ? 'not_current_period'
            : $generator->currentPeriodSkipReason($customer);

        if ($skip !== null) {
            Log::info('[billing] activation invoice skipped', [
                'customer_id' => $this->customerId,
                'period' => $this->period,
                'reason' => $skip,
            ]);

            return;
        }

        $generator->ensureForPeriod($customer, $current);
    }
}
