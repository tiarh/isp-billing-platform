<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for billing enforcement decisions.
 *
 * "Current" means the latest non-void invoice by billing period. A future
 * invoice is intentionally not current for enforcement; old arrears remain
 * receivables but cannot override a paid current invoice.
 */
class BillingEligibilityService
{
    public const POLICY_VERSION = 'current-invoice-v1';

    /**
     * @param  list<int>|null  $heldCustomerIds  prefetched held ids (null = query per call)
     * @return array<string, mixed>
     */
    public function evaluate(Customer $customer, ?CarbonInterface $at = null, ?array $heldCustomerIds = null): array
    {
        $decision = $this->evaluateCore($customer, $at);

        // Roadmap 1.3 PR-4a "tahan tagihan" (H3): a customer with a pending
        // (guard-deferred) termination is never isolated. Reconnect and every
        // other flag stay exactly as computed. Bulk callers pass the held ids
        // fetched once per chunk (LifecycleTerminationService::heldCustomerIds).
        $held = $heldCustomerIds !== null
            ? in_array((int) $customer->getKey(), $heldCustomerIds, true)
            : LifecycleTerminationService::billingHeld($customer);
        $decision['billing_hold'] = $held;
        $decision['hold_reason'] = $held ? 'pending_termination' : null;
        if ($held) {
            $decision['isolation_eligible'] = false;
        }

        return $decision;
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateCore(Customer $customer, ?CarbonInterface $at = null): array
    {
        $now = $at ? $at->copy() : OpsTime::now();

        // Owner 2026-10-08: customers on a Rp0 package (e.g. Paket CSR) are
        // never isolated, whatever invoices they carry. Checked before any
        // invoice logic so no settled/arrears rule can override it.
        if (static::isPriceZeroExempt($customer)) {
            return $this->result($customer->id, null, 'price_zero_exempt');
        }

        $current = $customer->invoices()
            ->where('status', '!=', 'void')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->first();

        if (! $current) {
            return $this->result($customer->id, null, 'no_current_invoice');
        }

        $dueDate = $current->due_date
            ? CarbonImmutable::parse($current->due_date->toDateString(), OpsTime::TZ)
            : null;
        $today = $now->copy()->startOfDay();
        $currentPeriod = $current->period?->format('Y-m');
        $futurePeriod = $currentPeriod !== null && $currentPeriod > $now->format('Y-m');
        $notDue = $dueDate === null || $dueDate->greaterThan($today);
        // A joining month is never billable (settled at signup). Guard against
        // isolating a customer whose current invoice is for their join month,
        // which produced same-month due dates and early isolation.
        $joinMonth = $customer->join_date?->format('Y-m');
        $joinMonthNotBillable = $joinMonth !== null
            && $currentPeriod !== null
            && $currentPeriod <= $joinMonth;
        $settled = $current->isSettled();
        $graceDays = max(0, (int) Setting::get(
            'billing_isolir_after_days',
            Setting::get('billing_grace_period_days', 3)
        ));
        $isolationCutoff = $today->copy()->subDays($graceDays);
        $graceElapsed = $dueDate !== null && $dueDate->lessThanOrEqualTo($isolationCutoff);
        $outstanding = in_array($current->status, ['unpaid', 'overdue'], true) && ! $settled;
        $isolated = in_array($customer->status, CustomerStatus::isolatedValues(), true);

        // STEP 2026-10-01 (owner): legacy arrears must also isolate. The old
        // policy only looked at the current invoice, so a customer with an
        // unpaid invoice from a previous month (current month invoice just
        // issued, not yet past grace) was never isolated — admins reported
        // "H+3 sudah lewat tapi belum diisolir".
        $arrearsPastGrace = false;
        $arrearsPaid = false;
        // STEP 2026-10-02 (owner): a customer who paid the most recent billable
        // month must reconnect/not-isolate even when the current-month invoice is
        // only just issued and not yet due, and even if OLDER months are still
        // unpaid (Group B — older arrears are receivables, not an isolation
        // trigger). Previously reconnect required the current invoice itself to
        // be settled (or a same-calendar-month payment), so a customer who paid
        // September stayed isolated once the October invoice appeared (reason
        // current_unpaid_grace). $priorInvoicesSettled/$priorArrearsUnpaid track
        // the full history for observability; the decision uses
        // $latestPriorInvoiceSettled below.
        $priorInvoicesSettled = true;
        $priorArrearsUnpaid = false;
        // STEP 2026-10-02 (owner, Group B): the newest invoice period that is
        // already billable (period < current) decides whether the customer has
        // "paid the latest month". Older unpaid months remain receivables but do
        // not isolate a customer who has paid the most recent billable month.
        // null when there is no earlier invoice.
        $latestPriorInvoiceSettled = null;
        // L1 murni (owner): pembayaran bulan berjalan melindungi dari isolir.
        // null = aktif by default; set false untuk mematikan tanpa deploy.
        $protectSetting = Setting::get('billing_paid_this_month_protects', null);
        $protectSetting = $protectSetting === null ? null : filter_var($protectSetting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dueDate === null || $dueDate->greaterThan($isolationCutoff) || ! $outstanding) {
            $arrears = $customer->invoices()
                ->where('status', '!=', 'void')
                ->where('id', '!=', $current->id)
                ->where(function ($q) use ($current) {
                    $q->where('period', '<', $current->period)
                        ->orWhere(function ($q2) use ($current) {
                            $q2->whereRaw('period = ?', [$current->period])->where('id', '<', $current->id);
                        });
                })
                ->orderByDesc('period')
                // Arrears settle through Invoice::isSettled() (effective payments
                // minus reversals; Rp0 = settled), not status === 'paid': a
                // gateway/manual payment that left the status `unpaid` must not
                // count as arrears.
                ->with(['transactions', 'reversals'])
                ->get(['id', 'period', 'due_date', 'status', 'amount']);
            $cutoff = $isolationCutoff->toDateString();
            // $arrears is ordered by period desc, so the first entry is the most
            // recent period strictly before the current invoice.
            $latestPriorInvoiceSettled = $arrears->isEmpty()
                ? null
                : $this->arrearsInvoiceSettled($arrears->first());
            foreach ($arrears as $inv) {
                $invDue = $inv->due_date
                    ? CarbonImmutable::parse($inv->due_date->toDateString(), OpsTime::TZ)->toDateString()
                    : null;
                $invSettled = $this->arrearsInvoiceSettled($inv);
                if (! $invSettled) {
                    $priorInvoicesSettled = false;
                    $priorArrearsUnpaid = true;
                }
                if ($invDue === null) {
                    continue;
                }
                $invPastGrace = $invDue <= $cutoff;
                if ($invPastGrace && ! $invSettled) {
                    $arrearsPastGrace = true;
                }
                if ($invSettled) {
                    $arrearsPaid = true;
                }
            }
        }
        $reason = 'current_unpaid';
        if ($joinMonthNotBillable) {
            $reason = 'join_month_not_billable';
        } elseif ($futurePeriod) {
            $reason = 'current_not_due';
        } elseif ($settled) {
            $reason = 'current_paid';
        } elseif (! $graceElapsed) {
            $reason = 'current_unpaid_grace';
        }

        // Group B (owner 2026-10-02): do not isolate on older arrears when the
        // most recent billable month is already paid. Older months stay as
        // receivables without cutting the customer off. Also never isolate on
        // older arrears when the CURRENT (latest) invoice is already settled —
        // a customer who paid the current month must not be cut off for older
        // months (e.g. paid October but older months still unpaid).
        $isolationByArrears = $arrearsPastGrace
            && $customer->status === 'active'
            && ! $futurePeriod
            && ! $joinMonthNotBillable
            && $latestPriorInvoiceSettled !== true
            && ! $settled;

        // STEP 2026-10-01 (owner, L1 murni): a payment made in the current month
        // protects the customer from isolation — no matter how much arrears
        // remain. This is intentionally soft; switch off with the setting below.
        // Payments live on invoices (Customer hasManyThrough Invoice -> Transaction).
        $paidThisMonth = false;
        if ($protectSetting === null || $protectSetting !== false) {
            // 2026-10-08 (audit Bug A): manual payments are stored as `verified`,
            // which the old whitelist (success/paid/settlement/completed) never
            // matched, so L1 was dead in production. "This month" is the WIB
            // calendar month converted to UTC bounds (columns are UTC), and a
            // payment counts when EITHER paid_at OR created_at (when it was
            // recorded) falls in it, so a backdated paid_at still protects.
            // Reversed payments never count.
            $wibNow = CarbonImmutable::instance($now)->setTimezone(OpsTime::TZ);
            $monthBounds = [
                $wibNow->startOfMonth()->utc()->toDateTimeString(),
                $wibNow->endOfMonth()->utc()->toDateTimeString(),
            ];
            $paidThisMonth = DB::table('transactions')
                ->join('invoices', 'invoices.id', '=', 'transactions.invoice_id')
                ->where('invoices.customer_id', $customer->id)
                ->whereIn('transactions.status', Transaction::SETTLED_STATUSES)
                ->where(function ($q) use ($monthBounds) {
                    $q->whereBetween('transactions.paid_at', $monthBounds)
                        ->orWhereBetween('transactions.created_at', $monthBounds);
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('payment_reversals')
                        ->whereColumn('payment_reversals.transaction_id', 'transactions.id');
                })
                ->exists();
        }
        $protected = $paidThisMonth
            && $customer->status === 'active'
            && ! $futurePeriod
            && ! $joinMonthNotBillable;

        $reconnectAllSettled = $arrearsPaid && ! $outstanding;
        $reconnectPaidThisMonth = $isolated && $paidThisMonth;
        // STEP 2026-10-02 (owner, Group B): paid the most recent billable month.
        // The current invoice is issued but not yet due (no future period, grace
        // not reached) and the latest prior invoice period is settled. Older
        // unpaid months do not block reconnect. This is the "sudah bayar
        // September, baru masuk Oktober" case, even with older arrears.
        $reconnectPaidThroughPriorMonth = $isolated
            && $latestPriorInvoiceSettled === true
            && ! $futurePeriod
            && $notDue;

        return [
            'policy_version' => self::POLICY_VERSION,
            'customer_id' => $customer->id,
            'current_invoice' => $current,
            'current_invoice_id' => $current->id,
            'current_period' => $current->period?->toDateString(),
            'current_due_date' => $current->due_date?->toDateString(),
            'current_is_future' => $futurePeriod,
            'current_is_paid' => $settled,
            'current_is_outstanding' => $outstanding,
            'grace_days' => $graceDays,
            'grace_elapsed' => $graceElapsed,
            'join_month_not_billable' => $joinMonthNotBillable,
            'arrears_past_grace' => $arrearsPastGrace,
            'paid_this_month' => $paidThisMonth,
            'prior_invoices_settled' => $priorInvoicesSettled,
            'prior_arrears_unpaid' => $priorArrearsUnpaid,
            'latest_prior_invoice_settled' => $latestPriorInvoiceSettled,
            'reconnect_paid_through_prior_month' => $reconnectPaidThroughPriorMonth,
            'isolation_eligible' => ! $protected
                && (($customer->status === 'active'
                    && $outstanding
                    && ! $futurePeriod
                    && ! $joinMonthNotBillable
                    && ! $notDue
                    && $graceElapsed)
                    || $isolationByArrears),
            'reconnect_eligible' => ($isolated && $settled && ! $futurePeriod)
                || ($isolated && $reconnectAllSettled && ! $futurePeriod)
                || ($reconnectPaidThisMonth && ! $futurePeriod)
                || $reconnectPaidThroughPriorMonth,
            'dismantle_eligible' => false,
            'reason' => $reason,
        ];
    }

    public static function isPriceZeroExempt(Customer $customer): bool
    {
        $package = $customer->relationLoaded('package')
            ? $customer->package
            : $customer->package()->first(['id', 'price']);

        return $package !== null && $package->price !== null && (float) $package->price <= 0;
    }

    /**
     * A prior invoice is settled when its status is `paid` (including
     * admin-accepted short payments after a price change, where transactions
     * are below the amount) or when effective payments cover it.
     * Reversals move the status off `paid`, so they still reopen arrears.
     */
    private function arrearsInvoiceSettled(Invoice $invoice): bool
    {
        return $invoice->status === 'paid' || $invoice->isSettled();
    }

    private function result(int $customerId, ?Invoice $invoice, string $reason): array
    {
        return [
            'policy_version' => self::POLICY_VERSION,
            'customer_id' => $customerId,
            'current_invoice' => $invoice,
            'current_invoice_id' => null,
            'current_period' => null,
            'current_due_date' => null,
            'current_is_future' => false,
            'current_is_paid' => false,
            'current_is_outstanding' => false,
            'grace_days' => 0,
            'grace_elapsed' => false,
            'join_month_not_billable' => false,
            'arrears_past_grace' => false,
            'paid_this_month' => false,
            'prior_invoices_settled' => true,
            'prior_arrears_unpaid' => false,
            'latest_prior_invoice_settled' => null,
            'reconnect_paid_through_prior_month' => false,
            'isolation_eligible' => false,
            'reconnect_eligible' => false,
            'dismantle_eligible' => false,
            'reason' => $reason,
        ];
    }
}
