<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancePerAreaRequest;
use App\Services\FinancePerAreaReportService;
use App\Support\AreaScope;
use Illuminate\Http\JsonResponse;

class FinancePerAreaController extends Controller
{
    public function summary(FinancePerAreaRequest $request, FinancePerAreaReportService $report): JsonResponse
    {
        $data = $request->validated();
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        return response()->json($report->summary(
            $request->user(),
            $data['period'] ?? now('Asia/Jakarta')->format('Y-m'),
            $areaId,
            $data['as_of'] ?? null,
        ));
    }

    public function detail(FinancePerAreaRequest $request, FinancePerAreaReportService $report): JsonResponse
    {
        $data = $request->validated();
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        return response()->json($report->detail(
            $request->user(),
            $data['period'] ?? now('Asia/Jakarta')->format('Y-m'),
            $areaId,
            $data['as_of'] ?? null,
            $data,
        ));
    }
}
