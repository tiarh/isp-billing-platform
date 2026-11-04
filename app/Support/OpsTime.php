<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Operational time helper. Storage and APP_TIMEZONE stay UTC; every time shown
 * to or typed by a user is WIB (Asia/Jakarta, UTC+7, no DST).
 */
class OpsTime
{
    public const TZ = 'Asia/Jakarta';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    public static function today(?CarbonInterface $at = null): string
    {
        return self::local($at)->toDateString();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(?CarbonInterface $at = null): array
    {
        $local = self::local($at);

        return [
            $local->startOfDay()->utc(),
            $local->endOfDay()->utc(),
        ];
    }

    /** A moment expressed in WIB (null = now). */
    public static function local(?CarbonInterface $at = null): CarbonImmutable
    {
        return ($at ? CarbonImmutable::instance($at) : self::now())->timezone(self::TZ);
    }

    /** Human format in WIB with the zone label, e.g. "13/10/2026 14:30 WIB". */
    public static function format(?CarbonInterface $at, string $format = 'd/m/Y H:i'): string
    {
        if ($at === null) {
            return '-';
        }

        return self::local($at)->format($format).' WIB';
    }

    /**
     * ISO-8601 with the +07:00 offset, for payloads the browser renders.
     * Accepts a Carbon instance or a raw DB string (naive "Y-m-d H:i:s" is
     * UTC, as stored); null/empty/unparseable gives null.
     */
    public static function iso(CarbonInterface|string|null $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }
        if (is_string($at)) {
            try {
                $at = CarbonImmutable::parse($at, 'UTC');
            } catch (\Throwable) {
                return null;
            }
        }

        return self::local($at)->toIso8601String();
    }

    /**
     * Parse user input as WIB wall-clock time and return it as UTC. Input that
     * carries its own offset or "Z" keeps that instant.
     */
    public static function parseLocal(string $input): CarbonImmutable
    {
        return CarbonImmutable::parse($input, self::TZ)->utc();
    }

    /**
     * UTC [start, end] of a WIB calendar month ("Y-m"), end inclusive.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthBounds(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', self::TZ);

        return [
            $start->startOfMonth()->utc(),
            $start->endOfMonth()->utc(),
        ];
    }

    /**
     * UTC [start, end] covering WIB dates $from..$to ("Y-m-d"), end inclusive.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function rangeBounds(string $from, string $to): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, self::TZ)->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, self::TZ)->endOfDay()->utc(),
        ];
    }
}
