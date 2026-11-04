<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Services\CustomerAutoCreditGate;
use App\Services\CustomerCreditService;
use App\Services\InvoiceGenerationService;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\OpsTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:generate 
                            {--month= : The billing month (YYYY-MM), defaults to current month}
                            {--dry-run : Simulate generation without creating records}
                            {--catch-up : Daily self-heal: bill only the CURRENT period for active/isolated customers missing it (skips join month, Rp0, NULL join_date)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate monthly invoices for all active customers';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceGenerationService $generator, CustomerCreditService $credits)
    {
        if ($this->option('catch-up')) {
            return $this->catchUp($generator, $credits, (bool) $this->option('dry-run'));
        }

        $inputMonth = $this->option('month');
        $isDryRun = $this->option('dry-run');

        // Determine Billing Period (1st of the month)
        $period = $inputMonth
            ? Carbon::createFromFormat('!Y-m', $inputMonth)->startOfMonth()
            : OpsTime::now()->startOfMonth();

        $this->info('Billing Period: '.$period->format('F Y'));
        // $this->info("Due Date: " . $dueDate->format('Y-m-d')); // Removed global due date
        if ($isDryRun) {
            $this->warn('!! DRY RUN MODE - No database changes will be made !!');
        }

        // Fetch eligible customers (Active or Suspended)
        // Suspended users still get billed until offboarded/churned
        $heldIds = [];
        $customers = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereHas('package') // Ensure they have a package
            ->with('package')
            ->chunk(100, function ($chunk) use ($period, $isDryRun, $generator, &$heldIds) {
                // "Tahan tagihan" (1.3 PR-4a H2): pending-termination customers are reported, not billed.
                $held = LifecycleTerminationService::heldCustomerIds($chunk->pluck('id')->map(fn ($id) => (int) $id)->all());
                foreach ($chunk as $customer) {
                    if (in_array((int) $customer->id, $held, true)) {
                        if ($this->holdSkipsCustomer($customer, $period, $generator)) {
                            $heldIds[] = (int) $customer->id;
                        }

                        continue;
                    }
                    $this->processCustomer($customer, $period, $isDryRun, $generator);
                }
            });

        if ($heldIds !== []) {
            $this->warn('held_pending_termination='.count($heldIds).' (tahan tagihan, not billed): '.implode(',', $heldIds));
            Log::info('[billing:generate] held_pending_termination', ['count' => count($heldIds), 'customer_ids' => $heldIds, 'dry_run' => (bool) $isDryRun]);
        }

        $this->newLine();
        $this->info('Billing generation completed.');
    }

    /** A held customer only counts when it would otherwise have been billed (no invoice for the period yet). */
    private function holdSkipsCustomer($customer, $period, InvoiceGenerationService $generator): bool
    {
        $billingPeriod = $generator->resolveBillingPeriod($customer, $period);

        return ! Invoice::where('customer_id', $customer->id)
            ->where('period', $billingPeriod->format('Y-m-d'))
            ->exists();
    }

    private function processCustomer($customer, $period, $isDryRun, InvoiceGenerationService $generator)
    {
        // The joining month is never billed: the first invoice is for the month
        // AFTER signup (period + due date both in that next month).
        $billingPeriod = $generator->resolveBillingPeriod($customer, $period);
        $dueDate = $generator->dueDateFor($customer, $billingPeriod);

        // Check Idempotency: Has an invoice been generated for this period?
        $exists = Invoice::where('customer_id', $customer->id)
            ->where('period', $billingPeriod->format('Y-m-d'))
            ->exists();

        if ($exists) {
            // $this->line("Skipping {$customer->name} (Invoice exists)");
            return;
        }

        $amount = $customer->package->price;

        $this->line("Generating invoice for: <comment>{$customer->name}</comment> (Rp ".number_format($amount).') - Due: '.$dueDate->format('Y-m-d'));

        if (! $isDryRun) {
            // The per-chunk prefetch only filters/reports; ensureForPeriod re-checks the hold inside its locked transaction.
            $generator->ensureForPeriod($customer, $period);
        }
    }

    /**
     * Daily catch-up (owner 2026-10-08): any active/isolated customer missing
     * the CURRENT period invoice gets it, whatever path made them billable
     * after the 1st (manual activation, raw insert, import). Idempotent via
     * InvoiceGenerationService::ensureForPeriod. Never bills arrears or the
     * join month; Rp0 packages and NULL join_date customers are reported only.
     */
    private function catchUp(InvoiceGenerationService $generator, CustomerCreditService $credits, bool $dryRun): int
    {
        $now = OpsTime::now();
        $period = $now->startOfMonth();
        $stats = ['created' => 0, 'would_create' => 0, 'skipped_exists' => 0, 'join_month' => 0, 'join_date_null' => 0, 'no_package' => 0, 'price_zero' => 0];
        $reported = ['join_date_null' => [], 'price_zero' => []];

        $this->info('Catch-up period: '.$period->toDateString().($dryRun ? ' (dry-run)' : ''));

        Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereDoesntHave('invoices', fn ($q) => $q->where('period', $period->toDateString()))
            ->with('package')
            ->chunkById(200, function ($chunk) use ($generator, $dryRun, $now, $period, &$stats, &$reported) {
                $heldIds = LifecycleTerminationService::heldCustomerIds($chunk->pluck('id')->map(fn ($id) => (int) $id)->all());
                foreach ($chunk as $customer) {
                    $skip = $generator->currentPeriodSkipReason($customer, $now, $heldIds);
                    if ($skip !== null) {
                        $stats[$skip] = ($stats[$skip] ?? 0) + 1;
                        if (isset($reported[$skip]) || $skip === 'pending_termination_hold') {
                            $reported[$skip][] = (int) $customer->id;
                        }

                        continue;
                    }

                    $due = $generator->dueDateForNewInvoice($customer, $period, $now)->toDateString();
                    $this->line("#{$customer->id} {$customer->name}: Rp ".number_format((float) $customer->package->price)." due {$due}");
                    if ($dryRun) {
                        $stats['would_create']++;

                        continue;
                    }

                    $existed = Invoice::where('customer_id', $customer->id)->where('period', $period->toDateString())->exists();
                    $invoice = $generator->ensureForPeriod($customer, $period);
                    if ($invoice && ! $existed) {
                        $stats['created']++;
                    } else {
                        $stats['skipped_exists']++;
                    }
                }
            });

        // Only reported while the gate is ON (summary line stays unchanged otherwise).
        if (CustomerAutoCreditGate::enabled()) {
            $stats['credit_applied'] = $this->applyCreditsToCurrentInvoices($credits, $period, $dryRun);
        }

        $this->info('Summary: '.json_encode($stats));
        foreach ($reported as $reason => $ids) {
            if ($ids !== []) {
                $note = $reason === 'pending_termination_hold' ? 'tahan tagihan, not billed' : 'owner decision, not billed';
                $this->warn("{$reason} ({$note}): ".implode(',', $ids));
            }
        }
        Log::info('[billing:generate --catch-up] done', ['dry_run' => $dryRun, 'period' => $period->toDateString(), 'stats' => $stats, 'reported' => $reported]);

        return self::SUCCESS;
    }

    /**
     * Safety net (Pay PR-3, gate billing_credit_auto_apply_enabled): current-period
     * unpaid/overdue invoices of customers that hold available credit get the credit
     * applied synchronously. Covers a missed/failed after-commit job and credit that
     * appears after the invoice exists. Idempotent (autoApplyToInvoice re-checks under
     * the invoice lock). Dry-run only counts. Returns the number of invoices touched.
     */
    private function applyCreditsToCurrentInvoices(CustomerCreditService $credits, CarbonInterface $period, bool $dryRun): int
    {
        if (! CustomerAutoCreditGate::enabled()) {
            return 0;
        }

        $touched = 0;
        Invoice::query()
            ->where('period', $period->toDateString())
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNotNull('generated_at')
            // Q4: only invoices created by ensureForPeriod (marker activity row).
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('activity_log')
                    ->where('activity_log.subject_type', (new Invoice)->getMorphClass())
                    ->whereColumn('activity_log.subject_id', 'invoices.id')
                    ->where('activity_log.description', InvoiceGenerationService::AUTO_GENERATED_MARKER);
            })
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'invoices.customer_id')
                    ->whereIn('customers.status', CustomerStatus::billable());
            })
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('customer_credits')
                    ->whereColumn('customer_credits.customer_id', 'invoices.customer_id')
                    ->where('customer_credits.status', CustomerCredit::STATUS_AVAILABLE);
            })
            ->chunkById(200, function ($chunk) use ($credits, $dryRun, &$touched) {
                foreach ($chunk as $invoice) {
                    if ($dryRun) {
                        $this->line("#{$invoice->customer_id} invoice #{$invoice->id}: would apply available credit");
                        $touched++;

                        continue;
                    }
                    try {
                        $result = $credits->autoApplyToInvoice((int) $invoice->id);
                    } catch (\Throwable $e) {
                        Log::warning('[billing:generate --catch-up] credit apply failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

                        continue;
                    }
                    if ($result['credits'] > 0) {
                        $touched++;
                    }
                }
            });

        return $touched;
    }
}
