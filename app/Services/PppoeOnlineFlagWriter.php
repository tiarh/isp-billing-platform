<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use Illuminate\Support\Facades\Schema;

/**
 * Narrow writer for the PPPoE online flag pair:
 *   customers.is_online + customers.pppoe_online_checked_at.
 *
 * It never touches any other customer column (router_id, sync status,
 * profiles, ...), so it is safe for the monitoring scheduler mode, which is
 * otherwise read-only for customer rows.
 *
 * Offline is only ever written from a COMPLETE /ppp/active name dump of the
 * router (authoritative). A failed, timed-out, empty or partial dump must
 * never demote anyone: callers use applySeen() (promote-only) or nothing.
 */
class PppoeOnlineFlagWriter
{
    private static ?bool $hasCheckedAtColumn = null;

    /**
     * A name dump is complete enough to be authoritative when it holds at
     * least `complete_dump_ratio` of the count-only session total that was
     * read in the same connection. An empty dump is never authoritative.
     */
    public static function isCompleteDump(int $uniqueNames, int $countOnly): bool
    {
        if ($uniqueNames <= 0) {
            return false;
        }

        if ($countOnly <= 0 || $uniqueNames >= $countOnly) {
            return true;
        }

        $ratio = (float) config('services.mt_plan_c.complete_dump_ratio', 0.85);

        return $uniqueNames >= (int) floor($countOnly * $ratio);
    }

    /**
     * Authoritative: names seen => online, every other eBilling customer of
     * this router => offline; all of them get a fresh checked-at stamp.
     *
     * @param  list<string>  $activeNames
     * @return array{online:int, offline:int}
     */
    public function applyAuthoritative(Router $router, array $activeNames): array
    {
        $names = $this->normalizeNames($activeNames);
        if ($names === []) {
            // Refuse: an empty list is indistinguishable from a failed fetch.
            return ['online' => 0, 'offline' => 0];
        }

        $now = now();
        $online = Customer::query()
            ->where('router_id', $router->id)
            ->ebilling()
            ->whereIn('pppoe_user', $names)
            ->update($this->payload(true, $now));

        // Offline only for customers whose secret is known to live on this
        // router. No pppoe_user, or secret reported "missing" on this router
        // (e.g. Krian router 15: renamed/removed secrets): we cannot tell where
        // the session would be, so leave them untouched => ageing => unknown.
        $offline = Customer::query()
            ->where('router_id', $router->id)
            ->ebilling()
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '<>', '')
            ->whereNotIn('pppoe_user', $names)
            ->where(function ($query) {
                $query->whereNull('mikrotik_sync_status')
                    ->orWhere('mikrotik_sync_status', '<>', 'missing');
            })
            ->update($this->payload(false, $now));

        return ['online' => $online, 'offline' => $offline];
    }

    /**
     * Promote-only: the names were really seen in /ppp/active right now, so
     * they are fresh online evidence. Customers not in the list keep their
     * previous flag AND their previous (ageing) checked-at stamp, so the read
     * model turns them "unknown" once the stamp is stale instead of trusting it.
     *
     * @param  list<string>  $activeNames
     */
    public function applySeen(Router $router, array $activeNames): int
    {
        $names = $this->normalizeNames($activeNames);
        if ($names === []) {
            return 0;
        }

        return Customer::query()
            ->where('router_id', $router->id)
            ->ebilling()
            ->whereIn('pppoe_user', $names)
            ->update($this->payload(true, now()));
    }

    public static function hasCheckedAtColumn(): bool
    {
        return self::$hasCheckedAtColumn ??= Schema::hasTable('customers')
            && Schema::hasColumn('customers', 'pppoe_online_checked_at');
    }

    /** Test hook: schema may change between RefreshDatabase runs. */
    public static function flushSchemaCache(): void
    {
        self::$hasCheckedAtColumn = null;
    }

    /** @return array<string, mixed> */
    private function payload(bool $online, mixed $now): array
    {
        $payload = ['is_online' => $online];
        if (self::hasCheckedAtColumn()) {
            $payload['pppoe_online_checked_at'] = $now;
        }

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    private function normalizeNames(array $names): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($name): string => trim((string) $name), $names),
            static fn (string $name): bool => $name !== '',
        )));
    }
}
