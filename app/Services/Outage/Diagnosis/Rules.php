<?php

namespace App\Services\Outage\Diagnosis;

use App\Enums\CustomerStatus;
use App\Enums\OutageRootCause as Cause;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;

/**
 * RCA-4 rule table. Rules are evaluated top-down by OutageDiagnoser: the first DECISIVE rule wins, every other
 * matching rule adds a `secondary` cause. A rule gets the context of OutageCorrelator and returns null (no match) or
 *
 *   ['cause' => code, 'confidence' => tinggi|sedang|rendah, 'layer' => string, 'decisive' => bool,
 *    'evidence' => list<item>, 'secondary' => list<code> (optional)]
 *
 * Confidence: tinggi = direct evidence from the authoritative source, fresh (<= 15 min for device data) and no
 * conflict; sedang = older or inferred evidence; rendah = fallback / partial data.
 *
 * Bump RULES_VERSION whenever a rule or a threshold changes (it is stored with every diagnosis).
 */
final class Rules
{
    public const RULES_VERSION = '2026-10-28.1';

    /** Stored while the kill switch diagnosis_rca5_enabled is off (the RCA-4 history rules, before RCA-5). */
    public const RULES_VERSION_LEGACY = '2026-10-21.1';

    // ---- 2026-10-26.1: four deterministic fixes + the abstain gate (plan diagnosis-accuracy 4.3) ----

    /** A day-over-day RX drop of this many dB (daily medians) is an optical step. */
    public const RX_STEP_DB = 3.0;

    /** RX falling steadily over 3 daily medians by this many dB (what weak_rx_trend would catch). */
    public const RX_DECLINE_DB = 2.0;

    /**
     * ONUs that went down more than this many hours before this one are not witnesses of its PON event. A cut drops
     * its ONUs within minutes; 2 h leaves room for staggered detector reads without counting a standing dead ONU.
     */
    public const PON_WITNESS_HOURS = 2;

    /** One dying gasp inside this many hours, with no LOS after it, is a power cut at home. */
    public const DG_RECENT_HOURS = 6;

    /** ONU drops of cause LOS / not recorded in 24 h that, with a weak RX, make an optical fault. */
    public const OPTICAL_DROPS_MIN = 2;

    /** Sources of an ONU drop that come from the OLT itself (the 5 minute poll included: its phase code is exact). */
    private const ONU_OLT_SOURCES = ['olt_poll', 'olt_detail', 'olt_live', 'huawei_info'];

    /** Causes the abstain gate does not touch. */
    public const GATE_EXEMPT = ['isolir_admin', 'unknown', 'data_blind'];

    // ---- RCA-5 (2026-10-22.1): isolir vs physical fault, PON cut only with several ONUs down NOW ----

    /** An ONU that has been down this long is not a "fresh" physical fault for an isolated customer (hours). */
    public const ISOLIR_PHYSICAL_MAX_HOURS = 72;

    /** PON cut: at least this many ONUs of the PON down now (the customer included). */
    public const PON_CUT_MIN_DOWN = 3;

    /** A PON with this many known ONUs or fewer is "small": 2 down and at least half of them. */
    public const PON_SMALL_MAX = 4;

    public const PON_SMALL_SHARE = 0.5;

    /** ODP cut: at least this many ONUs of the same ODP down now. */
    public const ODP_CUT_MIN_DOWN = 2;

    /** ONUs that dropped within this many minutes of each other dropped "together". */
    public const TOGETHER_MINUTES = 15;

    /** Other ONUs of the PON that dropped together with this one and are online again: a recovered PON event. */
    public const PON_RECOVERED_MIN = 2;

    /** An ONU down longer than this (or with no known start) is a leftover, not a witness of a PON cut (days). */
    public const PON_KNOWN_DAYS = 7;

    /** Share of the known ONUs down now that makes a whole PON. */
    public const PON_WHOLE_SHARE = 0.8;

    /** "PON leftover": at most this many ONUs down now (this one included) can be the leftovers of a recovered event. */
    public const PON_LEFTOVER_MAX_DOWN = 4;

    /**
     * The PON had an event (2+ ONUs that dropped with this one are online again) and the few ONUs still down are
     * clearly fewer than the recovered ones (recovered >= 2 x the others down): leftovers, not a PON cut. Never during
     * a big event (more than PON_LEFTOVER_MAX_DOWN down now).
     */
    private static function ponLeftover(array $c): bool
    {
        $down = (int) ($c['pon']['down_now'] ?? 0);
        $recovered = (int) ($c['pon']['recovered'] ?? 0);

        return $recovered >= self::PON_RECOVERED_MIN && $down <= self::PON_LEFTOVER_MAX_DOWN && $recovered >= 2 * max(0, $down - 1);
    }

    public const HIGH = 'tinggi';

    public const MID = 'sedang';

    public const LOW = 'rendah';

    public const REBOOT_LOOP_MIN = 3;

    public const AREA_POWER_SHARE = 0.20;

    public const AREA_POWER_MIN = 3;

    public const PPPOE_FLAP_MIN = 4;

    public const WLAN_FLAP_MIN = 2;

    // ---- history rules (2026-10-21.1): the rolling last 24 h, the line is up (or down) NOW ----

    /** Window of the history block, hours. */
    public const HISTORY_HOURS = 24;

    /** A PPP drop this soon after an ONU drop / ONU restart is explained by it (minutes). */
    public const EXPLAIN_MINUTES = 10;

    /** ONU drops (not area wide) in 24 h that make a cause: LOS x2, dying gasp x2; a cause-less drop only x3 (no guess). */
    public const ONU_FLAP_LOS_MIN = 2;

    public const ONU_FLAP_DG_MIN = 2;

    public const ONU_FLAP_OTHER_MIN = 3;

    /** Unexplained PPP drops in 24 h (ONU stable) that make PPPOE_FLAP. */
    public const PPPOE_FLAP_24H_MIN = 3;

    /** WiFi transitions in 24 h (no restart near them) that make WLAN_FLAP. */
    public const WLAN_FLAP_24H_MIN = 3;

    /** Sources of an ONU drop that come from the OLT's own history / a live read (not the 5 minute poll). */
    private const ONU_AUTHORITATIVE = ['olt_detail', 'olt_live', 'huawei_info'];

    /** Empty history block: a context built without one (older tests, a failed read) behaves as "nothing known". */
    private const HIST_EMPTY = [
        'onu_drops' => [], 'ppp_drops' => [], 'ppp_source' => 'none', 'ppp_log' => false, 'onu_log' => false,
        'ppp_last' => null, 'rx' => ['dbm' => null, 'at' => null, 'old' => false], 'ticket_rx_complaint' => false,
    ];

    public const WEAK_CLIENT_DBM = -75;

    public const SILENT_DOWN_MINUTES = 30;

    /** A router session may be this much younger / older than the CPE uptime before it counts as "older". */
    public const SESSION_TOLERANCE_SECONDS = 120;

    /**
     * The rule table: id => [layer, run]. `needs_data` rules are listed but never run (no data source in the app).
     *
     * @return array<string,array{layer:string,needs_data?:bool,run:?callable}>
     */
    public static function table(): array
    {
        return [
            'isolir_admin' => ['layer' => 'admin', 'run' => [self::class, 'isolirAdmin']],
            'core_router' => ['layer' => 'router', 'run' => [self::class, 'coreRouter']],
            'upstream' => ['layer' => 'router', 'needs_data' => true, 'run' => null],
            'olt_down' => ['layer' => 'olt', 'run' => [self::class, 'oltDown']],
            'pon_odp_cut' => ['layer' => 'pon', 'run' => [self::class, 'ponOrOdpCut']],
            'onu_offline' => ['layer' => 'onu', 'run' => [self::class, 'onuOffline']],
            'onu_reboot' => ['layer' => 'cpe', 'run' => [self::class, 'onuReboot']],
            'onu_history' => ['layer' => 'onu', 'run' => [self::class, 'onuHistory']],
            'pppoe_stuck' => ['layer' => 'pppoe', 'run' => [self::class, 'pppoeStuck']],
            'pppoe_down' => ['layer' => 'pppoe', 'run' => [self::class, 'pppoeDown']],
            'pppoe_history' => ['layer' => 'pppoe', 'run' => [self::class, 'pppoeHistory']],
            'wlan_down' => ['layer' => 'cpe', 'run' => [self::class, 'wlanDown']],
            'wlan_flap' => ['layer' => 'cpe', 'run' => [self::class, 'wlanFlap']],
            'wifi_client' => ['layer' => 'cpe', 'run' => [self::class, 'wifiClient']],
            'optical_degrade' => ['layer' => 'onu', 'run' => [self::class, 'opticalDegrade']],
            'blind_or_unknown' => ['layer' => 'data', 'run' => [self::class, 'blindOrUnknown']],
        ];
    }

    // ------------------------------------------------------------------ helpers

    private static function fresh(?Carbon $at, Carbon $now, int $minutes = 15): bool
    {
        return $at !== null && $at->gte($now->copy()->subMinutes($minutes));
    }

    /** ONU phase is a known "down" one (the collector answer is fresh). */
    private static function onuDown(array $c): bool
    {
        return $c['onu']['fresh'] && in_array($c['onu']['phase'], ['los', 'dying_gasp', 'offline'], true);
    }

    private static function onuUp(array $c): bool
    {
        return $c['onu']['fresh'] && $c['onu']['phase'] === 'online';
    }

    private static function oltName(array $c): string
    {
        return (string) ($c['olt']['name'] ?? 'OLT');
    }

    private static function routerName(array $c): string
    {
        return (string) ($c['router']['name'] ?? 'router');
    }

    /** @return array<string,mixed> */
    private static function r(string $cause, string $conf, string $layer, bool $decisive, array $evidence, array $secondary = []): array
    {
        return ['cause' => $cause, 'confidence' => $conf, 'layer' => $layer, 'decisive' => $decisive, 'evidence' => $evidence, 'secondary' => $secondary];
    }

    /**
     * Neutral supporting lines about the layers that are fine (router / ONU / session), added after the rule's own
     * evidence so the panel shows what was ruled out.
     *
     * @return list<array<string,mixed>>
     */
    public static function supporting(array $c): array
    {
        $now = $c['now'];
        $out = [];
        if ($c['router']['id'] !== null && $c['router']['name'] !== null && $c['router']['blind'] === null) {
            $uptime = $c['pppoe']['router_session_uptime_s'] ?? null;
            if ($c['pppoe']['online'] === true && $uptime !== null) {
                $out[] = DiagnosisEvidence::item('router', 'router_live', $now, 'router_ok_since', ['router' => self::routerName($c), 'since' => DiagnosisEvidence::clock($now->copy()->subSeconds($uptime))]);
            } else {
                $out[] = DiagnosisEvidence::item('router', 'router_db', $c['router']['health_checked_at'], 'router_ok', ['router' => self::routerName($c)], (bool) $c['router']['health_fresh']);
            }
        }
        if (self::onuUp($c)) {
            $out[] = DiagnosisEvidence::item('onu', 'olt_poll', $c['onu']['collected_at'], 'onu_online');
        }
        $sinceShown = $c['router']['id'] !== null && $c['router']['name'] !== null && $c['router']['blind'] === null && ($c['pppoe']['router_session_uptime_s'] ?? null) !== null && $c['pppoe']['online'] === true;
        if ($c['pppoe']['online'] === true && ! $sinceShown) {
            $out[] = DiagnosisEvidence::item('pppoe', (string) ($c['pppoe']['source'] ?? 'poll'), $c['pppoe']['at'], 'ppp_up', [], (bool) $c['pppoe']['fresh']);
        }

        return $out;
    }

    /**
     * History lines of the last 24 h (one summary line per kind, never one per event) followed by the current RX line.
     * Added by OutageDiagnoser after the rule's own evidence and before supporting(). A billing / router / OLT / PON
     * answer explains everything below it, so those get no history lines (only the RX line).
     *
     * @return list<array<string,mixed>>
     */
    public static function historyEvidence(array $c, string $primaryId, string $primaryCause): array
    {
        if (! is_array($c['history'] ?? null)) {
            return []; // a context without a history block (the read failed): say nothing rather than guess
        }
        $h = self::hist($c);
        $skipHistory = in_array($primaryId, ['isolir_admin', 'core_router', 'olt_down', 'pon_odp_cut'], true) || $primaryCause === Cause::DataBlind->value;

        return array_merge($skipHistory ? [] : self::historyLines($c, $h), self::rxLines($c, $h));
    }

    /** @return list<array<string,mixed>> */
    private static function historyLines(array $c, array $h): array
    {
        $out = [];
        $onuSource = fn (array $drops) => count(array_filter($drops, fn (array $d) => in_array($d['source'], self::ONU_AUTHORITATIVE, true))) > 0 ? 'olt_detail' : 'olt_poll';
        $none = true;

        foreach (['los' => 'hist_onu_los', 'dying_gasp' => 'hist_onu_dg'] as $cause => $key) {
            $drops = self::onuDropsOf($h, $cause);
            if ($drops !== []) {
                $none = false;
                $out[] = DiagnosisEvidence::item('onu', $onuSource($drops), self::lastAt($drops), $key, ['n' => count($drops), 'time' => DiagnosisEvidence::clock(self::lastAt($drops))]);
            }
        }
        $other = array_values(array_filter(self::onuDropsOf($h), fn (array $d) => ! in_array($d['cause'], ['los', 'dying_gasp'], true)));
        if ($other !== []) {
            $none = false;
            $out[] = DiagnosisEvidence::item('onu', $onuSource($other), self::lastAt($other), 'hist_onu_other', ['n' => count($other), 'time' => DiagnosisEvidence::clock(self::lastAt($other))]);
        }
        $area = array_values(array_filter($h['onu_drops'], fn (array $d) => $d['area']));
        if ($area !== []) {
            $none = false;
            $out[] = DiagnosisEvidence::item('pon', 'detector', self::lastAt($area), 'hist_area', ['time' => DiagnosisEvidence::clock(self::lastAt($area))]);
        }

        $boots = (int) ($c['cpe']['boots_24h'] ?? 0);
        if ($boots > 0) {
            $none = false;
            if ($boots <= 2) { // 3 or more is the reboot_loop line of its own rule
                $out[] = DiagnosisEvidence::item('cpe', 'cpe_events', end($c['cpe']['boot_times']) ?: null, 'hist_boot', ['n' => $boots]);
            }
        }

        $ppp = array_values(array_filter($h['ppp_drops'], fn (array $d) => ! $d['explained']));
        if ($h['ppp_drops'] !== []) {
            $none = false;
        }
        if (count($ppp) >= 2) {
            $last = self::lastAt($ppp);
            $out[] = DiagnosisEvidence::item('pppoe', $h['ppp_source'] === 'syslog' ? 'syslog' : 'poll', $last, 'hist_ppp', ['n' => count($ppp), 'time' => DiagnosisEvidence::clock($last)]);
            if (is_array($h['ppp_last']) && $h['ppp_last']['at'] instanceof Carbon) {
                $out[] = DiagnosisEvidence::item('pppoe', (string) ($h['ppp_last']['source'] ?? 'syslog'), $h['ppp_last']['at'], 'hist_ppp_last', ['time' => DiagnosisEvidence::clock($h['ppp_last']['at']), 'reason' => self::reasonText((string) $h['ppp_last']['reason'])]);
            }
        }

        $wlan = max((int) ($c['cpe']['wlan_transitions_24h'] ?? 0), 0);
        if ($wlan >= 2) {
            $none = false;
            $out[] = DiagnosisEvidence::item('cpe', 'cpe_events', null, 'hist_wlan', ['n' => $wlan]);
        }

        $cpePresent = (bool) ($c['cpe']['present'] ?? false);
        if ($none && $h['onu_log'] && ($h['ppp_log'] || $cpePresent)) {
            $out[] = DiagnosisEvidence::item('data', 'rules', $c['now'], 'hist_none');
        } elseif ($h['ppp_drops'] === [] && ! $h['ppp_log'] && ! $cpePresent && $h['ppp_source'] === 'none') {
            $out[] = DiagnosisEvidence::item('pppoe', 'rules', $c['now'], 'hist_no_log');
        }
        if (! $h['onu_log'] && ($c['olt']['id'] ?? null) !== null && $h['onu_drops'] === []) {
            $out[] = DiagnosisEvidence::item('onu', 'rules', $c['now'], 'hist_no_onu_log');
        }

        return $out;
    }

    /** Router disconnect reason as CS reads it: hung up = the ONU side, keepalive = the line went silent. */
    private static function reasonText(string $reason): string
    {
        $r = strtolower(trim($reason));
        if (str_contains($r, 'hung')) {
            return 'dari ONU';
        }
        if (str_contains($r, 'keepalive') || str_contains($r, 'not responding') || str_contains($r, 'timeout')) {
            return 'sambungan hilang';
        }

        return mb_substr(trim($reason), 0, 20);
    }

    /** @return list<array<string,mixed>> */
    private static function rxLines(array $c, array $h): array
    {
        $rx = $h['rx'];
        if ($rx['dbm'] === null) {
            return [];
        }
        $dbm = DiagnosisEvidence::dbm((float) $rx['dbm']);
        $at = $rx['at'] instanceof Carbon ? $rx['at'] : null;
        $weak = (bool) ($c['onu']['rx_weak'] ?? false);
        if ($rx['old']) {
            return [DiagnosisEvidence::item('onu', 'olt_poll', $at, 'rx_old', ['dbm' => $dbm, 'time' => DiagnosisEvidence::clock($at)], false)];
        }
        if ($weak) {
            return [DiagnosisEvidence::item('onu', 'olt_poll', $at, 'rx_weak', ['dbm' => $dbm, 'limit' => DiagnosisEvidence::dbm(OutageSettings::weakRxDbm())])];
        }
        $out = [$at === null
            ? DiagnosisEvidence::item('onu', 'olt_poll', null, 'rx_ok_nodate', ['dbm' => $dbm])
            : DiagnosisEvidence::item('onu', 'olt_poll', $at, 'rx_ok', ['dbm' => $dbm, 'time' => DiagnosisEvidence::clock($at)])];
        if ($h['ticket_rx_complaint']) {
            $out[] = DiagnosisEvidence::item('onu', 'rules', $c['now'], 'rx_complaint_ok');
        }

        return $out;
    }

    // ------------------------------------------------------------------ 1. billing / admin

    /** The RCA-5 kill switch (diagnosis_rca5_enabled) as the correlator copied it into the context; absent = on. */
    private static function rca5(array $c): bool
    {
        return (bool) ($c['rca5'] ?? true);
    }

    /**
     * P of the plan: a fresh physical fault. The ONU is fresh down with a LOS / link drop / dying gasp that started
     * within ISOLIR_PHYSICAL_MAX_HOURS (an unknown start does not count), or its own history shows
     * repeated drops (onu_history). An ONU that has been dead for days after the customer was isolated is NOT one.
     */
    private static function physicalFault(array $c): bool
    {
        $o = $c['onu'];
        if (self::onuDown($c)) {
            $losLike = in_array($o['phase'], ['los', 'dying_gasp'], true) || in_array($o['cause'], ['los', 'link_drop', 'dying_gasp'], true);
            if ($losLike) {
                // start: the tracker phase start, else the hint of the correlator (live last_offline_at, or the newest
                // own drop of the last 24 h). A start that stays unknown is NOT a fresh fault.
                $since = $o['since'] ?? $o['since_hint'] ?? null;
                if ($since !== null && $since->gte($c['now']->copy()->subHours(self::ISOLIR_PHYSICAL_MAX_HOURS))) {
                    return true;
                }
            }
        }

        return self::onuHistory($c, false) !== null;
    }

    /**
     * 2026-10-26.1: the newest dying gasp of the last DG_RECENT_HOURS from the OLT itself (poll / detail / live /
     * huawei_info), when no LOS drop follows it. ONE is enough here; the history rule needs two.
     */
    private static function recentDyingGasp(array $c): ?Carbon
    {
        if (! self::rca5($c)) {
            return null;
        }
        $from = $c['now']->copy()->subHours(self::DG_RECENT_HOURS);
        $drops = self::hist($c)['onu_drops'];
        $dg = null;
        foreach ($drops as $d) {
            if ($d['cause'] === 'dying_gasp' && in_array($d['source'], self::ONU_OLT_SOURCES, true) && $d['at']->gte($from) && ($dg === null || $d['at']->gt($dg))) {
                $dg = $d['at'];
            }
        }
        if ($dg === null) {
            return null;
        }
        foreach ($drops as $d) {
            if ($d['cause'] === 'los' && $d['at']->gt($dg)) {
                return null; // a cable drop came after it
            }
        }

        return $dg;
    }

    /** The ONU restarted after the dying gasp (a boot event, or an uptime shorter than the time since); unknown counts as yes. */
    private static function restartedAfter(array $c, Carbon $dgAt): bool
    {
        $cpe = $c['cpe'];
        if (! ($cpe['present'] ?? false)) {
            return true;
        }
        foreach ((array) ($cpe['boot_times'] ?? []) as $b) {
            if ($b instanceof Carbon && $b->getTimestamp() >= $dgAt->getTimestamp() - 120) {
                return true;
            }
        }
        $uptime = $cpe['uptime_now_s'] ?? null;

        return $uptime === null || $uptime <= ($c['now']->getTimestamp() - $dgAt->getTimestamp()) + 300;
    }

    public static function isolirAdmin(array $c): ?array
    {
        if (! self::rca5($c)) {
            return self::isolirAdminLegacy($c);
        }
        $a = $c['admin'];
        $now = $c['now'];
        $status = (string) $a['status'];
        $label = mb_strtolower(CustomerStatus::tryFrom($status)?->label() ?? $status);
        $terminated = $status === CustomerStatus::Terminated->value;
        $billing = (bool) $a['billing'] && ! $terminated;
        $secret = (string) ($a['secret_state'] ?? (($a['secret_isolir'] ?? false) ? 'isolated' : 'none'));
        $db = (bool) $a['db_isolir'];

        if ($terminated) {
            return self::r(Cause::IsolirAdmin->value, self::HIGH, 'admin', true, [DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_status', ['status' => $label])]);
        }
        if (! $billing && $secret !== 'isolated' && ! ($db && $secret !== 'normal')) {
            return null;
        }
        if (self::physicalFault($c)) {
            // not decisive: the ONU rules decide, the isolir note rides along in the first lines and in "Bisa juga"
            $word = $status === CustomerStatus::Suspended->value ? 'cuti' : 'diisolir';

            return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', false, [DiagnosisEvidence::item('admin', $secret === 'isolated' ? 'router_live' : 'billing', $now, 'isolir_also', ['status' => $word])]);
        }
        if ($secret === 'isolated') {
            $ev = [];
            if ($billing) {
                $ev[] = DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_status', ['status' => $label]);
            }
            $ev[] = DiagnosisEvidence::item('admin', 'router_live', $now, 'isolir_secret');
            $hit = self::r(Cause::IsolirAdmin->value, self::HIGH, 'admin', true, $ev);
            if (! $billing) {
                $hit['evidence'][] = DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_secret_active');
                $hit['action'] = 'buka_isolir';
            }

            return $hit;
        }
        if ($billing) {
            if ($db && $secret === 'normal') {
                return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', false, [DiagnosisEvidence::item('admin', 'router_live', $now, 'isolir_router_open')]);
            }
            $status = DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_status', ['status' => $label]);
            if (! $db) {
                // 2026-10-26.1: billing alone is no proof. Decisive only when the router side backs it: a fresh poll or
                // live read shows the session down. Otherwise a note ("Bisa juga") and the ONU / PPP rules decide.
                $sessionDown = ($c['pppoe']['online'] ?? null) === false && (bool) ($c['pppoe']['fresh'] ?? false);
                if (! $sessionDown) {
                    return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', false, [DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_billing_only', ['status' => $label])]);
                }

                return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', true, [$status, DiagnosisEvidence::item('admin', 'rules', $now, 'isolir_method_unknown')]);
            }
            if (self::onuUp($c)) {
                return self::r(Cause::IsolirAdmin->value, self::HIGH, 'admin', true, [$status, DiagnosisEvidence::item('onu', 'olt_poll', $c['onu']['collected_at'], 'onu_online')]);
            }
            $o = $c['onu'];
            $start = $o['since'] ?? $o['since_hint'] ?? null;
            $line = match (true) {
                ! self::onuDown($c) => DiagnosisEvidence::item('onu', 'rules', $now, 'isolir_check_line', [], false),
                $start === null => DiagnosisEvidence::item('onu', 'olt_poll', $o['collected_at'], 'isolir_onu_down_nodate'), // start unknown: no time that reads like "just died"
                default => DiagnosisEvidence::item('onu', 'olt_poll', $start, 'isolir_onu_down', ['time' => DiagnosisEvidence::clock($start)]),
            };

            return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', true, [$status, $line]);
        }

        // billing active, the profile in the database says isolir, no live read: only a hint
        return self::r(Cause::IsolirAdmin->value, self::LOW, 'admin', true, [
            DiagnosisEvidence::item('admin', 'billing', null, 'isolir_profile', [], false),
            DiagnosisEvidence::item('onu', 'rules', $now, 'isolir_check_line', [], false),
        ]);
    }

    /** The RCA-4 isolir rule (2026-10-21.1), used while diagnosis_rca5_enabled is off. */
    private static function isolirAdminLegacy(array $c): ?array
    {
        $a = $c['admin'];
        $now = $c['now'];
        if ($a['billing']) {
            $label = mb_strtolower(CustomerStatus::tryFrom((string) $a['status'])?->label() ?? (string) $a['status']);

            return self::r(Cause::IsolirAdmin->value, self::HIGH, 'admin', true, [DiagnosisEvidence::item('admin', 'billing', $now, 'isolir_status', ['status' => $label])]);
        }
        if ($a['secret_isolir']) {
            return self::r(Cause::IsolirAdmin->value, self::HIGH, 'admin', true, [DiagnosisEvidence::item('admin', 'router_live', $now, 'isolir_secret')]);
        }
        if ($a['db_isolir']) {
            return self::r(Cause::IsolirAdmin->value, self::MID, 'admin', true, [DiagnosisEvidence::item('admin', 'billing', null, 'isolir_profile', [], false)]);
        }

        return null;
    }

    // ------------------------------------------------------------------ 2. router

    public static function coreRouter(array $c): ?array
    {
        $r = $c['router'];
        if (! $r['offline'] || ! $r['all_gone']) {
            return null;
        }

        return self::r(Cause::CoreRouter->value, $r['health_fresh'] ? self::HIGH : self::MID, 'router', true, [
            DiagnosisEvidence::item('router', 'router_db', $r['health_checked_at'], 'router_offline', ['router' => self::routerName($c)], (bool) $r['health_fresh']),
        ]);
    }

    // ------------------------------------------------------------------ 3. OLT

    public static function oltDown(array $c): ?array
    {
        $wide = $c['olt']['wide'];
        if ($wide === null || self::onuUp($c)) {
            return null;
        }
        $fresh = self::fresh($wide['seen'], $c['now']);

        return self::r(Cause::OltDown->value, $fresh ? self::HIGH : self::MID, 'olt', true, [
            DiagnosisEvidence::item('olt', 'detector', $wide['since'], 'olt_wide', ['olt' => self::oltName($c), 'time' => DiagnosisEvidence::clock($wide['since'])], $fresh),
        ]);
    }

    // ------------------------------------------------------------------ 4. PON / ODP

    /**
     * Enough ONUs of the PON are down NOW (the customer included, leftovers not counted) to call it a PON cut:
     * 3 or more; a small PON (4 known ONUs or fewer) needs 2 and at least half of them; a PON with one known ONU never.
     */
    public static function ponEnough(int $down, int $known): bool
    {
        if ($known <= 1 || $down < 2) {
            return false;
        }
        if ($down >= self::PON_CUT_MIN_DOWN) {
            return true;
        }

        return $known <= self::PON_SMALL_MAX && ($down / $known) >= self::PON_SMALL_SHARE;
    }

    public static function ponOrOdpCut(array $c): ?array
    {
        if (! self::rca5($c)) {
            return self::ponOrOdpCutLegacy($c);
        }
        $m = $c['pon']['mass'];
        if ($m === null || ! $m['includes'] || self::onuUp($c)) {
            return null;
        }
        $o = $c['onu'];
        $down = (int) ($c['pon']['down_now'] ?? 0);
        $known = (int) ($c['pon']['known_total'] ?? 0);
        $dyingGasp = $o['phase'] === 'dying_gasp' || $o['cause'] === 'dying_gasp';
        $ownFresh = self::onuDown($c) && ! $dyingGasp;
        // an own ONU that is stale (no fresh read) only follows a CONFIRMED mass_los; then the others must be enough
        $ownStale = ! $o['fresh'] && ! $dyingGasp && ($m['confirmed'] ?? false);
        if (! $ownFresh && ! $ownStale) {
            return null;
        }
        $fresh = self::fresh($m['seen'], $c['now']);
        $time = DiagnosisEvidence::clock($m['since']);
        $whole = $known > 0 && ($down / $known) >= self::PON_WHOLE_SHARE;
        $odpShare = $c['cluster']['odp_share'];
        $odpDown = (int) ($c['cluster']['odp_down_now'] ?? 0);
        if (! $whole && $c['cluster']['odp'] !== null && $odpShare !== null && $odpShare >= 0.8 && $c['cluster']['odp_count'] >= 2 && $odpDown >= self::ODP_CUT_MIN_DOWN) {
            return self::r(Cause::OdpCut->value, self::MID, 'cluster', true, [
                DiagnosisEvidence::item('cluster', 'detector', $m['since'], 'odp_cut', ['odp' => $c['cluster']['odp'], 'time' => $time], $fresh),
            ]);
        }
        if (! self::ponEnough($down, $known) || self::ponLeftover($c)) {
            return null;
        }
        $high = $whole && $fresh && $ownFresh && ($c['olt']['family'] ?? null) === 'zte';

        return self::r(Cause::PonCut->value, $high ? self::HIGH : self::MID, 'pon', true, [
            DiagnosisEvidence::item('pon', 'detector', $m['since'], 'pon_cut', ['count' => $down, 'total' => $known, 'time' => $time], $fresh),
        ]);
    }

    /** The RCA-4 PON / ODP rule (2026-10-21.1), used while diagnosis_rca5_enabled is off. */
    private static function ponOrOdpCutLegacy(array $c): ?array
    {
        $m = $c['pon']['mass'];
        if ($m === null || ! $m['includes'] || self::onuUp($c)) {
            return null;
        }
        $fresh = self::fresh($m['seen'], $c['now']);
        $time = DiagnosisEvidence::clock($m['since']);
        $whole = $m['whole'] || ($m['ratio'] !== null && $m['ratio'] >= OutageDetector::MASS_WHOLE_PON_RATIO);
        $odpShare = $c['cluster']['odp_share'];
        if (! $whole && $c['cluster']['odp'] !== null && $odpShare !== null && $odpShare >= 0.8 && $c['cluster']['odp_count'] >= 2) {
            return self::r(Cause::OdpCut->value, self::MID, 'cluster', true, [
                DiagnosisEvidence::item('cluster', 'detector', $m['since'], 'odp_cut', ['odp' => $c['cluster']['odp'], 'time' => $time], $fresh),
            ]);
        }

        return self::r(Cause::PonCut->value, ($whole && $fresh) ? self::HIGH : self::MID, 'pon', true, [
            DiagnosisEvidence::item('pon', 'detector', $m['since'], 'pon_cut', ['count' => $m['count'], 'total' => $m['total'] ?: '?', 'time' => $time], $fresh),
        ]);
    }

    // ------------------------------------------------------------------ 5. ONU offline

    public static function onuOffline(array $c): ?array
    {
        $o = $c['onu'];
        $now = $c['now'];
        if ($o['never_registered']) {
            return self::r(Cause::Unknown->value, self::LOW, 'onu', true, [DiagnosisEvidence::item('onu', (string) ($o['cause_source'] ?? 'olt_detail'), $o['cause_at'], 'onu_never', [], (bool) $o['cause_fresh'])]);
        }
        if (! self::onuDown($c)) {
            return null;
        }
        $olt = self::oltName($c);
        $cause = $o['cause'];
        $sinceClock = DiagnosisEvidence::clock($o['since'] ?? $o['cause_at']);
        $source = (string) ($o['cause_source'] ?? 'olt_poll');

        if ($o['phase'] === 'los' || in_array($cause, ['los', 'link_drop'], true)) {
            $ev = [DiagnosisEvidence::item('onu', $source, $o['since'] ?? $o['cause_at'], 'onu_los', ['time' => $sinceClock, 'olt' => $olt], (bool) $o['cause_fresh'])];
            if (self::rca5($c)) {
                $down = (int) ($c['pon']['down_now'] ?? 0);
                $others = max(0, $down - 1);
                if (self::ponEnough($down, (int) ($c['pon']['known_total'] ?? 0)) && ! self::ponLeftover($c)) {
                    $ev[] = DiagnosisEvidence::item('pon', 'olt_poll', $o['collected_at'], 'onu_los_many', ['n' => $others], (bool) $o['fresh']);

                    return self::r(Cause::PonCut->value, self::MID, 'pon', true, $ev);
                }
                $recovered = (int) ($c['pon']['recovered'] ?? 0);
                if ($recovered >= self::PON_RECOVERED_MIN) {
                    $ev[] = DiagnosisEvidence::item('pon', 'olt_poll', $c['pon']['event_at'] ?? null, 'pon_recovered', ['time' => DiagnosisEvidence::clock($c['pon']['event_at'] ?? null), 'n' => $recovered], (bool) $o['fresh']);

                    return self::r(Cause::DropLos->value, self::MID, 'onu', true, $ev);
                }
                $ev[] = DiagnosisEvidence::item('pon', 'olt_poll', $o['collected_at'], $others === 0 ? 'onu_los_none' : 'onu_los_alone', ['n' => $others], (bool) $o['fresh']);

                return self::r(Cause::DropLos->value, $o['cause_fresh'] ? self::HIGH : self::MID, 'onu', true, $ev);
            }
            $others = (int) $c['pon']['los_others'];
            if ($others >= OutageDetector::MASS_RECORD_MIN) {
                $ev[] = DiagnosisEvidence::item('pon', 'olt_poll', $o['collected_at'], 'onu_los_many', ['n' => $others], (bool) $o['fresh']);

                return self::r(Cause::PonCut->value, self::MID, 'pon', true, $ev);
            }
            $ev[] = DiagnosisEvidence::item('pon', 'olt_poll', $o['collected_at'], $others === 0 ? 'onu_los_none' : 'onu_los_alone', ['n' => $others], (bool) $o['fresh']);

            return self::r(Cause::DropLos->value, $o['cause_fresh'] ? self::HIGH : self::MID, 'onu', true, $ev);
        }

        if ($o['phase'] === 'dying_gasp' || $cause === 'dying_gasp') {
            $ev = [DiagnosisEvidence::item('onu', $source, $o['since'] ?? $o['cause_at'], 'onu_dg', ['time' => $sinceClock, 'olt' => $olt], (bool) $o['cause_fresh'])];
            $secondary = [];
            if ($o['since'] !== null && $o['since']->lte($now->copy()->subHours(OutageDetector::DG_LONG_HOURS))) {
                $secondary[] = Cause::CpeDead->value;
                $ev[] = DiagnosisEvidence::item('onu', 'olt_poll', $o['since'], 'onu_dg_long');
            }

            return self::r(Cause::PowerOff->value, self::HIGH, 'onu', true, $ev, $secondary);
        }

        // 2026-10-26.1: offline without a recorded cause now, but one dying gasp from the OLT in the last 6 h and no LOS since
        if (($dgAt = self::recentDyingGasp($c)) !== null) {
            return self::r(Cause::PowerOff->value, self::MID, 'onu', true, [DiagnosisEvidence::item('onu', 'olt_poll', $dgAt, 'onu_dg_recent', ['time' => DiagnosisEvidence::clock($dgAt), 'olt' => $olt])]);
        }

        // Offline without a cause: a dead ONU only after a while, and only when it also stays silent towards TR-069.
        $since = $o['since'] ?? null;
        if ($since === null || $since->gt($now->copy()->subMinutes(self::SILENT_DOWN_MINUTES))) {
            return null;
        }
        $cpe = $c['cpe'];
        $informedSince = $cpe['present'] && $cpe['last_inform_at'] !== null && $cpe['last_inform_at']->gte($since);
        if ($informedSince) {
            return null; // the ONU still talks to the ACS: the link is down, not the device
        }

        return self::r(Cause::CpeDead->value, $cpe['present'] ? self::MID : self::LOW, 'onu', true, [
            DiagnosisEvidence::item('onu', 'olt_poll', $since, 'onu_silent', ['time' => DiagnosisEvidence::clock($since)], (bool) $o['fresh']),
        ]);
    }

    // ------------------------------------------------------------------ 6. ONU online, restarting

    public static function onuReboot(array $c): ?array
    {
        if (self::onuDown($c)) {
            return null;
        }
        $cpe = $c['cpe'];
        if (! $cpe['present'] || $cpe['boots_24h'] < self::REBOOT_LOOP_MIN) {
            return null;
        }
        $share = $c['pon']['boot_share'] ?? null;
        if ($share !== null && $share['count'] >= self::AREA_POWER_MIN && $share['share'] >= self::AREA_POWER_SHARE) {
            return self::r(Cause::PowerOff->value, self::MID, 'pon', true, [
                DiagnosisEvidence::item('pon', 'cpe_events', $share['at'], 'area_power', ['count' => $share['count'], 'total' => $share['total'], 'time' => DiagnosisEvidence::clock($share['at'])]),
            ]);
        }
        // Every restart came with a dying gasp: the power at home goes off (not a faulty ONU)
        $h = self::hist($c);
        $dgTimes = array_map(fn (array $d) => $d['at'], array_filter($h['onu_drops'], fn (array $d) => $d['cause'] === 'dying_gasp'));
        // ... or the internet session was gone long BEFORE the restart (switched off, then on again): the ONU was
        // without power, also where the OLT keeps no history (HiOSO). A restart right at the session drop is a real reboot.
        $dgMatched = count(array_filter($cpe['boot_times'], fn (Carbon $b) => self::near($b, $dgTimes)));
        $matched = count(array_filter($cpe['boot_times'], fn (Carbon $b) => self::near($b, $dgTimes) || self::afterLongPppDrop($b, $h)));
        if ($matched / max(1, count($cpe['boot_times'])) >= 2 / 3) {
            // restarts alone (no dying gasp) are an inference: rendah
            return self::r(Cause::PowerOff->value, $dgMatched > 0 ? self::MID : self::LOW, 'cpe', true, [
                DiagnosisEvidence::item('cpe', 'cpe_events', end($cpe['boot_times']) ?: null, 'hist_power_home', ['n' => count($cpe['boot_times'])]),
            ]);
        }
        $o = $c['onu'];
        $huaweiPattern = $o['online_seconds'] !== null && $o['system_up_seconds'] !== null && abs((int) $o['online_seconds'] - (int) $o['system_up_seconds']) <= 120;
        $ev = [DiagnosisEvidence::item('cpe', 'cpe_events', end($cpe['boot_times']) ?: null, 'reboot_loop', ['n' => $cpe['boots_24h']])];
        if ($huaweiPattern) {
            $ev[] = DiagnosisEvidence::item('onu', 'olt_live', $o['cause_at'], 'reboot_loop_pattern');
        }

        return self::r(Cause::CpeRebootLoop->value, ($huaweiPattern || ! $o['dg_in_24h']) ? self::HIGH : self::MID, 'cpe', true, $ev);
    }

    // ------------------------------------------------------------------ 6b. ONU online now, dropped earlier today

    /** @return array<string,mixed> the history block of the context, every key present */
    private static function hist(array $c): array
    {
        return array_replace(self::HIST_EMPTY, (array) ($c['history'] ?? []));
    }

    /** A time within EXPLAIN_MINUTES after (and 2 minutes before) one of the others. */
    private static function near(Carbon $at, array $others): bool
    {
        foreach ($others as $o) {
            $delta = $at->getTimestamp() - $o->getTimestamp();
            if ($delta >= -120 && $delta <= self::EXPLAIN_MINUTES * 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * A restart that comes 5+ minutes after a PPP drop started, while that drop was still open or just ended (power
     * cycle). Only where the OLT keeps no ONU history (HiOSO), or when an ONU drop is recorded between the drop and the
     * restart: on ZTE / Huawei an ONU that lost power shows up at the OLT, so a restart without one is the customer
     * restarting the modem during a network outage.
     */
    private static function afterLongPppDrop(Carbon $boot, array $h): bool
    {
        foreach ($h['ppp_drops'] as $d) {
            $start = $d['at'];
            $end = $d['end'] ?? null;
            if ($boot->getTimestamp() < $start->getTimestamp() + 300 || ($end !== null && $boot->getTimestamp() > $end->getTimestamp() + 600)) {
                continue;
            }
            if (! $h['onu_log']) {
                return true;
            }
            foreach ($h['onu_drops'] as $o) {
                if ($o['at']->getTimestamp() >= $start->getTimestamp() - 600 && $o['at']->getTimestamp() <= $boot->getTimestamp()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** ONU drops of the last 24 h that are NOT part of an area wide outage, optionally of one cause. */
    private static function onuDropsOf(array $h, ?string $cause = null): array
    {
        return array_values(array_filter($h['onu_drops'], fn (array $d) => ! $d['area'] && ($cause === null || $d['cause'] === $cause)));
    }

    private static function lastAt(array $drops): ?Carbon
    {
        $last = null;
        foreach ($drops as $d) {
            if ($last === null || $d['at']->gt($last)) {
                $last = $d['at'];
            }
        }

        return $last;
    }

    /**
     * The ONU is online now, but its own history shows repeated drops: LOS x2 = cable, dying gasp x2 = power.
     * One drop never makes a cause; a drop without a recorded cause stays "Tidak tahu" (only a history line).
     */
    public static function onuHistory(array $c, bool $recentDyingGasp = true): ?array
    {
        if (self::onuDown($c)) {
            return null;
        }
        $h = self::hist($c);
        $los = self::onuDropsOf($h, 'los');
        $dg = self::onuDropsOf($h, 'dying_gasp');
        $authoritative = fn (array $drops) => count(array_filter($drops, fn (array $d) => in_array($d['source'], self::ONU_AUTHORITATIVE, true))) > 0;

        if (count($los) >= self::ONU_FLAP_LOS_MIN && count($los) >= count($dg)) {
            $live = $authoritative($los);

            return self::r(Cause::DropLos->value, (count($los) >= 3 && $live) ? self::HIGH : self::MID, 'onu', true, [
                DiagnosisEvidence::item('onu', $live ? 'olt_detail' : 'olt_poll', self::lastAt($los), 'hist_onu_los', ['n' => count($los), 'time' => DiagnosisEvidence::clock(self::lastAt($los))]),
            ]);
        }
        if (count($dg) >= self::ONU_FLAP_DG_MIN) {
            // restarts of the ONU that no dying gasp explains point to the ONU itself (onu_reboot / CPE rules)
            $dgTimes = array_map(fn (array $d) => $d['at'], $dg);
            $unmatched = count(array_filter($c['cpe']['boot_times'] ?? [], fn (Carbon $b) => ! self::near($b, $dgTimes)));
            if ($unmatched <= 1) {
                $live = $authoritative($dg);

                return self::r(Cause::PowerOff->value, (count($dg) >= 3 && $live) ? self::HIGH : self::MID, 'onu', true, [
                    DiagnosisEvidence::item('onu', $live ? 'olt_detail' : 'olt_poll', self::lastAt($dg), 'hist_onu_dg', ['n' => count($dg), 'time' => DiagnosisEvidence::clock(self::lastAt($dg))]),
                ]);
            }
        }
        // (not while the internet session is down on a fresh read: the PPP / WiFi rules below decide that line)
        if ($recentDyingGasp && ($c['pppoe']['online'] ?? null) !== false && ($dgAt = self::recentDyingGasp($c)) !== null && self::restartedAfter($c, $dgAt)) {
            return self::r(Cause::PowerOff->value, self::MID, 'onu', true, [
                DiagnosisEvidence::item('onu', 'olt_poll', $dgAt, 'hist_onu_dg_recent', ['time' => DiagnosisEvidence::clock($dgAt)]),
            ]);
        }

        return null;
    }

    // ------------------------------------------------------------------ 7. ONU online, internet session

    public static function pppoeStuck(array $c): ?array
    {
        if (self::onuDown($c)) {
            return null;
        }
        $blind = $c['router']['blind'] !== null && ! $c['router']['active']; // the router poll is not trusted ...
        $p = $c['pppoe'];
        // ... but the syslog "already active" lines do not depend on that poll. 2026-10-26.1: they only mean "stuck"
        // while the session is NOT active now (fresh poll or live read says down); with a session up they are noise.
        if ($p['stuck_count'] >= 3 && (! self::rca5($c) || $p['online'] === false)) {
            return self::r(Cause::PppoeStuck->value, self::HIGH, 'pppoe', true, [
                DiagnosisEvidence::item('pppoe', 'syslog', $p['stuck_at'], 'ppp_stuck_log', ['n' => $p['stuck_count']]),
            ]);
        }
        $cpeUp = $c['cpe']['uptime_now_s'] ?? null; // as of now; the stored hourly uptime is never compared
        $session = $p['router_session_uptime_s'];
        if (! $blind && $p['source'] === 'router_live' && $session !== null && $cpeUp !== null && $c['cpe']['present'] && $session > $cpeUp + self::SESSION_TOLERANCE_SECONDS) {
            return self::r(Cause::PppoeStuck->value, self::MID, 'pppoe', true, [
                DiagnosisEvidence::item('pppoe', 'router_live', $c['now'], 'ppp_stuck_session', ['session' => self::duration($session), 'cpe' => self::duration((int) $cpeUp)]),
            ]);
        }

        return null;
    }

    public static function pppoeDown(array $c): ?array
    {
        if (self::onuDown($c)) {
            return null;
        }
        $blind = $c['router']['blind'] !== null && ! $c['router']['active'];
        $p = $c['pppoe'];
        $cpe = $c['cpe'];
        $now = $c['now'];
        if ($p['auth_failed_at'] !== null) {
            return self::r(Cause::PppoeAuth->value, self::HIGH, 'pppoe', true, [DiagnosisEvidence::item('pppoe', 'syslog', $p['auth_failed_at'], 'ppp_auth_log', ['time' => DiagnosisEvidence::clock($p['auth_failed_at'])])]);
        }
        if ($cpe['present'] && $cpe['ppp_fresh'] && $cpe['ppp_last_error'] === 'ERROR_AUTHENTICATION_FAILURE') {
            return self::r(Cause::PppoeAuth->value, self::fresh($cpe['ppp_at'], $now) ? self::HIGH : self::MID, 'pppoe', true, [DiagnosisEvidence::item('cpe', 'genieacs', $cpe['ppp_at'], 'ppp_auth_cpe', ['time' => DiagnosisEvidence::clock($cpe['ppp_at'])], self::fresh($cpe['ppp_at'], $now))]);
        }
        $bootedIn6h = count(array_filter($cpe['boot_times'], fn (Carbon $t) => $t->gte($now->copy()->subHours(6)))) > 0;
        // Reconnects that an ONU drop or restart explains do not count; an unrelated ONU drop earlier no longer blocks the rule.
        $unexplained = (int) ($p['reconnects_unexplained_6h'] ?? $p['reconnects_6h']);
        if ($unexplained >= self::PPPOE_FLAP_MIN && (! $blind || $p['syslog']) && ! $bootedIn6h) {
            return self::r(Cause::PppoeFlap->value, self::MID, 'pppoe', true, [DiagnosisEvidence::item('pppoe', 'syslog', $now, 'ppp_flap', ['n' => $unexplained])]);
        }

        return null;
    }

    /**
     * The line is up now, the ONU is stable, but the internet session dropped >= 3 times in 24 h with nothing explaining
     * it (no ONU drop, no restart). sedang from the router log / a live router read, rendah from polled data only.
     */
    public static function pppoeHistory(array $c): ?array
    {
        if (self::onuDown($c)) {
            return null;
        }
        $h = self::hist($c);
        $drops = array_values(array_filter($h['ppp_drops'], fn (array $d) => ! $d['explained']));
        if (count($drops) < self::PPPOE_FLAP_24H_MIN || count($h['onu_drops']) > 1) {
            return null;
        }
        $blind = $c['router']['blind'] !== null && ! $c['router']['active'];
        if ($blind && $h['ppp_source'] !== 'syslog') {
            return null;
        }
        // sedang needs the router's own word (log / secret / live session) on at least 2 of the counted drops;
        // polled and CPE data alone, or one live read that confirms only the newest reconnect, stay rendah
        $proven = count(array_filter($drops, fn (array $d) => array_intersect(['syslog', 'router_secret', 'router_live'], (array) $d['sources']) !== []));
        $solid = $proven >= 2;
        $last = self::lastAt($drops);
        $ev = [DiagnosisEvidence::item('pppoe', $h['ppp_source'] === 'syslog' ? 'syslog' : 'poll', $last, 'hist_ppp', ['n' => count($drops), 'time' => DiagnosisEvidence::clock($last)])];

        return self::r(Cause::PppoeFlap->value, $solid ? self::MID : self::LOW, 'pppoe', true, $ev);
    }

    // ------------------------------------------------------------------ 8. ONU + internet online: WiFi

    /** @return list<string> human band names of radios that are down (fresh values only). */
    private static function downBands(array $cpe): array
    {
        $down = [];
        foreach (['24' => '2.4 GHz', '5' => '5 GHz'] as $key => $label) {
            $w = $cpe['wlan'][$key];
            $statusDown = $w['status'] !== null && strtolower((string) $w['status']) !== 'up';
            if ($statusDown || $w['enabled'] === false) {
                $down[] = $label;
            }
        }

        return $down;
    }

    private static function wifiContextOk(array $c): bool
    {
        return $c['cpe']['present'] && $c['cpe']['wlan_fresh'] && ! self::onuDown($c) && $c['pppoe']['online'] === true;
    }

    public static function wlanDown(array $c): ?array
    {
        if (! self::wifiContextOk($c)) {
            return null;
        }
        $cpe = $c['cpe'];
        $bands = self::downBands($cpe);
        if ($bands === []) {
            return null;
        }
        $strong = $cpe['wlan_strong'] && $cpe['band_verified'];
        $item = DiagnosisEvidence::item('cpe', (string) ($cpe['wlan_source'] ?? 'sync'), $cpe['wlan_at'], $cpe['wlan_strong'] ? 'wlan_down' : 'wlan_down_old', ['band' => implode(' + ', $bands), 'time' => DiagnosisEvidence::clock($cpe['wlan_at'])], (bool) $cpe['wlan_strong']);

        return self::r(Cause::WlanDown->value, $strong ? self::HIGH : self::MID, 'cpe', true, [$item]);
    }

    public static function wlanFlap(array $c): ?array
    {
        if (! $c['cpe']['present'] || self::onuDown($c) || $c['pppoe']['online'] !== true) {
            return null;
        }
        $cpe = $c['cpe'];
        $now = $c['now'];
        $bootedIn6h = count(array_filter($cpe['boot_times'], fn (Carbon $t) => $t->gte($now->copy()->subHours(6)))) > 0;
        if ($cpe['wlan_transitions_6h'] >= self::WLAN_FLAP_MIN && ! $bootedIn6h) {
            return self::r(Cause::WlanFlap->value, self::MID, 'cpe', true, [DiagnosisEvidence::item('cpe', 'cpe_events', $now, 'wlan_flap', ['n' => $cpe['wlan_transitions_6h']])]);
        }
        // 24 h: transitions that no ONU restart explains (the reader already dropped the ones right after a restart)
        $day = (int) ($cpe['wlan_transitions_24h'] ?? 0);
        // a weak optical signal is the better cause (optical_degrade follows this rule): the 24 h WiFi rule steps aside
        // (2026-10-26.1: only a STRONG optical sign steps aside; a stable weak RX alone no longer pre-empts the WiFi rules)
        $opticalBetter = self::rca5($c) ? self::opticalStrong($c) : (bool) ($c['onu']['rx_weak'] ?? false);
        if ($day >= self::WLAN_FLAP_24H_MIN && ! $opticalBetter) {
            return self::r(Cause::WlanFlap->value, self::MID, 'cpe', true, [DiagnosisEvidence::item('cpe', 'cpe_events', $now, 'hist_wlan', ['n' => $day])]);
        }

        return null;
    }

    public static function wifiClient(array $c): ?array
    {
        if (! self::wifiContextOk($c)) {
            return null;
        }
        $cpe = $c['cpe'];
        $assoc = (int) ($cpe['wlan']['24']['assoc'] ?? 0) + (int) ($cpe['wlan']['5']['assoc'] ?? 0);
        $rssi = array_map('intval', $cpe['rssi']);
        if ($assoc <= 0 || $rssi === []) {
            return null;
        }
        sort($rssi);
        $median = $rssi[(int) floor((count($rssi) - 1) / 2)];
        $weak = count(array_filter($rssi, fn (int $v) => $v < self::WEAK_CLIENT_DBM));
        if (! ($median < self::WEAK_CLIENT_DBM || ($weak / count($rssi)) >= 0.5)) {
            return null;
        }

        return self::r(Cause::WifiClient->value, self::MID, 'cpe', true, [DiagnosisEvidence::item('cpe', (string) ($cpe['wlan_source'] ?? 'sync'), $cpe['wlan_at'], 'wifi_client', ['rssi' => $median])]);
    }

    // ------------------------------------------------------------------ 9. optical (secondary, primary only as a last resort)

    /**
     * 2026-10-26.1: what makes weak RX a PRIMARY optical fault. Weak RX alone is not enough (19 % of the ONUs sit at
     * or below the limit): it takes an RX step (>= 3 dB in a day), a steady decline over 3 days, or LOS / cause-less
     * drops twice in 24 h together with the weak RX.
     */
    public static function opticalStrong(array $c): bool
    {
        $o = $c['onu'];
        if (($o['rx_step'] ?? null) !== null || ! empty($o['rx_decline'])) {
            return true;
        }

        return ! empty($o['rx_weak']) && self::opticalDrops($c) >= self::OPTICAL_DROPS_MIN;
    }

    /** ONU drops of the last 24 h (not area wide) with cause LOS or no recorded cause. */
    private static function opticalDrops(array $c): int
    {
        return count(array_filter(self::hist($c)['onu_drops'], fn (array $d) => ! $d['area'] && in_array($d['cause'], ['los', null], true)));
    }

    public static function opticalDegrade(array $c): ?array
    {
        if (self::rca5($c)) {
            return self::opticalDegradeV2($c);
        }
        $o = $c['onu'];
        if (! $o['rx_weak'] && ! $o['rx_trend']) {
            return null;
        }
        $ev = [];
        if ($o['rx_weak']) {
            $ev[] = DiagnosisEvidence::item('onu', 'olt_poll', $o['collected_at'], 'rx_weak', ['dbm' => DiagnosisEvidence::dbm($o['rx_dbm']), 'limit' => DiagnosisEvidence::dbm(OutageSettings::weakRxDbm())], (bool) $o['fresh']);
        }
        if ($o['rx_trend']) {
            $ev[] = DiagnosisEvidence::item('onu', 'tracker', null, 'rx_trend', [], false);
        }
        // Primary only when the customer is online, the internet session is up and nothing else holds.
        $primary = self::onuUp($c) && $c['pppoe']['online'] === true;

        return self::r(Cause::OpticalDegrade->value, self::MID, 'onu', $primary, $ev);
    }

    /** 2026-10-26.1 optical rule: strong evidence makes it primary, weak RX alone stays a note. */
    private static function opticalDegradeV2(array $c): ?array
    {
        $o = $c['onu'];
        $step = is_array($o['rx_step'] ?? null) ? $o['rx_step'] : null;
        $decline = is_array($o['rx_decline'] ?? null) ? $o['rx_decline'] : null;
        $drops = self::opticalDrops($c);
        $dropsWeak = ! empty($o['rx_weak']) && $drops >= self::OPTICAL_DROPS_MIN;
        if (empty($o['rx_weak']) && empty($o['rx_trend']) && $step === null && $decline === null) {
            return null;
        }
        $fresh = (bool) $o['fresh'];
        $ev = [];
        if ($step !== null) {
            $ev[] = DiagnosisEvidence::item('onu', 'tracker', null, 'rx_step', ['from' => DiagnosisEvidence::dbm($step['from']), 'to' => DiagnosisEvidence::dbm($step['to'])], false);
        }
        if ($decline !== null) {
            $ev[] = DiagnosisEvidence::item('onu', 'tracker', null, 'rx_decline', ['from' => DiagnosisEvidence::dbm($decline['from']), 'to' => DiagnosisEvidence::dbm($decline['to'])], false);
        }
        if ($dropsWeak) {
            $ev[] = DiagnosisEvidence::item('onu', 'olt_poll', $o['collected_at'], 'rx_drops_weak', ['n' => $drops], $fresh);
        }
        if (! empty($o['rx_weak'])) {
            $ev[] = DiagnosisEvidence::item('onu', 'olt_poll', $o['collected_at'], 'rx_weak', ['dbm' => DiagnosisEvidence::dbm($o['rx_dbm']), 'limit' => DiagnosisEvidence::dbm(OutageSettings::weakRxDbm())], $fresh);
        }
        if (! empty($o['rx_trend'])) {
            $ev[] = DiagnosisEvidence::item('onu', 'tracker', null, 'rx_trend', [], false);
        }
        $secondary = [];
        if ($step !== null) {
            $odpPeers = (int) ($o['rx_step_peers_odp'] ?? 0);
            $ponPeers = (int) ($o['rx_step_peers_pon'] ?? 0);
            if ($odpPeers >= 1) {
                $ev[] = DiagnosisEvidence::item('cluster', 'tracker', null, 'rx_shared_odp', ['n' => $odpPeers + 1], false);
                $secondary[] = Cause::OdpCut->value;
            } elseif ($ponPeers >= 1) {
                $ev[] = DiagnosisEvidence::item('pon', 'tracker', null, 'rx_shared_pon', ['n' => $ponPeers + 1], false);
                $secondary[] = Cause::OdpCut->value;
            }
        }
        $strong = $step !== null || $decline !== null || $dropsWeak;

        return self::r(Cause::OpticalDegrade->value, self::MID, 'onu', $strong && self::onuUp($c), $ev, $secondary);
    }

    // ------------------------------------------------------------------ PR-D more evidence (2026-10-28.1, all flags default off)

    /** Traffic samples older than this are not "now". */
    public const TRAFFIC_FRESH_MINUTES = 20;

    /** More than this is a session in use; below TRAFFIC_IDLE_BPS (both directions) it is nearly unused. */
    public const TRAFFIC_ACTIVE_BPS = 50_000;

    public const TRAFFIC_IDLE_BPS = 10_000;

    /**
     * A sample is a rate over the last sampling interval (~10 min), so it only proves the line carried traffic AFTER the ONU
     * went down when it was taken this many minutes after the start of the outage.
     */
    public const TRAFFIC_AFTER_MINUTES = 10;

    /** Primary causes fresh traffic after the ONU went down contradicts. */
    public const TRAFFIC_BLOCKS = ['drop_los', 'pon_cut', 'odp_cut', 'power_off', 'cpe_dead'];

    /** @return array{at:Carbon,bps:int,up:int}|null the traffic sample when it is fresh (<= 20 min), else null */
    private static function freshTraffic(array $c): ?array
    {
        $t = $c['traffic'] ?? null;
        if (! is_array($t) || ! ($t['at'] ?? null) instanceof Carbon || ! self::fresh($t['at'], $c['now'], self::TRAFFIC_FRESH_MINUTES)) {
            return null;
        }

        return ['at' => $t['at'], 'bps' => (int) ($t['download_bps'] ?? 0), 'up' => (int) ($t['upload_bps'] ?? 0)];
    }

    /**
     * Guard: the ONU reads down now with a known start, yet the session carried traffic (> 50 kbps) in a sample taken 10+
     * minutes after that start. Then a hard fibre / power / modem answer is not safe: it becomes "unknown" and the cause stays
     * as "Bisa juga". Never answers "not a fault" (an intermittent fibre fault looks exactly like this).
     */
    public static function trafficConflict(array $c, array $primary): bool
    {
        if (! OutageSettings::diagnosisTrafficGuardEnabled() || ! in_array($primary['cause'], self::TRAFFIC_BLOCKS, true) || ! self::onuDown($c)) {
            return false;
        }
        $traffic = self::freshTraffic($c);
        $start = $c['onu']['since'] ?? $c['onu']['since_hint'] ?? null;
        if ($traffic === null || ! $start instanceof Carbon || $traffic['bps'] <= self::TRAFFIC_ACTIVE_BPS) {
            return false;
        }

        return $traffic['at']->gte($start->copy()->addMinutes(self::TRAFFIC_AFTER_MINUTES));
    }

    /** @return array<string,mixed> the answer that replaces a hard one the traffic contradicts */
    public static function trafficGuard(array $c): array
    {
        $t = self::freshTraffic($c);

        return self::r(Cause::Unknown->value, self::LOW, 'data', true, [
            DiagnosisEvidence::item('data', 'traffic', $t['at'] ?? null, 'traffic_conflict', ['rate' => DiagnosisEvidence::rate((int) ($t['bps'] ?? 0)), 'time' => DiagnosisEvidence::clock($t['at'] ?? null)]),
        ]);
    }

    /**
     * The PR-D lines (session burst, area power, traffic), each only while its flag is on, and the "Bisa juga" causes they
     * add. They never change the primary cause. Layers that explain everything below them (billing, router, OLT, PON)
     * get none of them, and neither does a guarded answer (its own line already says the data conflicts).
     *
     * @return array{evidence:list<array<string,mixed>>,secondary:list<string>}
     */
    public static function moreEvidence(array $c, array $primary, string $primaryId): array
    {
        $lines = [];
        $secondary = [];
        if (in_array($primaryId, ['isolir_admin', 'core_router', 'olt_down', 'pon_odp_cut', 'traffic_guard'], true) || $primary['cause'] === Cause::DataBlind->value) {
            return ['evidence' => $lines, 'secondary' => $secondary];
        }
        $now = $c['now'];

        $burst = $c['pppoe']['burst'] ?? null;
        if (OutageSettings::diagnosisSessionBurstEnabled() && is_array($burst) && ($burst['at'] ?? null) instanceof Carbon
            && ($burst['onu_down_share'] ?? null) !== null && (float) $burst['onu_down_share'] < 0.5) {
            // ONUs that stayed online: a path / router event. When most ONUs died too it is a PON event, not a session line.
            $lines[] = DiagnosisEvidence::item('router', 'syslog', $burst['at'], 'burst_session', ['time' => DiagnosisEvidence::clock($burst['at']), 'n' => (int) $burst['count']], self::fresh($burst['at'], $now, self::FRESH_BURST_MINUTES));
            $secondary[] = Cause::Upstream->value;
        }

        $area = $c['area_power'] ?? null;
        if (OutageSettings::diagnosisAreaPowerEnabled() && is_array($area) && ($area['at'] ?? null) instanceof Carbon) {
            $lines[] = DiagnosisEvidence::item('pon', 'detector', $area['at'], 'area_power_group', [
                'area' => $area['area'] ?? 'ini', 'n' => (int) $area['count'], 'time' => DiagnosisEvidence::clock($area['at']),
            ]);
            // "Bisa juga: Listrik mati" only while it can still be true: the ONU is not online now, or the group is still open.
            // A member whose ONU is back and whose group closed keeps the line as context only.
            if ($primary['cause'] !== Cause::PowerOff->value && (! self::onuUp($c) || ($area['open'] ?? true))) {
                $secondary[] = Cause::PowerOff->value;
            }
        }

        $traffic = OutageSettings::diagnosisTrafficGuardEnabled() ? self::freshTraffic($c) : null;
        if ($traffic !== null) {
            $time = DiagnosisEvidence::clock($traffic['at']);
            if ($traffic['bps'] > self::TRAFFIC_ACTIVE_BPS) {
                $lines[] = DiagnosisEvidence::item('pppoe', 'traffic', $traffic['at'], 'traffic_active', ['rate' => DiagnosisEvidence::rate($traffic['bps']), 'time' => $time]);
            } elseif ($traffic['bps'] < self::TRAFFIC_IDLE_BPS && $traffic['up'] < self::TRAFFIC_IDLE_BPS) {
                $lines[] = DiagnosisEvidence::item('pppoe', 'traffic', $traffic['at'], 'traffic_idle', ['time' => $time]);
            }
        }

        return ['evidence' => $lines, 'secondary' => array_values(array_unique($secondary))];
    }

    /** A burst line counts as "fresh" for this many minutes. */
    public const FRESH_BURST_MINUTES = 60;

    // ------------------------------------------------------------------ abstain gate (2026-10-26.1)

    /**
     * Does at least one hardware item back the answer: a fresh (<= 15 min) read of the ONU, the router session, the
     * CPE or a PON / OLT / router episode, or an authoritative event (OLT drop, router log line, CPE restart) of the
     * last 24 hours? Billing and the rules' own lines are not hardware.
     */
    public static function hardwareSupport(array $c, array $primary): bool
    {
        $now = $c['now'];
        $dayAgo = $now->copy()->subHours(24);
        foreach ((array) ($primary['evidence'] ?? []) as $e) {
            if (($e['fresh'] ?? false) && in_array($e['layer'] ?? '', ['router', 'olt', 'pon', 'cluster', 'onu', 'cpe'], true) && ! in_array($e['source'] ?? '', ['rules', 'billing'], true)) {
                return true;
            }
        }
        $o = $c['onu'];
        if (! in_array($o['phase'] ?? null, [null, 'unknown'], true) && (self::fresh($o['collected_at'] ?? null, $now) || ($o['cause_source'] ?? null) === 'olt_live')) {
            return true;
        }
        foreach (self::hist($c)['onu_drops'] as $d) {
            if (in_array($d['source'], self::ONU_OLT_SOURCES, true) && $d['at']->gte($dayAgo)) {
                return true;
            }
        }
        $p = $c['pppoe'];
        if (($p['source'] ?? null) === 'router_live' || (! empty($p['fresh']) && self::fresh($p['at'] ?? null, $now))) {
            return true;
        }
        foreach ((array) ($p['events'] ?? []) as $e) {
            if (($e['source'] ?? '') === 'syslog' && ($e['at'] ?? null) instanceof Carbon && $e['at']->gte($dayAgo)) {
                return true;
            }
        }
        $cpe = $c['cpe'];
        if (($cpe['present'] ?? false) && (! empty($cpe['wlan_fresh']) || ! empty($cpe['ppp_fresh']) || ((array) ($cpe['boot_times'] ?? [])) !== [] || self::fresh($cpe['last_inform_at'] ?? null, $now))) {
            return true;
        }
        $mass = $c['pon']['mass'] ?? null;
        if ($mass !== null && self::fresh($mass['seen'] ?? null, $now)) {
            return true;
        }
        $wide = $c['olt']['wide'] ?? null;
        if ($wide !== null && self::fresh($wide['seen'] ?? null, $now)) {
            return true;
        }

        return ! empty($c['router']['offline']) && ! empty($c['router']['health_fresh']);
    }

    /** @return array<string,mixed> the answer that replaces a decisive one nothing fresh supports */
    public static function abstainGate(array $c): array
    {
        return self::r(Cause::Unknown->value, self::LOW, 'data', true, [DiagnosisEvidence::item('data', 'rules', $c['now'], 'no_fresh_hw', [], false)]);
    }

    // ------------------------------------------------------------------ 10. fallbacks

    public static function blindOrUnknown(array $c): ?array
    {
        $now = $c['now'];
        $r = $c['router'];
        $olt = $c['olt'];
        if ($olt['blind'] !== null) {
            return self::r(Cause::DataBlind->value, self::MID, 'olt', true, [DiagnosisEvidence::item('olt', 'detector', $now, 'olt_blind', ['olt' => self::oltName($c)])]);
        }
        $onuKnown = $c['onu']['fresh'] || $c['onu']['cause_source'] === 'olt_live';
        if ($r['blind'] !== null && ! $r['active'] && ($onuKnown ? self::onuUp($c) : true)) {
            return self::r(Cause::DataBlind->value, self::MID, 'router', true, [DiagnosisEvidence::item('router', 'detector', $now, 'router_blind', ['router' => self::routerName($c)])]);
        }
        if (! $onuKnown) {
            // 2026-10-26.1: no OLT link at all (no OLT or no ONU ref) is not "unread"; a mapped ONU whose OLT is not read is
            $noOlt = self::rca5($c) && ($c['olt']['id'] === null || ($c['customer']['ref'] ?? null) === null);
            $ev = [$noOlt
                ? DiagnosisEvidence::item('onu', 'rules', $now, 'no_olt_data', [], false)
                : DiagnosisEvidence::item('onu', 'olt_poll', $c['onu']['collected_at'], 'onu_unread', [], false)];

            return self::r(Cause::Unknown->value, self::LOW, 'data', true, $ev);
        }
        if (self::onuDown($c)) {
            $since = $c['onu']['since'];
            $ev = [DiagnosisEvidence::item('onu', 'olt_poll', $since, 'onu_recent_down', ['time' => DiagnosisEvidence::clock($since)])];

            return self::r(Cause::Unknown->value, self::LOW, 'onu', true, $ev);
        }
        if ($c['pppoe']['online'] === false) {
            return self::r(Cause::Unknown->value, self::LOW, 'pppoe', true, [DiagnosisEvidence::item('pppoe', (string) ($c['pppoe']['source'] ?? 'poll'), $c['pppoe']['at'], 'ppp_down', [], (bool) $c['pppoe']['fresh'])]);
        }
        if ($c['pppoe']['online'] === null) {
            return self::r(Cause::Unknown->value, self::LOW, 'data', true, [DiagnosisEvidence::item('pppoe', 'poll', null, 'ppp_unread', [], false)]);
        }

        return self::r(Cause::Unknown->value, self::LOW, 'data', true, [DiagnosisEvidence::item('data', 'rules', $now, 'network_ok')]);
    }

    private static function duration(int $seconds): string
    {
        if ($seconds >= 86400) {
            return intdiv($seconds, 86400).' hari';
        }
        if ($seconds >= 3600) {
            return intdiv($seconds, 3600).' jam';
        }

        return max(1, intdiv($seconds, 60)).' menit';
    }
}
