<?php

namespace App\Console\Commands;

use App\Services\PaymentGateway\GatewayStatusChecker;
use Illuminate\Console\Command;

/**
 * Fallback for missed payment callbacks (see GatewayStatusChecker for the
 * sparse back-off that respects the provider's rate limit).
 */
class CheckPendingGatewayPayments extends Command
{
    protected $signature = 'payments:check-pending-gateway {--limit=20 : Max attempts to check in this run}';

    protected $description = 'Ask the payment gateway for the status of open online payments whose callback may have been missed';

    public function handle(GatewayStatusChecker $checker): int
    {
        $limit = min(max(1, (int) $this->option('limit')), 50);
        $outcomes = [];

        foreach ($checker->due($limit) as $txn) {
            $outcome = $checker->check($txn);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        $closed = $checker->closeStale();
        ksort($outcomes);
        $summary = $outcomes === [] ? 'none due' : collect($outcomes)->map(fn ($n, $k) => "{$k}={$n}")->implode(' ');
        $this->info("Checked: {$summary}. Closed stale: {$closed}.");

        return self::SUCCESS;
    }
}
