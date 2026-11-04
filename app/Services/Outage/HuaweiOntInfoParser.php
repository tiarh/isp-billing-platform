<?php

namespace App\Services\Outage;

use Illuminate\Support\Carbon;

/**
 * Pure parser for the Huawei MA5608T `display ont info <F> <S> <P> <ID>` output (RCA-2). No I/O.
 *
 * Only the FIRST block (up to "Power reduction status") is read; the long profile section after it is ignored.
 * Times carry their own offset ("2026-10-17 09:53:17+08:00") and are returned as UTC.
 */
class HuaweiOntInfoParser
{
    /** ONT online duration this much shorter than the system up duration => the link dropped without a reboot. */
    public const REBOOT_TOLERANCE_SECONDS = 180;

    /**
     * @return array{
     *   run_state: ?string, config_state: ?string, match_state: ?string, last_down_cause: ?string,
     *   last_up_at: ?Carbon, last_down_at: ?Carbon, last_dying_gasp_at: ?Carbon,
     *   online_seconds: ?int, system_up_seconds: ?int,
     *   never_registered: bool, rebooted: ?bool, cause: ?string
     * }
     */
    public static function parse(string $output): array
    {
        $output = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $output) ?? $output;
        $output = str_replace("\r", '', $output);
        $cut = stripos($output, 'Power reduction status');
        if ($cut !== false) {
            $output = substr($output, 0, $cut);
        }

        $f = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*([A-Za-z][A-Za-z \-\/]*?)\s*:\s*(.*?)\s*$/', $line, $m) === 1) {
                $key = strtolower($m[1]);
                if (! array_key_exists($key, $f)) {
                    $f[$key] = $m[2]; // first occurrence only
                }
            }
        }

        $run = self::val($f['run state'] ?? null);
        $config = self::val($f['config state'] ?? null);
        $match = self::val($f['match state'] ?? null);
        $cause = self::val($f['last down cause'] ?? null);
        $up = self::time($f['last up time'] ?? null);
        $down = self::time($f['last down time'] ?? null);
        $dg = self::time($f['last dying gasp time'] ?? null);
        $online = self::duration($f['ont online duration'] ?? null);
        $sysUp = self::duration($f['ont system up duration'] ?? null);

        $never = strtolower((string) $run) === 'offline'
            && strtolower((string) $config) === 'initial'
            && strtolower((string) $match) === 'initial'
            && $cause === null && $up === null && $down === null && $dg === null;

        $rebooted = ($online !== null && $sysUp !== null) ? ($sysUp - $online) <= self::REBOOT_TOLERANCE_SECONDS : null;

        return [
            'run_state' => $run,
            'config_state' => $config,
            'match_state' => $match,
            'last_down_cause' => $cause,
            'last_up_at' => $up,
            'last_down_at' => $down,
            'last_dying_gasp_at' => $dg,
            'online_seconds' => $online,
            'system_up_seconds' => $sysUp,
            'never_registered' => $never,
            'rebooted' => $rebooted,
            'cause' => self::causeCode($never, $cause, $rebooted, $run),
        ];
    }

    /**
     * never_registered | dying_gasp | los | other | onu_reboot | link_drop | null.
     * An explicit last down cause wins; without one an online ONT is labelled by reboot vs link drop.
     */
    public static function causeCode(bool $never, ?string $cause, ?bool $rebooted, ?string $run): ?string
    {
        if ($never) {
            return 'never_registered';
        }
        $c = strtolower((string) $cause);
        if ($c !== '') {
            if (str_contains($c, 'dying')) {
                return 'dying_gasp';
            }
            if (str_starts_with($c, 'los') || str_starts_with($c, 'lof')) {
                return 'los';
            }

            return 'other';
        }
        if (strtolower((string) $run) === 'online' && $rebooted !== null) {
            return null; // no recorded drop; reboot vs link drop only matters next to a down time
        }

        return null;
    }

    private static function val(?string $v): ?string
    {
        $v = trim((string) $v);

        return ($v === '' || $v === '-') ? null : $v;
    }

    private static function time(?string $v): ?Carbon
    {
        $v = self::val($v);
        if ($v === null) {
            return null;
        }
        try {
            return Carbon::parse($v)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** "5 day(s), 3 hour(s), 2 minute(s), 10 second(s)" => seconds. */
    public static function duration(?string $v): ?int
    {
        $v = self::val($v);
        if ($v === null) {
            return null;
        }
        $total = 0;
        $any = false;
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1] as $unit => $mult) {
            if (preg_match('/(\d+)\s*'.$unit.'/i', $v, $m) === 1) {
                $total += (int) $m[1] * $mult;
                $any = true;
            }
        }

        return $any ? $total : null;
    }
}
