<?php

namespace App\Services\PaymentGateway;

use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGatewayService;

/**
 * Turns a VERIFIED provider outcome for a known payment attempt into a
 * gateway event, reusing PaymentGatewayService::process() for every money
 * decision (row lock, duplicate credit, void/settled/overpay checks, kill
 * switch hold, reconnect + WA receipt).
 *
 * Used by the callback (signed) and by the status-check fallback (our own
 * signed request to the provider API).
 */
class GatewayPaymentRecorder
{
    public const STATUS_SUCCESS = 'success';

    /** Provider says paid, but not the amount we asked for. Never credited. */
    public const STATUS_AMOUNT_MISMATCH = 'rejected_amount_mismatch';

    public function __construct(private readonly PaymentGatewayService $payments) {}

    /**
     * @param  int  $amountReceived  amount the provider reports as paid (amount_charged incl. any fee)
     * @param  array<string, mixed>  $payload
     */
    public function recordPaid(PaymentGatewayTransaction $txn, int $amountReceived, string $eventKey, array $payload): PaymentGatewayEvent
    {
        $txn->loadMissing('invoice');
        $orderId = $txn->merchant_order_id;

        if ($amountReceived !== (int) $txn->amount_charged) {
            $event = $this->payments->process(
                provider: $txn->provider,
                eventKey: $eventKey,
                creditKey: $orderId,
                reference: $orderId,
                status: self::STATUS_SUCCESS,
                amount: (float) $amountReceived,
                paid: false,
                payload: $payload,
                eventStatusOverride: self::STATUS_AMOUNT_MISMATCH,
                method: $this->transactionMethod($txn),
                invoice: $txn->invoice,
            );

            if ($event->wasRecentlyCreated) {
                if ($txn->status !== PaymentGatewayTransaction::STATUS_PAID) {
                    $txn->update([
                        'status' => PaymentGatewayTransaction::STATUS_REJECTED,
                        'last_error' => sprintf('amount mismatch: received %d, expected %d', $amountReceived, $txn->amount_charged),
                    ]);
                }
                $this->payments->notifyOps($txn->provider, $event, sprintf(
                    'paid amount %d does not match charged amount %d (not credited, refund or manual review required)',
                    $amountReceived,
                    $txn->amount_charged,
                ));
            }

            return $event;
        }

        return $this->payments->process(
            provider: $txn->provider,
            eventKey: $eventKey,
            creditKey: $orderId,
            reference: $orderId,
            status: self::STATUS_SUCCESS,
            // The invoice is credited the invoice amount only; the fee stays with the provider.
            amount: (float) $txn->amount_invoice,
            paid: true,
            payload: $payload,
            eventStatusOverride: null,
            method: $this->transactionMethod($txn),
            invoice: $txn->invoice,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordNotPaid(PaymentGatewayTransaction $txn, string $eventKey, string $status, array $payload, bool $final): PaymentGatewayEvent
    {
        $event = $this->payments->process(
            provider: $txn->provider,
            eventKey: $eventKey,
            creditKey: $txn->merchant_order_id,
            reference: $txn->merchant_order_id,
            status: $status,
            amount: null,
            paid: false,
            payload: $payload,
            eventStatusOverride: null,
            invoice: $txn->invoice,
        );

        if ($final && $txn->isOpen()) {
            $txn->update([
                'status' => $txn->isExpired() ? PaymentGatewayTransaction::STATUS_EXPIRED : PaymentGatewayTransaction::STATUS_FAILED,
            ]);
        }

        return $event;
    }

    private function transactionMethod(PaymentGatewayTransaction $txn): string
    {
        return in_array($txn->method_group, ['va', 'qris', 'ewallet', 'retail', 'card'], true) ? $txn->method_group : 'other';
    }
}
