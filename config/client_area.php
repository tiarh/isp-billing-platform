<?php

return [
    // Customer client area (/pelanggan). OFF unless explicitly enabled.
    'enabled' => (bool) env('CLIENT_AREA_ENABLED', false),

    // Optional dedicated host (e.g. pay.example.test): GET / on this host
    // redirects to the client-area login, and the legal pages are served.
    'home_host' => strtolower(trim((string) env('CLIENT_AREA_HOST', ''))),

    // The S&K / Refund texts show a visible DRAFT marker until the owner approves them.
    'legal_approved' => (bool) env('CLIENT_AREA_LEGAL_APPROVED', false),

    // Account lockout after N consecutive wrong passwords.
    'lockout_attempts' => (int) env('CLIENT_AREA_LOCKOUT_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('CLIENT_AREA_LOCKOUT_MINUTES', 15),

    // Login throttles (attempts per decay window, seconds).
    'throttle_ip_attempts' => (int) env('CLIENT_AREA_THROTTLE_IP', 20),
    'throttle_code_attempts' => (int) env('CLIENT_AREA_THROTTLE_CODE', 10),
    'throttle_decay' => 60,
];
