<?php

namespace Tests\Support;

use Illuminate\Support\Carbon;

/**
 * Hand-built classifier contexts (synthetic, no customer data) with the cause a person would label them with. They
 * mirror the fixtures of DiagnosisAccuracyTest / OutageRca4RulesTest, so the back-test corpus and the snapshot
 * round-trip test cover every rule family without a database.
 */
final class DiagnosisSyntheticContexts
{
    /** The healthy base context (an ONU online and fresh, internet up, router normal, a 30 ONU PON). */
    public static function base(Carbon $now): array
    {
        return [
            'now' => $now,
            'rca5' => true,
            'customer' => ['id' => 1, 'status' => 'active', 'pppoe_user' => 'user.uji', 'router_id' => 1, 'olt_id' => 1, 'ref' => '1/2/3:4', 'pon' => '1/2/3', 'odp' => null, 'is_online' => true, 'online_checked_at' => $now, 'rx_dbm' => -20.0, 'mikrotik_profile' => 'default'],
            'probes' => [],
            'admin' => ['billing' => false, 'status' => 'active', 'secret_isolir' => false, 'secret_state' => 'none', 'db_isolir' => false, 'secret_disabled' => null],
            'router' => ['id' => 1, 'name' => 'router-1', 'isolation_profile' => null, 'blind' => null, 'offline' => false, 'all_gone' => false, 'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => $now, 'health_fresh' => true, 'active' => null, 'probe_status' => null],
            'olt' => ['id' => 1, 'name' => 'olt-1', 'family' => 'zte', 'wide' => null, 'blind' => null],
            'pon' => ['mass' => null, 'los_others' => 0, 'total' => 30, 'boot_share' => null, 'down_now' => 0, 'known_total' => 30, 'recovered' => 0, 'event_at' => null],
            'cluster' => ['odp' => null, 'odp_share' => null, 'odp_count' => 0, 'odp_down_now' => 0],
            'cpe' => [
                'present' => false, 'device_id' => null, 'model' => null, 'last_inform_at' => null, 'uptime_s' => null, 'uptime_now_s' => null, 'boots_24h' => 0, 'boot_times' => [], 'last_boot_at' => null,
                'wlan' => ['24' => ['status' => null, 'enabled' => null, 'assoc' => null], '5' => ['status' => null, 'enabled' => null, 'assoc' => null]],
                'wlan_at' => null, 'wlan_fresh' => false, 'wlan_strong' => false, 'wlan_source' => null, 'band_verified' => false,
                'ppp_status' => null, 'ppp_last_error' => null, 'ppp_at' => null, 'ppp_fresh' => false, 'rssi' => [], 'lan_active' => null, 'wlan_transitions_6h' => 0, 'wlan_transitions_24h' => 0, 'ppp_reconnects' => [], 'summoned_at' => null,
            ],
            'onu' => [
                'phase' => 'online', 'since' => null, 'since_hint' => null, 'collected_at' => $now, 'fresh' => true, 'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false,
                'flapping' => false, 'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null, 'events_24h' => 0, 'dg_in_24h' => false,
                'rx_dbm' => -20.0, 'rx_weak' => false, 'rx_trend' => false, 'olt_probe' => null, 'drops' => [],
                'rx_step' => null, 'rx_step_peers_pon' => 0, 'rx_step_peers_odp' => 0, 'rx_decline' => null,
            ],
            'pppoe' => ['online' => true, 'fresh' => true, 'at' => $now, 'source' => 'poll', 'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => true, 'router_session_uptime_s' => null, 'router_session' => null, 'events' => []],
            'history' => [
                'onu_drops' => [], 'ppp_drops' => [], 'ppp_source' => 'none', 'ppp_last' => null, 'onu_log' => true, 'ppp_log' => true,
                'rx' => ['dbm' => -20.0, 'at' => $now->copy()->subMinutes(30), 'old' => false], 'ticket_rx_complaint' => false,
            ],
        ];
    }

    /**
     * name => [context, the cause a technician would label it with (null = no label, an honest "unknown")].
     *
     * @return array<string,array{0:array<string,mixed>,1:?string}>
     */
    public static function cases(Carbon $now): array
    {
        $ago = fn (int $minutes): Carbon => $now->copy()->subMinutes($minutes);
        $drop = fn (int $minutes, ?string $cause, string $source = 'olt_poll'): array => ['at' => $ago($minutes), 'cause' => $cause, 'source' => $source, 'area' => false];
        $with = fn (array $o): array => array_replace_recursive(self::base($now), $o);
        $step = ['from' => -19.5, 'to' => -27.7, 'drop' => 8.2, 'date' => '2026-10-25'];
        $mass = ['id' => 7, 'includes' => true, 'count' => 8, 'total' => 30, 'ratio' => 8 / 30, 'whole' => false, 'confirmed' => true, 'state' => 'confirmed', 'since' => $ago(12), 'seen' => $ago(1), 'ids' => [1]];
        $dgCpe = ['present' => true, 'last_inform_at' => $ago(3), 'uptime_now_s' => 172800, 'boot_times' => [$ago(60 * 48)], 'boots_24h' => 0];

        $cases = [
            'healthy' => [$with([]), null],
            'onu_los_now' => [$with(['onu' => ['phase' => 'los', 'since' => $ago(90), 'cause' => 'los', 'cause_fresh' => true, 'cause_at' => $now, 'cause_source' => 'olt_poll'], 'pppoe' => ['online' => false], 'history' => ['onu_drops' => [$drop(90, 'los')]]]), 'drop_los'],
            'onu_dying_gasp_now' => [$with(['onu' => ['phase' => 'dying_gasp', 'since' => $ago(40), 'cause' => 'dying_gasp', 'cause_fresh' => true, 'cause_at' => $now, 'cause_source' => 'olt_poll'], 'pppoe' => ['online' => false], 'history' => ['onu_drops' => [$drop(40, 'dying_gasp')]]]), 'power_off'],
            'dying_gasp_then_back' => [$with(['history' => ['onu_drops' => [$drop(300, 'dying_gasp')]]]), 'power_off'],
            'dying_gasp_offline_no_cause' => [$with(['onu' => ['phase' => 'offline', 'since' => $ago(200), 'cause' => null], 'history' => ['onu_drops' => [$drop(200, 'dying_gasp', 'olt_detail')]]]), 'power_off'],
            'dying_gasp_cpe_restarted' => [$with(['cpe' => ['uptime_now_s' => 600, 'boot_times' => [$ago(10)]] + $dgCpe, 'history' => ['onu_drops' => [$drop(200, 'dying_gasp')]]]), 'power_off'],
            'dying_gasp_cpe_kept_running' => [$with(['cpe' => $dgCpe, 'history' => ['onu_drops' => [$drop(200, 'dying_gasp')]]]), null],
            'two_los_and_weak_rx' => [$with(['onu' => ['rx_dbm' => -27.9, 'rx_weak' => true], 'history' => ['onu_drops' => [$drop(600, 'los'), $drop(300, 'los')]]]), 'drop_los'],
            'two_causeless_drops_weak_rx' => [$with(['onu' => ['rx_dbm' => -27.9, 'rx_weak' => true], 'history' => ['onu_drops' => [$drop(600, null), $drop(300, null)]]]), 'optical_degrade'],
            'rx_step_in_a_day' => [$with(['onu' => ['rx_dbm' => -27.7, 'rx_weak' => true, 'rx_step' => $step]]), 'optical_degrade'],
            'rx_decline_3_days' => [$with(['onu' => ['rx_dbm' => -27.2, 'rx_weak' => true, 'rx_decline' => ['from' => -25.1, 'to' => -27.2]]]), 'optical_degrade'],
            'rx_step_shared_with_odp' => [$with(['onu' => ['rx_weak' => true, 'rx_step' => $step, 'rx_step_peers_odp' => 1, 'rx_step_peers_pon' => 3]]), 'optical_degrade'],
            'weak_rx_alone' => [$with(['onu' => ['rx_dbm' => -27.45, 'rx_weak' => true]]), null],
            'wifi_flap_with_weak_rx' => [$with(['cpe' => ['present' => true, 'last_inform_at' => $ago(2), 'wlan_transitions_24h' => 3, 'wlan_fresh' => true], 'onu' => ['rx_dbm' => -27.45, 'rx_weak' => true]]), 'wlan_flap'],
            'already_active_session_down' => [$with(['pppoe' => ['stuck_count' => 4, 'stuck_at' => $ago(4), 'online' => false]]), 'pppoe_stuck'],
            'already_active_session_up' => [$with(['pppoe' => ['stuck_count' => 4, 'stuck_at' => $ago(24), 'online' => true]]), null],
            'isolated_secret' => [$with(['customer' => ['status' => 'isolated'], 'admin' => ['billing' => true, 'status' => 'isolated', 'secret_isolir' => true, 'secret_state' => 'isolated']]), 'isolir_admin'],
            'terminated' => [$with(['customer' => ['status' => 'terminated'], 'admin' => ['billing' => true, 'status' => 'terminated', 'db_isolir' => true]]), 'isolir_admin'],
            'router_all_gone' => [$with(['router' => ['offline' => true, 'all_gone' => true, 'blind' => ['reason' => 'router_offline']], 'onu' => ['phase' => 'offline', 'since' => $ago(90)]]), 'core_router'],
            'olt_wide' => [$with(['olt' => ['wide' => ['id' => 5, 'since' => $ago(10), 'seen' => $ago(2)]], 'onu' => ['phase' => 'offline', 'since' => $ago(10)]]), 'olt_down'],
            'olt_blind' => [$with(['olt' => ['blind' => ['id' => 9, 'reason' => 'collector_stale']], 'onu' => ['phase' => 'unknown', 'fresh' => false]]), null],
            'pon_mass_part' => [$with(['pon' => ['mass' => $mass, 'down_now' => 8, 'known_total' => 30], 'onu' => ['phase' => 'los', 'since' => $ago(12)]]), 'pon_cut'],
            'pon_mass_one_odp' => [$with(['pon' => ['mass' => $mass, 'down_now' => 8, 'known_total' => 30], 'cluster' => ['odp' => 'ODP-07', 'odp_share' => 0.9, 'odp_count' => 7, 'odp_down_now' => 7], 'onu' => ['phase' => 'los', 'since' => $ago(12)]]), 'odp_cut'],
        ];

        // a long history: the snapshot keeps the newest 20 entries and the replay must put them back in order
        $long = [];
        for ($i = 40; $i >= 1; $i--) {
            $long[] = $drop($i * 10, $i % 3 === 0 ? 'los' : null);
        }
        $cases['long_history_los'] = [$with(['history' => ['onu_drops' => $long], 'onu' => ['rx_dbm' => -27.9, 'rx_weak' => true]]), 'drop_los'];

        return $cases;
    }
}
