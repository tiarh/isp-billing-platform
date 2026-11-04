<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Services\BillingEligibilityService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-shot backfill for customers who already paid up to the previous month but
 * were left isolated because their current-month invoice was only just issued
 * and not yet due (the "paid September, entered October" case).
 *
 * This command is deliberately narrow: it only dispatches a reconnect for
 * customers whose BillingEligibilityService decision is reconnect-eligible under
 * the paid-through-prior-month rule. It never isolates, never writes a device
 * directly, and every action goes through ReconnectCustomerJob (ledger, locks,
 * rate limit). Dry-run is the default; --live is required to dispatch.
 */
class ReconnectPaidThroughPriorMonth extends Command
{
    protected $signature = 'billing:reconnect-paid-prior-month
        {--period= : Only customers with a paid invoice for this period (Y-m), e.g. 2026-09}
        {--dry-run : Read-only list of reconnect candidates (default)}
        {--live : Dispatch ReconnectCustomerJob for every eligible candidate}
        {--limit=0 : Stop after this many candidates (0 = no limit)}';

    protected $description = 'Reconnect customers who paid up to the previous month but are still isolated.';

    public function handle(BillingEligibilityService $eligibility): int
    {
        $live = (bool) $this->option('live');
        $dryRun = ! $live || (bool) $this->option('dry-run');
        $period = $this->option('period');
        $limit = max(0, (int) $this->option('limit'));

        $periodDate = null;
        if (is_string($period) && $period !== '') {
            try {
                $periodDate = CarbonImmutable::createFromFormat('!Y-m', $period, OpsTime::TZ)->startOfMonth();
            } catch (Throwable) {
                $this->error('Invalid --period, expected Y-m (e.g. 2026-09).');

                return self::FAILURE;
            }
        }

        $this->info('Reconnect paid-through-prior-month @ '.OpsTime::now()->toDateTimeString().' WIB');
        $this->line('  mode='.($dryRun ? 'DRY-RUN (read-only)' : 'LIVE (dispatch reconnect jobs)'));
        if ($periodDate) {
            $this->line('  period-filter='.$periodDate->format('Y-m'));
        }

        $query = Customer::query()
            ->ebilling()
            ->whereIn('status', CustomerStatus::isolatedValues())
            ->whereNotNull('router_id')
            ->where(function ($q) {
                $q->whereNotNull('pppoe_user')->where('pppoe_user', '!=', '');
            });

        if ($periodDate) {
            $query->whereHas('invoices', function ($q) use ($periodDate) {
                $q->where('period', $periodDate->toDateString())->where('status', 'paid');
            });
        }

        $eligible = [];
        $reviewed = 0;
        $query->chunkById(100, function ($customers) use ($eligibility, &$eligible, &$reviewed, $limit) {
            foreach ($customers as $customer) {
                $reviewed++;
                $decision = $eligibility->evaluate($customer);
                if (($decision['reconnect_eligible'] ?? false) !== true) {
                    continue;
                }
                $eligible[] = [
                    'customer_id' => (int) $customer->getKey(),
                    'name' => (string) $customer->name,
                    'pppoe_user' => (string) $customer->pppoe_user,
                    'router_id' => (int) ($customer->router_id ?? 0),
                    'current_period' => $decision['current_period'] ?? null,
                    'reason' => $decision['reason'] ?? null,
                    'paid_through_prior_month' => (bool) ($decision['reconnect_paid_through_prior_month'] ?? false),
                    'invoice_id' => (int) ($decision['current_invoice_id'] ?? 0),
                ];
                if ($limit > 0 && count($eligible) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->newLine();
        $this->table(
            ['customer_id', 'name', 'pppoe_user', 'router_id', 'current', 'reason', 'prior_month_rule'],
            array_map(static fn (array $row): array => [
                $row['customer_id'],
                mb_strimwidth($row['name'], 0, 28, '…'),
                $row['pppoe_user'],
                $row['router_id'],
                (string) $row['current_period'],
                (string) $row['reason'],
                $row['paid_through_prior_month'] ? 'yes' : 'no',
            ], $eligible),
        );

        $this->info(sprintf(
            'Summary: reviewed=%d eligible=%d mode=%s',
            $reviewed,
            count($eligible),
            $dryRun ? 'dry-run' : 'live',
        ));

        Log::info('[billing:reconnect-paid-prior-month] evaluation complete', [
            'dry_run' => $dryRun,
            'period' => $periodDate?->format('Y-m'),
            'reviewed' => $reviewed,
            'eligible' => count($eligible),
            'customer_ids' => array_column($eligible, 'customer_id'),
        ]);

        if ($dryRun) {
            $this->warn('DRY-RUN: no reconnect job dispatched. Re-run with --live after approval.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($eligible as $row) {
            $customer = Customer::query()->find($row['customer_id']);
            if (! $customer) {
                continue;
            }
            ReconnectCustomerJob::dispatch($customer, $row['invoice_id'] ?: null);
            $dispatched++;
        }
        $this->info('Dispatched reconnect jobs: '.$dispatched);

        return self::SUCCESS;
    }
}
