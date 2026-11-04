<?php

namespace App\Services\Grafana;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB side of the Grafana widgets: which eBilling routers are in scope and
 * which Jakarta upstream links are configured. Tables owned by the mapping
 * work (monitoring_upstream_links, routers.grafana_identity) may not exist
 * yet, so every access is guarded and uses the query builder.
 */
class MonitoringTopology
{
    /**
     * Active routers (the router set for every widget).
     *
     * @return list<array{id: int, name: string, grafana_identity: ?string, current_online_count: int, total_pppoe_count: int}>
     */
    public function activeRouters(): array
    {
        $columns = ['id', 'name', 'current_online_count', 'total_pppoe_count'];
        $hasIdentity = Schema::hasColumn('routers', 'grafana_identity');
        if ($hasIdentity) {
            $columns[] = 'grafana_identity';
        }

        return DB::table('routers')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get($columns)
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'grafana_identity' => $hasIdentity && $r->grafana_identity !== null && $r->grafana_identity !== '' ? (string) $r->grafana_identity : null,
                'current_online_count' => (int) ($r->current_online_count ?? 0),
                'total_pppoe_count' => (int) ($r->total_pppoe_count ?? 0),
            ])
            ->all();
    }

    /**
     * Active upstream links ordered by sort, or null when the table is missing.
     *
     * @return list<array{upstream_key: string, display_name: string, kind: string, capacity_mbps: ?float}>|null
     */
    public function upstreamLinks(): ?array
    {
        if (! Schema::hasTable('monitoring_upstream_links')) {
            return null;
        }

        return DB::table('monitoring_upstream_links')
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['upstream_key', 'display_name', 'kind', 'capacity_mbps'])
            ->map(static fn ($l): array => [
                'upstream_key' => (string) $l->upstream_key,
                'display_name' => (string) $l->display_name,
                'kind' => (string) $l->kind,
                'capacity_mbps' => $l->capacity_mbps !== null && (float) $l->capacity_mbps > 0 ? (float) $l->capacity_mbps : null,
            ])
            ->all();
    }
}
