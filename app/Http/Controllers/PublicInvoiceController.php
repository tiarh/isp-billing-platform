<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Services\MidtransService;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\PaymentGatewayCheckoutService;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use App\Support\CompanyContact;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicInvoiceController extends Controller
{
    public function show(Request $request, $uuid)
    {
        $invoice = Invoice::where('uuid', $uuid)->with('customer:id,name')->firstOrFail();

        $midtrans = app(MidtransService::class);
        // Online payment is offered only while the "Payment write" kill switch is ON.
        $gateways = app(PaymentGatewayManager::class);
        $midtransReady = $gateways->isActive('midtrans') && $gateways->onlineAvailable();

        $manualAccounts = collect(Setting::get('payment_channels', []))
            ->filter(fn ($account): bool => is_array($account))
            ->map(fn (array $account): array => [
                'bank' => trim((string) ($account['bank'] ?? '')),
                'account_number' => trim((string) ($account['account_number'] ?? '')),
                'account_name' => trim((string) ($account['account_name'] ?? '')),
            ])
            ->filter(fn (array $account): bool => $account['bank'] !== ''
                && $account['account_number'] !== ''
                && $account['account_name'] !== '')
            ->values()
            ->all();

        // Instant channel: Midtrans (when keys set)
        $channels = [];
        if ($midtransReady) {
            $channels[] = [
                'code' => 'midtrans',
                'name' => 'Bayar Online (Midtrans)',
                'description' => 'QRIS / VA / e-wallet / kartu via Midtrans',
                'icon_url' => '',
                'group' => 'instant',
            ];
        }

        // Direct-API provider (Duitku): methods are loaded lazily by the page;
        // a still-valid pending charge is shown again instead of a new one.
        $online = null;
        $checkout = app(PaymentGatewayCheckoutService::class);
        if (! $midtransReady && $gateways->onlineAvailable($invoice->customer_id !== null ? (int) $invoice->customer_id : null) && $checkout->isPayable($invoice)) {
            $gateway = $gateways->active();
            $open = PaymentGatewayTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('provider', $gateway->provider())
                ->where('status', PaymentGatewayTransaction::STATUS_PENDING)
                ->where('amount_invoice', $checkout->remaining($invoice))
                ->latest('id')
                ->first();
            $online = [
                'provider' => $gateway->provider(),
                'label' => $gateway->label(),
                'remaining' => $checkout->remaining($invoice),
                'fee_bearer' => app(PaymentGatewaySettings::class)->feeBearer(),
                'open_transaction' => $open && $open->isReusable() ? $open->toPublicArray() : null,
                // Other still-payable codes (after "Ganti metode"): shown with "Tampilkan lagi". Only inside
                // the online block, so the page props are unchanged while no direct provider is active.
                'other_open_transactions' => $checkout->openTransactions($invoice, $gateway->provider())
                    ->reject(fn (PaymentGatewayTransaction $txn): bool => $open !== null && $txn->id === $open->id && $open->isReusable())
                    ->take(PaymentGatewayCheckoutService::MAX_OPEN_CODES)
                    ->map(fn (PaymentGatewayTransaction $txn): array => $txn->toPublicArray())
                    ->values()
                    ->all(),
            ];
        }

        return Inertia::render('Public/Payment/Show', [
            // Explicit public DTO: never expose internal ids, payment rows,
            // admin metadata, proof paths, or reversal/idempotency details.
            'invoice' => [
                'uuid' => (string) $invoice->uuid,
                'code' => (string) $invoice->code,
                'period' => $invoice->period?->toDateString(),
                'amount' => (string) $invoice->amount,
                'status' => (string) $invoice->status,
                'due_date' => $invoice->due_date?->toDateString(),
                'customer' => [
                    'name' => (string) ($invoice->customer?->name ?? 'Pelanggan'),
                ],
            ],
            'channels' => $channels,
            'midtrans_enabled' => $midtransReady,
            'midtrans_client_key' => $midtransReady ? $midtrans->clientKey() : null,
            'midtrans_snap_js_url' => $midtransReady ? $midtrans->snapJsUrl() : null,
            'payment_result' => $request->string('payment_result')->toString(),
            'company' => [
                'name' => Setting::get('company_name', 'Skynet Network'),
                'address' => Setting::get('company_address', ''),
                // Same source as the /isolir portal so the public pages agree;
                // when that is empty, the company_wa_admin setting (default =
                // the number the page used to hardcode) fills in.
                'wa_admin' => trim((string) Setting::get(
                    'isolation_portal_wa_admin',
                    (string) config('services.isolation_portal.wa_admin', ''),
                )) ?: CompanyContact::waAdmin(),
            ],
            'manual_accounts' => $manualAccounts,
            'online' => $online,
            // Manual bank transfer is hidden only while an online method is actually offered.
            'online_only' => $midtransReady || $online !== null,
            'from_isolir' => $request->routeIs('public.isolated.payment'),
            // Display only: banner when Duitku is the active online provider in sandbox mode.
            'sandbox' => $online !== null
                && $online['provider'] === 'duitku'
                && app(DuitkuConfig::class)->environment() === DuitkuConfig::ENV_SANDBOX,
        ]);
    }
}
