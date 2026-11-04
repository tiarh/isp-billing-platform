<?php

namespace App\Services\Recap;

use Carbon\CarbonImmutable;

/**
 * Parsed "#rekap <topic> <area|semua> [tanggal]" command (generic, reusable for
 * later #rekap migrasi / onu / ... recaps). Dates are WIB calendar dates.
 */
final class RecapRequest
{
    public const ERROR_INVALID_DATE = 'invalid_date';

    public const ERROR_RANGE_TOO_LONG = 'range_too_long';

    public const ERROR_MISSING_AREA = 'missing_area';

    public function __construct(
        public readonly string $topic,
        public readonly string $areaQuery,
        public readonly bool $allAreas,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly bool $explicitDates,
        public readonly bool $excelAlias,
        public readonly int $maxDays,
        public readonly ?string $error = null,
        /** Snapshot topics (#rekap onu): a typed date was given but is not used. */
        public readonly bool $dateIgnored = false,
    ) {}

    /** Inclusive number of days in the range. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }
}
