<?php

return [
    'dry_run' => (bool) env('OLT_PROVISIONING_DRY_RUN', true),
    'kill_switch' => (bool) env('OLT_PROVISIONING_KILL_SWITCH', false),
    'approval_window_seconds' => 900,
    'approval_lock_seconds' => 900,
];
