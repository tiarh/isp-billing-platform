<?php

use App\Models\Activity;

return [
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),
    // Retention cleanup is the only supported deletion path.
    'delete_records_older_than_days' => 365,
    'default_log_name' => 'default',
    'default_auth_driver' => null,
    'subject_returns_soft_deleted_models' => false,
    'activity_model' => Activity::class,
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
