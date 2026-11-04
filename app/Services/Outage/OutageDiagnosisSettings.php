<?php

namespace App\Services\Outage;

/**
 * RCA-4 settings block (diagnosis + "Cek sekarang"), kept in its own trait so the shared OutageSettings class only
 * gains one `use` line. It relies on OutageSettings::flag() / intSetting() / read() (private static, same class).
 * Defaults are the safe ones: stored diagnoses ON (DB only), the panel for admin + NOC, "Cek sekarang" OFF.
 */
trait OutageDiagnosisSettings
{
    /**
     * RCA-5 kill switch (default ON): isolir vs physical fault, PON cut only with several ONUs down now, narrower
     * history area flag. OFF = the 2026-10-21.1 behaviour and the stored rules_version 2026-10-21.1.
     */
    public static function diagnosisRca5Enabled(): bool
    {
        return self::flag('diagnosis_rca5_enabled', true);
    }

    /** Roles that exist in users.role. A listed role sees the panel; superadmin always does. */
    public const DIAGNOSIS_ROLES = ['superadmin', 'admin', 'noc', 'teknisi'];

    /** ticket_open / auto_episode / auto_refresh diagnoses are stored (DB only, no device I/O). */
    public static function diagnosisEnabled(): bool
    {
        return self::flag('diagnosis_enabled', true);
    }

    /**
     * Roles that see the "Diagnosa otomatis" panel (default admin,noc).
     *
     * @return list<string>
     */
    public static function diagnosisPanelRoles(): array
    {
        $raw = self::read('diagnosis_panel_roles');
        if (self::$lastReadFailed || $raw === null || trim((string) $raw) === '') {
            return ['admin', 'noc'];
        }
        $roles = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $role) {
            if (in_array($role, self::DIAGNOSIS_ROLES, true)) {
                $roles[$role] = $role;
            }
        }

        return $roles === [] ? ['admin', 'noc'] : array_values($roles);
    }

    public static function diagnosisPanelVisibleTo(?string $role): bool
    {
        return $role !== null && ($role === 'superadmin' || in_array($role, self::diagnosisPanelRoles(), true));
    }

    /**
     * Roles that may USE "Cek sekarang" (a subset of whoever sees the panel). Default admin only (owner: admin
     * first); superadmin always. Independent of diagnosis_panel_roles.
     *
     * @return list<string>
     */
    public static function diagnosisCheckNowRoles(): array
    {
        $raw = self::read('diagnosis_check_now_roles');
        if (self::$lastReadFailed || $raw === null || trim((string) $raw) === '') {
            return ['admin'];
        }
        $roles = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $role) {
            if (in_array($role, self::DIAGNOSIS_ROLES, true)) {
                $roles[$role] = $role;
            }
        }

        return $roles === [] ? ['admin'] : array_values($roles);
    }

    public static function diagnosisCheckNowAllowedTo(?string $role): bool
    {
        return $role !== null && self::diagnosisPanelVisibleTo($role) && ($role === 'superadmin' || in_array($role, self::diagnosisCheckNowRoles(), true));
    }

    /** The "Cek sekarang" button + probe endpoints. Default OFF (owner turns it on, admin first). */
    public static function diagnosisCheckNowEnabled(): bool
    {
        return self::flag('diagnosis_check_now_enabled', false);
    }

    public static function diagnoseNowPerUserHour(): int
    {
        return min(200, self::intSetting('diagnose_now_per_user_hour', 'diagnose_now_per_user_hour', 20, 1));
    }

    public static function diagnoseNowCustomerCooldownMinutes(): int
    {
        return min(60, self::intSetting('diagnose_now_customer_cooldown_minutes', 'diagnose_now_customer_cooldown_minutes', 2, 1));
    }

    /** Global number of checks running at the same time (each uses up to 3 web workers). */
    public static function diagnoseNowConcurrent(): int
    {
        return min(10, self::intSetting('diagnose_now_concurrent', 'diagnose_now_concurrent', 2, 1));
    }

    /** Manual OLT reads per OLT per rolling hour (separate from the automatic enrichment budget). */
    public static function diagnoseOltManualPerHour(): int
    {
        return min(60, self::intSetting('diagnose_olt_manual_per_hour', 'diagnose_olt_manual_per_hour', 10, 1));
    }

    /** Weekly precision summary to the technical log group (counts only). Default OFF. */
    public static function diagnosisWeeklyWaEnabled(): bool
    {
        return self::flag('diagnosis_weekly_wa_enabled', false);
    }

    /** Context snapshot also for detector episodes (auto_episode): DB only, invisible. Default ON. */
    public static function diagnosisEpisodeSnapshotEnabled(): bool
    {
        return self::flag('diagnosis_episode_snapshot_enabled', true);
    }

    /** Hourly `outage:outcome-label` (what really fixed an incident): DB only, invisible. Default ON. */
    public static function diagnosisOutcomeLabelsEnabled(): bool
    {
        return self::flag('diagnosis_outcome_labels_enabled', true);
    }

    /** Show "Pasti / Kemungkinan / Belum tahu" instead of tinggi / sedang / rendah. Default OFF. */
    public static function diagnosisCalibratedLabelsEnabled(): bool
    {
        return self::flag('diagnosis_calibrated_labels_enabled', false);
    }

    /** Show the "Saran dari data" chip (repair signature) in the ticket close form. Default OFF. */
    public static function diagnosisSignatureChipEnabled(): bool
    {
        return self::flag('diagnosis_signature_chip_enabled', false);
    }

    /**
     * PR-D "more evidence": the burst line ("N pelanggan putus bersamaan, ONU tetap nyala") in the diagnosis. The
     * session_burst signal rows themselves are always written (DB only, in Deteksi Gangguan). Default OFF.
     */
    public static function diagnosisSessionBurstEnabled(): bool
    {
        return self::flag('diagnosis_session_burst_enabled', false);
    }

    /** The "kemungkinan listrik padam di area" line for ONUs of an area_power group. The signal rows are always written. Default OFF. */
    public static function diagnosisAreaPowerEnabled(): bool
    {
        return self::flag('diagnosis_area_power_enabled', false);
    }

    /**
     * Traffic lines ("Internet aktif, pemakaian ...") and the conflict guard (fresh traffic after the ONU went down
     * blocks a hard fibre / power / modem answer: it becomes "unknown"). One switch for both. Default OFF.
     */
    public static function diagnosisTrafficGuardEnabled(): bool
    {
        return self::flag('diagnosis_traffic_guard_enabled', false);
    }

    /** ONUs entering dying gasp in one detector run on one OLT that make an area_power group (default 5). */
    public static function areaPowerMinOnus(): int
    {
        return min(50, self::intSetting('area_power_min_onus', 'area_power_min_onus', 5, 3));
    }

    /** @return array<string,mixed> */
    public static function diagnosisSnapshot(): array
    {
        return [
            'diagnosis_enabled' => self::diagnosisEnabled(),
            'diagnosis_rca5_enabled' => self::diagnosisRca5Enabled(),
            'diagnosis_panel_roles' => implode(',', self::diagnosisPanelRoles()),
            'diagnosis_check_now_enabled' => self::diagnosisCheckNowEnabled(),
            'diagnosis_check_now_roles' => implode(',', self::diagnosisCheckNowRoles()),
            'diagnose_now_per_user_hour' => self::diagnoseNowPerUserHour(),
            'diagnose_now_customer_cooldown_minutes' => self::diagnoseNowCustomerCooldownMinutes(),
            'diagnose_now_concurrent' => self::diagnoseNowConcurrent(),
            'diagnose_olt_manual_per_hour' => self::diagnoseOltManualPerHour(),
            'diagnosis_weekly_wa_enabled' => self::diagnosisWeeklyWaEnabled(),
            'diagnosis_episode_snapshot_enabled' => self::diagnosisEpisodeSnapshotEnabled(),
            'diagnosis_outcome_labels_enabled' => self::diagnosisOutcomeLabelsEnabled(),
            'diagnosis_calibrated_labels_enabled' => self::diagnosisCalibratedLabelsEnabled(),
            'diagnosis_signature_chip_enabled' => self::diagnosisSignatureChipEnabled(),
            'diagnosis_session_burst_enabled' => self::diagnosisSessionBurstEnabled(),
            'diagnosis_area_power_enabled' => self::diagnosisAreaPowerEnabled(),
            'diagnosis_traffic_guard_enabled' => self::diagnosisTrafficGuardEnabled(),
            'area_power_min_onus' => self::areaPowerMinOnus(),
        ];
    }
}
