<?php

namespace App\Services\Outage\Diagnosis\Backtest;

/**
 * Keeps the back-test corpus ids-only (owner Q4): no names, phone numbers, PPPoE users, addresses, MACs, SSIDs or
 * serials. The snapshot already drops pppoe_user / device_id / probes; this is the second fence, applied on export and
 * checked by the guard test on every committed corpus file.
 */
final class CorpusScrubber
{
    /** Keys that must never appear anywhere in a corpus row. */
    public const FORBIDDEN_KEYS = ['name', 'phone', 'address', 'pppoe_user', 'pppoe_password', 'username', 'user', 'mac', 'caller_mac', 'ssid', 'serial', 'onu_serial', 'device_id', 'email', 'wa', 'password', 'secret'];

    private const MAX_STRING = 120;

    private const PHONE = '/(?:\+?62|\b0)8\d{7,12}\b/';

    private const MAC = '/\b[0-9a-f]{2}(?:[:-][0-9a-f]{2}){5}\b/i';

    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const SERIAL = '/\b(?:ZTEG|HWTC|FHTT|GPON)[0-9A-F]{8}\b/i';

    /** A string value that looks like personal data. */
    public static function looksPersonal(string $s): bool
    {
        return preg_match(self::PHONE, $s) === 1 || preg_match(self::MAC, $s) === 1 || preg_match(self::EMAIL, $s) === 1 || preg_match(self::SERIAL, $s) === 1;
    }

    /**
     * A context snapshot made ids-only: router / OLT names (infrastructure, but free text) become "router-<id>" /
     * "olt-<id>", then every other string goes through the pattern scrub and forbidden keys are dropped.
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<string>  $knownIdentities  values of this customer to cut out (pppoe user, name, phone ...)
     * @return array<string,mixed>
     */
    public static function scrubContext(array $ctx, array $knownIdentities = []): array
    {
        foreach (['router', 'olt'] as $layer) {
            if (isset($ctx[$layer]) && is_array($ctx[$layer]) && ($ctx[$layer]['name'] ?? null) !== null) {
                $ctx[$layer]['name'] = $layer.'-'.(int) ($ctx[$layer]['id'] ?? 0);
            }
        }
        // ODP labels are place names: the rules only compare them, so a stable hash keeps every comparison intact
        foreach (['customer', 'cluster'] as $layer) {
            if (isset($ctx[$layer]['odp']) && is_string($ctx[$layer]['odp']) && $ctx[$layer]['odp'] !== '') {
                $ctx[$layer]['odp'] = 'odp-'.substr(hash('sha256', 'ebilling-corpus-v1:'.mb_strtolower($ctx[$layer]['odp'])), 0, 8);
            }
        }

        /** @var array<string,mixed> $out */
        $out = self::scrub($ctx, $knownIdentities, '');

        return $out;
    }

    /**
     * @param  list<string>  $knownIdentities
     */
    public static function scrub(mixed $value, array $knownIdentities = [], string $path = ''): mixed
    {
        if (is_string($value)) {
            foreach ($knownIdentities as $id) {
                if (mb_strlen($id) >= 4) {
                    $value = str_ireplace($id, '', $value);
                }
            }
            $value = (string) preg_replace([self::PHONE, self::MAC, self::EMAIL, self::SERIAL], '', $value);

            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && self::forbiddenKey($k, $path, $v)) {
                continue;
            }
            $out[$k] = self::scrub($v, $knownIdentities, $path === '' ? (string) $k : $path.'.'.$k);
        }

        return $out;
    }

    /**
     * Guard: every violation found in one corpus row (empty = clean).
     *
     * @return list<string>
     */
    public static function violations(mixed $value, string $path = ''): array
    {
        $out = [];
        if (is_string($value)) {
            if (self::looksPersonal($value)) {
                $out[] = "{$path}: personal-looking string";
            }
            if (mb_strlen($value) > self::MAX_STRING) {
                $out[] = "{$path}: string longer than ".self::MAX_STRING;
            }

            return $out;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $child = $path === '' ? (string) $k : $path.'.'.$k;
                if (is_string($k) && self::forbiddenKey($k, $path, $v)) {
                    $out[] = "{$child}: forbidden key";
                }
                $out = array_merge($out, self::violations($v, $child));
            }
        }

        return $out;
    }

    /** `router.name` / `olt.name` are allowed only as the "router-<id>" / "olt-<id>" placeholder (or null). */
    private static function forbiddenKey(string $key, string $path, mixed $value): bool
    {
        $key = strtolower($key);
        if (! in_array($key, self::FORBIDDEN_KEYS, true)) {
            return false;
        }
        if ($key === 'name' && in_array($path, ['context.router', 'context.olt', 'router', 'olt'], true)) {
            return ! ($value === null || (is_string($value) && preg_match('/^(router|olt)-\d+$/', $value) === 1));
        }

        return true;
    }
}
