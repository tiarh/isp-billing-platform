<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\CpeHealthReadModel;
use Illuminate\Http\JsonResponse;

class MonitoringCpeHealthController extends Controller
{
    /** Aggregate CPE health (no per-customer data); same widget envelope as the Grafana widgets. */
    public function show(CpeHealthReadModel $readModel): JsonResponse
    {
        return response()->json($readModel->payload());
    }
}
