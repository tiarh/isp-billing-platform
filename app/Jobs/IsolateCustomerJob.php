<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IsolateCustomerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /** Hung router is cut after 2 min (then backoff retry). Must stay < redis retry_after (700). */
    public int $timeout = 120;

    public $backoff = [60, 180, 600];

    public $uniqueFor = 900;

    public function __construct(public Customer $customer, public ?int $invoiceId = null)
    {
        $this->invoiceId ??= $customer->invoices()->where('status', '!=', 'void')
            ->orderByDesc('period')->orderByDesc('id')->value('id');
        $this->onQueue('network-enforcement');
    }

    public function uniqueId(): string
    {
        return app(BillingEnforcementLedger::class)->key((int) $this->customer->getKey(), (int) ($this->invoiceId ?? 0), 'isolate');
    }

    public function middleware(): array
    {
        return [
            // expireAfter > $timeout: a SIGKILL after the timeout cannot leave the lock stuck forever.
            (new WithoutOverlapping($this->uniqueId()))->expireAfter(180),
            new RateLimited('router-api'),
        ];
    }

    public function handle(MikrotikService $mikrotik, ?RadiusUserService $radius = null, ?BillingEligibilityService $eligibility = null, ?BillingEnforcementLedger $ledger = null): void
    {
        $this->customer->refresh();
        $legacyDirectCall = $eligibility === null || $ledger === null;
        $radius ??= app(RadiusUserService::class);
        if (! $legacyDirectCall) {
            $decision = $eligibility->evaluate($this->customer);
            $invoiceId = (int) ($decision['current_invoice_id'] ?? $this->invoiceId ?? 0);
            if (! $decision['isolation_eligible'] || $invoiceId === 0) {
                Log::info('[billing] isolate skipped by policy', ['customer_id' => $this->customer->id, 'reason' => $decision['reason'], 'hold_reason' => $decision['hold_reason'] ?? null]);

                return;
            }
        } else {
            $invoiceId = (int) ($this->invoiceId ?? 0);
        }

        $row = $legacyDirectCall ? null : $ledger->start((int) $this->customer->id, $invoiceId, 'isolate');
        if (! $legacyDirectCall && ! $row) {
            return;
        }
        if (! $this->customer->router_id || ! $this->customer->router || ! $this->customer->pppoe_user) {
            $error = new Exception('router or PPPoE username is unavailable');
            if ($row) {
                $ledger->failed($row, $error);
            }
            // Missing router/PPPoE is a permanent data-quality condition: a
            // retry cannot fix it, and the daily enforcement scan would emit a
            // retry storm that competes with the PSB/migration queues and trips
            // the new-failed-job alert. Keep the ledger failure and a
            // structured warning for data cleanup, then stop without throwing.
            Log::warning('[billing] isolate skipped: router or PPPoE username is unavailable', [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoiceId,
                'has_router' => (bool) $this->customer->router_id,
                'has_pppoe' => (bool) $this->customer->pppoe_user,
            ]);

            return;
        }

        $router = $this->customer->router;
        try {
            $mikrotik->connect($router);
            if (! $mikrotik->isolateUser($this->customer->pppoe_user)) {
                throw new Exception("PPPoE user '{$this->customer->pppoe_user}' not found on router '{$router->name}'");
            }
            $this->customer->update([
                'status' => 'isolated',
                'mikrotik_profile' => $router->isolation_profile ?: 'isolirebilling',
                'mikrotik_sync_status' => 'syncing',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ]);
            $radiusResult = $radius->syncCustomer($this->customer->fresh());
            if ($router->radius_enabled && ! ($radiusResult['synced'] ?? false)) {
                throw new Exception('RADIUS sync failed: '.($radiusResult['reason'] ?? 'unknown'));
            }
            $this->customer->update(['mikrotik_sync_status' => 'synced']);
            if ($row) {
                $ledger->succeeded($row, ['router_id' => $router->id, 'radius' => $radiusResult]);
            }
        } catch (Exception $e) {
            if ($row) {
                $ledger->failed($row, $e);
            }
            Log::error('[billing] isolate failed', ['customer_id' => $this->customer->id, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $mikrotik->disconnect();
        }
    }
}
