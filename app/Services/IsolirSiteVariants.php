<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Site-branded variants of the public isolir page (/isolir).
 *
 * Driven by the `isolir_site_variants` setting (JSON, editable without a
 * deploy):
 *
 *   {"<key>": {"brand": "...", "tagline": "...", "cs_phone": "...",
 *              "bank_name": "...", "bank_account": "...", "bank_holder": "...",
 *              "logo": "images/isolir/<file>.webp", "source_cidrs": ["a.b.c.d/n"]}}
 *
 * Fail-safe: anything missing or invalid is dropped (never throws), so the
 * caller falls back to the general page.
 */
class IsolirSiteVariants
{
    public const SETTING_KEY = 'isolir_site_variants';

    private const MAX_VARIANTS = 20;

    private const MAX_CIDRS = 50;

    /**
     * Variant for this client: explicit `$siteKey` first, else the first
     * variant whose source_cidrs contain `$clientIp`.
     *
     * @return array{key: string, brand: string, tagline: string, cs_phone: string, bank_name: string, bank_account: string, bank_holder: string, logo: string, source_cidrs: list<string>}|null
     */
    public function resolve(?string $clientIp, ?string $siteKey = null): ?array
    {
        try {
            $variants = $this->all();
        } catch (\Throwable) {
            return null;
        }

        $siteKey = strtolower(trim((string) $siteKey));
        if ($siteKey !== '' && isset($variants[$siteKey])) {
            return $variants[$siteKey];
        }

        $clientIp = trim((string) $clientIp);
        if ($clientIp === '' || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        foreach ($variants as $variant) {
            foreach ($variant['source_cidrs'] as $cidr) {
                if ($this->ipInCidr($clientIp, $cidr)) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{key: string, brand: string, tagline: string, cs_phone: string, bank_name: string, bank_account: string, bank_holder: string, logo: string, source_cidrs: list<string>}>
     */
    public function all(): array
    {
        $raw = Setting::get(self::SETTING_KEY);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_slice($raw, 0, self::MAX_VARIANTS, true) as $key => $config) {
            $key = strtolower(trim((string) $key));
            if (preg_match('/^[a-z0-9_-]{1,32}$/', $key) !== 1 || ! is_array($config)) {
                continue;
            }

            $variant = $this->validate($key, $config);
            if ($variant !== null) {
                $out[$key] = $variant;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $c
     * @return array{key: string, brand: string, tagline: string, cs_phone: string, bank_name: string, bank_account: string, bank_holder: string, logo: string, source_cidrs: list<string>}|null
     */
    private function validate(string $key, array $c): ?array
    {
        $brand = $this->text($c['brand'] ?? null, 60);
        $tagline = $this->text($c['tagline'] ?? '', 100);
        $bankName = $this->text($c['bank_name'] ?? null, 30);
        $holder = $this->text($c['bank_holder'] ?? null, 60);

        $phone = $this->text($c['cs_phone'] ?? null, 25);
        $phoneDigits = $phone === null ? '' : (preg_replace('/\D+/', '', $phone) ?? '');
        if ($phone === null || preg_match('/^[0-9+\s().-]+$/', $phone) !== 1
            || strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
            return null;
        }

        $account = $this->text($c['bank_account'] ?? null, 30);
        $accountDigits = $account === null ? '' : (preg_replace('/\D+/', '', $account) ?? '');
        if ($account === null || preg_match('/^[0-9\s.-]+$/', $account) !== 1
            || strlen($accountDigits) < 5 || strlen($accountDigits) > 24) {
            return null;
        }

        if ($brand === null || $brand === '' || $tagline === null
            || $bankName === null || $bankName === '' || $holder === null || $holder === '') {
            return null;
        }

        $logo = $c['logo'] ?? '';
        if (! is_string($logo) || ($logo !== '' && preg_match('#^images/isolir/[A-Za-z0-9._-]{1,64}\.(webp|png|jpe?g)$#', $logo) !== 1)) {
            return null;
        }

        $cidrs = $c['source_cidrs'] ?? [];
        if (! is_array($cidrs) || count($cidrs) > self::MAX_CIDRS) {
            return null;
        }
        $validCidrs = [];
        foreach ($cidrs as $cidr) {
            if (! is_string($cidr) || $this->parseCidr(trim($cidr)) === null) {
                return null;
            }
            $validCidrs[] = trim($cidr);
        }

        return [
            'key' => $key,
            'brand' => $brand,
            'tagline' => $tagline,
            'cs_phone' => $phone,
            'bank_name' => $bankName,
            'bank_account' => $account,
            'bank_holder' => $holder,
            'logo' => $logo,
            'source_cidrs' => $validCidrs,
        ];
    }

    /** Trimmed single-line string within 1..$max chars; '' allowed only via default ''. */
    private function text(mixed $value, int $max): ?string
    {
        if ($value === '') {
            return '';
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: int}|null packed address + prefix length
     */
    private function parseCidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr);
        if (count($parts) > 2) {
            return null;
        }

        $packed = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? @inet_pton($parts[0]) : false;
        if ($packed === false) {
            return null;
        }

        $max = strlen($packed) * 8;
        if (count($parts) === 1) {
            return [$packed, $max];
        }

        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
            return null;
        }

        return [$packed, (int) $parts[1]];
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $parsed = $this->parseCidr($cidr);
        $packedIp = @inet_pton($ip);
        if ($parsed === null || $packedIp === false || strlen($packedIp) !== strlen($parsed[0])) {
            return false;
        }

        [$net, $bits] = $parsed;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
