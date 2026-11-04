<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Failed-job alerts (PR-ALERT, audit O1)
    |--------------------------------------------------------------------------
    |
    | New failed jobs are summarised to the Evolution ops group as COUNTS per
    | job class only. Exception text, payloads and customer data are never
    | broadcast; failed_jobs and the Laravel log stay the source of truth.
    | Enforcement jobs listed in `immediate_jobs` also alert as soon as they
    | fail permanently (JobFailed event), deduplicated per class.
    |
    */
    'failed_job_alerts' => [
        'enabled' => filter_var(env('OPS_FAILED_JOB_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_rows_scanned' => max(1, (int) env('OPS_FAILED_JOB_ALERTS_MAX_ROWS', 2000)),
        'max_classes_listed' => max(1, (int) env('OPS_FAILED_JOB_ALERTS_MAX_CLASSES', 10)),
        'immediate_jobs' => [
            'App\\Jobs\\IsolateCustomerJob',
            'App\\Jobs\\ReconnectCustomerJob',
        ],
        'immediate_dedupe_seconds' => max(30, (int) env('OPS_FAILED_JOB_IMMEDIATE_DEDUPE_SECONDS', 300)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler dead-man switch (PR-ALERT, audit O3 / server P1-6)
    |--------------------------------------------------------------------------
    |
    | When HEALTHCHECK_SCHEDULER_URL is set (e.g. a healthchecks.io check with
    | a 5-minute period), the scheduler pings it at most once per
    | `ping_interval_seconds`. If the scheduler dies, pings stop and the
    | external service alerts. Empty URL = disabled. The URL is a credential
    | of sorts: it is never logged or broadcast.
    |
    */
    'dead_man' => [
        'scheduler_url' => (string) env('HEALTHCHECK_SCHEDULER_URL', ''),
        'ping_interval_seconds' => max(60, (int) env('HEALTHCHECK_SCHEDULER_INTERVAL_SECONDS', 300)),
        'timeout_seconds' => max(1, (int) env('HEALTHCHECK_SCHEDULER_TIMEOUT_SECONDS', 5)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis queue depth monitor
    |--------------------------------------------------------------------------
    |
    | Every queue consumed by the VM102 workers (deploy/3node/
    | docker-compose.workers-102.yml --queue args) is checked by
    | `ops:health-alert` and `/health/deep` (LLEN of `queues:<name>`); alert
    | when length > threshold. A Redis error is reported as `redis_error`
    | (never treated as length 0). One WA message per sweep, repeated at most
    | once per `alert_cooldown_seconds` for the same set of queues, and the
    | Redis-down alert at most once per `redis_alert_cooldown_seconds`.
    | Override/add with OPS_QUEUE_DEPTH_THRESHOLDS="billing:100,wa:300";
    | a threshold < 0 removes that queue from the monitor.
    |
    */
    'queue_depth' => [
        'queues' => array_filter(array_merge([
            'default' => (int) env('EVOLUTION_API_OPS_QUEUE_DEFAULT_THRESHOLD', 500),
            'psb' => (int) env('EVOLUTION_API_OPS_QUEUE_PSB_THRESHOLD', 20),
            // Disabled (-1) until the owner decides on the stale billing queue
            // (see HANDOFF session 7); re-enable via OPS_QUEUE_DEPTH_THRESHOLDS=billing:200
            'billing' => -1,
            'wa' => 500,
            'network-enforcement' => 100,
            'monitoring' => 1000,
            'monitoring-ping' => 1000,
            'olt-sync' => 200,
            'router-sync' => 200,
            'media-recovery' => 200,
            'migration-command' => 50,
            'migration-live' => 50,
            'ocr-media' => 100,
        ], (static function (string $raw): array {
            $parsed = [];
            foreach (explode(',', $raw) as $pair) {
                [$queue, $threshold] = array_pad(explode(':', trim($pair), 2), 2, '');
                $queue = trim($queue);
                if ($queue !== '' && is_numeric(trim($threshold))) {
                    $parsed[$queue] = (int) trim($threshold);
                }
            }

            return $parsed;
        })((string) env('OPS_QUEUE_DEPTH_THRESHOLDS', ''))), static fn (int $threshold): bool => $threshold >= 0),
        // Worker queues intentionally not monitored by default (see above).
        'disabled_by_default' => ['billing'],
        'alert_cooldown_seconds' => max(60, (int) env('OPS_QUEUE_ALERT_COOLDOWN_SECONDS', 1800)),
        'redis_alert_cooldown_seconds' => max(60, (int) env('OPS_REDIS_ALERT_COOLDOWN_SECONDS', 1800)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforce worker alert
    |--------------------------------------------------------------------------
    |
    | worker-enforce-102 (isolir / buka isolir only) writes the heartbeat key
    | services.monitoring.admission_gate.worker_heartbeat_keys.enforce. When it
    | is missing or older than `enforce_worker_max_age_seconds`, `ops:health-alert`
    | sends one ops-group WA (deduped 30 min). worker-ops still drains the queue.
    | Set OPS_ENFORCE_WORKER_ALERT=false if the worker is stopped on purpose.
    |
    */
    'enforce_worker_alert' => filter_var(env('OPS_ENFORCE_WORKER_ALERT', true), FILTER_VALIDATE_BOOL),
    'enforce_worker_max_age_seconds' => max(300, (int) env('OPS_ENFORCE_WORKER_MAX_AGE_SECONDS', 900)),

    /*
    |--------------------------------------------------------------------------
    | VM102 steal / GenieACS inform alert (`ops:vm-alert`)
    |--------------------------------------------------------------------------
    |
    | Every 10 minutes: CPU steal from /proc/stat and the number of modems that
    | informed GenieACS in the last 30 minutes (DB count). WA to the ops group
    | only. There is no on/off here: the Setting `ops_vm_alert_mode`
    | (off default / log / on) is the switch, set with `ops:vm-alert --set-mode`.
    |
    */
    'vm_alert' => [
        'steal_threshold_pct' => (float) env('OPS_VM_ALERT_STEAL_THRESHOLD_PCT', 30),
        'steal_window_minutes' => max(10, (int) env('OPS_VM_ALERT_STEAL_WINDOW_MINUTES', 30)),
        'steal_recover_pct' => (float) env('OPS_VM_ALERT_STEAL_RECOVER_PCT', 25),
        'inform_window_minutes' => 30,
        'inform_drop_pct' => (float) env('OPS_VM_ALERT_INFORM_DROP_PCT', 10),
        'inform_recover_pct' => (float) env('OPS_VM_ALERT_INFORM_RECOVER_PCT', 5),
        'inform_confirm_samples' => max(1, (int) env('OPS_VM_ALERT_INFORM_CONFIRM_SAMPLES', 2)),
        'snapshot_fresh_minutes' => max(10, (int) env('OPS_VM_ALERT_SNAPSHOT_FRESH_MINUTES', 30)),
        'snapshot_stale_alert_minutes' => max(31, (int) env('OPS_VM_ALERT_SNAPSHOT_STALE_MINUTES', 60)),
        'dedupe_seconds' => max(60, (int) env('OPS_VM_ALERT_DEDUPE_SECONDS', 3600)),
        'history_days' => max(1, (int) env('OPS_VM_ALERT_HISTORY_DAYS', 7)),
        'min_same_hour_days' => max(1, (int) env('OPS_VM_ALERT_MIN_SAME_HOUR_DAYS', 3)),
        'store' => (string) env('OPS_VM_ALERT_STORE', 'file'),
        'dry_run_sample_seconds' => max(0, (int) env('OPS_VM_ALERT_DRY_RUN_SAMPLE_SECONDS', 3)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deep health check
    |--------------------------------------------------------------------------
    |
    | GET /health/deep checks DB, Redis, scheduler heartbeat age and queue
    | depth. NOT public: it needs either a logged-in global admin or, for
    | external monitors, HEALTH_DEEP_TOKEN sent as the X-Health-Token header.
    | With no token configured only global admins can call it. Anyone else
    | gets 404. Only flags and numbers are returned (no hostnames, secrets,
    | exception text or payloads). 200 = ok/degraded, 503 = DB/Redis/scheduler
    | failure.
    |
    */
    'health' => [
        'token' => (string) env('HEALTH_DEEP_TOKEN', ''),
        'scheduler_max_age_seconds' => max(60, (int) env('HEALTH_SCHEDULER_MAX_AGE_SECONDS', 300)),
    ],
];
