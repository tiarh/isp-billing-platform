<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceBulkWaExecuteRequest;
use App\Http\Requests\FinancePerAreaRequest;
use App\Services\FinanceBulkWaService;
use App\Support\AreaScope;
use Illuminate\Http\JsonResponse;

class FinanceBulkWaController extends Controller
{
    public function preview(FinancePerAreaRequest $request, FinanceBulkWaService $bulkWa): JsonResponse
    {
        $data = $request->validated();
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        return response()->json($bulkWa->preview(
            $request->user(),
            $data['period'] ?? now('Asia/Jakarta')->format('Y-m'),
            $areaId,
            $data['as_of'] ?? null,
            $data,
        ));
    }

    public function execute(FinanceBulkWaExecuteRequest $request, FinanceBulkWaService $bulkWa): JsonResponse
    {
        $data = $request->validated();
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        if (! $bulkWa->canUse($request->user())) {
            abort(403);
        }

        $settings = $bulkWa->configSnapshot();
        if (! $settings['enabled'] || $settings['dry_run']) {
            return response()->json([
                'success' => false,
                'status' => 'blocked_by_setting',
                'message' => 'WA tagihan massal masih disabled atau dry-run.',
            ], 409);
        }

        $result = $bulkWa->execute(
            $request->user(),
            $data['period'],
            $areaId,
            $data['as_of'] ?? null,
            $data,
            $data['idempotency_key'],
        );

        return response()->json(['success' => true] + $result);
    }
}
