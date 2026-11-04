<?php

namespace App\Services\Recap;

use Carbon\CarbonImmutable;

/**
 * Pulls an Indonesian date expression off the END of a chat line.
 *
 * Accepted: "1-14 okt", "1 - 14 oktober", "1 okt - 3 nov", "01/10/2026 14/10/2026",
 * "01/10-14/10", "14/10", "14 okt", "hari ini", "kemarin", "minggu ini",
 * "minggu lalu", "bulan ini", "bulan lalu", "okt", "oktober 2026".
 *
 * Year omitted = current WIB year taken from the START date; if the start lands
 * in the future, the previous year. The end follows the start; the caller clips
 * it to today. Pure: all "today" logic comes from the $today argument (a WIB date).
 */
final class RecapDateRangeParser
{
    public const MONTHS = [
        'januari' => 1, 'jan' => 1,
        'februari' => 2, 'feb' => 2,
        'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agus' => 8, 'agu' => 8, 'agt' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12,
    ];

    private const SEP = '(?:-|s\/d|s\.d\.?|sd|sampai|hingga)';

    /**
     * @return array{rest: string, from: ?CarbonImmutable, to: ?CarbonImmutable, matched: bool, error: ?string}
     */
    public function extract(string $text, CarbonImmutable $today): array
    {
        $text = $this->normalize($text);
        $today = $today->startOfDay();
        $mon = $this->monthAlternation();
        $sep = self::SEP;
        $numDate = '(\d{1,2})[\/.](\d{1,2})(?:[\/.](\d{2,4}))?';

        $patterns = [
            'relative' => '(hari ini|kemarin|minggu ini|minggu lalu|bulan ini|bulan lalu)',
            'num_range' => $numDate.'\s*(?:'.$sep.')?\s*'.$numDate,
            'num_single' => $numDate,
            'dm_range' => '(\d{1,2})(?:\s*('.$mon.'))?(?:\s+(\d{4}))?\s*'.$sep.'\s*(\d{1,2})\s*('.$mon.')(?:\s+(\d{4}))?',
            'dm_single' => '(\d{1,2})\s*('.$mon.')(?:\s+(\d{4}))?',
            'month' => '('.$mon.')(?:\s+(\d{4}))?',
        ];

        foreach ($patterns as $kind => $body) {
            if (! preg_match('/(?:^|\s)('.$body.')\s*$/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $rest = trim(substr($text, 0, (int) $m[1][1]));
            $groups = [];
            foreach ($m as $i => $g) {
                if ($i >= 2) {
                    $groups[] = $g[0] === '' ? null : $g[0];
                }
            }

            // PHP drops trailing unmatched groups; pad so every index exists.
            $groups = array_pad($groups, 6, null);

            $range = match ($kind) {
                'relative' => $this->relative((string) $groups[0], $today),
                'num_range' => $this->endpoints(
                    (int) $groups[0], (int) $groups[1], $this->year($groups[2]),
                    (int) $groups[3], (int) $groups[4], $this->year($groups[5]),
                    $today,
                ),
                'num_single' => $this->endpoints(
                    (int) $groups[0], (int) $groups[1], $this->year($groups[2]),
                    (int) $groups[0], (int) $groups[1], $this->year($groups[2]),
                    $today,
                ),
                'dm_range' => $this->endpoints(
                    (int) $groups[0], $groups[1] !== null ? self::MONTHS[$groups[1]] : self::MONTHS[$groups[4]], $this->year($groups[2]),
                    (int) $groups[3], self::MONTHS[$groups[4]], $this->year($groups[5]),
                    $today,
                ),
                'dm_single' => $this->endpoints(
                    (int) $groups[0], self::MONTHS[$groups[1]], $this->year($groups[2]),
                    (int) $groups[0], self::MONTHS[$groups[1]], $this->year($groups[2]),
                    $today,
                ),
                'month' => $this->wholeMonth(self::MONTHS[$groups[0]], $this->year($groups[1]), $today),
            };

            if ($range === null) {
                return ['rest' => $rest, 'from' => null, 'to' => null, 'matched' => true, 'error' => RecapRequest::ERROR_INVALID_DATE];
            }

            return ['rest' => $rest, 'from' => $range[0], 'to' => $range[1], 'matched' => true, 'error' => null];
        }

        return ['rest' => $text, 'from' => null, 'to' => null, 'matched' => false, 'error' => null];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function monthAlternation(): string
    {
        $names = array_keys(self::MONTHS);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', $names);
    }

    private function year(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $year = (int) $raw;

        return $year < 100 ? 2000 + $year : $year;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function relative(string $word, CarbonImmutable $today): ?array
    {
        return match ($word) {
            'hari ini' => [$today, $today],
            'kemarin' => [$today->subDay(), $today->subDay()],
            'minggu ini' => [$today->startOfWeek(), $today],
            'minggu lalu' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()->startOfDay()],
            'bulan ini' => [$today->startOfMonth(), $today],
            'bulan lalu' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => null,
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function wholeMonth(int $month, ?int $year, CarbonImmutable $today): ?array
    {
        if ($year === null) {
            $year = $today->year;
            if ($today->setDate($year, $month, 1)->isAfter($today)) {
                $year--;
            }
        }
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        $first = $today->setDate($year, $month, 1)->startOfDay();

        return [$first, $first->endOfMonth()->startOfDay()];
    }

    /**
     * Resolve two (day, month, year?) endpoints into WIB dates. Missing years
     * follow the "current year, else previous year" rule, anchored on the START
     * date so "1 okt - 3 nov" stays one coherent range (end clipped to today later).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null null = invalid date
     */
    private function endpoints(int $d1, int $m1, ?int $y1, int $d2, int $m2, ?int $y2, CarbonImmutable $today): ?array
    {
        if ($y1 !== null && $y2 !== null) {
            $fromYear = $y1;
            $toYear = $y2;
        } elseif ($y2 !== null) {
            $toYear = $y2;
            $fromYear = $toYear;
            if ($m1 !== $m2 && $this->before($toYear, $m2, $d2, $fromYear, $m1, $d1)) {
                $fromYear--;
            }
        } elseif ($y1 !== null) {
            $fromYear = $y1;
            $toYear = $fromYear;
            if ($m1 !== $m2 && $this->before($toYear, $m2, $d2, $fromYear, $m1, $d1)) {
                $toYear++;
            }
        } else {
            // Anchor on the START date: current year, or the previous year when the
            // start itself is still in the future. The end follows the start (next
            // year when it is earlier in the calendar); the caller then clips the
            // end to today, so "1-31 okt" on 14 Oct 2026 = 2026-10-01..2026-10-14.
            $fromYear = $today->year;
            if ($this->before($today->year, $today->month, $today->day, $fromYear, $m1, $d1)) {
                $fromYear--;
            }
            $toYear = $fromYear;
            // Same month with a reversed day pair ("14-1 okt") stays in one year and is swapped later.
            if ($m1 !== $m2 && $this->before($toYear, $m2, $d2, $fromYear, $m1, $d1)) {
                $toYear++;
            }
        }

        if ($fromYear < 2000 || $toYear > 2100 || $fromYear > 2100 || $toYear < 2000) {
            return null;
        }
        if (! checkdate($m1, $d1, $fromYear) || ! checkdate($m2, $d2, $toYear)) {
            return null;
        }

        return [
            CarbonImmutable::create($fromYear, $m1, $d1, 0, 0, 0, $today->getTimezone()),
            CarbonImmutable::create($toYear, $m2, $d2, 0, 0, 0, $today->getTimezone()),
        ];
    }

    /** True when date A (y,m,d) is strictly before date B. */
    private function before(int $ay, int $am, int $ad, int $by, int $bm, int $bd): bool
    {
        return [$ay, $am, $ad] < [$by, $bm, $bd];
    }
}
