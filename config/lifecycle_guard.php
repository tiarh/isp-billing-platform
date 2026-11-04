<?php

/*
 * CustomerLifecycleGuard (roadmap 1.3). Deliberately NO global off switch:
 * the guard is fail-closed and only ever tightened via these thresholds.
 */
return [
    // Traffic look-back, hours (owner: 2 days). pppoe_usage_daily has WIB-day
    // granularity, so 48 h = today + yesterday (WIB): last traffic day + 24-48 h wait.
    'traffic_window_hours' => max(24, (int) env('LIFECYCLE_GUARD_TRAFFIC_WINDOW_HOURS', 48)),

    // Total bytes (download + upload) inside the window that count as "traffic".
    'min_bytes' => max(1, (int) env('LIFECYCLE_GUARD_MIN_BYTES', 1048576)),

    // pppoe_usage_daily rows of a router must have been written within this many
    // minutes, otherwise "no traffic" is not proof (rollup dead => unknown).
    'rollup_alive_minutes' => max(1, (int) env('LIFECYCLE_GUARD_ROLLUP_ALIVE_MINUTES', 60)),

    // OLT inventory age accepted as evidence on non-strict vendors (e.g. HiOSO).
    'inventory_fresh_minutes' => max(1, (int) env('LIFECYCLE_GUARD_INVENTORY_FRESH_MINUTES', 120)),

    // GenieACS inform newer than this is positive "ONU working" evidence.
    'genieacs_inform_minutes' => max(1, (int) env('LIFECYCLE_GUARD_GENIEACS_INFORM_MINUTES', 30)),

    // Roadmap 1.3 A10 model backstop for unguarded terminate/archive writes.
    // log (default) = Log::warning + activity only; enforce = throw LifecycleBackstopException.
    // Local data only (no HTTP): is_online/olt_onus must be fresher than this many minutes.
    'backstop_mode' => in_array(strtolower((string) env('LIFECYCLE_GUARD_BACKSTOP_MODE', 'log')), ['log', 'enforce'], true)
        ? strtolower((string) env('LIFECYCLE_GUARD_BACKSTOP_MODE', 'log'))
        : 'log',
    // Enforce-refusal ops alerts: queued, capped per source and overall per hour.
    'backstop_alert_per_source_hour' => max(1, (int) env('LIFECYCLE_GUARD_BACKSTOP_ALERT_PER_SOURCE_HOUR', 3)),
    'backstop_alert_per_hour' => max(1, (int) env('LIFECYCLE_GUARD_BACKSTOP_ALERT_PER_HOUR', 10)),
    'backstop_fresh_minutes' => max(1, (int) env('LIFECYCLE_GUARD_BACKSTOP_FRESH_MINUTES', 15)),

    // Minimum length of the written reason for a superadmin override.
    'override_reason_min' => max(1, (int) env('LIFECYCLE_GUARD_OVERRIDE_REASON_MIN', 20)),
];
