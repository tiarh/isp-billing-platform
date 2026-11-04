<?php

namespace App\Services\Outage;

use App\Models\Router;
use App\Models\RouterMtSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only "can this router's PPPoE view be trusted right now?" model. Extracted from OutageDetector (RCA-4) so the
 * detector (DATA_BLIND episodes) and the diagnosis correlator share ONE implementation. DB only, no device I/O.
 *
 * A router is "blind" when it is marked offline, its newest good PPPoE snapshot is stale, its health check is long
 * overdue, or its per-user traffic polling has holes (the Krian 514 case).
 */
class RouterHealthReadModel
{
    /** A router needs this many traffic rows before its poll gaps count as a blind spot. */
    public const TRAFFIC_GAP_MIN_ROWS = 10;

    /** Share of the router's customers that must be stale AND gappy. */
    public const TRAFFIC_GAP_SHARE = 0.5;

    /**
     * @return array<int,array<string,mixed>> router id => blind payload
     */
    public function blindMap(Carbon $now, ?int $onlyRouterId = null): array
    {
        try {
            $query = Router::query()->where('is_active', true);
            if ($onlyRouterId !== null) {
                $query->whereKey($onlyRouterId);
            }
            $routers = $query->get(['id', 'name', 'connection_status', 'last_health_check_at']);
            $snap = [];
            if (Schema::hasTable('router_mt_snapshots')) {
                $snapQuery = RouterMtSnapshot::query()
                    ->where('collected_at', '>=', $now->copy()->subDay())
                    ->selectRaw("router_id, MAX(collected_at) as any_at, MAX(CASE WHEN quality = 'good' THEN collected_at END) as good_at")
                    ->groupBy('router_id');
                if ($onlyRouterId !== null) {
                    $snapQuery->where('router_id', $onlyRouterId);
                }
                foreach ($snapQuery->get() as $row) {
                    $snap[(int) $row->router_id] = [
                        'good_at' => $row->good_at !== null ? Carbon::parse((string) $row->good_at) : null,
                    ];
                }
            }

            $snapshotLimit = $now->copy()->subMinutes(OutageSettings::routerBlindMinutes());
            $healthLimit = $now->copy()->subMinutes(max(120, OutageSettings::routerBlindMinutes()));
            $trafficGap = $this->trafficGapRouters($now, $onlyRouterId);
            $map = [];
            foreach ($routers as $router) {
                $id = (int) $router->id;
                $reason = null;
                if ((string) $router->connection_status === 'offline') {
                    $reason = 'router_offline';
                } elseif (isset($snap[$id]) && ($snap[$id]['good_at'] === null || $snap[$id]['good_at']->lt($snapshotLimit))) {
                    $reason = 'snapshot_stale';
                } elseif ($router->last_health_check_at !== null && $router->last_health_check_at->lt($healthLimit)) {
                    $reason = 'health_stale';
                } elseif (isset($trafficGap[$id])) {
                    $reason = 'traffic_gap';
                }
                if ($reason !== null) {
                    $map[$id] = ['kind' => 'router', 'reason' => $reason, 'router_id' => $id, 'router_name' => (string) $router->name];
                }
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning('[Outage] router blind check failed', ['exception_class' => $e::class]);

            return [];
        }
    }

    /** Blind payload of one router, or null when its data can be trusted (or the router is unknown / inactive). */
    public function blindReason(int $routerId, Carbon $now): ?array
    {
        return $this->blindMap($now, $routerId)[$routerId] ?? null;
    }

    /**
     * Routers whose per-user traffic polling has holes: at least half of their customers have a stale sample AND a
     * "gap" current row. The state comes from the CURRENT rows (quality = 'gap'), not from the cumulative daily
     * gap_count, so a customer with a few morning gaps who is good now (quality good, fresh sample) never blinds
     * the router in the afternoon, while a router whose polling stopped long ago stays blind.
     *
     * @return array<int,true>
     */
    public function trafficGapRouters(Carbon $now, ?int $onlyRouterId = null): array
    {
        if (! Schema::hasTable('customer_traffic_current')) {
            return [];
        }
        $cut = $now->copy()->subMinutes(OutageSettings::routerBlindMinutes())->format('Y-m-d H:i:s');
        $query = DB::table('customer_traffic_current')
            ->selectRaw(
                "router_id, COUNT(*) as total, SUM(CASE WHEN sampled_at IS NULL OR sampled_at < ? THEN 1 ELSE 0 END) as stale, SUM(CASE WHEN quality = 'gap' THEN 1 ELSE 0 END) as gappy",
                [$cut],
            )
            ->groupBy('router_id');
        if ($onlyRouterId !== null) {
            $query->where('router_id', $onlyRouterId);
        }
        $out = [];
        foreach ($query->get() as $row) {
            $total = (int) $row->total;
            if ($total >= self::TRAFFIC_GAP_MIN_ROWS
                && ((int) $row->stale / $total) >= self::TRAFFIC_GAP_SHARE
                && ((int) $row->gappy / $total) >= self::TRAFFIC_GAP_SHARE) {
                $out[(int) $row->router_id] = true;
            }
        }

        return $out;
    }
}
