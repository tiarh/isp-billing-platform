<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Grafana\GrafanaQueryTemplates;
use App\Services\Monitoring\PppoeUsageRollup;
use App\Services\Monitoring\RouterPppoeReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M-4c-2a: router detail JSON API (global admin only). Only whitelisted
 * parameters are read; PromQL never comes from the browser and Grafana error
 * text is never echoed (reason strings come from the client layer).
 */
class MonitoringRouterController extends Controller
{
    private const EDGE_ROUTER_ID = 900;

    /** M-4c-2b: the page itself. Tiny props; all data comes from the three API endpoints. */
    public function show(Router $router, RouterPppoeReadModel $model): Response
    {
        $this->guardRouter($router);

        return Inertia::render('Monitoring/RouterDetail', [
            'router' => [
                'id' => (int) $router->id,
                'name' => (string) $router->name,
                'is_active' => (bool) $router->is_active,
                'grafana_identity' => $router->getAttribute('grafana_identity'),
            ],
            'grafanaUrl' => $model->grafanaUrl($router, '1h'),
            'historyStart' => PppoeUsageRollup::HISTORY_START_WIB,
            'krianInvalidThrough' => (int) $router->id === PppoeUsageRollup::KRIAN_ROUTER_ID
                ? PppoeUsageRollup::KRIAN_INVALID_THROUGH
                : null,
        ]);
    }

    public function overview(Request $request, Router $router, RouterPppoeReadModel $model): JsonResponse
    {
        $this->guardRouter($router);
        $data = $request->validate([
            'range' => ['sometimes', 'string', Rule::in(array_keys(GrafanaQueryTemplates::RANGES))],
        ]);
        $range = $data['range'] ?? '1h';

        return response()->json(['range' => $range] + $model->overview($router, $range));
    }

    public function pppoe(Request $request, Router $router, RouterPppoeReadModel $model): JsonResponse
    {
        $this->guardRouter($router);
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(RouterPppoeReadModel::STATUSES)],
            'sort' => ['sometimes', 'string', Rule::in(RouterPppoeReadModel::SORTS)],
            'dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $table = $model->table($router, $data);

        return response()->json([
            'status' => $table['status'],
            'fetched_at' => $table['fetched_at'],
            'reason' => $table['reason'],
            'partial' => $table['partial'],
            'grafana_dashboard_url' => $model->grafanaUrl($router, '1h'),
            'history_start' => PppoeUsageRollup::HISTORY_START_WIB,
            'data' => ['rows' => $table['rows'], 'meta' => $table['meta']],
        ]);
    }

    public function pppoeDaily(Request $request, Router $router, RouterPppoeReadModel $model): JsonResponse
    {
        $this->guardRouter($router);
        $data = $request->validate([
            'user' => ['required', 'string', 'min:1', 'max:128'],
        ]);

        return response()->json(['data' => $model->daily($router, $data['user'])]);
    }

    private function guardRouter(Router $router): void
    {
        abort_if((int) $router->id === self::EDGE_ROUTER_ID, 404);
    }
}
