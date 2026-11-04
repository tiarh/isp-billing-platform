<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Services\OperationalAreaDashboardService;
use App\Support\AreaScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancePerAreaOperationsController extends Controller
{
    public function __invoke(Request $request, OperationalAreaDashboardService $report): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'area' => ['nullable', 'integer', 'exists:areas,id'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'project' => ['nullable', 'string', 'max:100'],
            'rw' => ['nullable', 'string', 'max:100'],
            'sub_area' => ['nullable', 'string', 'max:100'],
        ]);
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        $period = $data['period'] ?? now('Asia/Jakarta')->format('Y-m');
        $reportRequest = Request::create($request->url(), 'GET', [
            'ops_month' => $period,
            'ops_area' => $areaId === null ? 'all' : Area::query()->whereKey($areaId)->value('name'),
            'ops_project' => $data['project'] ?? null,
            'ops_rw' => $data['rw'] ?? null,
            'ops_sub_area' => $data['sub_area'] ?? null,
        ]);
        $reportRequest->setUserResolver($request->getUserResolver());
        $payload = $report->summary($reportRequest);
        $payload['period'] = $period;
        $payload['as_of'] = $data['as_of'] ?? now('Asia/Jakarta')->toDateString();
        $payload['formula_version'] = 'operational-area-report-v1';

        return response()->json($payload);
    }
}
