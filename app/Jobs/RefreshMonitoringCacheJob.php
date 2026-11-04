<?php

namespace App\Jobs;

use App\Http\Controllers\MonitoringController;
use App\Services\MonitoringAdmissionGate;
use App\Services\MonitoringQueueContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RefreshMonitoringCacheJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct()
    {
        // Keep the existing worker contract until a target lane is explicitly
        // enabled and consumed. A missing lane must never stale the cockpit.
        $this->onQueue(app(MonitoringQueueContract::class)->queueFor('evaluate'));
    }

    public function handle(MonitoringController $monitoring, MonitoringAdmissionGate $gate): void
    {
        // The cockpit read-model is exempt from host-load/queue pressure (it is
        // a bounded DB aggregate). Operator hold and DB availability still apply.
        if (! $gate->allowsMonitoringDashboard()) {
            Cache::put('monitoring.index.admission', [
                'status' => 'paused',
                'reason_codes' => ['operator_hold_or_db_unavailable'],
                'checked_at' => now('Asia/Jakarta')->toIso8601String(),
            ], now()->addMinutes(3));

            return;
        }

        $payload = $monitoring->generatePayload();
        // Pre-warm runs every 5 min (2-59/5); 6 min keeps the fresh cache alive between runs.
        $ttl = now()->addMinutes(6);

        Cache::put('monitoring.index.payload', $payload, $ttl);
        Cache::put('monitoring.index.payload_generated_at', now()->getTimestamp(), $ttl);

        // Last-good survives short outages / transient query failures so the UI
        // shows real (slightly stale) data instead of an empty cold stub.
        Cache::put('monitoring.index.payload.last_good', $payload, now()->addHours(12));
        Cache::put('monitoring.index.payload.last_good_at', now()->getTimestamp(), now()->addHours(12));

        Cache::forget('monitoring.index.payload_revalidating');
    }
}
