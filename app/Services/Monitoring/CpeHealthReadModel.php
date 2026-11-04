<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate CPE health for the Monitoring "Jaringan" tab (M-4d).
 *
 * Sources: genieacs_devices.last_inform_at (online %), customers.onu_rx_power_dbm
 * (optical power buckets, online and not isolated customers) and the customer's
 * area for the offline ranking. Aggregates only: no customer names or PPPoE
 * usernames ever leave this class.
 */
class CpeHealthReadModel
{
    public const CACHE_KEY = 'monitoring.widgets.cpe';

    public const CACHE_TTL_SECONDS = 120;

    /** Data older than this is reported as stale. */
    public const STALE_AFTER_SECONDS = 7200;

    /** RX power >= this (dBm) is "good". */
    public const RX_GOOD_MIN = -25.0;

    /** RX power <= this (dBm) is "poor"; anything between is "fair". */
    public const RX_POOR_MAX = -27.0;

    public const TOP_AREAS = 5;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => $this->build());
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = now();
        $onlineSince = $now->copy()->subMinutes(max(1, (int) config('services.genieacs.online_minutes', 30)));

        // Same population as GenieAcsService::summary(): devices GenieACS no longer reports are excluded.
        $totals = DB::table('genieacs_devices')
            ->where(function ($query): void {
                $query->whereNull('snapshot_status')->orWhere('snapshot_status', 'active');
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN last_inform_at >= ? THEN 1 ELSE 0 END) as online', [$onlineSince])
            ->selectRaw('MAX(last_inform_at) as last_inform_at')
            ->selectRaw('MAX(last_seen_sync_at) as last_seen_sync_at')
            ->first();

        $total = (int) ($totals->total ?? 0);
        $online = (int) ($totals->online ?? 0);
        $lastInform = $totals->last_inform_at ? Carbon::parse($totals->last_inform_at) : null;
        // Freshness is the last GenieACS sync, not the last device inform.
        $lastSync = $totals->last_seen_sync_at ? Carbon::parse($totals->last_seen_sync_at) : null;

        $status = 'ok';
        $reason = null;
        if ($total === 0) {
            $status = 'setup_required';
            $reason = 'Belum ada perangkat CPE yang tersinkron dari GenieACS.';
        } elseif ($lastSync === null || $lastSync->lt($now->copy()->subSeconds(self::STALE_AFTER_SECONDS))) {
            $status = 'stale';
            $reason = 'Sinkronisasi GenieACS belum diperbarui lebih dari 2 jam.';
        }

        return [
            'widget' => 'cpe',
            'range' => null,
            'status' => $status,
            'fetched_at' => $now->toIso8601String(),
            'reason' => $reason,
            'partial' => $status === 'stale',
            'step_seconds' => null,
            'grafana_dashboard_url' => null,
            'data' => [
                'devices' => [
                    'total' => $total,
                    'online' => $online,
                    'offline' => $total - $online,
                    'online_pct' => $total > 0 ? round($online / $total * 100, 1) : null,
                    'last_inform_at' => $lastInform?->toIso8601String(),
                    'last_sync_at' => $lastSync?->toIso8601String(),
                ],
                'rx_power' => $this->rxBuckets(),
                'top_offline_areas' => $this->topOfflineAreas($onlineSince),
            ],
        ];
    }

    /**
     * @return array{measured: int, good: int, fair: int, poor: int}
     */
    private function rxBuckets(): array
    {
        $row = DB::table('customers')
            ->whereNull('deleted_at')
            ->where('is_online', true)
            ->where('status', 'active')
            ->whereNotNull('onu_rx_power_dbm')
            ->selectRaw('COUNT(*) as measured')
            ->selectRaw('SUM(CASE WHEN onu_rx_power_dbm >= ? THEN 1 ELSE 0 END) as good', [self::RX_GOOD_MIN])
            ->selectRaw('SUM(CASE WHEN onu_rx_power_dbm <= ? THEN 1 ELSE 0 END) as poor', [self::RX_POOR_MAX])
            ->first();

        $measured = (int) ($row->measured ?? 0);
        $good = (int) ($row->good ?? 0);
        $poor = (int) ($row->poor ?? 0);

        return ['measured' => $measured, 'good' => $good, 'fair' => $measured - $good - $poor, 'poor' => $poor];
    }

    /**
     * @return list<array{area: string, offline: int, total: int}>
     */
    private function topOfflineAreas(Carbon $onlineSince): array
    {
        $rows = DB::table('genieacs_devices as d')
            ->join('customers as c', 'c.id', '=', 'd.customer_id')
            ->join('areas as a', 'a.id', '=', 'c.area_id')
            ->whereNull('c.deleted_at')
            ->where('c.status', 'active')
            ->where(function ($query): void {
                $query->whereNull('d.snapshot_status')->orWhere('d.snapshot_status', 'active');
            })
            ->groupBy('a.id', 'a.name')
            ->selectRaw('a.name as area, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN d.last_inform_at IS NULL OR d.last_inform_at < ? THEN 1 ELSE 0 END) as offline', [$onlineSince])
            ->havingRaw('SUM(CASE WHEN d.last_inform_at IS NULL OR d.last_inform_at < ? THEN 1 ELSE 0 END) > 0', [$onlineSince])
            ->orderByDesc('offline')
            ->orderBy('a.name')
            ->limit(self::TOP_AREAS)
            ->get();

        return $rows->map(static fn ($r): array => [
            'area' => (string) $r->area,
            'offline' => (int) $r->offline,
            'total' => (int) $r->total,
        ])->all();
    }
}
