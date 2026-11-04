<?php

namespace App\Services\Recap;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * "#rekap migrasi <area|semua> [tanggal] [excel]". Anchored whole-message parser: the char after
 * "#" is "r", so it matches none of the PSB / migration triggers ("#migrasi ..." stays the
 * migration bot). Same date grammar and range cap as "#rekap psb" (setting psb_recap_cmd_max_days).
 */
class MigrasiRecapCommandParser extends RecapCommandParser
{
    public function __construct()
    {
        parent::__construct('migrasi');
    }

    public function parse(string $text, ?CarbonImmutable $now = null, ?int $maxDays = null): ?RecapRequest
    {
        if (! $this->matches($text)) {
            return null;
        }

        return parent::parse($text, $now, $maxDays ?? (int) Setting::get('psb_recap_cmd_max_days', self::DEFAULT_MAX_DAYS));
    }
}
