<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCustomerIdentifiers;
use App\Jobs\BatchIsolateCustomersJob;
use App\Jobs\IsolateCustomerJob;
use App\Models\BillingEnforcementAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use App\Support\OpsTime;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One-off operator list action: isolate an explicit list of overdue customers on
 * their router now. DRY RUN unless --execute (needs --actor = admin/superadmin).
 *
 * Eligibility is the SAME policy as billing:check-overdue (BillingEligibilityService:
 * grace, paid-this-month protection, Rp0 package, pending-termination hold) plus a
 * hard check that the customer still has an overdue unpaid invoice. Execution runs
 * IsolateCustomerJob::handle() inline: the same per-customer steps as the queued
 * isolation (BatchIsolateCustomersJob for customers with a router): re-evaluate,
 * enforcement ledger, router call, status/profile, RADIUS sync. It sends no
 * customer WA (isolation WA is deliberately disabled in BillingWaEventService).
 *
 * Enforcement ledger: a row already `succeeded` for the customer's current invoice
 * (isolated earlier, then reconnected) would make the handler do nothing. For the
 * LISTED, eligible customers only, such a row (or a `failed` one) is re-armed to
 * `pending` with metadata (actor, time, previous state) before the handler runs.
 * A `running` row is never touched: the customer is skipped (ledger_running).
 *
 * One customer at a time, pause between router calls, errors caught per customer.
 * Output holds ids/codes only (no names, phones, addresses).
 */
class CustomersIsolateList extends Command
{
    use ResolvesCustomerIdentifiers;

    public const SOURCE = 'cli_isolate_list';

    protected $signature = 'customers:isolate-list
        {--codes= : Comma separated customer codes}
        {--pppoe= : Comma separated PPPoE usernames (customers without a code)}
        {--execute : Really isolate the eligible customers (default: dry run)}
        {--actor= : User id or email of an admin/superadmin (required with --execute)}
        {--sleep=3 : Seconds to wait between router calls}';

    protected $description = 'Isolate listed overdue customers through the normal enforcement handler (dry run by default)';

    public function handle(BillingEligibilityService $eligibility, BillingEnforcementLedger $ledger): int
    {
        $execute = (bool) $this->option('execute');
        $actor = null;
        if ($execute) {
            $actor = $this->resolveActor((string) $this->option('actor'));
            if (! $actor) {
                $this->error('--execute requires --actor=<user id or email> of an admin/superadmin.');

                return self::INVALID;
            }
        }
        $sleepRaw = trim((string) $this->option('sleep'));
        if (! ctype_digit($sleepRaw)) {
            $this->error('--sleep must be a whole number of seconds.');

            return self::INVALID;
        }
        $sleep = (int) $sleepRaw;

        $codes = $this->parseList($this->option('codes'));
        $pppoe = $this->parseList($this->option('pppoe'));
        if ($codes === [] && $pppoe === []) {
            $this->error('Give --codes= and/or --pppoe=.');

            return self::INVALID;
        }

        $stats = ['eligible' => 0, 'skipped' => 0, 'isolated' => 0, 'failed' => 0];
        $today = OpsTime::now()->startOfDay();
        $ranOne = false;

        foreach ($this->resolveIdentifiers($codes, $pppoe) as $row) {
            $customer = $row['customer'];
            if ($customer === null) {
                $stats['skipped']++;
                $this->line(sprintf('%s decision=%s result=skipped', $row['label'], $row['state']));

                continue;
            }

            $line = 'customer='.$customer->id.' code='.$customer->code;
            try {
                $overdue = $this->overdueInvoices($customer, $today);
                $overdueSum = array_sum(array_map(fn ($i) => (float) $i->amount, $overdue));
                $decision = null;
                $skip = $this->skipReason($customer, count($overdue), $eligibility, $decision);

                $line = sprintf(
                    'customer=%d code=%s status=%s router=%s pppoe=%s overdue_invoices=%d overdue_sum=%s already_isolated=%s',
                    $customer->id,
                    $customer->code,
                    $customer->status,
                    $customer->router_id ?: '-',
                    $customer->pppoe_user ?: '-',
                    count($overdue),
                    number_format($overdueSum, 0, '', ''),
                    $customer->status === 'isolated' ? 'yes' : 'no',
                );

                $invoiceId = (int) ($decision['current_invoice_id'] ?? 0);
                $ledgerRow = null;
                if ($skip === null) {
                    $ledgerRow = BillingEnforcementAction::query()
                        ->where('idempotency_key', $ledger->key((int) $customer->id, $invoiceId, 'isolate'))
                        ->first();
                    $ledgerState = $ledgerRow?->status ?? 'none';
                    $line .= ' ledger='.$ledgerState;
                    if ($ledgerState === 'running') {
                        $skip = 'ledger_running';
                    }
                }

                if ($skip !== null) {
                    $stats['skipped']++;
                    $this->line($line.' decision=skip reasons='.$skip.' result=skipped');

                    continue;
                }

                $stats['eligible']++;
                if (! $execute) {
                    $would = ($ledgerRow?->status === 'succeeded') ? 'rearm_ledger_and_isolate' : 'isolate';
                    $this->line($line.' decision=eligible would='.$would.' result=dry-run');

                    continue;
                }

                if ($ranOne && $sleep > 0) {
                    sleep($sleep);
                }
                $ranOne = true;

                $result = $this->isolate($customer, $invoiceId, $ledgerRow, $actor, $eligibility, $ledger);
                if ($result === 'isolated') {
                    $stats['isolated']++;
                } elseif ($result === 'isolated_radius_failed') {
                    $stats['isolated']++;
                    $stats['failed']++;
                } else {
                    $stats['skipped']++;
                }
                $this->line($line.' decision=eligible result='.$result);
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->line($line.' decision=eligible result=failed error='.$e::class);
            }
        }

        $this->info(sprintf(
            'isolate-list (%s): eligible=%d skipped=%d',
            $execute ? 'EXECUTE' : 'dry run, nothing written',
            $stats['eligible'],
            $stats['skipped'],
        ));
        if ($execute) {
            $this->info(sprintf('isolated=%d failed=%d', $stats['isolated'], $stats['failed']));
        }

        return self::SUCCESS;
    }

    /**
     * Unpaid invoices past due date and not settled by payments.
     *
     * @return list<Invoice>
     */
    private function overdueInvoices(Customer $customer, CarbonInterface $today): array
    {
        return $customer->invoices()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->get()
            ->filter(fn ($invoice) => ! $invoice->isSettled())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $decision  filled with the eligibility decision
     */
    private function skipReason(Customer $customer, int $overdueCount, BillingEligibilityService $eligibility, ?array &$decision): ?string
    {
        if (! $customer->router_id || ! $customer->router) {
            return 'no_router';
        }
        if (trim((string) $customer->pppoe_user) === '') {
            return 'no_pppoe_user';
        }
        if (in_array($customer->status, ['isolated', 'terminated'], true)) {
            return 'status_'.$customer->status;
        }
        if ($customer->status !== 'active') {
            return 'status_'.$customer->status.'_not_active';
        }
        if ($overdueCount === 0) {
            return 'no_overdue_invoice';
        }
        $decision = $eligibility->evaluate($customer);
        if (! ($decision['isolation_eligible'] ?? false) || (int) ($decision['current_invoice_id'] ?? 0) === 0) {
            $why = ($decision['billing_hold'] ?? false) ? 'billing_hold' : (string) ($decision['reason'] ?? 'policy');
            if (($decision['paid_this_month'] ?? false) && $why !== 'billing_hold') {
                $why .= ',paid_this_month';
            }

            return 'policy_not_eligible:'.$why;
        }

        return null;
    }

    /**
     * Re-arm a done/failed ledger row, run the handler, report the outcome.
     *
     * @return string isolated|isolated_radius_failed|skipped reasons=...
     */
    private function isolate(Customer $customer, int $invoiceId, ?BillingEnforcementAction $ledgerRow, User $actor, BillingEligibilityService $eligibility, BillingEnforcementLedger $ledger): string
    {
        // Same overlap lock the queued batch isolation of this router holds
        // (WithoutOverlapping key of BatchIsolateCustomersJob): never run the router call twice at once.
        $lock = Cache::lock('laravel-queue-overlap:'.BatchIsolateCustomersJob::class.':batch-isolate-router:'.(int) $customer->router_id, 600);
        if (! $lock->get()) {
            return 'skipped reasons=router_batch_running';
        }

        try {
            return $this->isolateLocked($customer, $invoiceId, $ledgerRow, $actor, $eligibility, $ledger);
        } finally {
            $lock->release();
        }
    }

    private function isolateLocked(Customer $customer, int $invoiceId, ?BillingEnforcementAction $ledgerRow, User $actor, BillingEligibilityService $eligibility, BillingEnforcementLedger $ledger): string
    {
        // Re-read under the router lock: the row may have changed since the preview.
        $ledgerRow = BillingEnforcementAction::query()
            ->where('idempotency_key', $ledger->key((int) $customer->id, $invoiceId, 'isolate'))
            ->first();
        if ($ledgerRow?->status === 'running') {
            return 'skipped reasons=ledger_running';
        }

        $rearm = null;
        $keptMetadata = (array) $ledgerRow?->metadata;
        if ($ledgerRow !== null && in_array($ledgerRow->status, ['succeeded', 'failed'], true)) {
            $rearm = [
                'rearmed_by' => self::SOURCE,
                'actor_id' => $actor->id,
                'rearmed_at' => now()->toIso8601String(),
                'previous_status' => $ledgerRow->status,
                'previous_completed_at' => $ledgerRow->completed_at?->toIso8601String(),
            ];
            $ledgerRow->forceFill(['status' => 'pending', 'metadata' => array_merge($keptMetadata, ['rearm' => $rearm])])->save();
        }

        $failure = null;
        try {
            $this->runIsolation($customer, $invoiceId);
        } catch (Throwable $e) {
            $failure = $e;
        }

        $ledgerLeft = 'none';
        $fresh = $customer->fresh();
        $isolated = $fresh?->status === 'isolated';
        if ($rearm !== null) {
            $row = BillingEnforcementAction::query()->find($ledgerRow->id);
            if ($row && $row->status === 'succeeded') {
                $row->forceFill(['metadata' => array_merge($keptMetadata, (array) $row->metadata, ['rearm' => $rearm])])->save();
            }
            $ledgerLeft = $row?->status ?? 'none';
        }

        if ($isolated) {
            activity()
                ->causedBy($actor)
                ->performedOn($fresh)
                ->event('customer_isolated_from_list')
                ->withProperties([
                    'source' => self::SOURCE,
                    'invoice_id' => $invoiceId,
                    'pppoe_user' => $fresh->pppoe_user,
                    'rearmed_ledger' => $rearm !== null,
                    'radius_failed' => $failure !== null,
                ])
                ->log('customer_isolated_from_list');

            return $failure === null ? 'isolated' : 'isolated_radius_failed';
        }

        if ($failure !== null) {
            throw $failure;
        }

        // Handler returned without isolating: say why.
        $decisionNow = $eligibility->evaluate($fresh ?? $customer);
        $why = ! ($decisionNow['isolation_eligible'] ?? false) ? 'policy_at_run' : 'ledger_done';

        return 'skipped reasons='.$why.($rearm !== null ? ' ledger_left='.$ledgerLeft : '');
    }

    /**
     * The same handler the enforcement queue runs, executed inline.
     */
    protected function runIsolation(Customer $customer, int $invoiceId): void
    {
        (new IsolateCustomerJob($customer, $invoiceId))->handle(
            app(MikrotikService::class),
            null,
            app(BillingEligibilityService::class),
            app(BillingEnforcementLedger::class),
        );
    }
}
