<?php

namespace App\Services\Recap;

use App\Support\OpsTime;
use Carbon\CarbonImmutable;

/**
 * Generic "#rekap <topic> <area|semua> [tanggal] [excel]" parser. Text only,
 * one line, anchored at the start of the message. A topic subclass (psb now,
 * migrasi / onu later) only supplies the topic word.
 *
 * Pure (no DB): "now" and the max range are arguments.
 */
class RecapCommandParser
{
    public const DEFAULT_MAX_DAYS = 92;

    public function __construct(protected string $topic) {}

    public function topic(): string
    {
        return $this->topic;
    }

    /** True when the whole message is a "#rekap <topic> ..." command (single line). */
    public function matches(string $text): bool
    {
        $t = $this->normalize($text);
        if ($t === '' || preg_match('/[\r\n]/', $t)) {
            return false;
        }

        return (bool) preg_match('/^#\s*rekap\s+'.preg_quote($this->topic, '/').'(?:\s+.*)?$/iu', $t);
    }

    public function parse(string $text, ?CarbonImmutable $now = null, ?int $maxDays = null): ?RecapRequest
    {
        if (! $this->matches($text)) {
            return null;
        }

        $maxDays = max(1, $maxDays ?? self::DEFAULT_MAX_DAYS);
        $today = ($now ?? OpsTime::now())->setTimezone(OpsTime::TZ)->startOfDay();

        $t = $this->normalize($text);
        $body = (string) preg_replace('/^#\s*rekap\s+'.preg_quote($this->topic, '/').'\b/iu', '', $t);
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));

        // "excel" / "xlsx" are accepted aliases (the Excel is always sent anyway).
        $excelAlias = false;
        if (preg_match('/(?:^|\s)(?:excel|xlsx)$/iu', $body)) {
            $excelAlias = true;
            $body = trim((string) preg_replace('/(?:^|\s)(?:excel|xlsx)$/iu', '', $body));
        }

        $extracted = (new RecapDateRangeParser)->extract($body, $today);
        $area = trim($extracted['rest']);
        $allAreas = (bool) preg_match('/^(?:semua|all)$/iu', $area);

        $from = $today->startOfMonth();
        $to = $today;
        $explicit = $extracted['matched'];
        $error = $extracted['error'];

        if ($extracted['from'] !== null && $extracted['to'] !== null) {
            $from = $extracted['from'];
            $to = $extracted['to'];
            if ($to->isAfter($today)) {
                $to = $today;
            }
            if ($from->isAfter($to)) {
                [$from, $to] = [$to, $from];
            }
        }

        // A bare trailing year ("#rekap psb klampok 2026") is not a date expression
        // on its own: reject with the date usage reply instead of ignoring it.
        if ($error === null && ! $extracted['matched'] && preg_match('/(?:^|\s)(?:19|20)\d{2}$/u', $area)) {
            $error = RecapRequest::ERROR_INVALID_DATE;
        }

        if ($error === null && (int) $from->diffInDays($to) + 1 > $maxDays) {
            $error = RecapRequest::ERROR_RANGE_TOO_LONG;
        }
        if ($error === null && $area === '') {
            $error = RecapRequest::ERROR_MISSING_AREA;
        }

        return new RecapRequest(
            topic: $this->topic,
            areaQuery: $area,
            allAreas: $allAreas,
            from: $from,
            to: $to,
            explicitDates: $explicit,
            excelAlias: $excelAlias,
            maxDays: $maxDays,
            error: $error,
        );
    }

    protected function normalize(string $text): string
    {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[\p{Cf}\x{FEFF}]/u', '', $text) ?? $text;

        return trim($text);
    }
}
