<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Recap\RecapCommandParser;
use App\Services\Recap\RecapRequest;
use Carbon\CarbonImmutable;

/**
 * "#rekap psb <area|semua> [tanggal]". Standalone on purpose: it matches none of
 * the PSB / migration triggers (the char after "#" is "r"), and the webhook only
 * routes here when both of those parsers say "not mine".
 */
class PsbRecapCommandParser extends RecapCommandParser
{
    public function __construct()
    {
        parent::__construct('psb');
    }

    /**
     * Max range comes from Setting psb_recap_cmd_max_days (default 92) unless given.
     */
    public function parse(string $text, ?CarbonImmutable $now = null, ?int $maxDays = null): ?RecapRequest
    {
        if (! $this->matches($text)) {
            return null;
        }

        return parent::parse($text, $now, $maxDays ?? (int) Setting::get('psb_recap_cmd_max_days', self::DEFAULT_MAX_DAYS));
    }
}
