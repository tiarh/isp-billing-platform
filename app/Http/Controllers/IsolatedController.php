<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Router;
use App\Models\Setting;
use App\Services\BillingEligibilityService;
use App\Services\IsolirSiteVariants;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class IsolatedController extends Controller
{
    /** Router profile is isolir and the customer still owes the current invoice. */
    public const STATE_ISOLATED = 'isolated';

    /** Router still isolir but the invoice is settled: reconnect is in progress. */
    public const STATE_PAID_RECOVERING = 'paid_recovering';

    /** Router secret read OK with a normal profile. */
    public const STATE_NOT_ISOLATED = 'not_isolated';

    /** IP unknown / out of scope / router unreadable: we cannot tell. */
    public const STATE_UNKNOWN = 'unknown';

    public function index(
        Request $request,
        BillingEligibilityService $eligibility,
        MikrotikService $mikrotik
    ) {
        $this->ensureEnabled();

        return Inertia::render('Isolated/Index', $this->payload($request, $eligibility, $mikrotik));
    }

    public function status(
        Request $request,
        BillingEligibilityService $eligibility,
        MikrotikService $mikrotik
    ) {
        $this->ensureEnabled();

        return response()->json($this->payload($request, $eligibility, $mikrotik), 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * General isolation notice page.
     *
     * Deliberately does NOT identify the customer, read MikroTik, or expose any
     * invoice/payment data. Anyone whose device was redirected here (captive
     * portal) sees the same message: internet is isolated, how to restore it,
     * and how to reach CS. Only company-level contact data (company name, CS
     * WhatsApp, office address/hours; the address falls back to the company
     * address) is shown, and only when configured in Settings. Bank accounts
     * (payment_channels) are deliberately NOT shown: owner request, customers
     * are directed to chat CS or come to the office.
     *
     * Rendered as a standalone Blade view (no JS, inline CSS, ~2 KB logo)
     * because it is opened inside phone captive-portal mini-browsers that may
     * run without JS and only reach this server through a narrow allow-list.
     */
    public function general(Request $request, IsolirSiteVariants $variants)
    {
        $this->ensureEnabled();

        // Site-branded variant: client IP (trusted-proxy aware, same as the
        // rest of the portal) inside a variant's source_cidrs, or an explicit
        // ?site=<key> (testing). Anything else keeps the general page below.
        $variant = $variants->resolve(
            (string) $request->ip(),
            is_string($request->query('site')) ? $request->query('site') : null,
        );
        if ($variant !== null) {
            return $this->siteResponse($variant);
        }

        $settings = $this->portalSettings();
        $waDigits = $this->waDigits($settings['wa_admin']);

        return response()
            ->view('isolir.general', [
                'companyName' => $this->companyName(),
                'waAdmin' => $settings['wa_admin'],
                'waLink' => $waDigits !== '' ? 'https://wa.me/'.$waDigits : null,
                'telLink' => $waDigits !== '' ? 'tel:+'.$waDigits : null,
                'officeAddress' => $settings['office_address'] !== ''
                    ? $settings['office_address']
                    : trim((string) Setting::get('company_address', '')),
                'officeHours' => $settings['office_hours'],
            ])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @param  array<string, mixed>  $variant  validated by IsolirSiteVariants
     */
    private function siteResponse(array $variant): Response
    {
        $digits = $this->waDigits((string) $variant['cs_phone']);

        return response()
            ->view('isolir.site', [
                'brand' => $variant['brand'],
                'tagline' => $variant['tagline'],
                'csPhone' => $variant['cs_phone'],
                'waLink' => $digits !== '' ? 'https://wa.me/'.$digits : null,
                'telLink' => $digits !== '' ? 'tel:+'.$digits : null,
                'bankName' => $variant['bank_name'],
                'bankAccount' => $variant['bank_account'],
                'bankHolder' => $variant['bank_holder'],
                'logo' => $variant['logo'] !== '' ? '/'.$variant['logo'] : null,
            ])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Captive-portal entrypoint.
     *
     * Isolated clients' plain-HTTP traffic is dst-nat'ed by MikroTik to the
     * eBilling server, where a low-priority Traefik catch-all redirects every
     * foreign Host/path here (see docs/ISOLIR_PORTAL_MIKROTIK_SETUP.md). This
     * endpoint forwards to the page selected by `isolation_portal_mode`, so
     * switching between the general notice and the per-customer page needs no
     * router change.
     *
     * The Location is always absolute on APP_URL: a request may arrive with
     * the original site's Host header (e.g. connectivitycheck.gstatic.com) and
     * the redirect must never point back at that foreign host.
     */
    public function captive(Request $request)
    {
        $this->ensureEnabled();

        $target = $this->portalMode() === 'customer'
            ? '/isolated'
            : '/isolir';

        // Preserve an explicit ip= hint for the customer page (test/canary).
        $ip = trim((string) $request->query('ip', ''));
        if ($target === '/isolated' && $ip !== '' && $this->isValidIp($ip)) {
            $target .= '?ip='.rawurlencode($ip);
        }

        // Pass an explicit site variant key through to the general page.
        $site = $request->query('site');
        if ($target === '/isolir' && is_string($site) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $site) === 1) {
            $target .= '?site='.rawurlencode($site);
        }

        return redirect()->away($this->appRoot().$target, 302, ['Cache-Control' => 'no-store, max-age=0']);
    }

    /**
     * APP_URL host (never the request's possibly-foreign Host), with the URL
     * generator's forced scheme applied: AppServiceProvider forces https in
     * production / APP_FORCE_HTTPS, so an http:// APP_URL must not downgrade
     * the captive redirect.
     */
    private function appRoot(): string
    {
        $root = rtrim(trim((string) config('app.url')), '/');

        if ($root === '' || preg_match('#^https?://#i', $root) !== 1) {
            return rtrim(url('/'), '/');
        }

        if (stripos($root, 'http://') === 0 && URL::formatScheme() === 'https://') {
            $root = 'https://'.substr($root, 7);
        }

        return $root;
    }

    private function companyName(): string
    {
        $name = trim((string) Setting::get('company_name', ''));

        return $name !== '' ? $name : 'Skynet Lintas Nusantara';
    }

    private function waDigits(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return strlen($digits) >= 8 ? $digits : '';
    }

    private function portalMode(): string
    {
        $mode = strtolower(trim((string) Setting::get(
            'isolation_portal_mode',
            (string) config('services.isolation_portal.mode', 'general'),
        )));

        return $mode === 'customer' ? 'customer' : 'general';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Request $request,
        BillingEligibilityService $eligibility,
        MikrotikService $mikrotik
    ): array {
        $ip = $this->candidateIp($request);

        if (! $this->isValidIp($ip)) {
            return $this->emptyPayload(self::STATE_UNKNOWN);
        }

        // The office canary is intentionally not an eBilling customer. It must
        // never perform a customer/area lookup before the device read.
        if ($this->isTestIp($ip)) {
            $secret = $this->lookupPppSecret($mikrotik, $ip);

            if (! $this->hasIsolationProfile($secret)) {
                return $this->emptyPayload($secret === null ? self::STATE_UNKNOWN : self::STATE_NOT_ISOLATED);
            }

            return [
                'is_isolated' => true,
                'state' => self::STATE_ISOLATED,
                'portal_type' => 'office',
                'message' => 'WiFi Kantor Diisolir',
                'period' => null,
                'amount' => null,
                'payment_url' => null,
            ];
        }

        // Test mode is an exact one-IP canary. No other IP may reach either
        // the database eligibility check or MikroTik while it is enabled.
        if ($this->testModeEnabled()) {
            return $this->emptyPayload(self::STATE_UNKNOWN);
        }

        $customer = $this->findCustomerByIp($ip);

        if (! $customer || ! $this->isAreaEnabled($customer->area?->name)) {
            return $this->emptyPayload(self::STATE_UNKNOWN);
        }

        $decision = $eligibility->evaluate($customer);
        $secret = $this->lookupPppSecret($mikrotik, $ip, (string) $customer->pppoe_user);
        $routerIsolated = $this->hasIsolationProfile($secret);
        $invoice = $decision['current_invoice'] ?? null;

        if (! $routerIsolated) {
            // Secret tidak terbaca (router down / tidak ditemukan) = status tidak
            // diketahui; secret terbaca dengan profil normal = koneksi normal.
            return $this->emptyPayload($secret === null ? self::STATE_UNKNOWN : self::STATE_NOT_ISOLATED);
        }

        $dbOutstanding = (bool) (($decision['current_is_outstanding'] ?? false)
            && ! ($decision['current_is_future'] ?? false)
            && $invoice);

        if (! $dbOutstanding) {
            return [
                ...$this->emptyPayload(self::STATE_PAID_RECOVERING),
                'message' => 'Pembayaran Diterima',
            ];
        }

        return [
            'is_isolated' => true,
            'state' => self::STATE_ISOLATED,
            'portal_type' => 'customer',
            'message' => 'Belum Lunas',
            'period' => $invoice->period?->toDateString(),
            'amount' => (int) round((float) $invoice->amount),
            'payment_url' => URL::temporarySignedRoute(
                'public.isolated.payment',
                now()->addMinutes(15),
                ['uuid' => $invoice->uuid],
            ),
            // STEP 1 additive fields (portal UX): name/id + office/WA details.
            'name' => $customer->name,
            'customer_id' => $customer->id,
            'customer_code' => (string) ($customer->code ?? ''),
            ...$this->portalSettings(),
        ];
    }

    /**
     * @return array{wa_admin: string, wa_digits: string, office_address: string, office_hours: string}
     */
    private function portalSettings(): array
    {
        $waAdmin = trim((string) Setting::get(
            'isolation_portal_wa_admin',
            (string) config('services.isolation_portal.wa_admin', ''),
        ));

        return [
            'wa_admin' => $waAdmin,
            // P2: nomor siap pakai untuk wa.me (0812… → 62812…), sama dengan halaman /isolir.
            'wa_digits' => $this->waDigits($waAdmin),
            'office_address' => trim((string) Setting::get(
                'isolation_portal_office_address',
                (string) config('services.isolation_portal.office_address', ''),
            )),
            'office_hours' => trim((string) Setting::get(
                'isolation_portal_office_hours',
                (string) config('services.isolation_portal.office_hours', ''),
            )),
        ];
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->portalEnabled(), 404);
    }

    private function portalEnabled(): bool
    {
        return $this->settingBoolean(
            'isolation_portal_enabled',
            (bool) config('services.isolation_portal.enabled', false),
        );
    }

    private function testModeEnabled(): bool
    {
        return $this->settingBoolean(
            'isolation_portal_test_mode',
            (bool) config('services.isolation_portal.test_mode', true),
        );
    }

    private function testIp(): string
    {
        return trim((string) Setting::get(
            'isolation_portal_test_ip',
            config('services.isolation_portal.test_ip', ''),
        ));
    }

    private function settingBoolean(string $key, bool $fallback): bool
    {
        $value = filter_var(Setting::get($key, $fallback), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $fallback;
    }

    private function candidateIp(Request $request): string
    {
        $requestedIp = trim((string) $request->query('ip', ''));

        if ($this->testModeEnabled() && $requestedIp !== '') {
            return $requestedIp;
        }

        return (string) $request->ip();
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupPppSecret(MikrotikService $mikrotik, string $ip, string $pppoeUser = ''): ?array
    {
        $subject = trim($pppoeUser) !== '' ? trim($pppoeUser) : $ip;
        $cacheKey = 'isolation-portal:ppp-secret:'.hash('sha256', strtolower($subject).'|'.$ip);

        try {
            // The lookup result contains only non-secret metadata. Passwords
            // are never read into this portal path or stored in the cache.
            $cached = Cache::remember(
                $cacheKey,
                now()->addSeconds(30 + random_int(0, 30)),
                fn (): array => ['secret' => $mikrotik->findPPPSecretByIp($ip)],
            );

            return is_array($cached) && is_array($cached['secret'] ?? null)
                ? $cached['secret']
                : null;
        } catch (\Throwable $exception) {
            Log::warning('Isolation portal MikroTik read failed.', [
                'error' => get_class($exception),
            ]);

            return null;
        }
    }

    /** STEP 1 lookup order: static_ip -> last_learned_ip -> pppoe_remote_address. */
    private function findCustomerByIp(string $ip): ?Customer
    {
        return Customer::query()
            ->where(function ($query) use ($ip): void {
                $query->where('static_ip', $ip)
                    ->orWhere('last_learned_ip', $ip)
                    ->orWhere('pppoe_remote_address', $ip);
            })
            ->with('area')
            ->first();
    }

    /** STEP 1 additive gate: the areas.portal_enabled flag. */
    private function areaPortalEnabled(?object $area): bool
    {
        return (bool) ($area?->portal_enabled ?? false);
    }

    private function isTestIp(string $ip): bool
    {
        $testIp = $this->testIp();

        return $testIp !== '' && hash_equals($testIp, $ip);
    }

    private function isAreaEnabled(?string $area): bool
    {
        $normalizedArea = $this->normalizeArea($area);
        if ($normalizedArea === '') {
            return false;
        }

        foreach ($this->enabledAreas() as $enabledArea) {
            $normalizedEnabledArea = $this->normalizeArea(is_string($enabledArea) ? $enabledArea : null);

            if ($normalizedEnabledArea === '*' || $normalizedArea === $normalizedEnabledArea) {
                return true;
            }
        }

        // STEP 1: areas.portal_enabled acts as an additional allowlist source.
        if ($this->areaPortalEnabled($this->areaByName($normalizedArea))) {
            return true;
        }

        return false;
    }

    private function areaByName(string $normalizedArea): ?object
    {
        return Area::query()
            ->get(['id', 'name', 'portal_enabled'])
            ->first(fn ($area) => $this->normalizeArea($area->name) === $normalizedArea);
    }

    /**
     * @return array<int, mixed>
     */
    private function enabledAreas(): array
    {
        $areas = Setting::get(
            'isolation_portal_areas',
            config('services.isolation_portal.areas', []),
        );

        if (is_string($areas)) {
            $decoded = json_decode($areas, true);
            $areas = is_array($decoded) ? $decoded : [];
        }

        return is_array($areas) ? array_values($areas) : [];
    }

    private function normalizeArea(?string $area): string
    {
        $area = trim((string) $area);

        return strtolower(preg_replace('/\s+/', ' ', $area) ?? $area);
    }

    private function hasIsolationProfile(?array $secret): bool
    {
        $profile = strtolower(trim((string) ($secret['profile'] ?? '')));

        return $profile !== '' && in_array($profile, $this->isolationProfileNames(), true);
    }

    /**
     * Default isolir profile names plus every per-router `routers.isolation_profile`
     * (lower-cased), so a router configured with a custom isolir profile name is
     * still recognised by the customer page.
     *
     * @return list<string>
     */
    private function isolationProfileNames(): array
    {
        $configured = Cache::remember('isolation-portal:profile-names', now()->addMinutes(5), function (): array {
            try {
                return Router::query()
                    ->whereNotNull('isolation_profile')
                    ->pluck('isolation_profile')
                    ->map(fn ($name): string => strtolower(trim((string) $name)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return array_values(array_unique([
            'isolirebilling',
            'isolir',
            ...(is_array($configured) ? $configured : []),
        ]));
    }

    /**
     * Non-isolated payload. `state` tells the page which of the three
     * non-billing messages to show (P3): paid_recovering, not_isolated or
     * unknown. Only the public CS WhatsApp is added, never customer data.
     *
     * @return array{is_isolated: bool, state: string, portal_type: ?string, message: ?string, period: ?string, amount: ?int, payment_url: ?string, wa_admin: string, wa_digits: string}
     */
    private function emptyPayload(string $state = self::STATE_UNKNOWN): array
    {
        $settings = $this->portalSettings();

        return [
            'is_isolated' => false,
            'state' => $state,
            'portal_type' => null,
            'message' => null,
            'period' => null,
            'amount' => null,
            'payment_url' => null,
            'wa_admin' => $settings['wa_admin'],
            'wa_digits' => $settings['wa_digits'],
        ];
    }
}
