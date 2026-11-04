<?php

namespace App\Support;

/**
 * One key per kind of ops alert. Used by OpsAlertService to decide, per kind,
 * whether an alert may reach the ops group while the global env flag
 * EVOLUTION_API_OPS_ALERTS_ENABLED is off (Setting ops_alert_sources_enabled).
 */
final class OpsAlertSource
{
    public const GENERAL = 'general';

    public const VM_ALERT = 'vm_alert';

    public const QUEUE_DEPTH = 'queue_depth';

    public const REDIS_DOWN = 'redis_down';

    public const ENFORCE_HEARTBEAT = 'enforce_heartbeat';

    public const FAILED_JOBS = 'failed_jobs';

    public const FAILED_JOB_IMMEDIATE = 'failed_job_immediate';

    public const OPS_PING = 'ops_ping';

    public const DEFERRED_TERMINATIONS = 'deferred_terminations';

    public const DEFERRED_TERMINATION_CASE = 'deferred_termination_case';

    public const WA_GATEWAY_DOWN = 'wa_gateway_down';

    public const NETWORK_RECONCILE = 'network_reconcile';

    public const OLT_CLEANUP = 'olt_cleanup';

    public const HUAWEI_ONU_DELETE = 'huawei_onu_delete';

    public const PAYMENT_GATEWAY = 'payment_gateway';

    public const LIFECYCLE_BACKSTOP = 'lifecycle_backstop';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GENERAL,
            self::VM_ALERT,
            self::QUEUE_DEPTH,
            self::REDIS_DOWN,
            self::ENFORCE_HEARTBEAT,
            self::FAILED_JOBS,
            self::FAILED_JOB_IMMEDIATE,
            self::OPS_PING,
            self::DEFERRED_TERMINATIONS,
            self::DEFERRED_TERMINATION_CASE,
            self::WA_GATEWAY_DOWN,
            self::NETWORK_RECONCILE,
            self::OLT_CLEANUP,
            self::HUAWEI_ONU_DELETE,
            self::PAYMENT_GATEWAY,
            self::LIFECYCLE_BACKSTOP,
        ];
    }
}
