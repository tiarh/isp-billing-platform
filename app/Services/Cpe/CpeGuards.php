<?php

namespace App\Services\Cpe;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\Outage\OutageDetector;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * RCA-3: PSB / migration stay-away check for the watch and summon services. Read-only on psb_drafts and
 * customer_migrations. A customer whose PSB draft or migration changed in the last 30 minutes is being (or is
 * about to be) provisioned: no task is created for that ONU.
 */
class CpeGuards
{
    public const WINDOW_MINUTES = 30;

    /** @return string|null 'psb' | 'migration' when the device must be left alone */
    public static function busyReason(?int $customerId, ?string $pppoeUser = null, ?CarbonInterface $now = null): ?string
    {
        if ($customerId === null && ($pppoeUser === null || trim($pppoeUser) === '')) {
            return null;
        }
        $since = ($now ? Carbon::instance($now) : Carbon::now())->subMinutes(self::WINDOW_MINUTES);

        try {
            $psb = PsbDraft::query()->whereIn('status', OutageDetector::PSB_OPEN_STATUSES)->where('updated_at', '>=', $since)
                ->where(function ($q) use ($customerId, $pppoeUser): void {
                    if ($customerId !== null) {
                        $q->where('customer_id', $customerId);
                    }
                    if ($pppoeUser !== null && trim($pppoeUser) !== '') {
                        $q->orWhere('pppoe_user', $pppoeUser);
                    }
                })->exists();
            if ($psb) {
                return 'psb';
            }
            if ($customerId !== null
                && CustomerMigration::query()->open()->where('customer_id', $customerId)->where('updated_at', '>=', $since)->exists()) {
                return 'migration';
            }
        } catch (\Throwable) {
            return 'unknown'; // cannot prove it is safe: stay away
        }

        return null;
    }
}
