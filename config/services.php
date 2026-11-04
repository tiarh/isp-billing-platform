<?php

// Deployment-specific literals live in config/site.php (read via require because
// config files load alphabetically, so config('site') is not registered yet here).
$site = require __DIR__.'/site.php';

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Legacy scraper. From Docker the public URL often times out —
    // prefer an internal LEGACY_SCRAPER_URL (host gateway or LAN IP) in .env.
    // Default URLs: config/site.php.
    'legacy_scraper' => [
        'url' => env('LEGACY_SCRAPER_URL', $site['legacy_scraper']['primary_url']),
        // Optional fallbacks tried in order if primary fails (comma-separated)
        'fallback_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'LEGACY_SCRAPER_FALLBACK_URLS',
            implode(',', [...$site['legacy_scraper']['internal_urls'], $site['legacy_scraper']['public_url']])
        ))))),
        'timeout' => (int) env('LEGACY_SCRAPER_TIMEOUT', 45),
    ],

    // Migration snapshot downloads are deny-by-default. Set an explicit
    // comma-separated HTTPS host allowlist in production before using URL import.
    'migration_snapshot' => [
        'allowed_hosts' => array_values(array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) env('MIGRATION_SNAPSHOT_ALLOWED_HOSTS', ''))),
        ))),
        'timeout' => max(5, (int) env('MIGRATION_SNAPSHOT_TIMEOUT', 30)),
        'max_bytes' => max(1024, (int) env('MIGRATION_SNAPSHOT_MAX_BYTES', 20 * 1024 * 1024)),
    ],

    'wireguard' => [
        'interface' => env('WIREGUARD_INTERFACE', 'wg0'),
        'endpoint' => env('WIREGUARD_ENDPOINT'),
        'port' => env('WIREGUARD_PORT', 443),
        'server_address' => env('WIREGUARD_SERVER_ADDRESS', $site['wireguard']['server_address']),
        'public_key' => env('WIREGUARD_PUBLIC_KEY'),
        'public_key_path' => env('WIREGUARD_PUBLIC_KEY_PATH', '/etc/wireguard/server_public.key'),
        'config_path' => env('WIREGUARD_CONFIG_PATH', '/etc/wireguard/wg0.conf'),
        'allowed_ips' => env('WIREGUARD_ALLOWED_IPS', $site['wireguard']['allowed_ips']),
        'webhook_url' => env('WIREGUARD_WEBHOOK_URL', $site['wireguard']['webhook_url']),
        // No default secret — set WIREGUARD_WEBHOOK_TOKEN in .env (fail-closed if empty).
        'webhook_token' => env('WIREGUARD_WEBHOOK_TOKEN', ''),
        'api_url' => env('WIREGUARD_API_URL', $site['wireguard']['api_url']),
    ],

    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

    'monitoring_export' => [
        'token' => env('MONITORING_EXPORT_TOKEN', ''),
    ],

    'whatspie' => [
        'key' => env('WHATSPIE_API_KEY', ''),
        'device' => env('WHATSPIE_DEVICE_ID', ''),
        'test_number' => env('WHATSPIE_TEST_NUMBER'),
    ],

    'fonnte' => [
        'key' => env('FONNTE_API_KEY', ''),
        'test_number' => env('FONNTE_TEST_NUMBER', ''),
    ],

    // WhatsApp Cloud API (Official Meta — 100% safe, won't get banned)
    'whatsapp_cloud' => [
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN', ''),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID', ''),
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),
        'template_name' => env('WHATSAPP_CLOUD_TEMPLATE_NAME', 'ticket_assignment'),
    ],

    // Evolution API (Self-hosted WA gateway — safer than shared APIs like Fonnte)
    'evolution_api' => [
        'base_url' => env('EVOLUTION_API_BASE_URL', 'http://127.0.0.1:8085'),
        'api_key' => env('EVOLUTION_API_API_KEY', ''),
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET', env('EVOLUTION_API_API_KEY', '')),
        'instance_name' => env('EVOLUTION_API_INSTANCE_NAME', 'skynet-ebilling'),
        'instance_ops' => env('EVOLUTION_API_INSTANCE_OPS', 'skynet-ebilling'),
        'instance_billing' => env('EVOLUTION_API_INSTANCE_BILLING', 'skynet-billing'),
        // Dedicated Nomor 3 instance shown on the WA Gateway page (selectable for Ticket & Progress).
        'instance_ticket_dedicated' => env('EVOLUTION_API_INSTANCE_TICKET_DEDICATED', 'skynet-ticket'),
        'instance_ticket' => env('EVOLUTION_API_INSTANCE_TICKET', env('EVOLUTION_API_INSTANCE_OPS', 'skynet-ebilling')),
        'delay_ms' => env('EVOLUTION_API_DELAY_MS', 5000), // 5s delay between messages — simulates human
        // Explicit HTTP timeouts (seconds). A connect failure is safe to retry; a
        // read timeout on a send is "delivery unknown" and is never auto-retried.
        'send_timeout' => (int) env('EVOLUTION_API_SEND_TIMEOUT', 45),
        'connect_timeout' => (int) env('EVOLUTION_API_CONNECT_TIMEOUT', 5),
        'admin_timeout' => (int) env('EVOLUTION_API_ADMIN_TIMEOUT', 15),
        'ticket_group_id' => env('EVOLUTION_API_TICKET_GROUP_ID', ''), // WA group JID for ticket notifications (e.g. 120363xxx@g.us)
        'ops_group_id' => env('EVOLUTION_API_OPS_GROUP_ID', env('CONNECTIVITY_ALERTS_GROUP_ID', '')),
        'ops_alerts_enabled' => filter_var(env('EVOLUTION_API_OPS_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'ops_failed_jobs_threshold' => (int) env('EVOLUTION_API_OPS_FAILED_JOBS_THRESHOLD', 1),
        'ops_exception_burst' => (int) env('EVOLUTION_API_OPS_EXCEPTION_BURST', 5),
        'ops_exception_window_seconds' => (int) env('EVOLUTION_API_OPS_EXCEPTION_WINDOW', 300),
    ],

    // All automated WhatsApp notifications use Evolution API.
    'whatsapp' => [
        'provider' => env('WA_PROVIDER', 'evolution_api'),
    ],

    'billing_receipt' => [
        'ttl_days' => (int) env('BILLING_RECEIPT_TTL_DAYS', 30),
    ],

    'isolation_portal' => [
        'enabled' => filter_var(env('ISOLATION_PORTAL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'test_mode' => filter_var(env('ISOLATION_PORTAL_TEST_MODE', 'true'), FILTER_VALIDATE_BOOLEAN),
        'test_ip' => trim((string) env('ISOLATION_PORTAL_TEST_IP', $site['isolation_portal_test_ip'])),
        'mode' => trim((string) env('ISOLATION_PORTAL_MODE', 'general')),
        'areas' => array_values(array_filter(array_map(
            static fn ($area) => is_string($area) ? trim($area) : '',
            (array) json_decode((string) env('ISOLATION_PORTAL_AREAS', '[]'), true)
        ))),
    ],

    'notification' => [
        'channel' => env('NOTIFICATION_CHANNEL', 'evolution_api'),
    ],

    // FileBrowser for absensi photos.
    // Upload from Docker MUST use the internal URL (NEXTCLOUD_INTERNAL_URL).
    // NEXTCLOUD_URL (public) is for browser/export links only — often unreachable from app container.
    'nextcloud' => [
        'url' => env('NEXTCLOUD_INTERNAL_URL', $site['nextcloud_internal_url']),
        'public_url' => env('NEXTCLOUD_URL', $site['drive_public_url']),
        'user' => env('NEXTCLOUD_USER', 'admin'),
        'pass' => env('NEXTCLOUD_PASS', env('NEXTCLOUD_PASSWORD', '')),
    ],

    // Connectivity WA alerts (PON mismatch, bad signal) — anti-spam defaults
    'genieacs' => [
        'online_minutes' => (int) env('GENIEACS_ONLINE_MINUTES', 30),
        'ping_fresh_minutes' => (int) env('GENIEACS_PING_FRESH_MINUTES', 45),
        'ping_host' => env('GENIEACS_PING_HOST', '8.8.8.8'),
        'ping_poll_seconds' => (int) env('GENIEACS_PING_POLL_SECONDS', 30),
        'ping_repetitions' => (int) env('GENIEACS_PING_REPETITIONS', 3),
        // List sync uses projection + bulk upsert — 20s is enough; raise only if ACS remote is slow.
        'sync_timeout' => (int) env('GENIEACS_SYNC_TIMEOUT', 20),
        // Migration/PSB tagging must fail fast; never hold a queue worker on ACS.
        'tag_timeout' => (int) env('GENIEACS_TAG_TIMEOUT', 5),
        // Shared secret for POST /webhooks/genieacs/device (GenieACS ext / inform hook)
        'webhook_token' => env('GENIEACS_WEBHOOK_TOKEN', ''),
        'webhook_url' => env('GENIEACS_WEBHOOK_URL', $site['genieacs_webhook_url']),
    ],

    // Training-dataset export of wa_chat_signals (wa-signals:export). Keep the salt
    // in env only; without it the export command refuses to run.
    'wa_signals' => [
        'export_salt' => env('WA_SIGNALS_EXPORT_SALT'),
    ],

    'monitoring' => [
        'weak_rx_dbm' => (float) env('MONITORING_WEAK_RX_DBM', -27),
        'slow_query_ms' => (int) env('MONITORING_SLOW_QUERY_MS', 250),
        // Ticketing candidates drawer: ignore events not refreshed within this many minutes (scan may run every 30 min).
        'candidate_stale_minutes' => (int) env('MONITORING_CANDIDATE_STALE_MINUTES', 45),
        'admission_gate' => [
            // Keep the guard dormant until production has the matching env/config
            // evidence. Enabling it is a deliberate rollout step, not a code-side
            // surprise that can stale the existing NOC page.
            'enabled' => filter_var(env('MONITORING_ADMISSION_GATE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'strict' => filter_var(env('MONITORING_ADMISSION_GATE_STRICT', false), FILTER_VALIDATE_BOOLEAN),
            'operator_hold' => filter_var(env('MONITORING_OPERATOR_HOLD', false), FILTER_VALIDATE_BOOLEAN),
            'cooldown_seconds' => max(120, (int) env('MONITORING_ADMISSION_COOLDOWN_SECONDS', 120)),
            'heartbeat_required' => filter_var(env('MONITORING_HEARTBEAT_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
            'heartbeat_max_age_seconds' => max(300, (int) env('MONITORING_HEARTBEAT_MAX_AGE_SECONDS', 900)),
            'resource_checks_enabled' => filter_var(env('MONITORING_RESOURCE_CHECKS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'critical_queues' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MONITORING_CRITICAL_QUEUES', 'psb,migration-command,migration-live,migration-lookup,ocr-media')),
            ))),
            'worker_heartbeat_keys' => [
                'psb' => (string) env('MONITORING_PSB_HEARTBEAT_KEY', 'monitoring.worker_heartbeat.psb'),
                'migration' => (string) env('MONITORING_MIGRATION_HEARTBEAT_KEY', 'monitoring.worker_heartbeat.migration'),
                'ocr' => (string) env('MONITORING_OCR_HEARTBEAT_KEY', 'monitoring.worker_heartbeat.ocr'),
                'enforce' => (string) env('MONITORING_ENFORCE_HEARTBEAT_KEY', 'monitoring.worker_heartbeat.enforce'),
            ],
            'load_threshold' => (float) env('MONITORING_LOAD_THRESHOLD', 0.70),
            'cpu_cores' => max(1, (int) env('MONITORING_CPU_CORES', 1)),
            // steal_aware: hold on PSI cpu stall or load with the hypervisor
            // steal share removed (raw load1 is mostly steal on VM102).
            // load: legacy raw load1/cpu_cores rule (rollback switch).
            'cpu_signal' => strtolower(trim((string) env('MONITORING_CPU_SIGNAL', 'steal_aware'))),
            'psi_cpu_some_threshold' => min(100.0, max(1.0, (float) env('MONITORING_PSI_CPU_SOME_THRESHOLD', 40))),
            'cpu_usable_busy_threshold' => min(100.0, max(1.0, (float) env('MONITORING_CPU_USABLE_BUSY_THRESHOLD', 80))),
            'available_memory_bytes' => max(1, (int) env('MONITORING_AVAILABLE_MEMORY_BYTES', 8589934592)),
            'root_free_bytes' => max(1, (int) env('MONITORING_ROOT_FREE_BYTES', 16106127360)),
            'redis_used_ratio' => min(0.95, max(0.10, (float) env('MONITORING_REDIS_USED_RATIO', 0.70))),
        ],
        'traffic' => [
            'enabled' => filter_var(env('MONITORING_TRAFFIC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'hash_secret' => (string) env('MONITORING_TRAFFIC_HASH_SECRET', ''),
            'hmac_secret' => (string) env('MONITORING_TRAFFIC_HMAC_SECRET', ''),
            // The collector is deliberately identified by configuration so the
            // VM107 move does not require changing the ingest contract again.
            'collector_allowlist' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MONITORING_TRAFFIC_COLLECTOR_ALLOWLIST', 'collector-107')),
            ))),
            'collector_label' => (string) env('MONITORING_TRAFFIC_COLLECTOR_LABEL', 'mikrotik_107'),
            'collector_display' => (string) env('MONITORING_TRAFFIC_COLLECTOR_DISPLAY', 'VM107 / MikroTik'),
            'ledger_source' => (string) env('MONITORING_TRAFFIC_LEDGER_SOURCE', 'collector_107'),
            'source_allowlist' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MONITORING_TRAFFIC_SOURCE_ALLOWLIST', '')),
            ))),
            'retention_months' => max(1, (int) env('MONITORING_TRAFFIC_RETENTION_MONTHS', 36)),
            'current_cache_budget_bytes' => max(1048576, (int) env('MONITORING_TRAFFIC_CURRENT_CACHE_BUDGET_BYTES', 16777216)),
            'max_rows_per_chunk' => min(250, max(1, (int) env('MONITORING_TRAFFIC_MAX_ROWS_PER_CHUNK', 250))),
            'max_request_bytes' => min(4194304, max(65536, (int) env('MONITORING_TRAFFIC_MAX_REQUEST_BYTES', 4194304))),
            'freshness_seconds' => max(90, (int) env('MONITORING_TRAFFIC_FRESHNESS_SECONDS', 180)),
            'batch_ledger_days' => max(1, (int) env('MONITORING_TRAFFIC_BATCH_LEDGER_DAYS', 14)),
        ],
        // Disabled until a worker/capacity canary proves the target lanes.
        'queue_decomposition_enabled' => env('MONITORING_QUEUE_DECOMPOSITION_ENABLED', false),
        // Read-only queue observability follows the current 102 worker contract.
        // migration-lookup stays OFF until a separate owner-approved canary.
        'queue_consumers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MONITORING_QUEUE_CONSUMERS', 'psb,migration-live,monitoring')),
        ))),
    ],

    // Fallback SNMP community for OLTs whose olts.snmp_community is empty
    // (per-OLT DB value always wins). Read via App\Support\OltSnmpCommunity.
    // Empty = legacy built-in fallback + one-time warning (no behaviour change);
    // removing that legacy value is a follow-up once this env is set.
    'olt_snmp' => [
        'community' => (string) env('OLT_SNMP_COMMUNITY', ''),
    ],

    // Huawei MA5608T optical read lane. Community is read from the encrypted
    // OLT record; it is intentionally not configured or logged here.
    'huawei_olt_snmp' => [
        // Production registry ID for OLT GADANG 3; override per environment.
        'olt_id' => max(1, (int) env('HUAWEI_GADANG_OLT_ID', 11)),
        'binary' => env('HUAWEI_OLT_SNMPWALK_BINARY', 'snmpwalk'),
        'port' => max(1, (int) env('HUAWEI_OLT_SNMP_PORT', 161)),
        'timeout' => max(1, (int) env('HUAWEI_OLT_SNMP_TIMEOUT', 2)),
        'retries' => max(0, (int) env('HUAWEI_OLT_SNMP_RETRIES', 1)),
        // One walk currently takes several seconds for 111 ONTs. Keep the
        // packet timeout small while allowing the bounded child process to
        // finish instead of killing a valid walk mid-output.
        'process_timeout' => max(15, min(60, (int) env('HUAWEI_OLT_SNMP_PROCESS_TIMEOUT', 30))),
        'if_name_oid' => '1.3.6.1.2.1.31.1.1.1.1',
        'serial_oid' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
        'rx_oid' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
        // 43.1.10 is a status/flag table on the live MA5608T, not distance.
        'distance_oid' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
        // ONT run state (audit 2026-10-08: Gadang state frozen since 19 Sep).
        // Candidate hwGponDeviceOntControlRunStatus; values 1=online,
        // 2=offline. NOT verified on the device yet: NOC must snmpwalk it on
        // OLT 11 and compare with `display ont info` before enabling.
        'state_enabled' => filter_var(env('HUAWEI_OLT_SNMP_STATE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'run_state_oid' => env('HUAWEI_OLT_SNMP_RUN_STATE_OID', '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15'),
        'run_state_online_values' => array_values(array_filter(array_map('trim', explode(',', (string) env('HUAWEI_OLT_SNMP_RUN_STATE_ONLINE', '1'))), 'strlen')),
        'run_state_offline_values' => array_values(array_filter(array_map('trim', explode(',', (string) env('HUAWEI_OLT_SNMP_RUN_STATE_OFFLINE', '2'))), 'strlen')),
        // Absent from a successful RX walk => RX cleared, only when the walk
        // returned at least this share of previously known RX rows.
        'rx_clear_min_ratio' => max(0.0, min(1.0, (float) env('HUAWEI_OLT_SNMP_RX_CLEAR_MIN_RATIO', 0.5))),
    ],

    'connectivity_alerts' => [
        'enabled' => env('CONNECTIVITY_ALERTS_ENABLED', false),
        'group_id' => env('CONNECTIVITY_ALERTS_GROUP_ID', ''),
        'dry_run' => env('CONNECTIVITY_ALERTS_DRY_RUN', true),
        // odp_only = 1 digest per ODP (recommended); both = per-customer + ODP (noisy)
        'notify_mode' => env('CONNECTIVITY_ALERTS_NOTIFY_MODE', 'odp_only'),
        'pending_minutes' => (int) env('CONNECTIVITY_ALERTS_PENDING_MINUTES', 15),
        'repeat_minutes' => (int) env('CONNECTIVITY_ALERTS_REPEAT_MINUTES', 120),
        'customer_limit_per_run' => (int) env('CONNECTIVITY_ALERTS_CUSTOMER_LIMIT', 5),
        'odp_limit_per_run' => (int) env('CONNECTIVITY_ALERTS_ODP_LIMIT', 5),
        'recovery_limit_per_run' => (int) env('CONNECTIVITY_ALERTS_RECOVERY_LIMIT', 5),
    ],

    // MKTXP legacy stack (host 106): light health exporter + PPP count-only
    // sidecar. The per-user traffic collector is isolated on VM107.
    // Online/Secret UI primary source via routers:import-ppp-metrics.
    'mktxp' => [
        'enabled' => env('MKTXP_ENABLED', true),
        'metrics_url' => env('MKTXP_METRICS_URL', $site['mktxp_metrics_url']),
        'ppp_metrics_url' => env('MKTXP_PPP_METRICS_URL', $site['mktxp_ppp_metrics_url']),
    ],

    // Plan C MikroTik Read Power Stack.
    // C1: use_snapshot_ui=true → Online/Secret di UI dari router_mt_snapshots (good+fresh).
    // write_live=false → kolom routers.* tetap diisi Plan B (backup/rollback).
    'mt_plan_c' => [
        'enabled' => env('MT_PLAN_C_ENABLED', true),
        'write_live' => env('MT_PLAN_C_WRITE_LIVE', true),
        'use_snapshot_ui' => env('MT_PLAN_C_USE_SNAPSHOT_UI', true),
        'max_stale_seconds' => (int) env('MT_PLAN_C_MAX_STALE', 180),
        // C4: live-health only updates CPU/uptime — not Online/Secret (pulse owns those for UI)
        'live_skip_online_write' => env('MT_PLAN_C_LIVE_SKIP_ONLINE', true),
        'sessions_enabled' => env('MT_PLAN_C_SESSIONS', true),
        'secrets_diff_enabled' => env('MT_PLAN_C_SECRETS_DIFF', true),
        'exporter_url' => env('MT_PLAN_C_EXPORTER_URL', env('MKTXP_PPP_METRICS_URL', $site['mktxp_ppp_metrics_url'])),
        'pulse_interval_seconds' => (int) env('MT_PLAN_C_PULSE_INTERVAL', 60),
        // Monitoring scheduler mode keeps the session lanes --read-only for
        // every customer column EXCEPT the PPPoE online flag pair
        // (customers.is_online + customers.pppoe_online_checked_at). Without
        // this, is_online freezes at the last manual "Sync router".
        'monitoring_write_online' => filter_var(env('MT_PLAN_C_MONITORING_WRITE_ONLINE', true), FILTER_VALIDATE_BOOLEAN),
        // A name dump is authoritative (may demote is_online=false) only when it
        // holds at least this share of the count-only session total.
        'complete_dump_ratio' => max(0.5, min(1.0, (float) env('MT_PLAN_C_COMPLETE_DUMP_RATIO', 0.85))),
    ],

    // Customer "online" truth. A customer is ONLINE only when the required
    // signals are positive AND fresh; stale snapshots yield "unknown", never a
    // fake online. Strict vendors (OLTs with a state collector) require all
    // three: PPPoE active, ONU state online, and a valid optical RX reading.
    'online_freshness' => [
        'ppp_max_age_minutes' => max(1, (int) env('ONLINE_PPP_MAX_AGE_MINUTES', 15)),
        'olt_state_max_age_minutes' => max(1, (int) env('ONLINE_OLT_STATE_MAX_AGE_MINUTES', 15)),
        'rx_max_age_minutes' => max(1, (int) env('ONLINE_RX_MAX_AGE_MINUTES', 20)),
        // Case-insensitive substring match against olts.vendor. Default covers
        // ZTE C300 (RA, Krian) and Huawei MA5608T (Gadang).
        'strict_vendors' => array_values(array_filter(array_map(
            static fn (string $vendor): string => strtolower(trim($vendor)),
            explode(',', (string) env('ONLINE_STRICT_VENDORS', 'zte,c300,huawei')),
        ))),
    ],

    // KTP OCR (OCR.space) — used by Auto PPPoE /api/ktp-ocr
    // Scheduler ownership lives on the web/execution host. Safe mode prevents
    // a scheduler startup from fanning out every device collector at once.
    // F0 PR-D: read-only router <-> DB isolation reconciliation
    // (billing:reconcile-network). The daily schedule is OFF until the owner
    // enables NETWORK_RECONCILE_SCHEDULE_ENABLED; the command never writes to
    // a router. Budget bounds the whole run; routers past it are reported.
    'network_reconcile' => [
        'schedule_enabled' => (bool) env('NETWORK_RECONCILE_SCHEDULE_ENABLED', false),
        'notify' => (bool) env('NETWORK_RECONCILE_NOTIFY', true),
        'connect_timeout' => (int) env('NETWORK_RECONCILE_CONNECT_TIMEOUT', 25),
        'budget_seconds' => (int) env('NETWORK_RECONCILE_BUDGET_SECONDS', 600),
        'retention_days' => (int) env('NETWORK_RECONCILE_RETENTION_DAYS', 14),
    ],

    'ebilling_scheduler' => [
        'mode' => env('EBILLING_SCHEDULER_MODE', 'safe'),
    ],

    // olt:inventory-sync budget. Audit 2026-10-08: OLT RA (1223 ONUs) opened
    // one SSH login per 8 ONUs, never finished inside the 600s job timeout and
    // blocked worker-ops for ~10 min every hour. The ZTE collector now stops
    // at the budget and records a `partial` run; the queued job is killed at
    // budget + job_grace_seconds so the ops worker is freed quickly.
    'olt_inventory_sync' => [
        'timeout_seconds' => max(60, min(540, (int) env('OLT_INVENTORY_SYNC_TIMEOUT', 130))),
        'job_grace_seconds' => max(15, min(120, (int) env('OLT_INVENTORY_SYNC_JOB_GRACE', 45))),
        // OLT RA inventory cadence (Asia/Jakarta). Every 6h on odd hours so it
        // never lands on the 2-hourly billing pass (even hours, :00).
        'ra_cron' => (string) env('OLT_INVENTORY_RA_CRON', '2 1-23/6 * * *'),
        // ZTE inventory source. `bulk` (default): state + running-config in two
        // SSH sessions (~1 min on OLT RA). `detail`: legacy per-ONU
        // `show gpon onu detail-info` (one SSH login per 8 ONUs).
        'zte_mode' => env('OLT_INVENTORY_ZTE_MODE', 'bulk') === 'detail' ? 'detail' : 'bulk',

        // SNMP inventory (ZTE C300, verified on OLT RA 2026-10-08: 1223 ONUs
        // in ~6 s, name/description/serial/phase equal to the CLI). OLT ids in
        // this list read inventory over SNMP first; others stay on SSH. The
        // community stays in olts.snmp_community.
        'snmp_olt_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('OLT_INVENTORY_SNMP_OLT_IDS', '1'))
        ), fn (int $id): bool => $id > 0)),
        // SNMP empty / incomplete / below min coverage -> SSH inventory.
        'snmp_fallback_ssh' => filter_var(env('OLT_INVENTORY_SNMP_FALLBACK_SSH', true), FILTER_VALIDATE_BOOLEAN),
        // At most one SSH fallback per OLT per this many minutes, so a broken
        // SNMP path cannot turn the 15-min cadence into a 15-min SSH load.
        'snmp_fallback_min_interval_minutes' => max(0, min(1440, (int) env('OLT_INVENTORY_SNMP_FALLBACK_MIN_INTERVAL', 60))),
        // SNMP rows / existing olt_onus rows needed to accept the SNMP walk.
        'snmp_min_coverage' => max(0.0, min(1.0, (float) env('OLT_INVENTORY_SNMP_MIN_COVERAGE', 0.9))),
        // Wall budget for all inventory walks (python collector SNMP_TIMEOUT).
        'snmp_timeout_seconds' => max(15, min(120, (int) env('OLT_INVENTORY_SNMP_TIMEOUT', 60))),
        // OLT RA cadence while SNMP inventory is enabled for OLT 1 (Jakarta):
        // SNMP every 15 min, SSH once a day as a cross-check. `ra_cron` above
        // remains the SSH cadence when SNMP inventory is disabled for OLT 1.
        'ra_snmp_cron' => (string) env('OLT_INVENTORY_RA_SNMP_CRON', '4,19,34,49 * * * *'),
        'ra_ssh_cron' => (string) env('OLT_INVENTORY_RA_SSH_CRON', '10 3 * * *'),
        // ZTE 3902.1082.500 tree, index <ifIndex 0x1101SSPP>.<onuId>.
        'snmp_oids' => [
            'status' => (string) env('OLT_INVENTORY_SNMP_STATUS_OID', '1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4'),
            'name' => (string) env('OLT_INVENTORY_SNMP_NAME_OID', '1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2'),
            'description' => (string) env('OLT_INVENTORY_SNMP_DESCRIPTION_OID', '1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.3'),
            'serial' => (string) env('OLT_INVENTORY_SNMP_SERIAL_OID', '1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.18'),
            'distance' => (string) env('OLT_INVENTORY_SNMP_DISTANCE_OID', '1.3.6.1.4.1.3902.1082.500.10.2.3.10.1.2'),
        ],
    ],

    'ocrspace' => [
        'key' => env('OCRSPACE_API_KEY', env('OCR_SPACE_API_KEY', '')),
        'paddle_enabled' => env('PADDLE_OCR_ENABLED', true),
        'paddle_url' => env('PADDLE_OCR_URL', env('OCR_PADDLE_URL', 'http://paddleocr:8000/ocr')),
        'paddle_timeout' => (int) env('PADDLE_OCR_TIMEOUT', 45),
        // Per-request PaddleOCR cap inside the PSB media OCR job (clamped 5-45 s
        // by the job). A warm call takes 12-20 s on VM102, so the old 8 s cap
        // made every call time out and KTP fell back to Tesseract only.
        'media_paddle_timeout' => (int) env('PSB_MEDIA_PADDLE_TIMEOUT', 30),
        // PHP memory_limit for the PSB media OCR job. Raised only when the worker
        // limit is lower (never lowered). Keep below the worker container limit.
        'media_memory_limit' => env('PSB_MEDIA_OCR_MEMORY_LIMIT', '512M'),
        // Per-PSM Tesseract budget. The host runs at high load, so a single
        // PSM can take 20s+; a 12s cap timed out the main PSM and returned an
        // empty read ("unknown").
        'tesseract_timeout' => (int) env('TESSERACT_OCR_TIMEOUT', 25),
        // Per-run Tesseract cap used by the PSB OCR paths (clamped 5-60 s). The
        // old callers clamped to 15 s, which at 50-70% CPU steal killed the main
        // PSM 3 run and made a readable KTP look empty.
        'tesseract_run_cap' => (int) env('PSB_OCR_TESS_RUN_CAP', 45),
        // Tesseract language pack order for the PSB OCR worker (ind+eng default).
        'tesseract_lang' => (string) env('PSB_OCR_TESS_LANG', 'ind+eng'),
        // Time budget for one image inside the OCR worker job.
        'media_image_budget_seconds' => max(15, (int) env('PSB_MEDIA_IMAGE_BUDGET_SECONDS', 90)),
        // Wait for the shared PaddleOCR lock (one inference at a time): jobs / web.
        'paddle_lock_wait_seconds' => (int) env('PSB_PADDLE_LOCK_WAIT_SECONDS', 15),
        'paddle_lock_wait_web_seconds' => (int) env('PADDLE_OCR_LOCK_WAIT_WEB_SECONDS', 5),
        // Paddle circuit breaker: N timeouts/503 within 5 min -> skip Paddle for N seconds.
        'paddle_breaker_failures' => (int) env('PADDLE_OCR_BREAKER_FAILURES', 3),
        'paddle_breaker_seconds' => (int) env('PADDLE_OCR_BREAKER_SECONDS', 180),
        // Smallest useful Paddle call. With less time left no call is made
        // (outcome skipped_short -> degraded -> automatic re-check). 0 = off.
        'paddle_min_timeout_seconds' => (int) env('PADDLE_OCR_MIN_TIMEOUT_SECONDS', 15),
        // After a Paddle timeout/503 keep the shared lock and wait (max N s) until
        // Paddle reports idle, so the next caller never hits a left-over run. 0 = off.
        'paddle_drain_seconds' => (int) env('PADDLE_OCR_DRAIN_SECONDS', 30),
        // PSB OCR job: time kept for Paddle at the end of each photo's budget
        // (Tesseract stops earlier). 0 = off (old behaviour).
        'paddle_reserve_seconds' => (int) env('PSB_PADDLE_RESERVE_SECONDS', 35),
        // PSB OCR job: max Paddle calls per photo batch (best KTP candidate first). 99 = old behaviour.
        'paddle_max_calls_per_batch' => (int) env('PSB_PADDLE_MAX_CALLS_PER_BATCH', 2),
        // One automatic re-check of a degraded (slow server) KTP photo.
        'media_ocr_retry_delay_seconds' => (int) env('PSB_MEDIA_OCR_RETRY_DELAY_SECONDS', 120),
        // Line-based KTP parser (v2). false = previous parser for one release.
        'ktp_parser_v2' => (bool) env('KTP_OCR_PARSER_V2', true),
        // Page-segmentation modes tried per photo (the best mode varies per
        // image). Ordered; extraction stops early on a strong KTP/SN signal.
        'tesseract_psms' => (string) env('TESSERACT_OCR_PSMS', '3,6,11'),
        // Total wall-clock budget for one draft's media classification, so a
        // batch of slow photos can never overrun the OCR worker timeout.
        'ocr_total_budget_seconds' => max(15, (int) env('PSB_OCR_TOTAL_BUDGET_SECONDS', 120)),
        'media_fast_path' => env('PSB_MEDIA_FAST_PATH_ENABLED', true),
        'media_hard_guard' => env('PSB_MEDIA_HARD_GUARD_ENABLED', false),
        // Staged rollout: keep OFF until disposable UAT and a 102 canary pass.
        'async_media_enabled' => env('PSB_ASYNC_MEDIA_OCR_ENABLED', false),
        // Optional local stub/container (currently health-only on many hosts)
        'local_url' => env('OCR_LOCAL_URL', 'http://ocr-api:3000'),
        // Empty in prod unless OCR_API_KEY set — do not ship a public default key.
        'local_key' => env('OCR_API_KEY', env('APP_ENV') === 'local' ? 'helloworld' : ''),
    ],

    // Read-only attendance export for external public apps (token header only).
    // See docs/ATTENDANCE_API_SETUP.md
    'attendance_export' => [
        'token' => env('ATTENDANCE_EXPORT_TOKEN', ''),
        'drive_public_url' => env('ATTENDANCE_DRIVE_PUBLIC_URL', env('NEXTCLOUD_URL', $site['drive_public_url']).'/files'),
        'require_https' => env('ATTENDANCE_EXPORT_REQUIRE_HTTPS', true),
        'allowed_ips' => env('ATTENDANCE_EXPORT_ALLOWED_IPS', ''),
        'max_failures' => (int) env('ATTENDANCE_EXPORT_MAX_FAILURES', 20),
        'failure_decay_seconds' => (int) env('ATTENDANCE_EXPORT_FAILURE_DECAY', 300),
    ],

    // Gemini API for Smart AI Parser in WA KERJAAN
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    ],

    // Duitku payment gateway. Credentials live in Settings > Payment Gateway
    // (encrypted), not here. callback_ips are the default of the editable
    // callback IP whitelist and come from the official Duitku API docs
    // (docs.duitku.com/api/en, "Callback" section).
    'duitku' => [
        'callback_ips' => [
            'production' => $site['duitku_callback_ips']['production'],
            'sandbox' => $site['duitku_callback_ips']['sandbox'],
        ],
        'http_timeout' => (int) env('DUITKU_HTTP_TIMEOUT', 20),
    ],

    // Grafana (Monitoring). Runtime values live in Settings (grafana_url,
    // grafana_datasource_uid, encrypted grafana_service_account_token); these
    // env values are only the fallback. Default URL is the monitoring VM.
    'grafana' => [
        'url' => env('GRAFANA_URL', $site['grafana']['url']),
        'token' => env('GRAFANA_TOKEN'),
        'datasource_uid' => env('GRAFANA_DATASOURCE_UID', 'prom-main'),
        // The bearer token is only sent to these hosts (SSRF / exfiltration guard).
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('GRAFANA_ALLOWED_HOSTS', $site['grafana']['allowed_hosts']))))),
    ],

    // Midtrans fallback values. Settings (Payment Gateway) win; these env values
    // are only used when the Settings rows are empty. Read via config() so the
    // value is frozen at config:cache time (env() outside config/ is not allowed).
    'midtrans' => [
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID', ''),
    ],

    // Fallback for the PSB WA health public token (Settings value wins).
    'psb_wa_health' => [
        'public_token' => env('PSB_WA_HEALTH_PUBLIC_TOKEN', ''),
    ],

    // ZTE/SNMP collector knobs. Null default on purpose: every call site keeps
    // its own `?: default` (walk timeout 120/130, bulk reps 15/20).
    'snmp_collector' => [
        'live_timeout' => env('SNMP_LIVE_TIMEOUT'),
        'walk_timeout' => env('SNMP_WALK_TIMEOUT'),
        'bulk_reps' => env('SNMP_BULK_REPS'),
        'mode' => env('SNMP_MODE'),
        'use_host_network' => env('SNMP_USE_HOST_NETWORK', true),
        'docker_image' => env('SNMP_DOCKER_IMAGE'),
    ],

];
