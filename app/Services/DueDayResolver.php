<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolves the billing due_day for a NEWLY created customer.
 *
 * Owner decision 2026-10-09: new customers whose router is in
 * `billing_fixed_due_day_router_ids` (default "513" = SKYNET-RA-OLT) OR whose
 * area is in `billing_fixed_due_day_area_ids` (default "14" =
 * STARLITE-AKUISISI) get the fixed `billing_fixed_due_day` (default 25).
 * Everyone else keeps the historical rule: due_day = join-date day.
 *
 * Only used when a customer is created without an explicit due_day; it never
 * rewrites an existing customer's due_day.
 */
class DueDayResolver
{
    public const DEFAULT_FIXED_DAY = 25;

    public const DEFAULT_ROUTER_IDS = '513';

    public const DEFAULT_AREA_IDS = '14';

    /**
     * Fixed due day for this router/area, or null when the customer is not
     * covered by the fixed-day rule.
     */
    public function fixedDueDayFor(?int $routerId, ?int $areaId): ?int
    {
        $day = (int) Setting::get('billing_fixed_due_day', self::DEFAULT_FIXED_DAY);
        if ($day < 1 || $day > 31) {
            return null;
        }

        $routerIds = self::parseIds(Setting::get('billing_fixed_due_day_router_ids', self::DEFAULT_ROUTER_IDS));
        $areaIds = self::parseIds(Setting::get('billing_fixed_due_day_area_ids', self::DEFAULT_AREA_IDS));

        if (($routerId && in_array($routerId, $routerIds, true))
            || ($areaId && in_array($areaId, $areaIds, true))) {
            return $day;
        }

        return null;
    }

    /**
     * due_day for a new customer: fixed day when the rule applies, otherwise
     * the join-date day (today WIB when no join date is known).
     */
    public function resolve(?int $routerId, ?int $areaId, CarbonInterface|string|null $joinDate = null): int
    {
        $fixed = $this->fixedDueDayFor($routerId, $areaId);
        if ($fixed !== null) {
            return $fixed;
        }
        if (is_string($joinDate) && $joinDate !== '') {
            // Date-only strings (e.g. OpsTime::today()) are WIB calendar dates.
            return (int) CarbonImmutable::parse($joinDate, OpsTime::TZ)->day;
        }

        return $joinDate instanceof CarbonInterface ? (int) $joinDate->day : (int) OpsTime::now()->day;
    }

    /**
     * "513, 600" / [513] / 513 -> [513, 600]. Non-numeric tokens are ignored.
     *
     * @return list<int>
     */
    public static function parseIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $tokens = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);

        $ids = [];
        foreach ($tokens ?: [] as $token) {
            $token = trim((string) $token);
            if ($token !== '' && ctype_digit($token) && (int) $token > 0) {
                $ids[] = (int) $token;
            }
        }

        return array_values(array_unique($ids));
    }
}
