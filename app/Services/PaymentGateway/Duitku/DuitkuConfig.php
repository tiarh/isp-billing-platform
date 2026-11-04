<?php

namespace App\Services\PaymentGateway\Duitku;

use App\Models\Setting;

/**
 * Duitku settings (Settings > Payment Gateway). The API key is stored with
 * Setting::setSecret() and never leaves the server.
 */
class DuitkuConfig
{
    public const MERCHANT_CODE = 'duitku_merchant_code';

    public const API_KEY = 'duitku_api_key';

    public const ENVIRONMENT = 'duitku_environment';

    public const SIGNATURE_MODE = 'duitku_signature_mode';

    public const ENABLED_METHODS = 'duitku_enabled_methods';

    public const IP_WHITELIST_ENABLED = 'duitku_callback_ip_whitelist_enabled';

    /** Old single list (before per-environment lists); read-only fallback. */
    public const CALLBACK_IPS = 'duitku_callback_ips';

    public const CALLBACK_IPS_SANDBOX = 'duitku_callback_ips_sandbox';

    public const CALLBACK_IPS_PRODUCTION = 'duitku_callback_ips_production';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    /** Current docs: HMAC-SHA256 keyed with the API key. */
    public const SIGNATURE_HMAC = 'hmac_sha256';

    /** Older docs: MD5/SHA256 over the string with the API key appended. */
    public const SIGNATURE_LEGACY = 'legacy';

    public const BASE_URLS = [
        self::ENV_SANDBOX => 'https://sandbox.duitku.com/webapi/api/merchant',
        self::ENV_PRODUCTION => 'https://passport.duitku.com/webapi/api/merchant',
    ];

    public function merchantCode(): string
    {
        return trim((string) Setting::get(self::MERCHANT_CODE, ''));
    }

    public function apiKey(): string
    {
        return Setting::secret(self::API_KEY);
    }

    public function isConfigured(): bool
    {
        return $this->merchantCode() !== '' && $this->apiKey() !== '';
    }

    public function environment(): string
    {
        return Setting::get(self::ENVIRONMENT, self::ENV_SANDBOX) === self::ENV_PRODUCTION
            ? self::ENV_PRODUCTION
            : self::ENV_SANDBOX;
    }

    public function isProduction(): bool
    {
        return $this->environment() === self::ENV_PRODUCTION;
    }

    public function baseUrl(): string
    {
        return self::BASE_URLS[$this->environment()];
    }

    public function signatureMode(): string
    {
        return Setting::get(self::SIGNATURE_MODE, self::SIGNATURE_HMAC) === self::SIGNATURE_LEGACY
            ? self::SIGNATURE_LEGACY
            : self::SIGNATURE_HMAC;
    }

    /**
     * Method codes enabled by the admin. Empty = every supported method the
     * merchant account returns (useful for the first sandbox test).
     *
     * @return list<string>
     */
    public function enabledMethods(): array
    {
        $value = Setting::get(self::ENABLED_METHODS, []);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($code): string => strtoupper(trim((string) $code)),
            is_array($value) ? $value : [],
        ))));
    }

    public function ipWhitelistEnabled(): bool
    {
        $value = Setting::get(self::IP_WHITELIST_ENABLED, true);

        return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Setting key holding the custom callback IP list of one environment.
     */
    public static function callbackIpsKey(string $environment): string
    {
        return $environment === self::ENV_PRODUCTION ? self::CALLBACK_IPS_PRODUCTION : self::CALLBACK_IPS_SANDBOX;
    }

    /**
     * Documented (official) callback IPs of an environment, from config.
     *
     * @return list<string>
     */
    public function documentedIps(?string $environment = null): array
    {
        return array_values((array) config('services.duitku.callback_ips.'.($environment ?? $this->environment()), []));
    }

    /**
     * True when two IP lists hold the same addresses (order and duplicates ignored).
     *
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    public static function sameIps(array $a, array $b): bool
    {
        $norm = static function (array $list): array {
            $list = array_values(array_unique(array_map(static fn ($ip): string => trim((string) $ip), $list)));
            sort($list);

            return $list;
        };

        return $norm($a) === $norm($b);
    }

    /**
     * True when the admin saved a custom list for the current environment.
     */
    public function callbackIpsCustomized(): bool
    {
        return $this->customIps($this->environment()) !== null;
    }

    /**
     * Allowed callback source IPs for the current environment: the custom
     * list saved for THIS environment, else the documented list. The old
     * single key (duitku_callback_ips) is still read as a fallback, but never
     * when it equals the other environment's documented list (the pin bug).
     *
     * @return list<string>
     */
    public function callbackIps(): array
    {
        return $this->callbackIpsFor($this->environment());
    }

    /**
     * @return list<string>
     */
    public function callbackIpsFor(string $environment): array
    {
        return $this->customIps($environment) ?? $this->documentedIps($environment);
    }

    /**
     * @return list<string>|null null = no custom list
     */
    private function customIps(string $environment): ?array
    {
        $value = $this->decodeList(Setting::get(self::callbackIpsKey($environment)));
        // The old single key is only a fallback while this environment has no row at all
        // (a saved '' row means "use the documented list" and must not fall back).
        if ($value === null && ! Setting::query()->where('key', self::callbackIpsKey($environment))->exists()) {
            $legacy = $this->decodeList(Setting::get(self::CALLBACK_IPS));
            $other = $environment === self::ENV_PRODUCTION ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
            if ($legacy !== null && ! self::sameIps($legacy, $this->documentedIps($other))) {
                $value = $legacy;
            }
        }
        if ($value === null) {
            return null;
        }

        return array_values(array_filter(
            array_map(fn ($ip): string => trim((string) $ip), $value),
            fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }
}
