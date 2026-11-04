<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancePerAreaRequest;
use App\Services\FinancePerAreaExportService;
use App\Services\FinancePerAreaReportService;
use App\Support\AreaScope;

class FinancePerAreaExportController extends Controller
{
    public function __invoke(FinancePerAreaRequest $request, FinancePerAreaExportService $export): mixed
    {
        $data = $request->validated();
        $areaId = isset($data['area']) ? (int) $data['area'] : null;
        if ($areaId !== null) {
            AreaScope::authorizeAreaId($areaId, $request->user());
        }

        $period = $data['period'] ?? now('Asia/Jakarta')->format('Y-m');
        $result = $export->create(
            $request->user(),
            $period,
            $areaId,
            $data['as_of'] ?? null,
            $data,
        );

        activity('finance-per-area.export')
            ->withProperties([
                'period' => $period,
                'area_id' => $areaId,
                'filters' => array_values(array_diff(array_keys($data), ['period', 'area', 'as_of'])),
                'search_present' => filled($data['search'] ?? null),
                'row_count' => $result['row_count'],
                'formula_version' => FinancePerAreaReportService::FORMULA_VERSION,
                'result' => 'created',
            ])
            ->log('finance_per_area_exported');

        return response()
            ->download($result['path'], 'finance-per-area-'.$period.'.xlsx')
            ->deleteFileAfterSend(true);
    }
}
