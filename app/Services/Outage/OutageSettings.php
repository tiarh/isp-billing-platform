<?php

namespace App\Services\Outage;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Read API for the roadmap 2.4 outage automation settings (Setting row first, config/outage.php
 * fallback; same pattern as ConnectivityAlertSettings). Defaults are the safe ones: master switch
 * off, every signal in shadow, customer WA off. This class is read-only; the activity-logged write
 * path is the `outage:settings` command (App\Services\Outage\OutageSettingsWriter). Reads fail safe
 * (store errors => off / config thresholds).
 */
class OutageSettings
{
    use OutageDiagnosisSettings; // RCA-4 settings block (own file)
    use OutageManualTicketSettings; // manual "Buat tiket" button (own file)

    public const SIGNALS = ['mass_los', 'single_los', 'dying_gasp', 'weak_rx', 'pppoe_down', 'olt_wide'];

    public const MODES = ['off', 'shadow', 'live'];

    /** Signals that may never act on their own (log only). */
    public const SHADOW_ONLY = ['dying_gasp', 'olt_wide'];

    public static function enabled(): bool
    {
        $raw = self::read('outage_automation_enabled');
        if (self::$lastReadFailed) {
            // Fail closed: never fall back to config (env could say true) when the store is down.
            return false;
        }

        if ($raw !== null && $raw !== '') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('outage.enabled', false);
    }

    /**
     * Effective mode for one signal. Unknown signal => off. Invalid stored value => shadow
     * (record only, never act). dying_gasp / olt_wide are capped at shadow.
     */
    public static function mode(string $signal): string
    {
        if (! in_array($signal, self::SIGNALS, true)) {
            return 'off';
        }

        $raw = self::read('outage_mode_'.$signal);
        if (self::$lastReadFailed) {
            return 'off';
        }
        if ($raw === null || $raw === '') {
            $raw = config('outage.modes.'.$signal, 'shadow');
        }

        $mode = is_string($raw) ? strtolower(trim($raw)) : '';
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'shadow';
        }

        if ($mode === 'live' && in_array($signal, self::SHADOW_ONLY, true)) {
            return 'shadow';
        }

        return $mode;
    }

    /** @return array<string,string> */
    public static function modes(): array
    {
        $out = [];
        foreach (self::SIGNALS as $signal) {
            $out[$signal] = self::mode($signal);
        }

        return $out;
    }

    /** Customer WA gate: off|live only. Anything else (including "shadow") is off. */
    public static function customerWaMode(): string
    {
        $raw = self::read('outage_customer_wa_mode');
        if (self::$lastReadFailed) {
            return 'off';
        }
        if ($raw === null || $raw === '') {
            $raw = config('outage.customer_wa_mode', 'off');
        }

        return is_string($raw) && strtolower(trim($raw)) === 'live' ? 'live' : 'off';
    }

    /**
     * Monitored OLT ids (ZTE RA 1, ZTE Krian 9, Huawei Gadang 11; HiOSO excluded).
     *
     * @return list<int>
     */
    public static function oltIds(): array
    {
        $raw = self::read('outage_olt_ids');
        if (self::$lastReadFailed) {
            return [];
        }
        // A stored empty array / "[]" means "no OLTs"; only a missing/blank value uses the config list.
        if ($raw === null || $raw === '') {
            $raw = config('outage.olt_ids', []);
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * OLTs where unmapped ONUs may be joined to customers by serial (incl. customers with olt_id NULL).
     * Default [1,9,11]; narrow it with `outage:settings` without a deploy. Only monitored OLTs matter.
     *
     * @return list<int>
     */
    public static function serialFallbackOltIds(): array
    {
        $raw = self::read('outage_serial_fallback_olt_ids');
        if (self::$lastReadFailed) {
            return [];
        }
        if ($raw === null || $raw === '') {
            $raw = config('outage.serial_fallback_olt_ids', []);
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = [];
        foreach ((array) $raw as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    public static function massMinCustomers(): int
    {
        return self::intSetting('outage_mass_min_customers', 'mass_min_customers', 4, 2);
    }

    public static function massWindowMinutes(): int
    {
        return self::intSetting('outage_mass_window_minutes', 'mass_window_minutes', 5, 1);
    }

    public static function singleLosMinutes(): int
    {
        return self::intSetting('outage_single_los_minutes', 'single_los_minutes', 30, 1);
    }

    /** Weak RX threshold in dBm: positive input is negated; outside -40..-15 falls back to -27. */
    public static function weakRxDbm(): float
    {
        $raw = self::read('outage_weak_rx_dbm');
        $value = is_numeric($raw) ? (float) $raw : (float) config('outage.weak_rx_dbm', -27);
        $value = $value > 0 ? -$value : $value;

        return ($value >= -40.0 && $value <= -15.0) ? $value : -27.0;
    }

    /** Consecutive days (one median sample per day) below the threshold. */
    public static function weakRxDays(): int
    {
        return self::intSetting('outage_weak_rx_days', 'weak_rx_days', 3, 1);
    }

    public static function weakRxTrendDb(): float
    {
        $raw = self::read('outage_weak_rx_trend_db');
        $value = is_numeric($raw) ? (float) $raw : (float) config('outage.weak_rx_trend_db', 2);

        return $value > 0 ? $value : 2.0;
    }

    /** Informational trend only by default; the owner rule is 3 consecutive daily medians below the threshold. */
    public static function weakRxTrendEnabled(): bool
    {
        $raw = self::read('outage_weak_rx_trend_enabled');
        if (self::$lastReadFailed) {
            return false;
        }

        return $raw !== null && $raw !== '' && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** Minimum minutes between two stored RX samples of one ONU (default 60, clamped 15..240). */
    public static function weakRxSampleMinutes(): int
    {
        return min(240, self::intSetting('outage_weak_rx_sample_minutes', 'weak_rx_sample_minutes', 60, 15));
    }

    /** Samples a WIB day needs before it counts as a daily median (default 3). */
    public static function weakRxMinDaySamples(): int
    {
        return min(24, self::intSetting('outage_weak_rx_min_day_samples', 'weak_rx_min_day_samples', 3, 1));
    }

    /** PSB drafts only suppress the detector when updated within this many days (RCA-1; default 3). */
    public static function psbSuppressDays(): int
    {
        return min(90, self::intSetting('outage_psb_suppress_days', 'psb_suppress_days', 3, 1));
    }

    /** Open migrations only suppress the detector when updated within this many days (RCA-1; default 7). */
    public static function migrationSuppressDays(): int
    {
        return min(90, self::intSetting('outage_migration_suppress_days', 'migration_suppress_days', 7, 1));
    }

    /** Minutes a ZTE "offline, no cause" ONU must stay down before it counts as a single LOS-like (default 30). */
    public static function downUnknownMinutes(): int
    {
        return self::intSetting('outage_down_unknown_minutes', 'down_unknown_minutes', 30, 1);
    }

    /** ZTE offline-without-cause counts as a LOS-like in shadow (default on; kill switch). */
    public static function downUnknownEnabled(): bool
    {
        return self::flag('outage_down_unknown_enabled', (bool) config('outage.down_unknown_enabled', true));
    }

    /** Record DATA_BLIND episodes and never open/confirm from stale collector / router data (default on). */
    public static function dataBlindEnabled(): bool
    {
        return self::flag('outage_data_blind_enabled', (bool) config('outage.data_blind_enabled', true));
    }

    /** Max weak_rx would-be maintenance tickets per WIB day (worst first); the rest is the weekly list (default 10). */
    public static function weakRxMaxPerDay(): int
    {
        return min(500, self::intSetting('outage_weak_rx_max_per_day', 'weak_rx_max_per_day', 10, 0));
    }

    /** Router counts as "blind" when its newest good PPPoE snapshot is older than this (default 30). */
    public static function routerBlindMinutes(): int
    {
        return self::intSetting('outage_router_blind_minutes', 'router_blind_minutes', 30, 5);
    }

    /** RCA-2 kill switch for the on-demand OLT cause read (read-only commands, shadow). Default on. */
    public static function oltEnrichEnabled(): bool
    {
        return self::flag('outage_olt_enrich_enabled', (bool) config('outage.olt_enrich_enabled', true));
    }

    /** Max enrichment reads per OLT per rolling hour (default 10). */
    public static function oltEnrichPerHour(): int
    {
        return min(60, self::intSetting('outage_olt_enrich_per_hour', 'olt_enrich_per_hour', 10, 1));
    }

    /** Max enrichment reads over ALL OLTs per rolling hour (default 20): the ops worker is a single process. */
    public static function oltEnrichGlobalPerHour(): int
    {
        return min(200, self::intSetting('outage_olt_enrich_global_per_hour', 'olt_enrich_global_per_hour', 20, 1));
    }

    /** One ONU is read at most once per this many minutes (default 30). */
    public static function oltEnrichCacheMinutes(): int
    {
        return min(240, self::intSetting('outage_olt_enrich_cache_minutes', 'olt_enrich_cache_minutes', 30, 5));
    }

    /** RCA-2: MikroTik PPPoE syslog ingestion. Default OFF; the listener idles while it is off. */
    public static function pppoeSyslogEnabled(): bool
    {
        $raw = self::read('pppoe_syslog_enabled');
        if (self::$lastReadFailed) {
            return false;
        }

        return $raw !== null && $raw !== '' && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Allowed syslog senders: "ip=router_id,ip=router_id" (setting pppoe_syslog_sources). Empty = nothing accepted.
     *
     * @return array<string,int> sender ip => router id
     */
    public static function pppoeSyslogSources(): array
    {
        $raw = self::read('pppoe_syslog_sources');
        if (self::$lastReadFailed || ! is_string($raw)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (preg_match('/^([0-9a-fA-F:.]+)=(\d+)$/', $pair, $m) === 1 && (int) $m[2] > 0) {
                $out[$m[1]] = (int) $m[2];
            }
        }

        return $out;
    }

    // ===== RCA-3 (CPE layer) settings: BEGIN =====

    /** New projection fields + cpe_daily + transitions (DB only). 0 = old behaviour (the model fix stays on). Default on. */
    public static function cpeLayerEnabled(): bool
    {
        return self::flag('cpe_layer_enabled', true);
    }

    /**
     * RCA-4 history: record PPP reconnects the CPE reports (genieacs_devices.ppp_uptime_s drop, cpe_events
     * `ppp_reconnect`). Kill switch, default on. Off = nothing is recorded and ppp_uptime_s is not updated (the next comparison after switching on starts from a stale value, which only needs the 6 hour freshness gate).
     */
    public static function cpePppReconnectEnabled(): bool
    {
        return self::flag('cpe_ppp_reconnect_enabled', true);
    }

    /** A GenieACS value older than this is unknown for decisions (default 120, clamped 15..1440). */
    public static function cpeValueMaxAgeMinutes(): int
    {
        return min(1440, max(15, self::cpeInt('cpe_value_max_age_minutes', 120)));
    }

    /** 5-minute watch of symptomatic devices (queues getParameterValues, never a connection request). Default OFF. */
    public static function cpeWatchEnabled(): bool
    {
        return self::cpeOffFlag('cpe_watch_enabled');
    }

    public static function cpeWatchMaxDevices(): int
    {
        return min(100, max(1, self::cpeInt('cpe_watch_max_devices', 30)));
    }

    /** Watch stays on this many hours after the last trigger (default 24). */
    public static function cpeWatchHours(): int
    {
        return min(168, max(1, self::cpeInt('cpe_watch_hours', 24)));
    }

    /** On-demand refresh with a connection request. Default OFF. */
    public static function cpeSummonEnabled(): bool
    {
        return self::cpeOffFlag('cpe_summon_enabled');
    }

    public static function cpeSummonDeviceCooldownMinutes(): int
    {
        return min(60, max(1, self::cpeInt('cpe_summon_device_cooldown_minutes', 2)));
    }

    public static function cpeSummonPerUserHour(): int
    {
        return min(200, max(1, self::cpeInt('cpe_summon_per_user_hour', 20)));
    }

    public static function cpeSummonGlobalHour(): int
    {
        return min(500, max(1, self::cpeInt('cpe_summon_global_hour', 60)));
    }

    public static function cpeSummonConcurrent(): int
    {
        return min(10, max(1, self::cpeInt('cpe_summon_concurrent', 3)));
    }

    /** NBI `timeout` of a summon in ms (default 8000, clamped 2000..15000). The HTTP timeout is this + 3 s. */
    public static function cpeSummonTimeoutMs(): int
    {
        return min(15000, max(2000, self::cpeInt('cpe_summon_timeout_ms', 8000)));
    }

    /** @return array<string,mixed> */
    public static function cpeSnapshot(): array
    {
        return [
            'cpe_layer_enabled' => self::cpeLayerEnabled(),
            'cpe_ppp_reconnect_enabled' => self::cpePppReconnectEnabled(),
            'cpe_value_max_age_minutes' => self::cpeValueMaxAgeMinutes(),
            'cpe_watch_enabled' => self::cpeWatchEnabled(),
            'cpe_watch_max_devices' => self::cpeWatchMaxDevices(),
            'cpe_watch_hours' => self::cpeWatchHours(),
            'cpe_summon_enabled' => self::cpeSummonEnabled(),
            'cpe_summon_device_cooldown_minutes' => self::cpeSummonDeviceCooldownMinutes(),
            'cpe_summon_per_user_hour' => self::cpeSummonPerUserHour(),
            'cpe_summon_global_hour' => self::cpeSummonGlobalHour(),
            'cpe_summon_concurrent' => self::cpeSummonConcurrent(),
            'cpe_summon_timeout_ms' => self::cpeSummonTimeoutMs(),
        ];
    }

    /** Default-OFF switch: a missing value or an unreadable store means off. */
    private static function cpeOffFlag(string $key): bool
    {
        $raw = self::read($key);
        if (self::$lastReadFailed) {
            return false;
        }

        return $raw !== null && $raw !== '' && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private static function cpeInt(string $key, int $default): int
    {
        $raw = self::read($key);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    // ===== RCA-3 (CPE layer) settings: END =====

    /** Boolean setting with a config fallback; an unreadable store keeps the safe default (true = safer here). */
    private static function flag(string $key, bool $default): bool
    {
        $raw = self::read($key);
        if (self::$lastReadFailed || $raw === null || $raw === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function pppoeDownMinutes(): int
    {
        return self::intSetting('outage_pppoe_down_minutes', 'pppoe_down_minutes', 10, 1);
    }

    public static function stateMaxAgeMinutes(): int
    {
        // Capped: a huge limit would defeat the freshness guard.
        return min(30, self::intSetting('outage_state_max_age_minutes', 'state_max_age_minutes', 12, 1));
    }

    public static function recoveryStableMinutes(): int
    {
        return self::intSetting('outage_recovery_stable_minutes', 'recovery_stable_minutes', 30, 1);
    }

    public static function liveTicketCapPerHour(): int
    {
        return self::intSetting('outage_live_ticket_cap_per_hour', 'live_ticket_cap_per_hour', 10, 0);
    }

    /** Max shadow WA summaries per rolling hour (default 4, clamped 1..20). */
    public static function shadowWaMaxPerHour(): int
    {
        return min(20, self::intSetting('outage_shadow_wa_max_per_hour', 'shadow_wa_max_per_hour', 4, 1));
    }

    /** Signal types that have their own flap window. */
    public const FLAP_TYPES = ['mass_los', 'single_los', 'dying_gasp', 'olt_wide'];

    // weak_rx: 7 days after recovery (PR-3); pppoe_down: 2 hours.
    private const FLAP_DEFAULTS = ['mass_los' => 60, 'single_los' => 120, 'dying_gasp' => 120, 'olt_wide' => 30, 'weak_rx' => 10080, 'pppoe_down' => 120];

    /** Flap window (minutes) for a signal type: setting outage_flap_window_{type}_minutes, clamped 5..720 (weak_rx up to its 7 day default). */
    public static function flapWindowMinutes(string $type): int
    {
        $default = self::FLAP_DEFAULTS[$type] ?? 120;
        $raw = self::read('outage_flap_window_'.$type.'_minutes');
        $value = is_numeric($raw) ? (int) $raw : (int) config('outage.flap_window_minutes.'.$type, $default);

        return max(5, min(max(720, $default), $value));
    }

    /**
     * Quiet window as [startHour, endHour] (WIB wall clock). Invalid input falls back to 22-07
     * (owner: night group notifications are held until 07:00).
     *
     * @return array{0:int,1:int}
     */
    public static function quietHours(): array
    {
        $raw = self::read('outage_quiet_hours');
        if (! is_string($raw) || trim($raw) === '') {
            $raw = (string) config('outage.quiet_hours', '22-07');
        }

        if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $raw, $m) === 1) {
            $start = (int) $m[1];
            $end = (int) $m[2];
            if ($start <= 23 && $end <= 23 && $start !== $end) {
                return [$start, $end];
            }
        }

        return [22, 7];
    }

    /** Whether the given hour (0-23, WIB) falls inside the quiet window (handles wrap past midnight). */
    public static function isQuietHour(int $hour): bool
    {
        [$start, $end] = self::quietHours();

        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    /**
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        return [
            'enabled' => self::enabled(),
            'modes' => self::modes(),
            'customer_wa_mode' => self::customerWaMode(),
            'olt_ids' => self::oltIds(),
            'serial_fallback_olt_ids' => self::serialFallbackOltIds(),
            'mass_min_customers' => self::massMinCustomers(),
            'mass_window_minutes' => self::massWindowMinutes(),
            'single_los_minutes' => self::singleLosMinutes(),
            'weak_rx_dbm' => self::weakRxDbm(),
            'weak_rx_days' => self::weakRxDays(),
            'weak_rx_trend_db' => self::weakRxTrendDb(),
            'pppoe_down_minutes' => self::pppoeDownMinutes(),
            'psb_suppress_days' => self::psbSuppressDays(),
            'migration_suppress_days' => self::migrationSuppressDays(),
            'down_unknown_minutes' => self::downUnknownMinutes(),
            'down_unknown_enabled' => self::downUnknownEnabled(),
            'data_blind_enabled' => self::dataBlindEnabled(),
            'weak_rx_max_per_day' => self::weakRxMaxPerDay(),
            'router_blind_minutes' => self::routerBlindMinutes(),
            'olt_enrich_enabled' => self::oltEnrichEnabled(),
            'olt_enrich_per_hour' => self::oltEnrichPerHour(),
            'olt_enrich_global_per_hour' => self::oltEnrichGlobalPerHour(),
            'olt_enrich_cache_minutes' => self::oltEnrichCacheMinutes(),
            'pppoe_syslog_enabled' => self::pppoeSyslogEnabled(),
            'pppoe_syslog_sources' => count(self::pppoeSyslogSources()),
            'state_max_age_minutes' => self::stateMaxAgeMinutes(),
            'recovery_stable_minutes' => self::recoveryStableMinutes(),
            'quiet_hours' => implode('-', array_map(fn (int $h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT), self::quietHours())),
            'live_ticket_cap_per_hour' => self::liveTicketCapPerHour(),
            'shadow_wa_max_per_hour' => self::shadowWaMaxPerHour(),
            'flap_window_minutes' => array_combine(self::FLAP_TYPES, array_map(fn (string $t) => self::flapWindowMinutes($t), self::FLAP_TYPES)),
            'huawei_single_live' => self::huaweiSingleLive(),
            'shadow_log_jid_set' => self::shadowLogJid() !== '',
            'shadow_allow_report_group' => self::shadowAllowReportGroup(),
            'shadow_wa_include_names' => self::shadowWaIncludeNames(),
            'maintenance_windows' => count(self::maintenanceWindows()),
            // RCA-3 BEGIN
            'cpe' => self::cpeSnapshot(),
            // RCA-3 END
        ] + self::diagnosisSnapshot() + self::manualTicketSnapshot();
    }

    private static function intSetting(string $key, string $configKey, int $default, int $min): int
    {
        $raw = self::read($key);
        $value = is_numeric($raw) ? (int) $raw : (int) config('outage.'.$configKey, $default);

        return max($min, $value);
    }

    /** True when the most recent read() could not reach the settings store. */
    private static bool $lastReadFailed = false;

    /** Unix time of the last "settings read failed" warning (rate limit, not once-per-process). */
    private static int $lastWarnAt = 0;

    private const WARN_EVERY_SECONDS = 600;

    /**
     * Fail-safe Setting read: a Redis/DB error must never throw out of a schedule filter. On failure
     * returns null and sets $lastReadFailed so switch-like getters can fail closed.
     */
    private static function read(string $key): mixed
    {
        if (self::$overrides !== [] && array_key_exists($key, self::$overrides)) {
            self::$lastReadFailed = false;

            return self::$overrides[$key];
        }
        try {
            $value = Setting::get($key);
            self::$lastReadFailed = false;

            return $value;
        } catch (\Throwable $e) {
            self::$lastReadFailed = true;
            $now = Carbon::now()->getTimestamp(); // honours Carbon::setTestNow in tests
            // Time-based (once per 10 minutes) so a long-lived worker still logs a second outage.
            if ($now - self::$lastWarnAt >= self::WARN_EVERY_SECONDS) {
                self::$lastWarnAt = $now;
                try {
                    Log::warning('[Outage] settings read failed, failing safe', ['key' => $key, 'exception_class' => $e::class]);
                } catch (\Throwable) {
                    // logging must never throw either
                }
            }

            return null;
        }
    }

    /** @var array<string,mixed> in-memory values that win over the stored settings (back-test flag runs only) */
    private static array $overrides = [];

    /**
     * Run $fn with some settings forced in memory (nothing is written). Used by `outage:diagnosis-backtest --flag=k=v`
     * to replay a stored corpus as if a flag were flipped.
     *
     * @param  array<string,mixed>  $values
     */
    public static function withOverrides(array $values, callable $fn): mixed
    {
        $before = self::$overrides;
        self::$overrides = $values + $before;
        try {
            return $fn();
        } finally {
            self::$overrides = $before;
        }
    }

    /** Test hook: forget the warn rate limit. */
    public static function resetWarnThrottle(): void
    {
        self::$lastWarnAt = 0;
    }

    /**
     * Mode for one signal on one vendor. Huawei single_los has its own gate: the Gadang OLT only knows
     * online/offline (LOS cause unknown), so it stays shadow unless outage_huawei_single_live is on.
     */
    public static function modeFor(string $signal, ?string $vendorFamily): string
    {
        $mode = self::mode($signal);
        if ($mode === 'live' && $vendorFamily === 'huawei' && in_array($signal, ['single_los', 'mass_los'], true) && ! self::huaweiSingleLive()) {
            return 'shadow';
        }

        return $mode;
    }

    /** Explicit extra gate for Huawei individual/mass LOS going live (default false). */
    public static function huaweiSingleLive(): bool
    {
        $raw = self::read('outage_huawei_single_live');
        if (self::$lastReadFailed) {
            return false;
        }

        return $raw !== null && $raw !== '' && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** Technical log group for the shadow summary. Empty (default) = no WA at all. */
    public static function shadowLogJid(): string
    {
        $raw = self::read('outage_shadow_log_jid');
        if (self::$lastReadFailed || ! is_string($raw)) {
            return '';
        }

        return trim($raw);
    }

    /** Explicit opt-in to let the shadow summary go to the Report / ops alert group (default 0). */
    public static function shadowAllowReportGroup(): bool
    {
        $raw = self::read('outage_shadow_allow_report_group');
        if (self::$lastReadFailed) {
            return false;
        }

        return in_array($raw, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * Customer names / codes in the shadow log group message (default 0 = counts and OLT / PON labels only).
     * Fails closed: an unreadable store means no names.
     */
    public static function shadowWaIncludeNames(): bool
    {
        $raw = self::read('outage_shadow_wa_include_names');
        if (self::$lastReadFailed) {
            return false;
        }

        return in_array($raw, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * Maintenance windows [{olt_id, pon_port?, from, to, note}] (ISO datetimes, WIB wall clock if no zone).
     *
     * @return list<array{olt_id:int,pon_port:?string,from:string,to:string}>
     */
    public static function maintenanceWindows(): array
    {
        $raw = self::read('outage_maintenance_windows');
        if (self::$lastReadFailed) {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $w) {
            if (! is_array($w) || ! is_numeric($w['olt_id'] ?? null) || empty($w['from']) || empty($w['to'])) {
                continue;
            }
            $out[] = [
                'olt_id' => (int) $w['olt_id'],
                'pon_port' => isset($w['pon_port']) && $w['pon_port'] !== '' ? (string) $w['pon_port'] : null,
                'from' => (string) $w['from'],
                'to' => (string) $w['to'],
            ];
        }

        return $out;
    }
}
