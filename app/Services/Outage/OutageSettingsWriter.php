<?php

namespace App\Services\Outage;

use App\Models\Setting;
use App\Support\WaGroupJid;

/**
 * The (only) write path for the outage_* settings: validated, whitelisted and activity-logged.
 * Used by `outage:settings`. Live modes and customer WA cannot be enabled in this PR (shadow only).
 */
class OutageSettingsWriter
{
    private const BOOL_KEYS = [
        'outage_automation_enabled',
        'outage_huawei_single_live',
        'outage_shadow_allow_report_group',
        'outage_shadow_digest_enabled',
        'outage_shadow_wa_include_names',
        'outage_weak_rx_trend_enabled',
        'outage_down_unknown_enabled',
        'outage_data_blind_enabled',
        'outage_olt_enrich_enabled',
        'outage_manual_ticket_enabled',
        'pppoe_syslog_enabled',
    ];

    /** key => [min, max] */
    private const INT_KEYS = [
        'outage_mass_min_customers' => [2, 100],
        'outage_mass_window_minutes' => [10, 60], // the detector uses max(x, 10) (2 x the 5 minute snapshot)
        'outage_single_los_minutes' => [1, 1440],
        'outage_weak_rx_days' => [1, 14],
        'outage_weak_rx_sample_minutes' => [15, 240],
        'outage_weak_rx_min_day_samples' => [1, 24],
        'outage_pppoe_down_minutes' => [1, 1440],
        'outage_psb_suppress_days' => [1, 90],
        'outage_migration_suppress_days' => [1, 90],
        'outage_down_unknown_minutes' => [1, 1440],
        'outage_weak_rx_max_per_day' => [0, 500],
        'outage_router_blind_minutes' => [5, 1440],
        'outage_olt_enrich_per_hour' => [1, 60],
        'outage_olt_enrich_global_per_hour' => [1, 200],
        'outage_olt_enrich_cache_minutes' => [5, 240],
        'outage_state_max_age_minutes' => [1, 30],
        'outage_recovery_stable_minutes' => [1, 1440],
        'outage_live_ticket_cap_per_hour' => [0, 200],
        'outage_shadow_wa_max_per_hour' => [1, 20],
        'outage_flap_window_mass_los_minutes' => [5, 720],
        'outage_flap_window_single_los_minutes' => [5, 720],
        'outage_flap_window_dying_gasp_minutes' => [5, 720],
        'outage_flap_window_olt_wide_minutes' => [5, 720],
    ];

    // ===== RCA-3 (CPE layer) keys: BEGIN =====
    private const CPE_BOOL_KEYS = ['cpe_layer_enabled', 'cpe_ppp_reconnect_enabled', 'cpe_watch_enabled', 'cpe_summon_enabled'];

    /** key => [min, max] */
    private const CPE_INT_KEYS = [
        'cpe_value_max_age_minutes' => [15, 1440],
        'cpe_watch_max_devices' => [1, 100],
        'cpe_watch_hours' => [1, 168],
        'cpe_summon_device_cooldown_minutes' => [1, 60],
        'cpe_summon_per_user_hour' => [1, 200],
        'cpe_summon_global_hour' => [1, 500],
        'cpe_summon_concurrent' => [1, 10],
        'cpe_summon_timeout_ms' => [2000, 15000],
    ];

    /** @return list<string> */
    public static function cpeKeys(): array
    {
        return array_merge(self::CPE_BOOL_KEYS, array_keys(self::CPE_INT_KEYS));
    }
    // ===== RCA-3 (CPE layer) keys: END =====

    /** @return list<string> */
    public static function keys(): array
    {
        $modes = array_map(fn (string $s) => 'outage_mode_'.$s, OutageSettings::SIGNALS);

        return array_merge(
            self::cpeKeys(), // RCA-3
            self::BOOL_KEYS,
            array_keys(self::INT_KEYS),
            $modes,
            ['outage_customer_wa_mode', 'outage_olt_ids', 'outage_serial_fallback_olt_ids', 'outage_weak_rx_dbm', 'outage_weak_rx_trend_db', 'outage_quiet_hours', 'outage_shadow_log_jid', 'outage_maintenance_windows', 'pppoe_syslog_sources', 'outage_manual_ticket_roles'],
            self::diagnosisKeys(), // RCA-4 block below
        );
    }

    // ------------------------------------------------------------------ RCA-4 block (diagnosis settings)

    private const DIAGNOSIS_BOOL_KEYS = ['diagnosis_enabled', 'diagnosis_rca5_enabled', 'diagnosis_check_now_enabled', 'diagnosis_weekly_wa_enabled', 'diagnosis_episode_snapshot_enabled', 'diagnosis_outcome_labels_enabled', 'diagnosis_calibrated_labels_enabled', 'diagnosis_signature_chip_enabled', 'diagnosis_session_burst_enabled', 'diagnosis_area_power_enabled', 'diagnosis_traffic_guard_enabled'];

    /** key => [min, max] */
    private const DIAGNOSIS_INT_KEYS = [
        'area_power_min_onus' => [3, 50],
        'diagnose_now_per_user_hour' => [1, 200],
        'diagnose_now_customer_cooldown_minutes' => [1, 60],
        'diagnose_now_concurrent' => [1, 10],
        'diagnose_olt_manual_per_hour' => [1, 60],
    ];

    /** @return list<string> */
    private static function diagnosisKeys(): array
    {
        return array_merge(self::DIAGNOSIS_BOOL_KEYS, array_keys(self::DIAGNOSIS_INT_KEYS), ['diagnosis_panel_roles', 'diagnosis_check_now_roles']);
    }

    /** @return array{ok:bool,message:string,stored?:string}|null null when the key is not a diagnosis key */
    private function validateDiagnosis(string $key, string $value): ?array
    {
        if (in_array($key, self::DIAGNOSIS_BOOL_KEYS, true)) {
            if (! in_array(strtolower($value), ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                return ['ok' => false, 'message' => 'Value must be 0 or 1.'];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0'];
        }
        if (isset(self::DIAGNOSIS_INT_KEYS[$key])) {
            [$min, $max] = self::DIAGNOSIS_INT_KEYS[$key];
            if (! ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                return ['ok' => false, 'message' => "Value must be an integer {$min}..{$max}."];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => (string) (int) $value];
        }
        if ($key === 'diagnosis_panel_roles' || $key === 'diagnosis_check_now_roles') {
            $roles = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), fn (string $r) => $r !== ''));
            if ($roles === [] || array_diff($roles, OutageSettings::DIAGNOSIS_ROLES) !== []) {
                return ['ok' => false, 'message' => 'Use roles from '.implode(',', OutageSettings::DIAGNOSIS_ROLES).', e.g. admin,noc.'];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => implode(',', array_values(array_unique($roles)))];
        }

        return null;
    }

    // ------------------------------------------------------------------ end RCA-4 block

    /**
     * @return array{ok:bool,message:string,stored?:string}
     */
    public function validate(string $key, string $value): array
    {
        $value = trim($value);

        if (! in_array($key, self::keys(), true)) {
            return ['ok' => false, 'message' => 'Unknown key. Allowed: '.implode(', ', self::keys())];
        }
        if (($diagnosis = $this->validateDiagnosis($key, $value)) !== null) {
            return $diagnosis;
        }
        // RCA-3 (CPE layer) keys
        if (in_array($key, self::CPE_BOOL_KEYS, true)) {
            if (! in_array(strtolower($value), ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                return ['ok' => false, 'message' => 'Value must be 0 or 1.'];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0'];
        }
        if (isset(self::CPE_INT_KEYS[$key])) {
            [$min, $max] = self::CPE_INT_KEYS[$key];
            if (! ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                return ['ok' => false, 'message' => "Value must be an integer {$min}..{$max}."];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => (string) (int) $value];
        }
        if (in_array($key, self::BOOL_KEYS, true)) {
            if (! in_array(strtolower($value), ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                return ['ok' => false, 'message' => 'Value must be 0 or 1.'];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0'];
        }
        if (isset(self::INT_KEYS[$key])) {
            [$min, $max] = self::INT_KEYS[$key];
            if (! ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                return ['ok' => false, 'message' => "Value must be an integer {$min}..{$max}."];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => (string) (int) $value];
        }
        if (str_starts_with($key, 'outage_mode_')) {
            $mode = strtolower($value);
            if ($mode === 'live') {
                return ['ok' => false, 'message' => 'live is not available yet (shadow only in this release).'];
            }
            if (! in_array($mode, ['off', 'shadow'], true)) {
                return ['ok' => false, 'message' => 'Mode must be off or shadow.'];
            }

            return ['ok' => true, 'message' => 'ok', 'stored' => $mode];
        }

        return match ($key) {
            'outage_customer_wa_mode' => strtolower($value) === 'off'
                ? ['ok' => true, 'message' => 'ok', 'stored' => 'off']
                : ['ok' => false, 'message' => 'Customer WA stays off until Meta WA (roadmap 2.5).'],
            'outage_olt_ids', 'outage_serial_fallback_olt_ids' => $this->validateOltIds($value),
            'outage_weak_rx_dbm' => $this->validateRange($value, -40.0, -15.0, 'dBm between -40 and -15'),
            'outage_weak_rx_trend_db' => $this->validateRange($value, 0.5, 20.0, 'dB between 0.5 and 20'),
            'outage_quiet_hours' => preg_match('/^(\d{1,2})-(\d{1,2})$/', $value, $m) === 1 && (int) $m[1] <= 23 && (int) $m[2] <= 23 && $m[1] !== $m[2]
                ? ['ok' => true, 'message' => 'ok', 'stored' => $value]
                : ['ok' => false, 'message' => 'Use HH-HH (0-23), e.g. 22-07.'],
            'outage_manual_ticket_roles' => $this->validateManualTicketRoles($value),
            'outage_shadow_log_jid' => $this->validateJid($value),
            'outage_maintenance_windows' => $this->validateWindows($value),
            'pppoe_syslog_sources' => $value === '' || preg_match('/^([0-9a-fA-F:.]+=\d+)(,[0-9a-fA-F:.]+=\d+)*$/', $value) === 1
                ? ['ok' => true, 'message' => 'ok', 'stored' => $value]
                : ['ok' => false, 'message' => 'Use ip=router_id[,ip=router_id], e.g. 192.0.2.1=513.'],
            default => ['ok' => false, 'message' => 'Unsupported key.'],
        };
    }

    /**
     * Validate, store and write an activity log row. Secrets/JIDs are logged as set/cleared only.
     *
     * @return array{ok:bool,message:string}
     */
    public function set(string $key, string $value, string $actor): array
    {
        $check = $this->validate($key, $value);
        if (! $check['ok']) {
            return ['ok' => false, 'message' => $check['message']];
        }

        $stored = (string) $check['stored'];
        $old = Setting::get($key);
        Setting::set($key, $stored, 'text', 'outage');

        $mask = fn ($v) => $key === 'outage_shadow_log_jid' ? (($v === null || $v === '') ? 'empty' : 'set') : $v;
        activity('outage')
            ->withProperties([
                'key' => $key,
                'old' => $mask($old),
                'new' => $mask($stored),
                'actor' => $actor,
            ])
            ->event('outage_setting_changed')
            ->log('outage_setting_changed');

        return ['ok' => true, 'message' => $check['message']];
    }

    /** @return array{ok:bool,message:string,stored?:string} */
    private function validateManualTicketRoles(string $value): array
    {
        $roles = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), fn (string $r) => $r !== ''));
        if ($roles === [] || array_diff($roles, OutageSettings::MANUAL_TICKET_ROLES) !== []) {
            return ['ok' => false, 'message' => 'Use roles from '.implode(',', OutageSettings::MANUAL_TICKET_ROLES).', e.g. admin,noc. Superadmin always may.'];
        }

        return ['ok' => true, 'message' => 'ok', 'stored' => implode(',', array_values(array_unique($roles)))];
    }

    /** @return array{ok:bool,message:string,stored?:string} */
    private function validateOltIds(string $value): array
    {
        $decoded = json_decode($value, true);
        $list = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        // Only the owner-approved OLTs (config outage.olt_ids = [1,9,11]) may be monitored; the detector
        // additionally requires a ZTE C300 / Huawei MA5608T vendor.
        $allowed = array_map('intval', (array) config('outage.olt_ids', []));
        $ids = [];
        foreach ((array) $list as $id) {
            if (! is_numeric($id) || (int) $id < 1) {
                return ['ok' => false, 'message' => 'OLT ids must be positive integers, e.g. [1,9,11].'];
            }
            if (! in_array((int) $id, $allowed, true)) {
                return ['ok' => false, 'message' => 'OLT id '.(int) $id.' is not in the approved list ['.implode(',', $allowed).'].'];
            }
            $ids[(int) $id] = (int) $id;
        }

        return ['ok' => true, 'message' => 'ok', 'stored' => (string) json_encode(array_values($ids))];
    }

    /** @return array{ok:bool,message:string,stored?:string} */
    private function validateRange(string $value, float $min, float $max, string $what): array
    {
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            return ['ok' => false, 'message' => "Value must be {$what}."];
        }

        return ['ok' => true, 'message' => 'ok', 'stored' => (string) (float) $value];
    }

    /** @return array{ok:bool,message:string,stored?:string} */
    private function validateJid(string $value): array
    {
        if ($value === '') {
            return ['ok' => true, 'message' => 'cleared (no shadow WA)', 'stored' => ''];
        }
        $jid = WaGroupJid::normalize($value);
        if (! str_ends_with($jid, '@g.us')) {
            return ['ok' => false, 'message' => 'Must be a WhatsApp group JID (...@g.us).'];
        }
        $reason = (new OutageShadowNotifier)->refusalReason($jid, OutageSettings::shadowAllowReportGroup());
        if ($reason !== null) {
            return ['ok' => false, 'message' => "Refused ({$reason}): this is a Report/ops/PSB group. Report/ops needs outage_shadow_allow_report_group=1 first; PSB groups are never allowed."];
        }

        return ['ok' => true, 'message' => 'ok', 'stored' => $jid];
    }

    /** @return array{ok:bool,message:string,stored?:string} */
    private function validateWindows(string $value): array
    {
        if ($value === '' || $value === '[]') {
            return ['ok' => true, 'message' => 'ok', 'stored' => '[]'];
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'message' => 'Must be a JSON array of {olt_id,pon_port?,from,to,note?}.'];
        }
        foreach ($decoded as $w) {
            if (! is_array($w) || ! is_numeric($w['olt_id'] ?? null)) {
                return ['ok' => false, 'message' => 'Each window needs a numeric olt_id.'];
            }
            try {
                $from = new \DateTimeImmutable((string) ($w['from'] ?? ''));
                $to = new \DateTimeImmutable((string) ($w['to'] ?? ''));
            } catch (\Throwable) {
                return ['ok' => false, 'message' => 'from/to must be valid datetimes (WIB).'];
            }
            if ($to <= $from) {
                return ['ok' => false, 'message' => 'to must be after from.'];
            }
        }

        return ['ok' => true, 'message' => 'ok', 'stored' => (string) json_encode($decoded)];
    }
}
