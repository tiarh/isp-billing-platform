<?php

namespace App\Http\Controllers;

use App\Models\OutageSignal;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\OutageShadowEpisodeReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Deteksi Gangguan" tab of Monitoring (shadow mode). GET-only JSON, global-admin (route middleware).
 * No WA, no queue, no writes.
 */
class OutageShadowController extends Controller
{
    public function index(Request $request, OutageShadowEpisodeReadModel $model): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'olt_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(OutageShadowEpisodeReadModel::TYPE_FILTERS)],
            'state' => ['nullable', Rule::in(OutageShadowEpisodeReadModel::STATE_FILTERS)],
            'episode' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:64'],
            'view' => ['nullable', Rule::in(OutageShadowEpisodeReadModel::VIEW_FILTERS)],
            'info' => ['nullable', 'boolean'],
            'hours' => ['nullable', Rule::in([OutageShadowEpisodeReadModel::LAST_HOURS])],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($data['from']) && ! empty($data['to']) && $data['from'] > $data['to']) {
            throw ValidationException::withMessages(['from' => 'Tanggal "dari" tidak boleh setelah tanggal "sampai".']);
        }

        return response()->json($model->page(OutageShadowEpisodeReadModel::normalizeFilters($data), $request->user()?->role));
    }

    /** RCA-4 card "Ketepatan diagnosa": the latest stored weekly report (null before the first run). */
    public function precision(): JsonResponse
    {
        return response()->json(['report' => PrecisionReport::latest()]);
    }

    public function history(OutageSignal $signal, OutageShadowEpisodeReadModel $model): JsonResponse
    {
        return response()->json(['data' => $model->history($signal)]);
    }

    public function customers(OutageSignal $signal, OutageShadowEpisodeReadModel $model): JsonResponse
    {
        return response()->json(['data' => $model->customers($signal)]);
    }
}
