<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Jobs\BatchIsolateCustomersJob;
use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\BillingEligibilityService;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\OpsTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue
        {--dry-run : Force read-only dry-run (no job, status, ledger, MT, RADIUS, or WA write)}
        {--live : Run live only when billing_enforcement_dry_run is already disabled}';

    protected $description = 'Evaluate current billing invoice policy and queue enforcement actions.';

    public function handle(BillingEligibilityService $eligibility): int
    {
        $settingDryRun = $this->isSettingDryRun();
        if ($this->option('live') && $settingDryRun) {
            $this->error('Refusing --live while billing_enforcement_dry_run=1. Owner approval is required.');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run') || $settingDryRun;
        $now = OpsTime::now();
        $this->info('Billing policy check @ '.$now->toDateTimeString().' WIB');
        $this->line('  mode='.($isDryRun ? 'DRY-RUN (read-only)' : 'LIVE (approved setting)'));

        $stats = [
            'customers_reviewed' => 0,
            'no_current_invoice' => 0,
            'isolir_candidates' => 0,
            'reconnect_candidates' => 0,
            'isolir_dispatched' => 0,
            'reconnect_dispatched' => 0,
            'dismantle_review' => 0,
            'skipped_current_paid' => 0,
        ];

        // Group isolation candidates per router so the batch job isolates them
        // over ONE MikroTik connection instead of one login per customer.
        $isolateByRouter = [];

        Customer::query()
            ->ebilling()
            ->whereIn('status', CustomerStatus::billable())
            ->chunkById(100, function ($customers) use ($eligibility, $isDryRun, &$stats, &$isolateByRouter) {
                // One held-ids query per chunk instead of one per customer.
                $heldIds = LifecycleTerminationService::heldCustomerIds($customers->pluck('id')->map(fn ($id) => (int) $id)->all());
                foreach ($customers as $customer) {
                    $stats['customers_reviewed']++;
                    $decision = $eligibility->evaluate($customer, null, $heldIds);
                    $reason = $decision['reason'];
                    $invoiceId = (int) ($decision['current_invoice_id'] ?? 0);

                    if ($decision['billing_hold'] ?? false) {
                        // Pending termination: counted lazily so output is unchanged with 0 rows.
                        $stats['billing_hold'] = ($stats['billing_hold'] ?? 0) + 1;
                    }
                    if ($reason === 'no_current_invoice') {
                        $stats['no_current_invoice']++;
                    }
                    if ($decision['current_is_paid'] ?? false) {
                        $stats['skipped_current_paid']++;
                    }
                    if ($decision['isolation_eligible'] ?? false) {
                        $stats['isolir_candidates']++;
                        if (! $isDryRun && $invoiceId > 0) {
                            $routerId = (int) ($customer->router_id ?? 0);
                            if ($routerId > 0) {
                                $isolateByRouter[$routerId][] = [
                                    'customer_id' => (int) $customer->getKey(),
                                    'invoice_id' => $invoiceId,
                                ];
                                $stats['isolir_dispatched']++;
                            } else {
                                // No router: keep the single job so the ledger records the data gap.
                                IsolateCustomerJob::dispatch($customer, $invoiceId);
                                $stats['isolir_dispatched']++;
                            }
                        }
                    }
                    if ($decision['reconnect_eligible'] ?? false) {
                        $stats['reconnect_candidates']++;
                        if (! $isDryRun && $invoiceId > 0) {
                            ReconnectCustomerJob::dispatch($customer, $invoiceId);
                            $stats['reconnect_dispatched']++;
                        }
                    }
                    if (($decision['current_is_outstanding'] ?? false) && ($decision['grace_elapsed'] ?? false)) {
                        // Dismantle is deliberately a separate reviewed workflow.
                        $stats['dismantle_review']++;
                    }
                }
            });

        // One batched isolation job per router (single MikroTik connection).
        if (! $isDryRun) {
            foreach ($isolateByRouter as $routerId => $items) {
                if ($items === []) {
                    continue;
                }
                BatchIsolateCustomersJob::dispatch((int) $routerId, array_values($items));
            }
        }

        $this->info('Summary: '.json_encode($stats, JSON_UNESCAPED_SLASHES));
        Log::info('[billing:check-overdue] policy evaluation complete', [
            'dry_run' => $isDryRun,
            'stats' => $stats,
            'policy_version' => BillingEligibilityService::POLICY_VERSION,
        ]);

        // A read-only dry-run must not mutate settings or activity logs.
        if (! $isDryRun) {
            Setting::set('billing_enforcement_last_run', [
                'at' => now()->toIso8601String(),
                'dry_run' => false,
                'stats' => $stats,
                'policy_version' => BillingEligibilityService::POLICY_VERSION,
            ], 'json', 'billing', 'Last enforcement run');
        }

        return self::SUCCESS;
    }

    private function isSettingDryRun(): bool
    {
        $value = Setting::get('billing_enforcement_dry_run', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int) $value !== 0);
    }
}
