<?php

namespace App\Services\Grafana;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suggests routers.grafana_identity for active eBilling routers. Read-only:
 * the admin confirms by saving on Monitoring > Pengaturan.
 */
class RouterIdentitySuggester
{
    /** Grafana router_id of the Jakarta edge; not an eBilling router. */
    private const EDGE_ROUTER_ID = '900';

    public function __construct(private readonly GrafanaClient $grafana) {}

    /**
     * @return list<array{router_id: int, router_name: string, current: ?string, suggested: ?string, source: ?string, in_mktxp: bool}>
     */
    public function suggest(): array
    {
        if (! Schema::hasTable('routers') || ! Schema::hasColumn('routers', 'grafana_identity')) {
            return [];
        }

        $routers = DB::table('routers')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'grafana_identity']);

        // Source 1: skynet_scrape_up carries both router_id (= routers.id) and router.
        $byId = [];
        $scrape = $this->grafana->query('collector_scrape_up');
        if (in_array($scrape['status'], ['ok', 'stale'], true)) {
            foreach ($scrape['series'] as $series) {
                $rid = (string) ($series['labels']['router_id'] ?? '');
                $label = trim((string) ($series['labels']['router'] ?? ''));
                if ($rid === '' || $rid === self::EDGE_ROUTER_ID || $label === '') {
                    continue;
                }
                $byId[$rid] = $label;
            }
        }

        // Source 2: case-insensitive match against mktxp routerboard_name.
        $mktxp = $this->grafana->labelValues('routerboard_name');
        $byName = [];
        foreach ($mktxp['values'] as $value) {
            $byName[mb_strtolower($value)] = $value;
        }

        $rows = [];
        foreach ($routers as $router) {
            $suggested = null;
            $source = null;
            if (isset($byId[(string) $router->id])) {
                $suggested = $byId[(string) $router->id];
                $source = 'router_id_label';
            } elseif (isset($byName[mb_strtolower(trim((string) $router->name))])) {
                $suggested = $byName[mb_strtolower(trim((string) $router->name))];
                $source = 'name_match';
            }

            $rows[] = [
                'router_id' => (int) $router->id,
                'router_name' => (string) $router->name,
                'current' => $router->grafana_identity,
                'suggested' => $suggested,
                'source' => $source,
                'in_mktxp' => $suggested !== null && isset($byName[mb_strtolower($suggested)]),
            ];
        }

        return $rows;
    }
}
