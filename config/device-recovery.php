<?php

$configuredMode = strtolower(trim((string) env('DEVICE_RECOVERY_MODE', '')));
$defaultMode = strtolower((string) env('APP_ENV', 'production')) === 'production'
    ? 'read_only'
    : 'write';

return [
    'mode' => in_array($configuredMode ?: $defaultMode, ['read_only', 'write'], true)
        ? ($configuredMode ?: $defaultMode)
        : 'read_only',

    // Owner 2026-10-10: a new dismantle ticket for a customer whose device was
    // carried/received within this many days links to that case (no new
    // pickup, no assignment WA) and closes. 0 disables the check.
    'duplicate_window_days' => (int) env('DEVICE_RECOVERY_DUPLICATE_WINDOW_DAYS', 30),
];
