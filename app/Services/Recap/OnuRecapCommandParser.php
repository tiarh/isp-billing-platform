<?php

namespace App\Services\Recap;

use App\Support\OpsTime;
use Carbon\CarbonImmutable;

/**
 * "#rekap onu <area|semua> [excel]": a snapshot of the current ONU health. A typed date
 * is accepted but ignored (RecapRequest::$dateIgnored) so the reply can say so.
 */
class OnuRecapCommandParser extends RecapCommandParser
{
    public function __construct()
    {
        parent::__construct('onu');
    }

    public function parse(string $text, ?CarbonImmutable $now = null, ?int $maxDays = null): ?RecapRequest
    {
        $request = parent::parse($text, $now, $maxDays);
        if ($request === null) {
            return null;
        }

        $error = $request->error;
        // A snapshot has no range: a too long range is irrelevant, an unusable date stays an error.
        if ($error === RecapRequest::ERROR_RANGE_TOO_LONG) {
            $error = $request->areaQuery === '' ? RecapRequest::ERROR_MISSING_AREA : null;
        }

        $today = ($now ?? OpsTime::now())->setTimezone(OpsTime::TZ)->startOfDay();

        return new RecapRequest(
            topic: $request->topic,
            areaQuery: $request->areaQuery,
            allAreas: $request->allAreas,
            from: $today,
            to: $today,
            explicitDates: $request->explicitDates,
            excelAlias: $request->excelAlias,
            maxDays: $request->maxDays,
            error: $error,
            dateIgnored: $request->explicitDates && $error === null,
        );
    }
}
