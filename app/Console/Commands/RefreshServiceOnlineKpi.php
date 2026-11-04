<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerConnectivityService;
use Illuminate\Console\Command;

class RefreshServiceOnlineKpi extends Command
{
    protected $signature = 'customers:refresh-service-kpi
                            {--limit=500 : Max customers to refresh this run}
                            {--chunk=100 : Customers processed in memory per batch}';

    protected $description = 'Recompute service_online KPI from PON, PPPoE, and cached GenieACS ping';

    public function handle(CustomerConnectivityService $connectivity): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $chunk = max(25, min($limit, (int) $this->option('chunk')));
        $updated = 0;

        $customers = Customer::query()
            ->where('status', 'active')
            ->with('genieAcsDevice')
            ->orderByRaw('service_online_at IS NULL DESC')
            ->orderBy('service_online_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $customers->chunk($chunk)->each(
            function ($batch) use ($connectivity, &$updated) {
                foreach ($batch as $customer) {
                    $connectivity->syncCustomerServiceState($customer, $customer->genieAcsDevice);
                    $updated++;
                }
            }
        );

        $this->info("service_online refreshed for {$updated} active customers (limit={$limit}).");

        return self::SUCCESS;
    }
}
