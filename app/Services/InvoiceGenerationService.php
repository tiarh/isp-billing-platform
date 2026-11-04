<?php

namespace App\Services;

use App\Jobs\ApplyCustomerCreditsJob;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceGenerationService
{
    /** activity_log description written for every invoice created by ensureForPeriod. */
    public const AUTO_GENERATED_MARKER = 'invoice_auto_generated';

    public function dueDateFor(Customer $customer, CarbonInterface $period): CarbonImmutable
    {
        $billingPeriod = CarbonImmutable::instance($period)->startOfMonth();
        $day = (int) ($customer->due_day ?? ($customer->join_date?->day ?? 20));
        $due = $billingPeriod->day(min(max($day, 1), $billingPeriod->daysInMonth));

        // A customer's first payment is due the month AFTER they join (the
        // joining month is settled at signup). Without this floor, a customer
        // who joined on the 25th got a due date in the same month -> overdue
        // within days -> isolated before their first month. Existing customers
        // are unaffected because their join month is in the past.
        if ($customer->join_date) {
            $firstDueMonth = CarbonImmutable::instance($customer->join_date)
                ->startOfMonth()
                ->addMonth();
            $firstDue = $firstDueMonth->day(min(max($day, 1), $firstDueMonth->daysInMonth));
            if ($due->lessThan($firstDue)) {
                $due = $firstDue;
            }
        }

        return $due;
    }

    /**
     * First billable period for a customer: the month AFTER they joined.
     * The joining month is settled at signup, so the first invoice is for the
     * following month (period + due date both fall in that next month).
     */
    public function firstBillablePeriod(Customer $customer): ?CarbonImmutable
    {
        if (! $customer->join_date) {
            return null;
        }

        return CarbonImmutable::instance($customer->join_date)->startOfMonth()->addMonth();
    }

    /** Resolve the period to bill: never the join month, at least join month + 1. */
    public function resolveBillingPeriod(Customer $customer, CarbonInterface $period): CarbonImmutable
    {
        $billingPeriod = CarbonImmutable::instance($period)->startOfMonth();
        $firstBillable = $this->firstBillablePeriod($customer);

        if ($firstBillable !== null && $billingPeriod->lessThan($firstBillable)) {
            return $firstBillable;
        }

        return $billingPeriod;
    }

    /**
     * Due date for an invoice created NOW. Policy (owner 2026-10-08): an
     * invoice created after its regular due date (late PSB activation,
     * catch-up run) is due on its creation date (WIB) instead, so isolation
     * happens H+grace after the invoice exists, never instantly.
     */
    public function dueDateForNewInvoice(Customer $customer, CarbonInterface $period, ?CarbonInterface $now = null): CarbonImmutable
    {
        $due = $this->dueDateFor($customer, $period);
        $today = CarbonImmutable::parse(OpsTime::today($now), OpsTime::TZ)->startOfDay();
        $dueLocal = CarbonImmutable::parse($due->toDateString(), OpsTime::TZ)->startOfDay();

        return $dueLocal->lessThan($today) ? $today : $dueLocal;
    }

    /**
     * Why a customer must NOT get a current-period invoice right now, or null
     * when it should. Used by the activation hook job and the daily catch-up
     * run (current period only; arrears are never created automatically).
     *
     * - join_date_null: no join date (bulk/raw inserts), owner decides.
     * - join_month: still in the join month (never billed); the regular run
     *   of join month + 1 bills them.
     * - no_package / price_zero: nothing billable (Rp0 packages get no invoice).
     */
    public function currentPeriodSkipReason(Customer $customer, ?CarbonInterface $now = null, ?array $heldCustomerIds = null): ?string
    {
        if (! in_array((string) $customer->status, ['active', 'isolated'], true)) {
            return 'status_not_billable';
        }
        $held = $heldCustomerIds !== null
            ? in_array((int) $customer->getKey(), $heldCustomerIds, true)
            : LifecycleTerminationService::billingHeld($customer);
        if ($held) {
            return 'pending_termination_hold';
        }
        if (! $customer->join_date) {
            return 'join_date_null';
        }
        $current = CarbonImmutable::parse(OpsTime::today($now), OpsTime::TZ)->startOfMonth();
        // Compare calendar dates: join_date is a UTC-midnight Carbon, the
        // period a WIB one; the same month must compare equal.
        if ($this->resolveBillingPeriod($customer, $current)->toDateString() !== $current->toDateString()) {
            return 'join_month';
        }
        $package = $customer->package;
        if (! $package) {
            return 'no_package';
        }
        if ((float) $package->price <= 0) {
            return 'price_zero';
        }

        return null;
    }

    public function ensureCurrentPeriod(Customer $customer, bool $dryRun = false): ?Invoice
    {
        return $this->ensureForPeriod($customer, OpsTime::now()->startOfMonth(), $dryRun);
    }

    public function ensureForPeriod(Customer $customer, CarbonInterface $period, bool $dryRun = false): ?Invoice
    {
        $billingPeriod = $this->resolveBillingPeriod($customer, $period);
        $periodDate = $billingPeriod->toDateString();

        if ($dryRun) {
            return Invoice::query()
                ->where('customer_id', $customer->getKey())
                ->where('period', $periodDate)
                ->first();
        }

        return DB::transaction(function () use ($customer, $billingPeriod, $periodDate) {
            /** @var Customer|null $lockedCustomer */
            $lockedCustomer = Customer::query()
                ->whereKey($customer->getKey())
                ->with('package')
                ->lockForUpdate()
                ->first();

            if (! $lockedCustomer || ! in_array($lockedCustomer->status, ['active', 'isolated'], true)) {
                return null;
            }

            // Roadmap 1.3 PR-4a "tahan tagihan" (H1): a customer with a pending
            // (guard-deferred) termination gets no new invoice from any caller.
            if (LifecycleTerminationService::billingHeld($lockedCustomer)) {
                Log::info('[billing] held_pending_termination', [
                    'customer_id' => $lockedCustomer->getKey(),
                    'period' => $periodDate,
                ]);

                return null;
            }

            $existing = Invoice::query()
                ->where('customer_id', $lockedCustomer->getKey())
                ->where('period', $periodDate)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            if (! $lockedCustomer->package) {
                return null;
            }

            $invoice = Invoice::create([
                'customer_id' => $lockedCustomer->getKey(),
                'period' => $periodDate,
                'due_date' => $this->dueDateForNewInvoice($lockedCustomer, $billingPeriod)->toDateString(),
                'amount' => $lockedCustomer->package->price,
                'status' => 'unpaid',
                // Storage is UTC: a WIB-zoned Carbon would be written with its +7h wall clock.
                'generated_at' => OpsTime::now()->utc(),
            ]);

            // Pay PR-3: spend the customer's saldo on the NEW invoice, in a separate
            // after-commit job (never inside this transaction: a credit failure must
            // not abort invoice generation). Only this branch creates invoices, so
            // re-runs ($existing above) never dispatch twice.
            // Marker for the catch-up sweep (Q4: only ensureForPeriod invoices get
            // credit): admin/import/sync paths never write it. No schema change.
            activity()
                ->performedOn($invoice)
                ->event('auto_generated')
                ->withProperties(['period' => $periodDate])
                ->log(self::AUTO_GENERATED_MARKER);

            if (CustomerAutoCreditGate::enabled()
                && CustomerCredit::query()
                    ->where('customer_id', $lockedCustomer->getKey())
                    ->where('status', CustomerCredit::STATUS_AVAILABLE)
                    ->exists()) {
                $invoiceId = (int) $invoice->getKey();
                // A queue/Redis failure must never abort invoice generation (the
                // catch-up sweep is the safety net), hence the try/catch inside the callback.
                DB::afterCommit(function () use ($invoiceId): void {
                    try {
                        ApplyCustomerCreditsJob::dispatch($invoiceId);
                    } catch (\Throwable $e) {
                        Log::warning('[billing] credit apply dispatch failed', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
                    }
                });
            }

            return $invoice;
        });
    }
}
