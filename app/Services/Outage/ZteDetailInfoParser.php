<?php

namespace App\Services\Outage;

use Illuminate\Support\Carbon;

/**
 * Pure parser for the ZTE C300 `show gpon onu detail-info gpon-onu_<ref>` output (RCA-2). No I/O.
 *
 * Header fields (Phase state, Online Duration, ...) plus the 10-row history table
 * `Authpass Time / OfflineTime / Cause` (newest last, unused rows are 0000-00-00 and ignored).
 * Times on the OLT are local wall clock (config outage.olt_clock_timezone, default UTC); results are the OLT clock expressed in UTC (the caller subtracts the clock drift).
 */
class ZteDetailInfoParser
{
    public const FLAP_WINDOW_MINUTES = 30;

    public const FLAP_MIN_DROPS = 3;

    /** A flap burst only labels the ONU while its last drop is this recent. */
    public const FLAP_RECENT_HOURS = 6;

    /**
     * @return array{
     *   phase: ?string, online_duration_seconds: ?int,
     *   history: list<array{online_at: ?Carbon, offline_at: ?Carbon, cause: ?string}>,
     *   last_offline_at: ?Carbon, last_cause: ?string, cause: ?string
     * }
     */
    public static function parse(string $output): array
    {
        $output = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $output) ?? $output;
        $output = str_replace("\r", '', $output);

        $phase = null;
        $duration = null;
        $history = [];
        $tz = (string) config('outage.olt_clock_timezone', 'UTC');

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*Phase state\s*:\s*(\S.*?)\s*$/i', $line, $m) === 1) {
                $phase = $m[1];

                continue;
            }
            if (preg_match('/^\s*Online Duration\s*:\s*(\S.*?)\s*$/i', $line, $m) === 1) {
                $duration = self::duration($m[1]);

                continue;
            }
            if (preg_match('/^\s*(\d{1,2})\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+(\S.*?))?\s*$/', $line, $m) === 1) {
                $online = self::time($m[2], $tz);
                $offline = self::time($m[3], $tz);
                if ($online === null && $offline === null) {
                    continue; // unused row
                }
                $history[] = ['online_at' => $online, 'offline_at' => $offline, 'cause' => isset($m[4]) && $m[4] !== '' ? $m[4] : null];
            }
        }

        $lastOffline = null;
        $lastCause = null;
        foreach ($history as $row) {
            if ($row['offline_at'] !== null) {
                $lastOffline = $row['offline_at'];
                $lastCause = $row['cause'];
            }
        }

        return [
            'phase' => $phase,
            'online_duration_seconds' => $duration,
            'history' => $history,
            'last_offline_at' => $lastOffline,
            'last_cause' => $lastCause,
            'cause' => self::causeCode($phase, $lastCause),
        ];
    }

    /**
     * Cause code of the CURRENT drop: an offline phase (LOS / DyingGasp) wins, otherwise the cause of the newest
     * history row. los | dying_gasp | other | null.
     */
    public static function causeCode(?string $phase, ?string $lastCause): ?string
    {
        $p = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) $phase));
        if ($p === 'los') {
            return 'los';
        }
        if (in_array($p, ['dyinggasp', 'dgi'], true)) {
            return 'dying_gasp';
        }
        if ($p === 'working') {
            return null; // online: no current drop
        }
        $c = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) $lastCause));
        if ($c === '') {
            return null;
        }
        if ($c === 'los') {
            return 'los';
        }
        if (in_array($c, ['dyinggasp', 'dgi'], true)) {
            return 'dying_gasp';
        }

        return 'other';
    }

    /**
     * RCA-4 history: every drop of the 10-row history since $since (real UTC), oldest first. The OLT clock drift is
     * removed BEFORE the filter (the OLT RA clock is ~45 minutes off). Each cause is los | dying_gasp | other | null.
     *
     * @param  list<array{online_at: ?Carbon, offline_at: ?Carbon, cause: ?string}>  $history
     * @return list<array{at: Carbon, cause: ?string}>
     */
    public static function drops(array $history, Carbon $since, ?int $drift = null): array
    {
        $out = [];
        foreach ($history as $row) {
            if ($row['offline_at'] === null) {
                continue;
            }
            $at = $row['offline_at']->copy()->subSeconds($drift ?? 0)->utc();
            if ($at->lt($since)) {
                continue;
            }
            $out[] = ['at' => $at, 'cause' => self::rowCause($row['cause'])];
        }
        usort($out, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $out;
    }

    /** Cause of ONE history row (not the phase): los | dying_gasp | other | null. */
    public static function rowCause(?string $text): ?string
    {
        return self::causeCode('', $text);
    }

    /**
     * Flapping = at least 3 drops inside 30 minutes, with the last one in the last 6 hours.
     *
     * @param  list<array{online_at: ?Carbon, offline_at: ?Carbon, cause: ?string}>  $history
     */
    public static function isFlapping(array $history, Carbon $now): bool
    {
        $drops = [];
        foreach ($history as $row) {
            if ($row['offline_at'] !== null) {
                $drops[] = $row['offline_at']->copy();
            }
        }
        usort($drops, fn (Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $n = count($drops);
        $window = self::FLAP_WINDOW_MINUTES * 60;
        for ($i = 0; $i + self::FLAP_MIN_DROPS - 1 < $n; $i++) {
            $last = $drops[$i + self::FLAP_MIN_DROPS - 1];
            if ($last->getTimestamp() - $drops[$i]->getTimestamp() <= $window
                && $last->getTimestamp() >= $now->copy()->subHours(self::FLAP_RECENT_HOURS)->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /** "697h 44m 36s" => seconds. */
    public static function duration(string $text): ?int
    {
        if (preg_match('/^\s*(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?\s*(?:(\d+)\s*s)?\s*$/i', $text, $m) !== 1) {
            return null;
        }
        if (($m[1] ?? '') === '' && ($m[2] ?? '') === '' && ($m[3] ?? '') === '') {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
    }

    private static function time(string $text, string $tz): ?Carbon
    {
        if (str_starts_with($text, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse($text, $tz)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
