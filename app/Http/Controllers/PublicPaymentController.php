<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGateway\CheckoutException;
use App\Services\PaymentGateway\PaymentGatewayCheckoutService;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing online payment (direct API providers such as Duitku) on
 * the public /pay/{uuid} page. The invoice uuid is the capability; responses
 * expose only the public DTOs.
 */
class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayCheckoutService $checkout,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function methods(string $uuid): JsonResponse
    {
        $invoice = $this->invoice($uuid);
        if (! $this->directProviderAvailable($invoice)) {
            return response()->json(['ok' => false, 'message' => CheckoutException::unavailable()->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'remaining' => $this->checkout->remaining($invoice),
            'methods' => $this->checkout->methodsFor($invoice),
            'open_transactions' => $this->checkout->openTransactions($invoice, (string) $this->gateways->active()?->provider())
                ->map(fn (PaymentGatewayTransaction $txn): array => $txn->toPublicArray())
                ->all(),
        ]);
    }

    public function start(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['method' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/']]);
        $invoice = $this->invoice($uuid);

        if (! $this->directProviderAvailable($invoice)) {
            return response()->json(['ok' => false, 'reason' => CheckoutException::UNAVAILABLE, 'message' => CheckoutException::unavailable()->getMessage()], 422);
        }

        try {
            $txn = $this->checkout->start($invoice, $validated['method'], $request->boolean('from_isolir') ? 'isolir' : 'public');
        } catch (CheckoutException $e) {
            return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], $e->httpStatus());
        }

        return response()->json(['ok' => true, 'transaction' => $txn->toPublicArray()]);
    }

    /**
     * Polled by the pay page while a VA/QR is shown. DB only, never calls the provider.
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $invoice = $this->invoice($uuid);
        $orderId = (string) $request->query('order', '');

        $txn = $orderId === '' ? null : PaymentGatewayTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('merchant_order_id', $orderId)
            ->first();

        return response()->json([
            'invoice_status' => (string) $invoice->status,
            'paid' => $invoice->status === 'paid',
            'transaction' => $txn ? [
                'order_id' => $txn->merchant_order_id,
                'status' => $txn->status,
                'expires_at' => $txn->expires_at?->toISOString(),
            ] : null,
        ]);
    }

    private function invoice(string $uuid): Invoice
    {
        return Invoice::query()->where('uuid', $uuid)->with('customer')->firstOrFail();
    }

    /** Direct-API flow is for non-hosted providers; Midtrans keeps its Snap flow. */
    private function directProviderAvailable(Invoice $invoice): bool
    {
        return $this->gateways->onlineAvailable($invoice->customer_id !== null ? (int) $invoice->customer_id : null)
            && ! $this->gateways->isActive('midtrans');
    }
}
