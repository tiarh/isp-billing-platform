<?php

namespace App\Services\PaymentGateway;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayTransaction;
use App\Models\User;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts (or reuses) one online payment for one invoice.
 *
 * Rules:
 *  - only while a configured provider is active AND the Payment write kill
 *    switch is ON;
 *  - only open invoices; the charge covers the remaining balance (never more);
 *  - 1 invoice per transaction;
 *  - a still-valid pending charge for the same invoice/method/amount is
 *    reused, so double clicks and page reloads do not create several VAs;
 *  - per-invoice lock serialises concurrent starts.
 */
class PaymentGatewayCheckoutService
{
    /** Max still-payable codes per invoice (a 4th different method is refused). */
    public const MAX_OPEN_CODES = 3;

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentWriteGate $gate,
        private readonly PaymentGatewaySettings $settings,
    ) {}

    /**
     * Methods the customer can choose, with fee/total already applied for
     * the configured fee bearer.
     *
     * @return list<array<string, mixed>>
     */
    public function methodsFor(Invoice $invoice): array
    {
        $gateway = $this->availableGateway($invoice);
        if (! $gateway) {
            return [];
        }

        $remaining = $this->remaining($invoice);
        if ($remaining < $this->settings->minAmount() || ! $this->isPayable($invoice)) {
            return [];
        }

        try {
            $options = $gateway->paymentMethods($remaining);
        } catch (\Throwable $e) {
            Log::warning('[PaymentGateway] payment methods unavailable', [
                'provider' => $gateway->provider(),
                'exception_class' => $e::class,
            ]);

            return [];
        }

        $customerPaysFee = $this->settings->feeBearer() === PaymentGatewayTransaction::FEE_BEARER_CUSTOMER;

        return array_values(array_map(fn (PaymentMethodOption $option): array => array_merge($option->toArray(), [
            'fee' => $customerPaysFee ? $option->fee : 0,
            'total' => $remaining + ($customerPaysFee ? $option->fee : 0),
        ]), $options));
    }

    public function start(Invoice $invoice, string $methodCode, string $source = 'public', ?User $actor = null): PaymentGatewayTransaction
    {
        $gateway = $this->availableGateway($invoice);
        if (! $gateway) {
            throw CheckoutException::unavailable();
        }

        if ($invoice->status === 'void') {
            throw CheckoutException::void();
        }

        if (! $this->isPayable($invoice)) {
            throw CheckoutException::notPayable();
        }

        $remaining = $this->remaining($invoice);
        if ($remaining < $this->settings->minAmount()) {
            throw CheckoutException::amountTooSmall($this->settings->minAmount());
        }

        $lock = Cache::lock('payment-gateway:checkout:'.$invoice->id, 60);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw CheckoutException::busy();
        }

        try {
            return $this->startLocked($gateway, $invoice->fresh(['customer']) ?? $invoice, $methodCode, $source, $actor);
        } finally {
            $lock->release();
        }
    }

    private function startLocked(PaymentGateway $gateway, Invoice $invoice, string $methodCode, string $source, ?User $actor): PaymentGatewayTransaction
    {
        // Re-check under the lock: a manual payment may have landed meanwhile.
        if (! $this->isPayable($invoice)) {
            throw CheckoutException::notPayable();
        }

        $remaining = $this->remaining($invoice);
        $option = $this->findOption($gateway, $remaining, $methodCode);
        $feeBearer = $this->settings->feeBearer();
        $fee = $feeBearer === PaymentGatewayTransaction::FEE_BEARER_CUSTOMER ? max(0, $option->fee) : 0;
        $charged = $remaining + $fee;

        $existing = PaymentGatewayTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('provider', $gateway->provider())
            ->where('payment_method', $option->code)
            ->where('amount_invoice', $remaining)
            ->where('amount_charged', $charged)
            ->where('status', PaymentGatewayTransaction::STATUS_PENDING)
            ->latest('id')
            ->first();
        if ($existing && $existing->isReusable()) {
            return $existing;
        }

        // A different method creates a new code, but never more than MAX_OPEN_CODES at once.
        if ($this->openTransactions($invoice, $gateway->provider())->count() >= self::MAX_OPEN_CODES) {
            throw CheckoutException::tooManyOpen();
        }

        $expiry = in_array($option->group, [PaymentMethodOption::GROUP_VA, PaymentMethodOption::GROUP_RETAIL], true)
            ? $this->settings->vaExpiryMinutes()
            : null;

        $txn = PaymentGatewayTransaction::create([
            'provider' => $gateway->provider(),
            'environment' => $gateway->isProduction() ? 'production' : 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => $this->newOrderId($invoice),
            'payment_method' => $option->code,
            'method_group' => $option->group,
            'method_name' => mb_substr($option->name, 0, 100),
            'amount_invoice' => $remaining,
            'fee' => max(0, $option->fee),
            'fee_bearer' => $feeBearer,
            'amount_charged' => $charged,
            'status' => PaymentGatewayTransaction::STATUS_CREATED,
            'source' => $source,
            'created_by' => $actor?->id,
        ]);

        $customer = $invoice->customer;

        try {
            $result = $gateway->createCharge(new ChargeRequest(
                invoice: $invoice,
                merchantOrderId: $txn->merchant_order_id,
                amount: $charged,
                methodCode: $option->code,
                customerName: (string) ($customer?->name ?: 'Pelanggan'),
                email: $this->settings->emailFor($customer),
                phone: preg_replace('/\D+/', '', (string) ($customer?->phone ?? '')) ?: null,
                productDetails: 'Tagihan internet '.($invoice->code ?: '#'.$invoice->id),
                returnUrl: route('public.invoice.show', $invoice->uuid).'?payment_result=return',
                callbackUrl: route('webhooks.payment-gateway', $gateway->provider()),
                expiryMinutes: $expiry,
            ));
        } catch (\Throwable $e) {
            $txn->update([
                'status' => PaymentGatewayTransaction::STATUS_FAILED,
                'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 255),
            ]);
            Log::warning('[PaymentGateway] create charge failed', [
                'provider' => $gateway->provider(),
                'invoice_id' => $invoice->id,
                'order_id' => $txn->merchant_order_id,
                'exception_class' => $e::class,
            ]);

            throw CheckoutException::providerError();
        }

        $txn->update([
            'merchant_order_id' => $result->merchantOrderId !== '' ? $result->merchantOrderId : $txn->merchant_order_id,
            'provider_reference' => $result->providerReference,
            'kind' => $result->kind,
            'payment_url' => $result->paymentUrl,
            'va_number' => $result->vaNumber,
            'qr_string' => $result->qrString,
            'expires_at' => $result->expiresAt ?? ($expiry ? now()->addMinutes($expiry) : null),
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'meta' => ['charge' => $result->raw],
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($invoice)
            ->withProperties([
                'provider' => $gateway->provider(),
                'order_id' => $txn->merchant_order_id,
                'method' => $option->code,
                'amount_invoice' => $remaining,
                'fee' => $txn->fee,
                'fee_bearer' => $feeBearer,
                'amount_charged' => $charged,
                'source' => $source,
            ])
            ->log('payment_gateway_charge_created');

        return $txn->fresh();
    }

    /**
     * Still-payable (pending, not expired) codes of this invoice, newest first.
     *
     * @return Collection<int, PaymentGatewayTransaction>
     */
    public function openTransactions(Invoice $invoice, string $provider): Collection
    {
        return PaymentGatewayTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('provider', $provider)
            ->where('status', PaymentGatewayTransaction::STATUS_PENDING)
            ->latest('id')
            ->get()
            // No reuse grace here: a code in its last minutes is still payable at the provider, so it still
            // counts for the cap, the list and the fallback block.
            ->filter(fn (PaymentGatewayTransaction $txn): bool => $txn->expires_at === null || $txn->expires_at->isFuture())
            ->values();
    }

    private function availableGateway(Invoice $invoice): ?PaymentGateway
    {
        if (! $this->gate->enabled()) {
            return null;
        }

        // Pilot allowlist: a customer who is not listed gets the manual fallback.
        if (! $this->gateways->pilotAllowsStart($invoice->customer_id !== null ? (int) $invoice->customer_id : null)) {
            return null;
        }

        return $this->gateways->active();
    }

    private function findOption(PaymentGateway $gateway, int $amount, string $methodCode): PaymentMethodOption
    {
        try {
            $options = $gateway->paymentMethods($amount);
        } catch (\Throwable $e) {
            Log::warning('[PaymentGateway] payment methods unavailable', [
                'provider' => $gateway->provider(),
                'exception_class' => $e::class,
            ]);

            throw CheckoutException::providerError();
        }

        foreach ($options as $option) {
            if (strcasecmp($option->code, $methodCode) === 0) {
                return $option;
            }
        }

        throw CheckoutException::invalidMethod();
    }

    public function isPayable(Invoice $invoice): bool
    {
        return in_array((string) $invoice->status, ['unpaid', 'overdue'], true) && ! $invoice->isSettled();
    }

    public function remaining(Invoice $invoice): int
    {
        return (int) round(max(0, (float) $invoice->amount - $invoice->totalPaid()));
    }

    /** INV{id}-{yymmddHHMMSS}{3 random} — keeps the INV{id}- prefix used by invoice resolution; max 50 chars. */
    private function newOrderId(Invoice $invoice): string
    {
        return mb_substr('INV'.$invoice->id.'-'.now()->format('ymdHis').Str::upper(Str::random(3)), 0, 50);
    }
}
