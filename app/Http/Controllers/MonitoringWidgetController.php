<?php

namespace App\Http\Controllers;

use App\Services\Grafana\GrafanaQueryTemplates;
use App\Services\Grafana\MonitoringWidgets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringWidgetController extends Controller
{
    /**
     * Server-side Grafana widget data. Only the widget name and a whitelisted
     * range are read from the request; PromQL never comes from the browser.
     */
    public function show(Request $request, string $widget, MonitoringWidgets $widgets): JsonResponse
    {
        abort_unless(in_array($widget, MonitoringWidgets::WIDGETS, true), 404);

        $range = $request->query->has('range') ? $request->query('range') : '1h';
        if (! is_string($range) || ! isset(GrafanaQueryTemplates::RANGES[$range])) {
            return response()->json([
                'message' => 'The selected range is invalid.',
                'errors' => ['range' => ['The selected range is invalid.']],
            ], 422);
        }

        return response()->json($widgets->build($widget, $range));
    }
}
