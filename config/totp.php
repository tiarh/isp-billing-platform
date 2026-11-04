<?php

return [
    'issuer' => (string) env('TOTP_ISSUER', 'Skynet'),
    'algorithm' => strtoupper((string) env('TOTP_ALGORITHM', 'SHA1')),
    'digits' => 6,
    'period' => 30,
    'recovery_codes' => 8,
    'recovery_code_length' => 16,
    'verification_drift' => 1,
    'session_lifetime_minutes' => 480,
    'policy_version' => '2026-08-25',
];
