<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback SNMP v2c community for OLTs whose `olts.snmp_community` is empty.
 *
 * Resolution order (unchanged from the inline `?:` fallbacks it replaces):
 *   1. the per-OLT DB value (`$olt->snmp_community`), when truthy;
 *   2. `config('services.olt_snmp.community')` <- env OLT_SNMP_COMMUNITY;
 *   3. the legacy built-in value, so behaviour stays identical until the
 *      owner sets the env. Using it logs a warning (never the value) at most
 *      once per process and once per day across processes.
 *
 * Removing the legacy value (fail-closed) is a follow-up after the env is set.
 */
final class OltSnmpCommunity
{
    private const WARN_CACHE_KEY = 'olt_snmp_community.legacy_fallback_warned';

    private static bool $warned = false;

    /**
     * Community for one OLT: DB value first, then {@see fallback()}.
     */
    public static function forOlt(object $olt): string
    {
        $dbValue = $olt->snmp_community ?? null;

        return (string) ($dbValue ?: self::fallback());
    }

    /**
     * Fallback used when the OLT row has no community.
     */
    public static function fallback(): string
    {
        $configured = trim((string) config('services.olt_snmp.community', ''));
        if ($configured !== '') {
            return $configured;
        }

        self::warnLegacyFallbackOnce();

        return (string) config('site.olt_snmp_legacy_community');
    }

    /**
     * True when the env/config value is set (no legacy fallback needed).
     */
    public static function isConfigured(): bool
    {
        return trim((string) config('services.olt_snmp.community', '')) !== '';
    }

    /** Test hook: allow the one-time warning to fire again. */
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }

    private static function warnLegacyFallbackOnce(): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;

        try {
            if (! Cache::add(self::WARN_CACHE_KEY, 1, 86400)) {
                return;
            }
        } catch (Throwable) {
            // Cache unavailable: still warn once for this process.
        }

        try {
            Log::warning('OLT SNMP community fallback: OLT_SNMP_COMMUNITY is not set; using the legacy built-in fallback. Set OLT_SNMP_COMMUNITY in .env (value is never logged).');
        } catch (Throwable) {
            // Logging must never break an SNMP read.
        }
    }
}
