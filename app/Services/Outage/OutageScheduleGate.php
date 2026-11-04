<?php

namespace App\Services\Outage;

use App\Services\MonitoringAdmissionGate;
use Illuminate\Support\Facades\Log;

/**
 * The schedule `when()` filter for outage:detect. schedule:run evaluates filters outside its per-event
 * try/catch, so this must never throw (a Redis/DB blip would abort every later schedule entry).
 */
class OutageScheduleGate
{
    public static function shouldRun(): bool
    {
        try {
            return OutageSettings::enabled()
                && app(MonitoringAdmissionGate::class)->allowsMonitoringDashboard();
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] schedule gate failed, skipping run', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return false;
        }
    }
}
