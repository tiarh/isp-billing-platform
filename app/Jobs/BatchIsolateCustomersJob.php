<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Router;
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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Isolate many customers on ONE router over a SINGLE MikroTik connection.
 *
 * The daily enforcement scan can flag hundreds of customers on the same router
 * at once. Isolating them one-per-job opened one API login per customer; this
 * batches them so the router sees one connection instead of N, which keeps a
 * large isolation wave from loading the router.
 *
 * Per-customer failures are logged + ledgered and do not abort the batch.
 */
class BatchIsolateCustomersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** = overlap expireAfter(600); < redis retry_after (700). Measured max run: 4 s. */
    public int $timeout = 600;

    public int $uniqueFor = 900;

    /**
     * @param  list<array{customer_id: int, invoice_id: int}>  $items
     */
    public function __construct(public int $routerId, public array $items)
    {
        $this->onQueue('network-enforcement');
    }

    public function uniqueId(): string
    {
        return 'batch-isolate-router:'.$this->routerId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter(600)->dontRelease(),
        ];
    }

    public function handle(
        MikrotikService $mikrotik,
        RadiusUserService $radius,
        BillingEligibilityService $eligibility,
        BillingEnforcementLedger $ledger,
    ): void {
        $router = Router::query()->find($this->routerId);
        if (! $router) {
            Log::warning('[billing] batch isolate skipped: router missing', ['router_id' => $this->routerId]);

            return;
        }

        $isolated = 0;
        $skipped = 0;

        try {
            $mikrotik->connect($router);
        } catch (\Throwable $e) {
            Log::error('[billing] batch isolate connect failed', [
                'router_id' => $this->routerId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            foreach ($this->items as $item) {
                $customerId = (int) ($item['customer_id'] ?? 0);
                $invoiceId = (int) ($item['invoice_id'] ?? 0);
                if ($customerId === 0 || $invoiceId === 0) {
                    continue;
                }

                $customer = Customer::query()->find($customerId);
                if (! $customer) {
                    continue;
                }

                // Re-check the policy at isolation time (state may have changed).
                $decision = $eligibility->evaluate($customer);
                if (! ($decision['isolation_eligible'] ?? false) || (int) ($decision['current_invoice_id'] ?? 0) === 0) {
                    $skipped++;

                    continue;
                }

                if (! $customer->pppoe_user) {
                    Log::warning('[billing] batch isolate skipped: PPPoE missing', ['customer_id' => $customerId]);
                    $skipped++;

                    continue;
                }

                $row = $ledger->start($customerId, $invoiceId, 'isolate');
                if (! $row) {
                    $skipped++;

                    continue;
                }

                try {
                    if (! $mikrotik->isolateUser($customer->pppoe_user)) {
                        throw new Exception("PPPoE user '{$customer->pppoe_user}' not found on router '{$router->name}'");
                    }
                    $customer->update([
                        'status' => 'isolated',
                        'mikrotik_profile' => $router->isolation_profile ?: 'isolirebilling',
                        'mikrotik_sync_status' => 'syncing',
                        'mikrotik_synced_at' => now(),
                        'mikrotik_sync_checked_at' => now(),
                    ]);
                    $radiusResult = $radius->syncCustomer($customer->fresh());
                    if ($router->radius_enabled && ! ($radiusResult['synced'] ?? false)) {
                        throw new Exception('RADIUS sync failed: '.($radiusResult['reason'] ?? 'unknown'));
                    }
                    $customer->update(['mikrotik_sync_status' => 'synced']);
                    $ledger->succeeded($row, ['router_id' => $router->id, 'radius' => $radiusResult, 'batched' => true]);
                    $isolated++;
                } catch (\Throwable $e) {
                    $ledger->failed($row, $e);
                    Log::error('[billing] batch isolate item failed', [
                        'customer_id' => $customerId,
                        'router_id' => $router->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $mikrotik->disconnect();
        }

        Log::info('[billing] batch isolate complete', [
            'router_id' => $this->routerId,
            'isolated' => $isolated,
            'skipped' => $skipped,
        ]);
    }
}
