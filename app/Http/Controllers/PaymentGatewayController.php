<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseHeldGatewayPaymentsJob;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Setting;
use App\Services\MidtransService;
use App\Services\PaymentGateway\Duitku\DuitkuCallbackHandler;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentWriteGate;
use App\Services\PaymentGatewayService;
use App\Support\AreaScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentGatewayController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentGatewayEvent::query();
        AreaScope::applyToPaymentGatewayEvents($query, $request->user());
        $events = $query
            ->with('invoice:id,code,uuid,status,amount')
            ->latest()
            ->paginate(min(max((int) $request->input('limit', 50), 1), 100));

        $events->getCollection()->transform(fn (PaymentGatewayEvent $event): array => [
            'id' => $event->id,
            'provider' => $event->provider,
            'external_id' => $event->external_id,
            'reference' => $event->reference,
            'amount' => (string) $event->amount,
            'status' => $event->status,
            'processed_at' => $event->processed_at?->toISOString(),
            'created_at' => $event->created_at?->toISOString(),
            'invoice' => $event->invoice ? [
                'code' => $event->invoice->code,
                'uuid' => $event->invoice->uuid,
                'status' => $event->invoice->status,
                'amount' => (string) $event->invoice->amount,
            ] : null,
        ]);

        return response()->json($events);
    }

    /**
     * Public pay page → create Midtrans Snap & redirect to hosted payment.
     * Route name kept: public.invoice.pay
     */
    public function payXendit(Request $request, string $uuid, MidtransService $midtrans)
    {
        $invoice = Invoice::where('uuid', $uuid)->with('customer')->firstOrFail();

        if ($invoice->status === 'void') {
            $message = 'Tagihan ini sudah dibatalkan dan tidak dapat dibayar. Hubungi admin.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'reason' => 'void'], 422);
            }

            return back()->withErrors(['method' => $message]);
        }

        if (! $this->isPayable($invoice)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'redirect_url' => route('public.invoice.show', $uuid),
                ]);
            }

            return redirect()->route('public.invoice.show', $uuid);
        }

        $gateways = app(PaymentGatewayManager::class);
        if (! $midtrans->isConfigured() || ! $gateways->isActive('midtrans') || ! $gateways->onlineAvailable()) {
            $message = 'Pembayaran online belum siap. Gunakan transfer bank atau hubungi admin.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'reason' => 'unavailable'], 422);
            }

            return back()->withErrors([
                'method' => $message,
            ]);
        }

        try {
            $snap = $midtrans->createSnap($invoice);

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'token' => $snap['token'],
                    // Hosted Midtrans checkout is the redirect target. The
                    // persisted payment_link remains the canonical eBilling URL.
                    'redirect_url' => $snap['redirect_url'],
                    'payment_link' => $invoice->fresh()->payment_link,
                ]);
            }

            return redirect()->away($snap['redirect_url']);
        } catch (\Throwable $e) {
            Log::warning('[Midtrans] public pay failed', [
                'uuid' => $uuid,
                'exception_class' => $e::class,
            ]);
            $message = 'Pembayaran Midtrans belum siap. Coba lagi sebentar atau gunakan transfer bank.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'reason' => 'provider_error'], 422);
            }

            return back()->withErrors([
                'method' => $message,
            ]);
        }
    }

    /**
     * Admin: create Midtrans Snap link for invoice.
     */
    public function createMidtransSnap(Invoice $invoice, MidtransService $midtrans)
    {
        AreaScope::authorizeInvoice($invoice, request()->user());

        if ($invoice->status === 'void') {
            return response()->json(['success' => false, 'message' => 'Invoice sudah dibatalkan (void).'], 422);
        }

        if (! $this->isPayable($invoice)) {
            return response()->json(['success' => false, 'message' => 'Invoice sudah lunas.'], 422);
        }

        if (! app(PaymentWriteGate::class)->enabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Kill switch "Payment write" OFF: pembayaran online sedang dimatikan.',
            ], 422);
        }

        try {
            $snap = $midtrans->createSnap($invoice->load('customer'));

            return response()->json([
                'success' => true,
                'token' => $snap['token'],
                'redirect_url' => $snap['redirect_url'],
                'order_id' => $snap['order_id'],
                'payment_link' => $invoice->fresh()->payment_link,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Midtrans] admin snap creation failed', [
                'invoice_id' => $invoice->getKey(),
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Pembayaran Midtrans belum siap. Coba lagi sebentar.',
            ], 422);
        }
    }

    public function webhook(Request $request, string $provider, PaymentGatewayService $service, MidtransService $midtrans)
    {
        $provider = strtolower($provider);

        // Allowlist: only providers with a dedicated signature-verified path.
        // Anything else (incl. the former generic shared-secret branch, which
        // was fail-open outside production/staging) is 404.
        abort_unless(in_array($provider, ['midtrans', 'duitku'], true), 404);

        if ($provider === 'midtrans') {
            // Midtrans posts JSON. Never read the query string: only body
            // fields can be covered by signature_key.
            $payload = $request->post();
            if (! $midtrans->isConfigured()) {
                abort(401, 'Midtrans not configured');
            }
            if (! $midtrans->verifyNotificationSignature($payload)) {
                Log::warning('[Midtrans] invalid signature', ['order_id' => $payload['order_id'] ?? null]);
                abort(401, 'Invalid Midtrans signature');
            }

            $event = $service->handleMidtransNotification($payload);

            return response()->json([
                'ok' => true,
                'event_id' => $event->id,
                'invoice_id' => $event->invoice_id,
                'status' => $event->status,
            ]);
        }

        // duitku: dedicated signature-verified path (fail-closed in every env).
        return app(DuitkuCallbackHandler::class)->handle($request);
    }

    public function config(MidtransService $midtrans)
    {
        return response()->json([
            'midtrans' => [
                'configured' => $midtrans->isConfigured(),
                'is_production' => $midtrans->isProduction(),
                'merchant_id' => $midtrans->merchantId(),
                'client_key_set' => $midtrans->clientKey() !== '',
                'server_key_set' => $midtrans->serverKey() !== '',
                'client_key_masked' => $this->maskKey($midtrans->clientKey()),
                'webhook_url' => url('/webhooks/payment-gateway/midtrans'),
            ],
        ]);
    }

    public function updateConfig(Request $request)
    {
        $validated = $request->validate([
            'merchant_id' => 'nullable|string|max:64',
            'client_key' => 'nullable|string|max:128',
            'server_key' => 'nullable|string|max:128',
            'is_production' => 'nullable|boolean',
            // empty string = keep existing key
            'clear_keys' => 'nullable|boolean',
        ]);

        if (! empty($validated['merchant_id'])) {
            Setting::set('midtrans_merchant_id', $validated['merchant_id'], 'text', 'payment', 'Midtrans Merchant ID');
        }
        if (array_key_exists('client_key', $validated) && trim((string) $validated['client_key']) !== '') {
            Setting::setSecret('midtrans_client_key', $validated['client_key'], 'payment', 'Midtrans Client Key');
        }
        if (array_key_exists('server_key', $validated) && trim((string) $validated['server_key']) !== '') {
            Setting::setSecret('midtrans_server_key', $validated['server_key'], 'payment', 'Midtrans Server Key');
        }
        if (array_key_exists('is_production', $validated)) {
            Setting::set(
                'midtrans_is_production',
                ! empty($validated['is_production']) ? '1' : '0',
                'boolean',
                'payment',
                'Midtrans production mode'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Konfigurasi Midtrans disimpan.',
            'config' => app(MidtransService::class)->isConfigured(),
        ]);
    }

    /**
     * Superadmin + TOTP step-up: turn the "Payment write" kill switch ON/OFF.
     * Turning it ON releases payments that were held while it was OFF.
     */
    public function updateKillSwitch(Request $request, PaymentWriteGate $gate)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);
        $enabled = (bool) $validated['enabled'];

        $gate->set($enabled, $request->user());
        if ($enabled) {
            ReleaseHeldGatewayPaymentsJob::dispatch();
        }

        $message = $enabled
            ? 'Payment write ON. Pembayaran yang ditahan sedang diproses.'
            : 'Payment write OFF. Pembayaran online dimatikan; callback yang masuk akan ditahan.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'enabled' => $enabled, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    /**
     * Only open invoices with a remaining balance can start a gateway payment.
     */
    protected function isPayable(Invoice $invoice): bool
    {
        return in_array((string) $invoice->status, ['unpaid', 'overdue'], true)
            && ! $invoice->isSettled();
    }

    protected function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 6).str_repeat('•', max(4, $len - 10)).substr($key, -4);
    }
}
