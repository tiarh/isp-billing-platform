<?php

namespace App\Services\Monitoring;

use App\Models\Router;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\GrafanaQueryTemplates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M-4c-1: rolls Prometheus per-user byte counters (skynet_user_{rx,tx}_bytes,
 * label `user`) up into pppoe_usage_daily, one row per WIB day / router / user.
 *
 * Each run asks Prometheus for increase() over the window "00:00 WIB of the day
 * .. now" (instant query evaluated at `now`), so a 15-minute run is idempotent:
 * it overwrites today's row with the larger-or-equal cumulative value. The
 * finalize run evaluates at the next 00:00 WIB with a 24h window and sets
 * is_final. Usernames are never logged (counts only).
 */
class PppoeUsageRollup
{
    /**
     * Customer direction -> exporter counter. VERIFY against uplink totals before
     * showing numbers to customers: the default assumption is that on MikroTik
     * <pppoe-user> interfaces the interface TX is what the customer DOWNLOADS and
     * interface RX is what the customer UPLOADS. If the exporter reports the
     * opposite, flip this constant and re-run the backfill with --replace.
     */
    public const DIRECTION = ['download' => 'tx', 'upload' => 'rx'];

    public const TZ = 'Asia/Jakarta';

    public const RETENTION_MONTHS = 13;

    /** Krian (router 15) data up to and including this WIB day came from the CCR: invalid. */
    public const KRIAN_ROUTER_ID = 15;

    public const KRIAN_INVALID_THROUGH = '2026-10-10';

    /** Prometheus history starts around here; days up to this date are only partially covered. */
    public const HISTORY_START_WIB = '2026-10-03';

    public const QUALITY_OK = 'ok';

    public const QUALITY_PARTIAL = 'partial';

    public const QUALITY_INVALID = 'invalid';

    /** Self-heal finalize limits (see pendingFinalizeRouters). */
    public const MAX_SELF_HEAL_ATTEMPTS = 4;

    public const SELF_HEAL_UNTIL_HOUR = 6;

    /** Oldest day --finalize-date / --backfill-from accept. */
    public const MIN_BACKFILL_DATE = '2026-10-01';

    /** Prometheus keeps ~40 days; older [1d] windows would be empty. */
    public const PROMETHEUS_RETENTION_DAYS = 40;

    private const EDGE_ROUTER_ID = 900;

    private const QUERY_TIMEOUT = 15;

    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly PppoeCustomerMatcher $matcher,
    ) {}

    public function dayStart(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(self::TZ)->startOfDay();
    }

    /** Seconds of the WIB day elapsed at $at (capped to one full day). */
    public function windowSeconds(CarbonImmutable $dayStart, CarbonInterface $at): int
    {
        $elapsed = $at->getTimestamp() - $dayStart->getTimestamp();

        return max(0, min(86400, $elapsed));
    }

    public function qualityFor(int $routerId, CarbonImmutable $day): string
    {
        $date = $day->format('Y-m-d');
        if ($routerId === self::KRIAN_ROUTER_ID && $date <= self::KRIAN_INVALID_THROUGH) {
            return self::QUALITY_INVALID;
        }
        if ($date <= self::HISTORY_START_WIB) {
            return self::QUALITY_PARTIAL;
        }

        return self::QUALITY_OK;
    }

    /**
     * Roll up one WIB day.
     *
     * @param  bool  $final  evaluate at the end of the day and mark rows final
     * @return array{status: string, routers_ok: int, routers_failed: int, rows: int, linked: int, unlinked: int}
     *                                                                                                            status: done | failed | skipped_window | skipped_breaker | skipped_unconfigured | skipped_final
     */
    public function rollupDay(
        CarbonImmutable $day,
        CarbonInterface $now,
        bool $final = false,
        bool $dryRun = false,
        bool $replace = false,
        ?int $onlyRouter = null,
        ?array $restrictRouters = null,
        int $pauseMs = 0,
    ): array {
        $day = $day->setTimezone(self::TZ)->startOfDay();
        $stats = ['status' => 'done', 'routers_ok' => 0, 'routers_failed' => 0, 'rows' => 0, 'linked' => 0, 'unlinked' => 0];

        if (! $this->grafana->isConfigured()) {
            $stats['status'] = 'skipped_unconfigured';

            return $stats;
        }

        $dayEnd = $day->addDay();
        $at = $final ? $dayEnd : CarbonImmutable::instance($now);
        if ($at->greaterThan($dayEnd)) {
            $at = $dayEnd;
        }
        if ($final && CarbonImmutable::instance($now)->lessThan($dayEnd)) {
            $stats['status'] = 'skipped_window';

            return $stats;
        }
        $window = $this->windowSeconds($day, $at);
        if ($window < GrafanaQueryTemplates::WIN_MIN_SECONDS) {
            $stats['status'] = 'skipped_window';

            return $stats;
        }
        $win = $window.'s';

        if (! $final && ! $replace && ! $dryRun && $this->dayIsFinal($day)) {
            $stats['status'] = 'skipped_final';

            return $stats;
        }

        $routerIds = $this->routerIds($onlyRouter);
        if ($restrictRouters !== null) {
            $routerIds = array_values(array_intersect($routerIds, array_map('intval', $restrictRouters)));
        }

        foreach ($routerIds as $i => $routerId) {
            if ($this->grafana->breakerIsOpen()) {
                $stats['status'] = 'skipped_breaker';

                break;
            }
            if ($i > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }

            $users = $this->fetchRouter($routerId, $win, $at);
            if ($users === null) {
                $stats['routers_failed']++;

                continue;
            }
            $stats['routers_ok']++;

            $rows = $this->buildRows($routerId, $day, $users, $final);
            foreach ($rows as $row) {
                $row['customer_id'] === null ? $stats['unlinked']++ : $stats['linked']++;
            }
            $stats['rows'] += count($rows);

            if (! $dryRun) {
                if ($rows !== []) {
                    // The full-day [1d] value is authoritative: final runs overwrite;
                    // in-day runs keep the larger value.
                    $this->store($routerId, $day, $rows, $replace || $final);
                }
                if ($final) {
                    // Router answered: every row of the day is final, including
                    // users absent from the result (offline at 00:00).
                    DB::table('pppoe_usage_daily')
                        ->where('usage_date_wib', $day->format('Y-m-d'))
                        ->where('router_id', $routerId)
                        ->update(['is_final' => true, 'updated_at' => now()]);
                }
            }
        }

        if ($stats['status'] === 'done' && $stats['routers_ok'] === 0 && $stats['routers_failed'] > 0) {
            $stats['status'] = 'failed';
        }

        Log::info('[PppoeUsageRollup] run', [
            'date' => $day->format('Y-m-d'),
            'final' => $final,
            'dry_run' => $dryRun,
        ] + $stats);

        return $stats;
    }

    /**
     * Self-heal for a skipped/failed finalize: the routers that still have
     * non-final rows for yesterday (WIB) and are still queried. Bounded: only
     * before 06:00 WIB and at most MAX_SELF_HEAL_ATTEMPTS attempts per day
     * (afterwards use --finalize-date). Rows of routers that are no longer
     * queried (deactivated) are marked final without any query.
     *
     * @return list<int>
     */
    public function pendingFinalizeRouters(CarbonInterface $now): array
    {
        $local = CarbonImmutable::instance($now)->setTimezone(self::TZ);
        $yesterday = $local->startOfDay()->subDay()->format('Y-m-d');

        $pending = DB::table('pppoe_usage_daily')
            ->where('usage_date_wib', $yesterday)
            ->where('is_final', false)
            ->distinct()
            ->pluck('router_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($pending === []) {
            return [];
        }

        $active = $this->routerIds(null);
        $inactive = array_values(array_diff($pending, $active));
        if ($inactive !== []) {
            DB::table('pppoe_usage_daily')
                ->where('usage_date_wib', $yesterday)
                ->whereIn('router_id', $inactive)
                ->update(['is_final' => true, 'updated_at' => now()]);
        }
        $todo = array_values(array_intersect($pending, $active));
        if ($todo === [] || $local->hour >= self::SELF_HEAL_UNTIL_HOUR) {
            return [];
        }

        $key = 'pppoe_usage_rollup.self_heal.'.$yesterday;
        Cache::add($key, 0, now()->addDays(2));
        if ((int) Cache::increment($key) > self::MAX_SELF_HEAL_ATTEMPTS) {
            return [];
        }

        return $todo;
    }

    /** Delete rows older than the retention window (chunked). Returns deleted count. */
    public function purge(CarbonInterface $now, bool $dryRun = false): int
    {
        $cutoff = $this->dayStart($now)->subMonthsNoOverflow(self::RETENTION_MONTHS)->format('Y-m-d');
        $query = DB::table('pppoe_usage_daily')->where('usage_date_wib', '<', $cutoff);
        if ($dryRun) {
            return (int) $query->count();
        }

        $deleted = 0;
        do {
            $n = DB::table('pppoe_usage_daily')
                ->where('usage_date_wib', '<', $cutoff)
                ->limit(5000)
                ->delete();
            $deleted += $n;
        } while ($n > 0);

        return $deleted;
    }

    /**
     * The exporter labels dynamic PPPoE interfaces as `<pppoe-USERNAME>`. Strip
     * the angle brackets and, only for that interface form, the `pppoe-` prefix
     * (case-insensitive). Plain labels are returned trimmed but otherwise as-is.
     * A `-N` suffix is deliberately NOT stripped: usernames may legitimately end
     * in digits and the suffix cannot be told apart reliably.
     */
    public static function normalizeUserLabel(string $label): string
    {
        $label = trim($label);
        if (preg_match('/^<(.*)>$/s', $label, $m) === 1) {
            $label = trim($m[1]);
            $label = preg_replace('/^pppoe-/i', '', $label) ?? $label;
        }

        return $label;
    }

    /** @return list<int> */
    private function routerIds(?int $only): array
    {
        $query = Router::query()->where(function ($q): void {
            $q->where('is_active', true);
            if (Schema::hasColumn('routers', 'monitoring_enabled')) {
                $q->orWhere('monitoring_enabled', true);
            }
        })->where('id', '!=', self::EDGE_ROUTER_ID);

        if ($only !== null) {
            $query->where('id', $only);
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, array{name: string, download: float, upload: float}>|null keyed by lowercase user; null when either query failed
     */
    private function fetchRouter(int $routerId, string $win, CarbonInterface $at): ?array
    {
        $params = ['rid' => $routerId, 'win' => $win];
        $down = $this->grafana->queryAt('pppoe_user_'.self::DIRECTION['download'].'_increase', $params, $at, self::QUERY_TIMEOUT);
        if ($down['status'] !== 'ok') {
            return null;
        }
        $up = $this->grafana->queryAt('pppoe_user_'.self::DIRECTION['upload'].'_increase', $params, $at, self::QUERY_TIMEOUT);
        if ($up['status'] !== 'ok') {
            return null;
        }

        $users = [];
        foreach (['download' => $down, 'upload' => $up] as $dir => $result) {
            foreach ($result['series'] as $series) {
                $user = self::normalizeUserLabel((string) ($series['labels']['user'] ?? ''));
                if ($user === '' || mb_strlen($user) > 128) {
                    continue;
                }
                $value = $series['points'][0][1] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    continue;
                }
                // Merge case variants (MySQL ci unique key): keep the first-seen spelling.
                $key = mb_strtolower($user);
                $users[$key] ??= ['name' => $user, 'download' => 0.0, 'upload' => 0.0];
                $users[$key][$dir] += (float) $value;
            }
        }

        return $users;
    }

    /**
     * @param  array<string, array{name: string, download: float, upload: float}>  $users
     * @return list<array<string, mixed>>
     */
    private function buildRows(int $routerId, CarbonImmutable $day, array $users, bool $final): array
    {
        $quality = $this->qualityFor($routerId, $day);
        $date = $day->format('Y-m-d');
        $now = now();
        $rows = [];
        foreach ($users as $bytes) {
            $user = (string) $bytes['name'];
            $rows[] = [
                'usage_date_wib' => $date,
                'router_id' => $routerId,
                'pppoe_user' => $user,
                'customer_id' => $this->matcher->resolve($routerId, $user),
                'download_bytes' => (int) round($bytes['download']),
                'upload_bytes' => (int) round($bytes['upload']),
                'is_final' => $final,
                'quality' => $quality,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Idempotent upsert. Unless $replace, existing larger byte totals win: a
     * user that went offline drops out of the instant query but must keep the
     * usage already recorded earlier in the day.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(int $routerId, CarbonImmutable $day, array $rows, bool $replace): void
    {
        if (! $replace) {
            $existing = DB::table('pppoe_usage_daily')
                ->where('usage_date_wib', $day->format('Y-m-d'))
                ->where('router_id', $routerId)
                ->get(['pppoe_user', 'download_bytes', 'upload_bytes'])
                ->keyBy(fn ($r) => mb_strtolower((string) $r->pppoe_user));
            foreach ($rows as &$row) {
                $prev = $existing->get(mb_strtolower($row['pppoe_user']));
                if ($prev !== null) {
                    $row['download_bytes'] = max($row['download_bytes'], (int) $prev->download_bytes);
                    $row['upload_bytes'] = max($row['upload_bytes'], (int) $prev->upload_bytes);
                }
            }
            unset($row);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pppoe_usage_daily')->upsert(
                $chunk,
                ['usage_date_wib', 'router_id', 'pppoe_user'],
                ['customer_id', 'download_bytes', 'upload_bytes', 'is_final', 'quality', 'updated_at'],
            );
        }
    }

    private function dayIsFinal(CarbonImmutable $day): bool
    {
        return DB::table('pppoe_usage_daily')
            ->where('usage_date_wib', $day->format('Y-m-d'))
            ->where('is_final', true)
            ->exists();
    }
}
