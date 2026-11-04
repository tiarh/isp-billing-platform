<?php

/*
 * Roadmap 2.4 outage automation (shadow mode first). Every value here is only the
 * fallback; the Setting table (outage_* keys) wins. See App\Services\Outage\OutageSettings.
 */
return [
    // Master switch. Default OFF: nothing runs until the owner enables it.
    'enabled' => (bool) env('OUTAGE_AUTOMATION_ENABLED', false),

    // off|shadow|live per signal. dying_gasp and olt_wide are capped at shadow in code.
    'modes' => [
        'mass_los' => 'shadow',
        'single_los' => 'shadow',
        'dying_gasp' => 'shadow',
        'weak_rx' => 'shadow',
        'pppoe_down' => 'shadow',
        'olt_wide' => 'shadow',
    ],

    // off|live. Never sent in shadow; separate gate (waits for Meta WA, roadmap 2.5).
    'customer_wa_mode' => 'off',

    // Monitored OLT ids: ZTE RA (1), ZTE Krian (9), Huawei Gadang (11). HiOSO is not monitored.
    'olt_ids' => [1, 9, 11],

    // OLTs where the serial / olt_id-NULL customer fallback join is allowed (subset of olt_ids).
    'serial_fallback_olt_ids' => [1, 9, 11],

    'mass_min_customers' => 4,
    'mass_window_minutes' => 5,
    'single_los_minutes' => 30,
    'weak_rx_dbm' => -27,
    // Consecutive DAYS (one median sample per day), not 15-minute scans.
    'weak_rx_days' => 3,
    'weak_rx_trend_db' => 2,
    'pppoe_down_minutes' => 10,

    // RCA-1: a PSB draft / open migration only suppresses detection while it was updated this recently.
    'psb_suppress_days' => 3,
    'migration_suppress_days' => 7,
    // ZTE "offline, cause unknown" counts as a LOS-like (shadow) after this many minutes.
    'down_unknown_minutes' => 30,
    'down_unknown_enabled' => true,
    // Stale / failed collector or router data records DATA_BLIND instead of an outage signal.
    'data_blind_enabled' => true,
    'router_blind_minutes' => 30,

    // RCA-2: on-demand OLT cause read (read-only commands). Kill switch + caps (settings win).
    'olt_enrich_enabled' => true,
    // Technical gate (phpunit turns it off so the sync test queue never opens an OLT session).
    'olt_enrich_dispatch' => (bool) env('OUTAGE_OLT_ENRICH_DISPATCH', true),
    'olt_enrich_per_hour' => 10,
    'olt_enrich_global_per_hour' => 20,
    'olt_enrich_cache_minutes' => 30,
    // The ZTE OLT prints its own clock in detail-info: UTC (owner checked `show clock`), with no NTP so it drifts
    // (+45 min on OLT RA 2026-10-17). Huawei prints its own +08:00 offset. Both are corrected by the drift read
    // in the same session (see OltClock).
    'olt_clock_timezone' => 'UTC',
    // Drift (OLT clock minus app clock) above this many seconds records a "jam OLT tidak sinkron" warning.
    'olt_clock_drift_warn_seconds' => 600,
    // weak_rx: at most this many would-be maintenance tickets per WIB day (worst first); rest = weekly list.
    'weak_rx_max_per_day' => 10,
    'state_max_age_minutes' => 12,
    // Auto-close a ticket only after the ONU has been stable online this long.
    'recovery_stable_minutes' => 30,

    // "HH-HH" in WIB; group notifications are held until the end hour (owner: 07:00).
    'quiet_hours' => '22-07',
    'live_ticket_cap_per_hour' => 10,

    // Shadow WA summary guards (only relevant once outage_shadow_log_jid is set).
    'shadow_wa_max_per_hour' => 4,

    // A signal that reappears within this window of its resolution re-opens the same episode (flap).
    'flap_window_minutes' => [
        'mass_los' => 60,
        'single_los' => 120,
        'dying_gasp' => 120,
        'olt_wide' => 30,
        'weak_rx' => 10080,
        'pppoe_down' => 120,
    ],

    // How long outage:events-prune keeps the diagnosis history (onu_state_events, cpe_events,
    // pppoe_session_events, onu_rx_daily, pppoe_session_presence, pppoe_presence_runs). 400 days so the
    // January co-failure analysis still has the first rows of October (roadmap v2 section 9).
    'events_retention_days' => (int) env('OUTAGE_EVENTS_RETENTION_DAYS', 400),
];
