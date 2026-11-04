<?php

namespace App\Services;

use App\Models\DeviceActionLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductionControlPlaneService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'source_of_truth' => $this->sourceOfTruth(),
            'state_machines' => $this->stateMachines(),
            'wa_command_contracts' => $this->waCommandContracts(),
            'device_action_policy' => $this->deviceActionPolicy(),
            'rbac_matrix' => $this->rbacMatrix(),
            'feature_flags' => $this->featureFlags(),
            'kill_switches' => $this->killSwitches(),
            'monitoring_severity' => $this->monitoringSeverity(),
            'data_quality' => $this->dataQualityPolicy(),
            'observability' => $this->observabilitySnapshot(),
            'ml_guard' => [
                'mode' => 'read_only_recommendation',
                'manual_approval_required' => true,
                'blocked_actions' => ['device_write', 'customer_write', 'payment_write', 'broadcast_send'],
            ],
        ];
    }

    public function writeEnabled(string $domain): bool
    {
        $globalReadOnly = $this->boolSetting('control_plane.device_read_only_mode', true);
        if ($globalReadOnly) {
            return false;
        }

        return $this->boolSetting("control_plane.{$domain}_writes_enabled", false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preflight
     */
    public function ledgerRequested(
        string $domain,
        string $targetType,
        ?string $targetId,
        string $action,
        array $payload,
        ?User $actor,
        array $preflight = [],
        ?string $idempotencyKey = null,
        ?string $rollbackHint = null
    ): DeviceActionLog {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $key = $idempotencyKey ?: implode(':', [
            'device',
            $domain,
            $targetType,
            $targetId ?: 'none',
            $action,
            hash('sha256', $payloadJson),
        ]);

        return DeviceActionLog::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'domain' => $domain,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'action' => $action,
                'status' => 'requested',
                'payload_hash' => hash('sha256', $payloadJson),
                'requested_by' => $actor?->id,
                'payload' => $payload,
                'preflight' => $preflight,
                'rollback_hint' => $rollbackHint,
                'requested_at' => now(),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function featureFlags(): array
    {
        return [
            $this->flag('monitoring_unified_read_model_v2', 'Monitoring unified read model', false, 'monitoring'),
            $this->flag('monitoring_incident_correlation_enabled', 'Monitoring incident correlation', false, 'monitoring'),
            $this->flag('monitoring_noc_cockpit_enabled', 'NOC cockpit monitoring', false, 'monitoring'),
            $this->flag('monitoring_ticket_triage_enabled', 'Monitoring ticket triage actions', false, 'monitoring'),
            $this->flag('monitoring_p1p2_notification_enabled', 'Notifikasi P1/P2 monitoring', false, 'monitoring'),
            $this->flag('psb_wa_reply_enabled', 'WA auto-reply PSB', false, 'ops'),
            $this->flag('psb_correction_writes_enabled', 'Apply koreksi PSB live', false, 'psb'),
            $this->flag('migration_device_actions_enabled', 'Action migrasi perangkat', false, 'migration'),
            $this->flag('router_secret_delete_enabled', 'Hapus secret router yang belum terhubung', false, 'router'),
            $this->flag('ticket_lifecycle_writes_enabled', 'Ticket lifecycle customer write', false, 'ticket'),
            $this->flag('payment_write_enabled', 'Payment write gateway', false, 'billing'),
            $this->flag('wa_broadcast_writes_enabled', 'WA broadcast live', false, 'billing'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function killSwitches(): array
    {
        return [
            $this->flag('control_plane.device_read_only_mode', 'Semua perangkat read-only', true, 'global'),
            $this->flag('control_plane.mikrotik_writes_enabled', 'MikroTik write', false, 'mikrotik'),
            $this->flag('control_plane.olt_writes_enabled', 'OLT write', false, 'olt'),
            $this->flag('control_plane.genieacs_writes_enabled', 'GenieACS write', false, 'genieacs'),
            $this->flag('control_plane.payment_writes_enabled', 'Payment write', false, 'payment'),
            $this->flag('control_plane.wa_auto_reply_enabled', 'WA auto-reply global', false, 'wa'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceOfTruth(): array
    {
        return [
            'customer_master' => 'customers',
            'device_snapshots' => ['router_mt_snapshots', 'router_mt_secret_states', 'olt_onus', 'genieacs_devices'],
            'pending_review' => ['psb_drafts', 'customer_migrations', 'ticket_activities', 'customer_migration_import_rows'],
            'attachments' => ['psb_drafts.meta/image_paths', 'customers.*_photo_url', 'customer_migrations.meta'],
            'overwrite_policy' => 'new field data may propose changes; confirmed customer/device writes require review/approval + audit.',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function stateMachines(): array
    {
        return [
            'psb' => ['draft', 'validated', 'pppoe_created', 'olt_done', 'customer_synced', 'completed', 'correction_pending_review'],
            'migration' => ['pending', 'verified', 'confirmed_pending_approval', 'olt_swapped', 'customer_updated', 'completed', 'needs_review'],
            'ticket_customer_lifecycle' => ['requested', 'pending_review', 'approved', 'applied', 'rollback_ready'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waCommandContracts(): array
    {
        return [
            [
                'command' => '#psb',
                'version' => '1.0',
                'required' => ['Nama', 'ODP', 'RT', 'RW', 'Daerah', 'Router', 'Paket', 'SN'],
                'optional' => ['Tikor', 'HP', 'KTP', 'Foto rumah', 'Foto router'],
                'ambiguity' => 'candidate max 5 + wajib pilih #ID jika ganda',
            ],
            [
                'command' => '#migrasi',
                'version' => '1.0',
                'required' => ['Nama/PPPoE', 'SN baru', 'Daerah/Router'],
                'optional' => ['SN lama', 'OLT lama', 'ONU lama', 'ODP', 'Tikor'],
                'ambiguity' => 'match lemah/ganda masuk needs_review, no device write',
            ],
            [
                'command' => '#pppoe cari',
                'version' => '1.0',
                'required' => ['nama/user/telp'],
                'optional' => [],
                'ambiguity' => 'read-only, kandidat max 5, timeout router harus jujur',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceActionPolicy(): array
    {
        return [
            'ledger_table' => 'device_action_logs',
            'default_mode' => $this->writeEnabled('mikrotik') || $this->writeEnabled('olt') || $this->writeEnabled('genieacs') ? 'guarded_write' : 'read_only',
            'required_fields' => ['requested_by', 'target', 'action', 'payload_hash', 'idempotency_key', 'preflight', 'result', 'verify', 'rollback_hint'],
            'recent' => $this->recentDeviceActions(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rbacMatrix(): array
    {
        return [
            ['action' => 'ganti_sn', 'role' => 'admin/noc/superadmin', 'approval' => true],
            ['action' => 'approve_migrasi', 'role' => 'admin/noc/superadmin', 'approval' => true],
            ['action' => 'close_dismantle', 'role' => 'admin/noc/superadmin', 'approval' => true],
            ['action' => 'retry_olt', 'role' => 'noc/superadmin', 'approval' => true],
            ['action' => 'edit_vlan', 'role' => 'global_admin/superadmin', 'approval' => true],
            ['action' => 'commit_import', 'role' => 'admin/noc/superadmin', 'approval' => true],
            ['action' => 'export', 'role' => 'admin/noc/superadmin', 'approval' => false],
            ['action' => 'payment_write', 'role' => 'superadmin', 'approval' => true],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function monitoringSeverity(): array
    {
        return [
            'outage' => 'critical',
            'billing_isolir' => 'info',
            'fiberhome_tr069_offline' => 'warning',
            'pppoe_down' => 'major',
            'olt_los' => 'critical',
            'genieacs_stale' => 'warning',
            'mikrotik_timeout' => 'major',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataQualityPolicy(): array
    {
        return [
            'excel_kmz_import' => ['staging_required' => true, 'row_hash' => true, 'batch_diff' => true, 'undo_batch' => true],
            'duplicate_detector' => ['keys' => ['pppoe_user', 'phone', 'sn', 'name_area_rw'], 'fuzzy_score_review_below' => 85],
            'lineage' => ['source', 'batch_key', 'row_number', 'manual_override_reason', 'actor', 'timestamp'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observabilitySnapshot(): array
    {
        return [
            'failed_jobs' => $this->countTable('failed_jobs'),
            'queued_jobs' => $this->countTable('jobs'),
            'wa_chat_signals_24h' => $this->countRecent('wa_chat_signals', 'created_at', now()->subDay()),
            'monitoring_alert_events_active' => $this->activeMonitoringAlertCount(),
            'device_actions_24h' => $this->countRecent('device_action_logs', 'created_at', now()->subDay()),
            'scheduler_last_run' => Setting::get('scheduler_last_run_at', null),
            'php_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDeviceActions(): array
    {
        if (! Schema::hasTable('device_action_logs')) {
            return [];
        }

        return DeviceActionLog::query()
            ->latest()
            ->limit(10)
            ->get(['id', 'domain', 'target_type', 'target_id', 'action', 'status', 'requested_at'])
            ->map(fn (DeviceActionLog $log) => [
                'id' => $log->id,
                'domain' => $log->domain,
                'target' => trim($log->target_type.' '.($log->target_id ?? '')),
                'action' => $log->action,
                'status' => $log->status,
                'requested_at' => optional($log->requested_at)->toIso8601String(),
            ])
            ->all();
    }

    private function flag(string $key, string $label, bool $default, string $domain): array
    {
        $value = $this->boolSetting($key, $default);

        return compact('key', 'label', 'value', 'default', 'domain');
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = Setting::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function countTable(string $table): ?int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    private function countRecent(string $table, string $column, \DateTimeInterface $since): ?int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        return DB::table($table)->where($column, '>=', $since)->count();
    }

    private function activeMonitoringAlertCount(): ?int
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return null;
        }

        if (Schema::hasColumn('monitoring_alert_events', 'status')) {
            return DB::table('monitoring_alert_events')->where('status', 'active')->count();
        }

        if (Schema::hasColumn('monitoring_alert_events', 'resolved_at')) {
            return DB::table('monitoring_alert_events')->whereNull('resolved_at')->count();
        }

        return DB::table('monitoring_alert_events')->count();
    }
}
