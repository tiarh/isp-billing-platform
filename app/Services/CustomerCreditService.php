<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Jobs\ReconnectCustomerJob;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Customer credits (saldo kelebihan bayar). No refunds are made here: money
 * that cannot be credited to its own invoice is parked in customer_credits and
 * an admin applies it to a later unpaid invoice of the same customer.
 */
class CustomerCreditService
{
    /** Gateway providers whose double/over payments become credits (Midtrans and generic webhooks keep the old rejection). */
    public const CREDIT_PROVIDERS = ['duitku'];

    public static function providerCredits(string $provider): bool
    {
        return in_array(strtolower($provider), self::CREDIT_PROVIDERS, true);
    }

    /**
     * Records a credit for money that arrived from the gateway. MUST run inside
     * the settlement DB transaction (invoice row already locked). Idempotent on
     * (provider, gateway credit key): a retried callback returns the same row.
     */
    public function recordFromGateway(
        Invoice $invoice,
        string $provider,
        string $creditKey,
        float $amount,
        string $reasonCode,
        string $status,
        ?PaymentGatewayEvent $event = null,
    ): CustomerCredit {
        $sourceKey = $provider.':'.$creditKey;
        $existing = CustomerCredit::query()->where('source_key', $sourceKey)->first();
        if ($existing) {
            return $existing;
        }

        return CustomerCredit::create([
            'customer_id' => $invoice->customer_id,
            'source_invoice_id' => $invoice->id,
            'payment_gateway_event_id' => $event?->id,
            'provider' => $provider,
            'gateway_reference' => $event?->reference ?? $creditKey,
            'source_key' => $sourceKey,
            'amount' => round($amount, 2),
            'reason_code' => $reasonCode,
            'status' => $status,
        ]);
    }

    /**
     * Applies (part of) an available credit to an UNPAID invoice of the same
     * customer. A partial apply leaves the remainder available on the original
     * row and writes a split row for the applied part.
     *
     * @param  float  $expectedAmount  credit amount the admin saw; stale views (double click) are refused
     * @return array{credit: CustomerCredit, transaction: Transaction, settled: bool}
     */
    public function apply(int $creditId, int $invoiceId, ?float $amount, float $expectedAmount, User $actor): array
    {
        $result = DB::transaction(function () use ($creditId, $invoiceId, $amount, $expectedAmount, $actor) {
            // LOCK ORDER: invoice row first, then credit row. Every money path
            // (settleInvoice, PaymentReversalService, auto-apply) locks the invoice
            // first; keep it that way or concurrent paths can deadlock.
            $invoice = Invoice::query()->with('customer')->lockForUpdate()->findOrFail($invoiceId);
            $credit = CustomerCredit::query()->lockForUpdate()->findOrFail($creditId);

            if ($credit->status !== CustomerCredit::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['credit' => 'Saldo ini tidak berstatus tersedia (mungkin sudah diterapkan).']);
            }
            if (abs((float) $credit->amount - $expectedAmount) > 0.001) {
                throw ValidationException::withMessages(['credit' => 'Nominal saldo sudah berubah. Muat ulang halaman lalu coba lagi.']);
            }
            if ((int) $invoice->customer_id !== (int) $credit->customer_id) {
                throw ValidationException::withMessages(['invoice_id' => 'Tagihan harus milik pelanggan yang sama dengan saldo.']);
            }
            if (! in_array($invoice->status, ['unpaid', 'overdue'], true) || $invoice->isSettled()) {
                throw ValidationException::withMessages(['invoice_id' => 'Saldo hanya bisa diterapkan ke tagihan yang belum lunas.']);
            }

            $creditAmount = round((float) $credit->amount, 2);
            $remaining = round((float) $invoice->amount - $invoice->totalPaid(), 2);
            $applyAmount = $amount === null ? min($creditAmount, $remaining) : round($amount, 2);
            if ($applyAmount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
            }
            if ($applyAmount > $creditAmount + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi saldo tersedia.']);
            }
            if ($applyAmount > $remaining + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa tagihan: '.number_format($remaining, 2, ',', '.')]);
            }

            $applied = $this->applyLocked($invoice, $credit, $applyAmount, $actor, 'admin');

            return ['credit' => $credit->fresh(), 'transaction' => $applied['transaction'], 'settled' => $applied['settled']];
        });

        AnalyticsCache::flushForUser($actor);

        return $result;
    }

    /**
     * System auto-apply (no actor): spends the customer's available credits,
     * oldest first, on ONE unpaid/overdue invoice. Idempotent: a settled, paid,
     * void or missing invoice is a no-op, so a retried/duplicate job never
     * applies twice. Excess credit stays available (split); a short credit leaves
     * the invoice unpaid with the remainder.
     *
     * Lock order: invoice, then credits (same as apply()).
     *
     * @return array{applied: float, credits: int, settled: bool}
     */
    public function autoApplyToInvoice(int $invoiceId): array
    {
        $result = DB::transaction(function () use ($invoiceId): array {
            $noop = ['applied' => 0.0, 'credits' => 0, 'settled' => false];

            $invoice = Invoice::query()->with('customer')->lockForUpdate()->find($invoiceId);
            if (! $invoice || ! in_array($invoice->status, ['unpaid', 'overdue'], true) || $invoice->isSettled()) {
                return $noop;
            }
            // Only billable customers (active/isolated) get credit spent automatically.
            if (! in_array((string) $invoice->customer?->status, CustomerStatus::billable(), true)) {
                return $noop;
            }

            $credits = CustomerCredit::query()
                ->where('customer_id', $invoice->customer_id)
                ->where('status', CustomerCredit::STATUS_AVAILABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $appliedTotal = 0.0;
            $count = 0;
            $settled = false;
            $ids = [];
            foreach ($credits as $credit) {
                // Never re-spend a credit (or the split it came from) that was already
                // used on THIS invoice and then reversed by an admin: that is a manual
                // decision auto-apply must not undo. An admin can still apply it by hand.
                if ($this->wasAppliedToInvoice($credit, (int) $invoice->id)) {
                    continue;
                }
                $remaining = round((float) $invoice->amount - $invoice->totalPaid(), 2);
                if ($remaining <= 0) {
                    break;
                }
                $creditAmount = round((float) $credit->amount, 2);
                $applyAmount = min($creditAmount, $remaining);
                if ($applyAmount <= 0) {
                    continue;
                }

                $applied = $this->applyLocked($invoice, $credit, $applyAmount, null, 'auto');
                $appliedTotal = round($appliedTotal + $applyAmount, 2);
                $count++;
                $ids[] = ['credit_id' => $credit->id, 'amount' => $applyAmount];
                $settled = $applied['settled'];
                if ($settled) {
                    break;
                }
            }

            if ($count > 0) {
                Log::info('[billing] credit_auto_applied', [
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'applications' => $ids,
                    'settled' => $settled,
                ]);
            }

            return ['applied' => $appliedTotal, 'credits' => $count, 'settled' => $settled];
        });

        if ($result['credits'] > 0) {
            AnalyticsCache::flushAll();
        }

        return $result;
    }

    /**
     * True when the credit, or any ancestor it was split from, already has a credit
     * Transaction (any status, reversed ones included) on this invoice. Split rows
     * carry no key of their own: the Transaction key uses the parent's id.
     */
    private function wasAppliedToInvoice(CustomerCredit $credit, int $invoiceId): bool
    {
        $id = (int) $credit->id;
        for ($depth = 0; $id > 0 && $depth < 10; $depth++) {
            $exists = Transaction::query()
                ->where('invoice_id', $invoiceId)
                ->where('idempotency_key', 'like', 'credit:'.$id.':'.$invoiceId.':%')
                ->exists();
            if ($exists) {
                return true;
            }
            $id = (int) CustomerCredit::query()->whereKey($id)->value('parent_credit_id');
        }

        return false;
    }

    /**
     * Shared money step. Caller holds the invoice lock and then the credit lock,
     * has validated everything and passes the exact amount to apply (> 0, <= credit,
     * <= invoice remaining). $actor null = system (auto-apply).
     *
     * @return array{transaction: Transaction, settled: bool, partial: bool, applied_row: CustomerCredit}
     */
    private function applyLocked(Invoice $invoice, CustomerCredit $credit, float $applyAmount, ?User $actor, string $source): array
    {
        $creditAmount = round((float) $credit->amount, 2);
        $partial = $applyAmount < $creditAmount - 0.001;
        $keyBase = sprintf('credit:%d:%d:%s', $credit->id, $invoice->id, number_format($creditAmount, 2, '.', ''));
        // A credit restored by a payment reversal may be applied again: count earlier uses of the same base key.
        $uses = Transaction::query()->where('idempotency_key', 'like', $keyBase.'%')->count();
        $key = $uses === 0 ? $keyBase : $keyBase.':'.$uses;
        $existing = Transaction::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            throw ValidationException::withMessages(['credit' => 'Penerapan saldo ini sudah diproses sebelumnya.']);
        }

        $transaction = Transaction::create([
            'reference' => 'CREDIT-'.$credit->id.'-'.$invoice->id.'-'.now()->format('ymdHis').($uses === 0 ? '' : '-'.$uses),
            'invoice_id' => $invoice->id,
            'idempotency_key' => $key,
            'channel' => 'manual',
            'admin_id' => $actor?->id,
            'amount' => $applyAmount,
            'method' => 'other',
            'status' => 'verified',
            'paid_at' => now(),
            'notes' => 'Saldo kelebihan bayar #'.$credit->id.($credit->gateway_reference ? ' (order '.$credit->gateway_reference.')' : ''),
        ]);

        if ($partial) {
            $applied = CustomerCredit::create([
                'customer_id' => $credit->customer_id,
                'source_invoice_id' => $credit->source_invoice_id,
                'payment_gateway_event_id' => $credit->payment_gateway_event_id,
                'provider' => $credit->provider,
                'gateway_reference' => $credit->gateway_reference,
                'parent_credit_id' => $credit->id,
                'amount' => $applyAmount,
                'reason_code' => $credit->reason_code,
                'status' => CustomerCredit::STATUS_APPLIED,
                'applied_invoice_id' => $invoice->id,
                'applied_transaction_id' => $transaction->id,
                'applied_at' => now(),
                'actor_id' => $actor?->id,
                'resolved_at' => now(),
            ]);
            $credit->update(['amount' => round($creditAmount - $applyAmount, 2)]);
        } else {
            $credit->update([
                'status' => CustomerCredit::STATUS_APPLIED,
                'applied_invoice_id' => $invoice->id,
                'applied_transaction_id' => $transaction->id,
                'applied_at' => now(),
                'actor_id' => $actor?->id,
                'resolved_at' => now(),
            ]);
            $applied = $credit;
        }

        $settled = $invoice->fresh()->isSettled();
        if ($settled && $invoice->status !== 'paid') {
            $invoice->update(['status' => 'paid']);
        }
        if ($settled) {
            $customer = $invoice->customer;
            if ($customer && in_array((string) $customer->status, CustomerStatus::isolatedValues(), true)) {
                $decision = app(BillingEligibilityService::class)->evaluate($customer);
                if ($decision['reconnect_eligible'] ?? false) {
                    ReconnectCustomerJob::dispatch($customer, (int) $decision['current_invoice_id'])->afterCommit();
                }
            }
        }

        $log = activity();
        if ($actor) {
            $log = $log->causedBy($actor);
        }
        $log->performedOn($credit)
            ->withProperties([
                'credit_id' => $credit->id,
                'applied_row_id' => $applied->id,
                'invoice_id' => $invoice->id,
                'amount' => $applyAmount,
                'partial' => $partial,
                'transaction_id' => $transaction->id,
                'source' => $source,
            ])
            ->log($source === 'auto' ? 'customer_credit_auto_applied' : 'customer_credit_applied');

        return ['transaction' => $transaction, 'settled' => $settled, 'partial' => $partial, 'applied_row' => $applied];
    }

    /**
     * Called by PaymentReversalService INSIDE its DB transaction. When the reversed
     * transaction was a credit application, the money goes back to the credit row
     * (status available again, application cleared) so it is never untracked.
     * A split row stays a separate available row (no merge: the parent may be spent).
     */
    public function restoreOnReversal(Transaction $transaction, User $actor, string $reason): ?CustomerCredit
    {
        $credit = CustomerCredit::query()
            ->where('applied_transaction_id', $transaction->id)
            ->where('status', CustomerCredit::STATUS_APPLIED)
            ->lockForUpdate()
            ->first();
        if (! $credit) {
            return null;
        }

        $invoiceId = $credit->applied_invoice_id;
        $credit->update([
            'status' => CustomerCredit::STATUS_AVAILABLE,
            'applied_invoice_id' => null,
            'applied_transaction_id' => null,
            'applied_at' => null,
            'resolved_at' => null,
            'actor_id' => $actor->id,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($credit)
            ->withProperties([
                'credit_id' => $credit->id,
                'invoice_id' => $invoiceId,
                'transaction_id' => $transaction->id,
                'amount' => (string) $credit->amount,
                'reason' => $reason,
            ])
            ->log('customer_credit_restored_by_reversal');

        return $credit;
    }

    /**
     * Admin decision on a needs_review row (or an available one):
     * make_balance | refunded_manual | dismissed. refunded_manual and dismissed need a reason.
     */
    public function resolve(int $creditId, string $action, ?string $reason, User $actor): CustomerCredit
    {
        $reason = $reason !== null ? trim($reason) : null;
        if (in_array($action, ['refunded_manual', 'dismissed'], true) && ($reason === null || mb_strlen($reason) < 3)) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi (minimal 3 karakter).']);
        }

        return DB::transaction(function () use ($creditId, $action, $reason, $actor) {
            $credit = CustomerCredit::query()->lockForUpdate()->findOrFail($creditId);

            $allowedFrom = match ($action) {
                'make_balance' => [CustomerCredit::STATUS_NEEDS_REVIEW],
                'refunded_manual', 'dismissed' => [CustomerCredit::STATUS_NEEDS_REVIEW, CustomerCredit::STATUS_AVAILABLE],
                default => throw ValidationException::withMessages(['action' => 'Aksi tidak dikenal.']),
            };
            if (! in_array($credit->status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['credit' => 'Status saldo sudah berubah. Muat ulang halaman.']);
            }

            $before = $credit->status;
            $new = match ($action) {
                'make_balance' => CustomerCredit::STATUS_AVAILABLE,
                'refunded_manual' => CustomerCredit::STATUS_REFUNDED_MANUAL,
                'dismissed' => CustomerCredit::STATUS_DISMISSED,
            };
            $credit->update([
                'status' => $new,
                'reason' => $reason !== null && $reason !== '' ? $reason : $credit->reason,
                'actor_id' => $actor->id,
                'resolved_at' => $new === CustomerCredit::STATUS_AVAILABLE ? null : now(),
            ]);

            activity()
                ->causedBy($actor)
                ->performedOn($credit)
                ->withProperties(['credit_id' => $credit->id, 'from' => $before, 'to' => $new, 'amount' => (string) $credit->amount, 'reason' => $reason])
                ->log('customer_credit_'.$action);

            return $credit->fresh();
        });
    }
}
