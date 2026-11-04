<?php

/*
|--------------------------------------------------------------------------
| Site-specific values (public snapshot)
|--------------------------------------------------------------------------
|
| Placeholders only. Hosts use the reserved example.test domain and IPs use the
| documentation ranges from RFC 5737 (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24). A real
| deployment replaces this whole file.
|
| config/services.php loads this file with require, so keep it free of helper
| calls that read other configuration.
|
*/

use App\Services\RandomPppoePasswordPolicy;

return [

    'company' => [
        'email' => 'contact@example.test',
        'phone' => '081200000000',
        'wa_admin' => '628120000000',
    ],

    'app_url' => 'https://billing.example.test',
    'drive_public_url' => 'https://drive.example.test',
    'genieacs_cwmp_url' => 'https://billing.example.test/genieacs-cwmp/',
    'genieacs_webhook_url' => 'https://billing.example.test/webhooks/genieacs/device',
    'genieacs_panel_url' => 'https://billing.example.test/genieacs-ui/#!/overview',
    'csp_extra_hosts' => ['https://img.example.test', 'http://img.example.test'],
    'ktp_media_allowed_hosts' => 'media.example.test,billing.example.test',

    'acs_url' => 'http://192.0.2.10:7547',

    'psb_router_ip' => '192.0.2.20',

    'olt_default_management_ip' => '192.0.2.30',

    'legacy_scraper' => [
        'primary_url' => 'http://scraper.example.test:3000',
        'internal_urls' => [
            'http://scraper.example.test:3000',
            'http://192.0.2.40:3000',
        ],
        'public_url' => 'http://scraper-public.example.test',
    ],

    'ppp_metrics_fallback_urls' => [
        'http://198.51.100.1:49091/metrics',
        'http://198.51.100.2:49091/metrics',
        'http://metrics.example.test:49091/metrics',
    ],
    'mktxp_metrics_url' => 'http://metrics.example.test:49090/metrics',
    'mktxp_ppp_metrics_url' => 'http://metrics.example.test:49091/metrics',

    'grafana' => [
        'url' => 'http://192.0.2.50:3001',
        'allowed_hosts' => '192.0.2.50',
    ],

    'cpe_source_hosts' => ['192.0.2.60', '192.0.2.61'],

    'wireguard' => [
        'server_address' => '192.0.2.1',
        'allowed_ips' => '192.0.2.0/24',
        'peer_address' => '192.0.2.2/24',
        'peer_allowed_ip' => '192.0.2.2/32',
        'webhook_url' => 'http://198.51.100.1:9000/hooks/wg-add-peer',
        'api_url' => 'http://198.51.100.1',
        'api_url_fallback' => 'http://198.51.100.1:8000',
    ],

    'nextcloud_internal_url' => 'http://files.example.test:8091',

    'isolation_portal_test_ip' => '192.0.2.70',

    'infrastructure_tags_extra' => ['CANARYTAG'],

    'olt_snmp_legacy_community' => 'canary-community',

    'ui_examples' => [
        'router_public_ip' => '192.0.2.80',
        'router_address' => '192.0.2.81',
        'vpn_server_endpoint' => '192.0.2.82',
    ],

    'duitku_callback_ips' => [
        'production' => ['203.0.113.10', '203.0.113.11'],
        'sandbox' => ['203.0.113.12', '203.0.113.13'],
    ],

    // New PSB PPPoE secrets get a random password.
    'psb_password_policy' => RandomPppoePasswordPolicy::class,

    'psb_password_error' => 'Password PSB tidak dapat dibuat.',
    'psb_password_manual_error' => 'Password PSB tidak dapat dibuat. Aktivasi dihentikan.',

    'psb_password_hint' => 'Password dibuat otomatis (acak) dan tidak dapat diubah dari form ini.',

];
