<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'webhooks/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // APP_URL + optional comma-separated origins (e.g. public apps that call attendance export from browser)
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $o): string => trim($o),
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            env('APP_URL', 'http://localhost')
        ))
    ))),

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        static fn (string $p): string => trim($p),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
    ))),

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'X-Inertia',
        'Authorization',
        'X-Attendance-Token',
        'X-Attendance-Export-Token',
        'Accept',
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    // false = public APIs can use simple token headers without cookie credentials
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
