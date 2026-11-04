<?php

namespace App\Services\Outage\Diagnosis;

use App\Services\Outage\OutageCauseText;
use Illuminate\Support\Carbon;

/**
 * One evidence line of a diagnosis: {layer, source, at (WIB, ISO 8601), text, fresh}. The text is short plain
 * Indonesian from OutageCauseText::EVIDENCE.
 */
final class DiagnosisEvidence
{
    public const MAX_ITEMS = 8;

    /**
     * @param  array<string,scalar|null>  $vars
     * @return array{layer:string,source:string,at:?string,text:string,fresh:bool}
     */
    public static function item(string $layer, string $source, ?Carbon $at, string $key, array $vars = [], bool $fresh = true): array
    {
        return [
            'layer' => $layer,
            'source' => $source,
            'at' => $at?->copy()->timezone('Asia/Jakarta')->toIso8601String(),
            'text' => OutageCauseText::evidence($key, $vars),
            'fresh' => $fresh,
        ];
    }

    /** "09:12" in WIB, or "-" when unknown. */
    public static function clock(?Carbon $at): string
    {
        if ($at === null) {
            return '-';
        }
        $wib = $at->copy()->timezone('Asia/Jakarta');

        // a drop from an earlier day must not read like today
        return $wib->isSameDay(now()->timezone('Asia/Jakarta')) ? $wib->format('H:i') : $wib->format('d/m H:i');
    }

    /** Optical power / limit as the panel reads it: comma, no trailing zero of a whole number ("-25,2", "-27"). */
    public static function dbm(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '-';
        }
        $text = number_format($value, $decimals, ',', '');

        if (! str_contains($text, ',')) {
            return $text;
        }
        $trimmed = rtrim(rtrim($text, '0'), ',');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** Traffic as the panel reads it: "3,2 Mbps" or "80 kbps". */
    public static function rate(int $bps): string
    {
        if ($bps >= 1_000_000) {
            return self::dbm($bps / 1_000_000).' Mbps';
        }

        return max(0, (int) round($bps / 1000)).' kbps';
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function cap(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (isset($seen[$item['text']])) {
                continue;
            }
            $seen[$item['text']] = true;
            $out[] = $item;
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }
}
