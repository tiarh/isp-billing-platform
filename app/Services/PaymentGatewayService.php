<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Jobs\ReconnectCustomerJob;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Transaction;
use App\Services\PaymentGateway\PaymentWriteGate;
use App\Support\OpsAlertSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    /** Event status recorded when the amount is missing, exceeds the remaining balance, or the invoice is already settled. */
    public const STATUS_REJECTED_AMOUNT = 'rejected_amount';

    /** Event status recorded when a payment arrives for a void invoice. No transaction is created. */
    public const STATUS_REJECTED_VOID = 'rejected_void';

    /** Midtrans claimed a paid transaction_status but the signed status_code is not 200. */
    public const STATUS_REJECTED_UNSIGNED = 'rejected_unsigned_status';

    /** Paid notification whose credit (transaction reference) was already recorded. */
    public const STATUS_DUPLICATE_CREDIT = 'duplicate_credit';

    /** Paid notification that does not resolve to an invoice. */
    public const STATUS_UNMATCHED = 'unmatched_invoice';

    /** Duitku only: a second full payment for an already settled invoice was recorded as customer credit (saldo). */
    public const STATUS_CREDITED_TO_BALANCE = 'credited_to_balance';

    /** Duitku only: payment exceeded the remaining balance; invoice settled, excess recorded as needs-review credit. */
    public const STATUS_SETTLED_EXCESS_REVIEW = 'settled_excess_review';

    /** Paid notification stored while the "Payment write" kill switch is OFF; not credited yet. */
    public const STATUS_HELD = 'held_kill_switch';

    /** A held event that was credited after the kill switch was turned ON. */
    public const STATUS_RELEASED = 'released';

    /** Transaction methods allowed by the transactions.method enum. */
    public const METHODS = ['qris', 'va', 'ewallet', 'retail', 'card', 'transfer', 'other'];

    /**
     * Generic provider webhook (non-Midtrans). Fields are trusted only after the
     * caller authenticated the request with the provider webhook secret.
     */
    public function handleWebhook(string $provider, array $payload): PaymentGatewayEvent
    {
        $externalId = $this->firstString($payload, ['id', 'event_id', 'transaction_id', 'external_id', 'payment_id']);
        $reference = $this->firstString($payload, ['reference', 'external_id', 'merchant_ref', 'order_id', 'invoice_id']);
        $status = strtolower($this->firstString($payload, ['status', 'payment_status', 'transaction_status']) ?? 'unknown');
        $amount = $this->firstNumber($payload, ['amount', 'paid_amount', 'gross_amount', 'total_amount']);

        // Dedupe per (external id, status): a non-final event (pending) must
        // never swallow the later final event for the same payment. The credit
        // key stays the bare external id so one payment = at most one credit.
        $eventKey = $externalId !== null
            ? $this->boundedKey($externalId.':'.$status)
            : hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
        $creditKey = $externalId !== null ? $this->boundedKey($externalId) : $eventKey;

        return $this->process(
            provider: $provider,
            eventKey: $eventKey,
            creditKey: $creditKey,
            reference: $reference,
            status: $status,
            amount: $amount,
            paid: $this->isPaidStatus($status),
            payload: $payload,
            eventStatusOverride: null,
        );
    }

    /**
     * Midtrans HTTP notification. The caller MUST have verified signature_key,
     * which covers only order_id + status_code + gross_amount. Money decisions
     * therefore use only those signed fields:
     *  - credit key / transaction reference = order_id (one order = one credit);
     *  - amount = gross_amount;
     *  - a payment is accepted only with status_code 200, plus a final
     *    transaction_status (settlement, or capture with fraud_status=accept).
     */
    public function handleMidtransNotification(array $payload): PaymentGatewayEvent
    {
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $statusCode = trim((string) ($payload['status_code'] ?? ''));
        $status = strtolower(trim((string) ($payload['transaction_status'] ?? ''))) ?: 'unknown';
        $fraudStatus = strtolower(trim((string) ($payload['fraud_status'] ?? '')));
        $amount = isset($payload['gross_amount']) && is_numeric($payload['gross_amount'])
            ? (float) $payload['gross_amount']
            : null;

        $finalSuccess = $status === 'settlement'
            || ($status === 'capture' && $fraudStatus === 'accept');
        $paid = $finalSuccess && $statusCode === '200';

        $override = null;
        if ($finalSuccess && ! $paid) {
            $override = self::STATUS_REJECTED_UNSIGNED;
        } elseif ($status === 'capture' && ! $finalSuccess) {
            $override = 'capture_'.($fraudStatus !== '' ? $fraudStatus : 'unverified');
        }

        $eventKey = $this->boundedKey($orderId.':'.$statusCode.':'.$status);

        $event = $this->process(
            provider: 'midtrans',
            eventKey: $eventKey,
            creditKey: $this->boundedKey($orderId),
            reference: $orderId !== '' ? $orderId : null,
            status: $status,
            amount: $amount,
            paid: $paid,
            payload: $payload,
            eventStatusOverride: $override,
            method: $this->midtransMethod((string) ($payload['payment_type'] ?? '')),
        );

        if ($override === self::STATUS_REJECTED_UNSIGNED) {
            $this->alert('midtrans', $event, 'paid transaction_status without signed status_code 200');
        }

        return $event;
    }

    /**
     * Records one provider notification and, when it is a verified final
     * payment, credits the invoice. Callers MUST have authenticated the
     * notification (signature / secret) before calling this.
     *
     * @param  float|null  $amount  amount to credit to the invoice (net of any gateway fee)
     * @param  Invoice|null  $invoice  resolved invoice; null = resolve from reference/payload
     */
    public function process(
        string $provider,
        string $eventKey,
        string $creditKey,
        ?string $reference,
        string $status,
        ?float $amount,
        bool $paid,
        array $payload,
        ?string $eventStatusOverride,
        ?string $method = null,
        ?Invoice $invoice = null,
    ): PaymentGatewayEvent {
        $existing = $this->findEvent($provider, $eventKey);
        if ($existing) {
            return $existing;
        }

        $method = in_array($method, self::METHODS, true) ? $method : 'other';

        try {
            [$event, $alertReason] = DB::transaction(function () use (
                $provider, $eventKey, $creditKey, $reference, $status, $amount, $paid, $payload, $eventStatusOverride, $method, $invoice
            ) {
                $invoice ??= $this->resolveInvoice($reference, $payload);

                $event = PaymentGatewayEvent::create([
                    'provider' => $provider,
                    'external_id' => $eventKey,
                    'reference' => $reference,
                    'credit_key' => $paid ? $creditKey : null,
                    'invoice_id' => $invoice?->id,
                    'amount' => $amount,
                    'credit_amount' => $paid ? $amount : null,
                    'credit_method' => $paid ? $method : null,
                    'status' => $eventStatusOverride ?? $status,
                    'payload' => $payload,
                    'processed_at' => now(),
                ]);

                if (! $paid) {
                    return [$event, null];
                }

                if (! $invoice) {
                    $event->update(['status' => self::STATUS_UNMATCHED]);

                    return [$event, 'paid notification does not match an invoice'];
                }

                if (! app(PaymentWriteGate::class)->enabled()) {
                    $event->update(['status' => self::STATUS_HELD, 'held_at' => now()]);

                    return [$event, 'kill switch "Payment write" OFF: payment held, not credited yet (released automatically when switched ON)'];
                }

                [$rejection, $alertReason] = $this->settleInvoice($invoice, $amount, $provider, $creditKey, $method, $event);
                if ($rejection !== null) {
                    $event->update(['status' => $rejection]);
                }

                return [$event, $alertReason];
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent delivery of the same notification won the insert.
            $existing = $this->findEvent($provider, $eventKey);
            if ($existing) {
                return $existing;
            }

            throw $e;
        }

        $this->syncGatewayTransaction($event);

        if ($alertReason !== null) {
            $this->alert($provider, $event, $alertReason);
        }

        return $event;
    }

    /**
     * Credits an invoice under a row lock. All balance checks happen after the
     * lock so a concurrent manual payment or webhook cannot overpay (M5).
     *
     * @return array{0: ?string, 1: ?string} [rejection event status (null = credited), ops alert reason]
     */
    protected function settleInvoice(Invoice $invoice, ?float $amount, string $provider, string $creditKey, string $method = 'other', ?PaymentGatewayEvent $event = null): array
    {
        $lockedInvoice = Invoice::query()->with('customer')->lockForUpdate()->findOrFail($invoice->id);
        $reference = 'PG-'.strtoupper($provider).'-'.$creditKey;
        // Duitku (direct provider) only: double/over payments are tracked as customer
        // credits instead of being dropped. Midtrans + generic webhooks keep the old rules.
        $credits = CustomerCreditService::providerCredits($provider) ? app(CustomerCreditService::class) : null;

        if ($lockedInvoice->status === 'void') {
            // Never record a credit for money that was already credited to the invoice (same order, other event key).
            if ($credits !== null && $amount !== null && $amount > 0 && ! Transaction::query()->where('reference', $reference)->exists()) {
                $credit = $credits->recordFromGateway($lockedInvoice, $provider, $creditKey, $amount, CustomerCredit::REASON_VOID_INVOICE, CustomerCredit::STATUS_NEEDS_REVIEW, $event);

                return [self::STATUS_REJECTED_VOID, $credit->wasRecentlyCreated
                    ? 'payment received for a void invoice: recorded on the Kelebihan bayar page as needs-review (admin decides; no automatic refund)'
                    : null];
            }

            return [self::STATUS_REJECTED_VOID, 'payment received for a void invoice (refund required)'];
        }

        if (Transaction::query()->where('reference', $reference)->exists()) {
            // Same payment already credited (e.g. settlement retried under a
            // different event key). Only heal the invoice status.
            $this->markPaidIfSettled($lockedInvoice);

            return [self::STATUS_DUPLICATE_CREDIT, null];
        }

        if ($amount === null || $amount <= 0) {
            return [self::STATUS_REJECTED_AMOUNT, 'paid notification without a valid amount'];
        }

        if ($lockedInvoice->isSettled()) {
            if ($credits !== null) {
                $credit = $credits->recordFromGateway($lockedInvoice, $provider, $creditKey, $amount, CustomerCredit::REASON_DOUBLE_PAYMENT, CustomerCredit::STATUS_AVAILABLE, $event);

                // A repeat delivery (other event key) reuses the credit row and must not re-alert ops.
                return [self::STATUS_CREDITED_TO_BALANCE, $credit->wasRecentlyCreated ? sprintf(
                    'double payment for an already settled invoice: recorded as customer credit %s (saldo, available on the Kelebihan bayar page; not a refund)',
                    number_format($amount, 2, '.', ''),
                ) : null];
            }

            return [self::STATUS_REJECTED_AMOUNT, 'payment received for an already settled invoice (overpayment, refund required)'];
        }

        $remaining = round((float) $lockedInvoice->amount - $lockedInvoice->totalPaid(), 2);
        $excess = 0.0;
        $alertReason = null;
        $eventStatus = null;
        if ($amount > $remaining + 0.01) {
            if ($credits === null) {
                return [self::STATUS_REJECTED_AMOUNT, sprintf(
                    'amount %s exceeds remaining balance %s (overpayment, refund required)',
                    number_format($amount, 2, '.', ''),
                    number_format($remaining, 2, '.', ''),
                )];
            }

            // Settle the invoice (the customer must not stay isolated); the excess
            // is NOT credited automatically: it waits for an admin decision.
            $excess = round($amount - $remaining, 2);
            $amount = $remaining;
            $eventStatus = self::STATUS_SETTLED_EXCESS_REVIEW;
            $alertReason = sprintf(
                'payment larger than remaining balance: invoice settled with %s, excess %s recorded as needs-review on the Kelebihan bayar page (admin decides: saldo / refunded manually / dismiss)',
                number_format($remaining, 2, '.', ''),
                number_format($excess, 2, '.', ''),
            );
        }

        $transaction = Transaction::create([
            'reference' => $reference,
            'invoice_id' => $lockedInvoice->id,
            'idempotency_key' => "gateway:{$provider}:{$creditKey}",
            'amount' => $amount,
            'channel' => 'gateway',
            'method' => $method,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        if ($excess > 0.0 && $credits !== null) {
            $credits->recordFromGateway($lockedInvoice, $provider, $creditKey, $excess, CustomerCredit::REASON_OVERPAYMENT, CustomerCredit::STATUS_NEEDS_REVIEW, $event);
        }

        // M1: isSettled() re-queries the transactions, so it already includes
        // the row above. The old "! isSettled() && totalPaid() >= amount" check
        // could therefore never be true and the invoice stayed unpaid.
        if (! $this->markPaidIfSettled($lockedInvoice)) {
            return [$eventStatus, $alertReason];
        }

        $customer = $lockedInvoice->customer;
        if ($customer && in_array((string) $customer->status, CustomerStatus::isolatedValues(), true)) {
            $decision = app(BillingEligibilityService::class)->evaluate($customer);
            if ($decision['reconnect_eligible'] ?? false) {
                ReconnectCustomerJob::dispatch($customer, (int) $decision['current_invoice_id'])->afterCommit();
            }
        }
        DB::afterCommit(function () use ($lockedInvoice, $transaction): void {
            try {
                app(BillingWaEventService::class)->notifyPayment($lockedInvoice->fresh(['customer.package', 'customer.area']), $transaction);
            } catch (\Throwable $e) {
                Log::warning('[BillingWa] PG notifyPayment failed: '.$e->getMessage());
            }
        });

        return [$eventStatus, $alertReason];
    }

    /**
     * Credits payments that arrived while the kill switch was OFF. No-op while
     * the switch is still OFF. Each event is settled under the same invoice
     * row lock and duplicate-credit checks as a live notification.
     *
     * @return int number of events processed (credited or rejected)
     */
    public function releaseHeld(int $limit = 100): int
    {
        if (! app(PaymentWriteGate::class)->enabled()) {
            return 0;
        }

        $processed = 0;
        $eventIds = PaymentGatewayEvent::query()
            ->where('status', self::STATUS_HELD)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            [$event, $alertReason] = DB::transaction(function () use ($eventId) {
                $event = PaymentGatewayEvent::query()->lockForUpdate()->find($eventId);
                if (! $event || $event->status !== self::STATUS_HELD) {
                    return [null, null];
                }

                $invoice = $event->invoice_id ? Invoice::query()->find($event->invoice_id) : null;
                if (! $invoice || ! $event->credit_key) {
                    $event->update(['status' => self::STATUS_UNMATCHED, 'released_at' => now()]);

                    return [$event, 'held payment could not be matched to an invoice on release'];
                }

                [$rejection, $alertReason] = $this->settleInvoice(
                    $invoice,
                    $event->credit_amount !== null ? (float) $event->credit_amount : null,
                    (string) $event->provider,
                    (string) $event->credit_key,
                    (string) ($event->credit_method ?: 'other'),
                    $event,
                );
                $event->update([
                    'status' => $rejection ?? self::STATUS_RELEASED,
                    'released_at' => now(),
                ]);

                return [$event, $alertReason];
            });

            if ($event) {
                $processed++;
                $this->syncGatewayTransaction($event);
                if ($alertReason !== null) {
                    $this->alert((string) $event->provider, $event, $alertReason);
                }
            }
        }

        return $processed;
    }

    /**
     * Same rule as the manual payment path (PaymentController::store).
     */
    protected function markPaidIfSettled(Invoice $lockedInvoice): bool
    {
        if (! $lockedInvoice->isSettled()) {
            return false;
        }

        if ($lockedInvoice->status !== 'paid') {
            $lockedInvoice->update(['status' => 'paid']);
        }

        return true;
    }

    /**
     * Mirrors the outcome of a paid notification onto its payment attempt
     * (payment_gateway_transactions, matched by provider + order id). Never
     * downgrades an attempt that is already paid.
     */
    public function syncGatewayTransaction(PaymentGatewayEvent $event): void
    {
        $orderId = (string) ($event->reference ?? '');
        if ($orderId === '' || $event->credit_key === null) {
            return;
        }

        $txn = PaymentGatewayTransaction::query()
            ->where('provider', $event->provider)
            ->where('merchant_order_id', $orderId)
            ->first();
        if (! $txn || $txn->status === PaymentGatewayTransaction::STATUS_PAID) {
            return;
        }

        $credit = Transaction::query()
            ->where('reference', 'PG-'.strtoupper((string) $event->provider).'-'.$event->credit_key)
            ->first();

        if ($credit) {
            $txn->update([
                'status' => PaymentGatewayTransaction::STATUS_PAID,
                'paid_at' => $credit->paid_at ?? now(),
                'transaction_id' => $credit->id,
                'last_error' => null,
            ]);

            return;
        }

        if ($event->status === self::STATUS_HELD) {
            $txn->update(['status' => PaymentGatewayTransaction::STATUS_HELD]);

            return;
        }

        if ($event->status === self::STATUS_CREDITED_TO_BALANCE) {
            // Money received and tracked as customer credit (no invoice transaction).
            $txn->update([
                'status' => PaymentGatewayTransaction::STATUS_CREDITED_BALANCE,
                'paid_at' => now(),
                'last_error' => null,
            ]);

            return;
        }

        if (str_starts_with((string) $event->status, 'rejected') || $event->status === self::STATUS_UNMATCHED) {
            $txn->update([
                'status' => PaymentGatewayTransaction::STATUS_REJECTED,
                'last_error' => mb_substr('event #'.$event->id.': '.$event->status, 0, 255),
            ]);
        }
    }

    /**
     * Public entry for provider handlers that reject a verified notification
     * before it reaches process() money logic (e.g. amount mismatch).
     */
    public function notifyOps(string $provider, PaymentGatewayEvent $event, string $reason): void
    {
        $this->alert($provider, $event, $reason);
    }

    protected function alert(string $provider, PaymentGatewayEvent $event, string $reason): void
    {
        $context = [
            'provider' => $provider,
            'event_id' => $event->id,
            'status' => $event->status,
            'reference' => $event->reference,
            'invoice_id' => $event->invoice_id,
            'amount' => $event->amount,
            'reason' => $reason,
        ];
        Log::warning('[PaymentGateway] payment needs ops review', $context);

        try {
            app(OpsAlertService::class)->send(
                sprintf(
                    "[eBilling] Pembayaran gateway perlu dicek\nProvider: %s\nStatus: %s\nOrder: %s\nInvoice ID: %s\nNominal: %s\nAlasan: %s",
                    $provider,
                    (string) $event->status,
                    (string) ($event->reference ?? '-'),
                    (string) ($event->invoice_id ?? '-'),
                    (string) ($event->amount ?? '-'),
                    $reason,
                ),
                'payment_gateway:'.$provider.':'.$event->id,
                300,
                '',
                OpsAlertSource::PAYMENT_GATEWAY,
            );
        } catch (\Throwable $e) {
            Log::warning('[PaymentGateway] ops alert failed', ['exception_class' => $e::class]);
        }
    }

    protected function findEvent(string $provider, string $eventKey): ?PaymentGatewayEvent
    {
        return PaymentGatewayEvent::query()
            ->where('provider', $provider)
            ->where('external_id', $eventKey)
            ->first();
    }

    protected function resolveInvoice(?string $reference, array $payload): ?Invoice
    {
        $orderId = $this->firstString($payload, ['order_id']);
        if ($orderId && preg_match('/^INV(\d+)(?:-|$)/i', $orderId, $m)) {
            $byId = Invoice::query()->find((int) $m[1]);
            if ($byId) {
                return $byId;
            }
        }
        $candidate = $reference ?: $this->firstString($payload, ['invoice_uuid', 'invoice_code', 'order_id']);
        if (! $candidate) {
            return null;
        }

        return Invoice::where('uuid', $candidate)
            ->orWhere('code', $candidate)
            ->orWhere('payment_link', $candidate)
            ->first();
    }

    /**
     * Reporting label only (payment_type is not covered by the signature and
     * never influences money decisions).
     */
    protected function midtransMethod(string $paymentType): string
    {
        return match (strtolower(trim($paymentType))) {
            'qris' => 'qris',
            'bank_transfer', 'echannel', 'permata', 'bca_va', 'bni_va', 'bri_va', 'other_va' => 'va',
            'gopay', 'shopeepay', 'dana', 'ovo' => 'ewallet',
            'cstore', 'indomaret', 'alfamart' => 'retail',
            'credit_card' => 'card',
            default => 'other',
        };
    }

    protected function isPaidStatus(string $status): bool
    {
        return in_array($status, ['paid', 'settled', 'settlement', 'success', 'capture', 'completed'], true);
    }

    /**
     * external_id is varchar(128) and transactions.idempotency_key is 191.
     */
    protected function boundedKey(string $key): string
    {
        return strlen($key) <= 100 ? $key : 'sha256:'.hash('sha256', $key);
    }

    protected function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    protected function firstNumber(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        return null;
    }
}
