<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Services\PaymentGateway\Drivers\DuitkuGateway;
use App\Services\PaymentGateway\Duitku\DuitkuCallbackHandler;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use App\Services\PaymentGateway\PaymentWriteGate;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Payment Gateway (superadmin only). Saving requires a TOTP
 * step-up because it controls where customer money goes.
 */
class PaymentGatewaySettingsController extends Controller
{
    public function index(
        PaymentGatewayManager $gateways,
        PaymentWriteGate $gate,
        PaymentGatewaySettings $settings,
        DuitkuConfig $duitku,
    ): Response {
        $apiKey = $duitku->apiKey();

        return Inertia::render('Settings/PaymentGateway', [
            'active_provider' => $gateways->activeProvider() ?? PaymentGatewayManager::NONE,
            'online_available' => $gateways->onlineAvailable(),
            'providers' => collect($gateways->providers())->map(fn (string $key): array => [
                'key' => $key,
                'label' => $gateways->driver($key)->label(),
                'configured' => $gateways->driver($key)->isConfigured(),
            ])->values(),
            'kill_switch' => [
                'enabled' => $gate->enabled(),
                'held_count' => PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_HELD)->count(),
            ],
            'general' => [
                'fee_bearer' => $settings->feeBearer(),
                'va_expiry_minutes' => $settings->vaExpiryMinutes(),
                'min_amount' => $settings->minAmount(),
                'placeholder_email_domain' => $settings->placeholderEmailDomain(),
                'pilot_customer_ids' => $settings->pilotCustomerIds(),
                'pilot_customers' => Customer::query()
                    ->whereIn('id', $settings->pilotCustomerIds())
                    ->orderBy('id')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Customer $c): array => ['id' => (int) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name])
                    ->all(),
            ],
            'duitku' => [
                'merchant_code' => $duitku->merchantCode(),
                'api_key_set' => $apiKey !== '',
                'api_key_masked' => $this->mask($apiKey),
                'environment' => $duitku->environment(),
                'signature_mode' => $duitku->signatureMode(),
                'enabled_methods' => $duitku->enabledMethods(),
                'ip_whitelist_enabled' => $duitku->ipWhitelistEnabled(),
                'callback_ips' => $duitku->callbackIps(),
                'callback_ips_customized' => $duitku->callbackIpsCustomized(),
                'callback_ips_by_env' => [
                    DuitkuConfig::ENV_SANDBOX => $duitku->callbackIpsFor(DuitkuConfig::ENV_SANDBOX),
                    DuitkuConfig::ENV_PRODUCTION => $duitku->callbackIpsFor(DuitkuConfig::ENV_PRODUCTION),
                ],
                'documented_ips' => (array) config('services.duitku.callback_ips', []),
                'callback_url' => route('webhooks.payment-gateway', 'duitku'),
                'return_url_example' => route('public.invoice.show', 'UUID-INVOICE').'?payment_result=return',
                'last_rejected_ip' => Cache::get(DuitkuCallbackHandler::LAST_REJECTED_IP_CACHE_KEY),
                'supported_methods' => DuitkuGateway::METHOD_GROUPS,
            ],
            'stats' => [
                'pending' => PaymentGatewayTransaction::query()->where('status', PaymentGatewayTransaction::STATUS_PENDING)->count(),
                'rejected' => PaymentGatewayTransaction::query()->where('status', PaymentGatewayTransaction::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function update(Request $request, DuitkuConfig $duitku): RedirectResponse
    {
        if (is_array($request->input('duitku_enabled_methods'))) {
            $request->merge(['duitku_enabled_methods' => array_map(
                fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code,
                $request->input('duitku_enabled_methods'),
            )]);
        }

        $validated = $request->validate([
            'active_provider' => ['required', Rule::in(array_merge([PaymentGatewayManager::NONE], app(PaymentGatewayManager::class)->providers()))],
            'fee_bearer' => ['required', Rule::in(['customer', 'merchant'])],
            'va_expiry_minutes' => ['required', 'integer', 'min:60', 'max:10080'],
            'min_amount' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'placeholder_email_domain' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'pilot_customer_ids' => ['nullable', 'array', 'max:200'],
            'pilot_customer_ids.*' => ['integer', 'min:1', 'distinct', Rule::exists('customers', 'id')],
            'duitku_merchant_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]*$/'],
            'duitku_api_key' => ['nullable', 'string', 'max:255'],
            'duitku_clear_api_key' => ['nullable', 'boolean'],
            'duitku_environment' => ['required', Rule::in([DuitkuConfig::ENV_SANDBOX, DuitkuConfig::ENV_PRODUCTION])],
            'duitku_signature_mode' => ['required', Rule::in([DuitkuConfig::SIGNATURE_HMAC, DuitkuConfig::SIGNATURE_LEGACY])],
            'duitku_enabled_methods' => ['nullable', 'array', 'max:40'],
            'duitku_enabled_methods.*' => ['string', Rule::in(array_keys(DuitkuGateway::METHOD_GROUPS))],
            'duitku_ip_whitelist_enabled' => ['required', 'boolean'],
            'duitku_callback_ips' => ['nullable', 'array', 'max:50'],
            'duitku_callback_ips.*' => ['ip'],
            'confirm_production' => ['nullable', 'boolean'],
        ]);

        $toProduction = $validated['duitku_environment'] === DuitkuConfig::ENV_PRODUCTION && ! $duitku->isProduction();
        if ($toProduction && empty($validated['confirm_production'])) {
            throw ValidationException::withMessages([
                'duitku_environment' => 'Konfirmasi dulu perpindahan ke production (transaksi uang asli).',
            ]);
        }

        $before = $this->snapshot($duitku);

        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, $validated['active_provider'], 'text', 'payment', 'Payment gateway aktif');
        Setting::set(PaymentGatewaySettings::FEE_BEARER, $validated['fee_bearer'], 'text', 'payment', 'Penanggung fee gateway');
        Setting::set(PaymentGatewaySettings::VA_EXPIRY_MINUTES, (string) $validated['va_expiry_minutes'], 'integer', 'payment', 'Masa berlaku VA (menit)');
        Setting::set(PaymentGatewaySettings::MIN_AMOUNT, (string) $validated['min_amount'], 'integer', 'payment', 'Minimum bayar online');
        Setting::set(PaymentGatewaySettings::PLACEHOLDER_EMAIL_DOMAIN, strtolower($validated['placeholder_email_domain']), 'text', 'payment', 'Domain email placeholder');
        if (array_key_exists('pilot_customer_ids', $validated)) {
            Setting::set(
                PaymentGatewaySettings::PILOT_CUSTOMER_IDS,
                array_values(array_unique(array_map('intval', $validated['pilot_customer_ids'] ?? []))),
                'json',
                'payment',
                'Pelanggan uji bayar online (kosong = semua)',
            );
        }

        Setting::set(DuitkuConfig::MERCHANT_CODE, trim((string) ($validated['duitku_merchant_code'] ?? '')), 'text', 'payment', 'Duitku merchant code');
        if (! empty($validated['duitku_clear_api_key'])) {
            Setting::setSecret(DuitkuConfig::API_KEY, '', 'payment', 'Duitku API key');
        } elseif (trim((string) ($validated['duitku_api_key'] ?? '')) !== '') {
            Setting::setSecret(DuitkuConfig::API_KEY, $validated['duitku_api_key'], 'payment', 'Duitku API key');
        }
        Setting::set(DuitkuConfig::ENVIRONMENT, $validated['duitku_environment'], 'text', 'payment', 'Duitku environment');
        Setting::set(DuitkuConfig::SIGNATURE_MODE, $validated['duitku_signature_mode'], 'text', 'payment', 'Duitku signature mode');
        Setting::set(DuitkuConfig::ENABLED_METHODS, array_values(array_unique(array_map('strtoupper', $validated['duitku_enabled_methods'] ?? []))), 'json', 'payment', 'Duitku metode aktif');
        Setting::set(DuitkuConfig::IP_WHITELIST_ENABLED, $validated['duitku_ip_whitelist_enabled'] ? '1' : '0', 'boolean', 'payment', 'Duitku whitelist IP callback');
        $this->saveCallbackIps($duitku, $validated['duitku_environment'], $validated['duitku_callback_ips'] ?? null);

        $after = $this->snapshot($duitku);
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        activity()
            ->causedBy($request->user())
            ->withProperties(['changed' => $changed, 'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))])
            ->log('payment_gateway_settings_updated');

        return back()->with('success', 'Pengaturan payment gateway disimpan.');
    }

    /**
     * Saves the callback IP list for the environment being saved. A list that
     * equals the OTHER environment's documented list is ignored: it is what
     * the form carries after an environment switch and would block every
     * callback of the new environment. A list equal to this environment's
     * documented list clears the custom list (the documented one applies).
     *
     * @param  array<int, string>|null  $submitted
     */
    private function saveCallbackIps(DuitkuConfig $duitku, string $environment, ?array $submitted): void
    {
        // The old single-list key is retired on every save; per-environment rows replace it.
        Setting::query()->where('key', DuitkuConfig::CALLBACK_IPS)->delete();
        Cache::forget('setting.'.DuitkuConfig::CALLBACK_IPS);
        Setting::flushRequestMemo();

        if ($submitted === null || $submitted === []) {
            return;
        }

        $other = $environment === DuitkuConfig::ENV_PRODUCTION ? DuitkuConfig::ENV_SANDBOX : DuitkuConfig::ENV_PRODUCTION;
        if (DuitkuConfig::sameIps($submitted, $duitku->documentedIps($other))) {
            return;
        }

        $key = DuitkuConfig::callbackIpsKey($environment);
        if (DuitkuConfig::sameIps($submitted, $duitku->documentedIps($environment))) {
            Setting::set($key, '', 'json', 'payment', 'Duitku IP callback ('.$environment.')');

            return;
        }

        Setting::set($key, array_values(array_unique($submitted)), 'json', 'payment', 'Duitku IP callback ('.$environment.')');
    }

    /**
     * Calls Duitku getpaymentmethod with the saved credentials: doubles as
     * "Tes koneksi" and as the source of the selectable method list.
     */
    public function duitkuMethods(Request $request, DuitkuGateway $duitku): JsonResponse
    {
        $amount = (int) $request->integer('amount', 100000);
        $amount = min(max($amount, 10000), 100000000);

        if (! $duitku->isConfigured()) {
            return response()->json(['ok' => false, 'message' => 'Isi merchant code dan API key lalu simpan dulu.'], 422);
        }

        try {
            $methods = $duitku->availableMethods($amount);
        } catch (\Throwable $e) {
            Log::warning('[Duitku] settings method list failed', ['exception_class' => $e::class]);

            return response()->json([
                'ok' => false,
                'message' => 'Gagal menghubungi Duitku: '.mb_substr($e->getMessage(), 0, 160),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'environment' => $duitku->isProduction() ? 'production' : 'sandbox',
            'amount' => $amount,
            'methods' => array_map(fn ($m) => $m->toArray(), $methods),
        ]);
    }

    /**
     * Non-secret snapshot for the audit log (API key reduced to set/unset + fingerprint).
     *
     * @return array<string, mixed>
     */
    private function snapshot(DuitkuConfig $duitku): array
    {
        $settings = app(PaymentGatewaySettings::class);
        $apiKey = $duitku->apiKey();

        return [
            'active_provider' => app(PaymentGatewayManager::class)->activeProvider() ?? PaymentGatewayManager::NONE,
            'fee_bearer' => $settings->feeBearer(),
            'va_expiry_minutes' => $settings->vaExpiryMinutes(),
            'min_amount' => $settings->minAmount(),
            'placeholder_email_domain' => $settings->placeholderEmailDomain(),
            'pilot_customer_ids' => $settings->pilotCustomerIds(),
            'duitku_merchant_code' => $duitku->merchantCode(),
            'duitku_api_key' => $apiKey === '' ? 'unset' : 'set:'.substr(hash('sha256', $apiKey), 0, 8),
            'duitku_environment' => $duitku->environment(),
            'duitku_signature_mode' => $duitku->signatureMode(),
            'duitku_enabled_methods' => $duitku->enabledMethods(),
            'duitku_ip_whitelist_enabled' => $duitku->ipWhitelistEnabled(),
            'duitku_callback_ips' => $duitku->callbackIps(),
        ];
    }

    private function mask(string $key): string
    {
        $len = strlen($key);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 4).str_repeat('•', 8).substr($key, -4);
    }
}
