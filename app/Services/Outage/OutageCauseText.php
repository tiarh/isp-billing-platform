<?php

namespace App\Services\Outage;

/**
 * Plain Indonesian cause text for an episode (RCA-2). One place, used by the Deteksi Gangguan panel and its
 * "Riwayat" list. Codes are the detector's own cause codes (payload.cause / payload.cause_detail), not tickets.
 */
class OutageCauseText
{
    public const LABELS = [
        'los' => 'Kabel/LOS',
        'link_drop' => 'Kabel/LOS',
        'dying_gasp' => 'Listrik mati',
        'onu_reboot' => 'ONU restart',
        'never_registered' => 'Belum pernah online',
        'flapping' => 'Putus-sambung',
        'pppoe_stuck' => 'Sesi nyangkut',
        'pppoe_auth' => 'Gagal login PPPoE',
        'down_unknown' => 'Sebab belum diketahui',
        'other' => 'Sebab lain',
    ];

    /**
     * Best cause of an episode payload: flapping and never-registered win, then the enrichment answer, then the
     * detector's own cause.
     *
     * @param  array<string,mixed>  $payload
     * @return array{code:string,label:string}|null
     */
    public static function forPayload(array $payload): ?array
    {
        $enrich = (array) ($payload['enrich'] ?? []);
        $detail = $payload['cause_detail'] ?? null;
        $code = match (true) {
            $detail === 'never_registered' => 'never_registered',
            ! empty($enrich['flapping']) => 'flapping',
            is_string($detail) && $detail !== '' => $detail,
            is_string($payload['cause'] ?? null) && $payload['cause'] !== '' => $payload['cause'],
            default => null,
        };
        if ($code === null) {
            return null;
        }

        return ['code' => $code, 'label' => self::LABELS[$code] ?? self::LABELS['other']];
    }

    public static function label(?string $code): ?string
    {
        return $code === null || $code === '' ? null : (self::LABELS[$code] ?? self::LABELS['other']);
    }

    // ------------------------------------------------------------------ RCA-4: diagnosis evidence + advice

    /**
     * Short plain Indonesian evidence lines of the diagnosis (RCA-4). {placeholders} are filled by evidence().
     * Keep every text short: it is read by CS on the ticket form.
     */
    public const EVIDENCE = [
        'isolir_status' => 'Pelanggan berstatus {status}.',
        'isolir_secret' => 'Akun internet pelanggan di router dimatikan atau diisolir.',
        'isolir_profile' => 'Data profil isolir, status aktif (belum dicek ke router).',
        'isolir_also' => 'Pelanggan juga sedang {status}.',
        'isolir_onu_down_nodate' => 'ONU juga mati. Kalau sudah bayar, kirim teknisi.',
        'isolir_check_line' => 'Kondisi ONU belum terbaca. Kalau sudah bayar, cek kabel dan listrik ONU.',
        'isolir_onu_down' => 'ONU juga mati sejak {time}. Kalau sudah bayar, kirim teknisi.',
        'isolir_router_open' => 'Di tagihan isolir, tapi di router tidak diisolir.',
        'isolir_method_unknown' => 'Isolir tidak lewat profil router. Cek ke NOC kalau sudah bayar.',
        'isolir_secret_active' => 'Status aktif, tapi di router masih diisolir.',
        'router_offline' => 'Router {router} tidak terbaca. Sesi internet semua pelanggannya hilang.',
        'router_blind' => 'Data router {router} belum terbaca.',
        'router_ok' => 'Router {router} normal.',
        'router_ok_since' => 'Router {router} normal, sesi internet aktif sejak {since}.',
        'olt_wide' => 'OLT {olt} bermasalah: banyak pelanggan putus bersamaan sejak {time}.',
        'olt_blind' => 'Data OLT {olt} belum terbaca.',
        'pon_cut' => '{count} dari {total} ONU satu PON putus sejak {time}.',
        'odp_cut' => 'Pelanggan satu ODP ({odp}) putus bersamaan sejak {time}.',
        'onu_los' => 'ONU putus karena kabel (LOS) {time}, dari OLT {olt}.',
        'onu_los_alone' => 'Hanya {n} ONU lain di PON ini yang baru putus.',
        'onu_los_none' => 'Tidak ada ONU lain di PON ini yang baru putus.',
        'onu_los_many' => '{n} ONU lain di PON ini juga putus sekarang.',
        'pon_recovered' => 'PON ini sempat putus {time}, {n} ONU lain sudah pulih, ONU ini belum.',
        'onu_dg' => 'ONU putus karena listrik mati (dying gasp) {time}, dari OLT {olt}.',
        'onu_dg_long' => 'Sudah mati lebih dari 24 jam.',
        'onu_silent' => 'ONU mati sejak {time}. OLT tidak mencatat sebab dan ONU tidak menghubungi sistem.',
        'onu_never' => 'ONU belum pernah aktif di OLT.',
        'onu_unread' => 'Status ONU belum terbaca dari OLT.',
        'onu_recent_down' => 'ONU baru putus {time}, sebabnya belum terbaca.',
        'onu_online' => 'ONU menyala di OLT.',
        'reboot_loop' => 'ONU restart {n} kali dalam 24 jam.',
        'reboot_loop_pattern' => 'Waktu aktif ONU sama dengan waktu sistem naik (ONU yang restart, bukan kabel).',
        'area_power' => '{count} dari {total} ONU satu PON restart bersamaan {time}.',
        'ppp_stuck_log' => 'Router mencatat "sesi sudah aktif" {n} kali dalam 10 menit.',
        'ppp_stuck_session' => 'Router masih menyimpan sesi lama (aktif {session}), ONU baru hidup {cpe}.',
        'ppp_auth_log' => 'Router mencatat gagal login {time}.',
        'ppp_auth_cpe' => 'ONU melaporkan gagal login internet {time}.',
        'ppp_flap' => 'Sesi internet putus-sambung {n} kali dalam 6 jam, ONU tidak restart.',
        'ppp_down' => 'Sesi internet pelanggan mati padahal ONU menyala.',
        'ppp_up' => 'Sesi internet pelanggan aktif.',
        'ppp_unread' => 'Status sesi internet belum terbaca.',
        'wlan_down' => 'WiFi {band} mati, dicek {time}.',
        'wlan_down_old' => 'WiFi {band} tercatat mati, data {time} (bukan cek langsung).',
        'wlan_flap' => 'WiFi putus-sambung {n} kali dalam 6 jam, ONU tidak restart.',
        'wifi_client' => 'Sinyal HP lemah ({rssi} dBm), ONU dan internet normal.',
        'network_ok' => 'Jaringan normal saat dicek.',
        'rx_weak' => 'Redaman {dbm} dBm, batas {limit} dBm.',
        'rx_trend' => 'Redaman makin buruk beberapa hari.',
        'rx_ok' => 'Redaman {dbm} dBm (normal), data {time}.',
        'rx_ok_nodate' => 'Redaman {dbm} dBm (normal).',
        'rx_old' => 'Redaman {dbm} dBm, data lama ({time}).',
        'rx_complaint_ok' => 'Keluhan redaman, tapi redaman sekarang normal.',
        'hist_onu_los' => 'ONU putus karena kabel {n} kali dalam 24 jam, terakhir {time}.',
        'hist_onu_dg' => 'ONU mati listrik {n} kali dalam 24 jam, terakhir {time}.',
        'hist_onu_other' => 'ONU putus {n} kali dalam 24 jam, sebab tidak tercatat, terakhir {time}.',
        'hist_area' => 'Putus bersamaan dengan pelanggan lain satu PON ({time}).',
        'hist_ppp' => 'Internet putus-sambung {n} kali dalam 24 jam, terakhir {time}.',
        'hist_ppp_last' => 'Terakhir putus di router {time} ({reason}).',
        'hist_boot' => 'ONU restart {n} kali dalam 24 jam.',
        'hist_power_home' => 'ONU restart karena listrik mati {n} kali dalam 24 jam.',
        'hist_wlan' => 'WiFi putus-sambung {n} kali dalam 24 jam.',
        'hist_none' => '24 jam terakhir tidak ada putus tercatat.',
        'hist_no_log' => 'Riwayat putus internet belum tercatat untuk router ini.',
        'hist_no_onu_log' => 'Riwayat putus ONU belum tercatat untuk OLT ini.',
        'probe_skipped' => 'Cek {what} dilewati: {why}.',
        'isolir_billing_only' => 'Di tagihan {status}, tapi belum ada tanda isolir di router.',
        'onu_dg_recent' => 'ONU mati listrik (dying gasp) {time}, dari OLT {olt}.',
        'hist_onu_dg_recent' => 'ONU mati listrik (dying gasp) {time}, lalu menyala lagi.',
        'rx_step' => 'Redaman turun mendadak dari {from} ke {to} dBm dalam sehari.',
        'rx_decline' => 'Redaman turun terus 3 hari ({from} ke {to} dBm).',
        'rx_drops_weak' => 'ONU putus {n} kali dalam 24 jam dan redaman lemah.',
        'rx_shared_odp' => '{n} ONU satu ODP redamannya turun bersamaan. Kemungkinan kabel bersama bermasalah.',
        'rx_shared_pon' => '{n} ONU satu PON redamannya turun bersamaan. Kemungkinan kabel bersama bermasalah.',
        'no_olt_data' => 'Pelanggan ini belum punya data OLT.',
        'no_fresh_hw' => 'Belum ada data perangkat yang baru. Cek manual.',
        // PR-D (each behind its own flag, default off)
        'burst_session' => 'Jam {time}, {n} pelanggan putus bersamaan, ONU tetap menyala. Kemungkinan gangguan jalur atau router, bukan di rumah.',
        'area_power_group' => 'Kemungkinan listrik padam di area {area} ({n} ONU mati bersamaan {time}).',
        'traffic_active' => 'Internet aktif, pemakaian {rate} ({time}).',
        'traffic_idle' => 'Sesi aktif tapi hampir tanpa pemakaian sejak {time}.',
        'traffic_conflict' => 'Data bertentangan: internet masih jalan ({rate}, {time}).',
    ];

    /** Cause code (OutageRootCause value) => suggested action code (stored in outage_diagnoses.suggested_action). */
    public const ACTION_BY_CAUSE = [
        'drop_los' => 'kirim_teknisi_fo',
        'pon_cut' => 'kirim_teknisi_fo',
        'odp_cut' => 'kirim_teknisi_fo',
        'cpe_dead' => 'teknisi_onu_pengganti',
        'cpe_reboot_loop' => 'teknisi_onu_pengganti',
        'power_off' => 'tanya_listrik',
        'wlan_down' => 'bantu_wifi',
        'wlan_flap' => 'restart_onu',
        'wifi_client' => 'masalah_hp',
        'pppoe_auth' => 'teruskan_noc',
        'pppoe_flap' => 'teruskan_noc',
        'pppoe_stuck' => 'teruskan_noc',
        'core_router' => 'gangguan_besar',
        'olt_down' => 'gangguan_besar',
        'upstream' => 'gangguan_besar',
        'data_blind' => 'cek_manual',
        'isolir_admin' => 'cek_tagihan',
        'optical_degrade' => 'jadwal_maintenance',
        'unknown' => 'belum_tahu',
    ];

    /** Suggested action code => the one line CS reads. */
    public const ACTION_TEXT = [
        'kirim_teknisi_fo' => 'Kirim teknisi FO',
        'teknisi_onu_pengganti' => 'Kirim teknisi bawa ONU pengganti',
        'tanya_listrik' => 'Tanya pelanggan: listrik atau adaptor ONU',
        'bantu_wifi' => 'Bantu nyalakan WiFi lewat telepon',
        'restart_onu' => 'Restart ONU, kalau berulang kirim teknisi',
        'masalah_hp' => 'Masalah di HP atau jarak, bukan jaringan',
        'teruskan_noc' => 'Teruskan ke NOC',
        'gangguan_besar' => 'Gangguan besar, NOC sudah menangani',
        'cek_manual' => 'Data belum terbaca, cek manual',
        'cek_tagihan' => 'Cek tagihan. Sudah bayar tapi tetap mati: kirim teknisi',
        'buka_isolir' => 'Sudah bayar? Minta NOC buka isolir',
        'jadwal_maintenance' => 'Jadwalkan perbaikan kabel',
        'belum_tahu' => 'Belum bisa ditebak',
    ];

    /** @param array<string,scalar|null> $vars */
    public static function evidence(string $key, array $vars = []): string
    {
        $text = self::EVIDENCE[$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) ($value ?? '-'), $text);
        }

        return $text;
    }

    public static function actionFor(?string $cause): string
    {
        return self::ACTION_BY_CAUSE[$cause ?? 'unknown'] ?? 'belum_tahu';
    }

    public static function actionText(?string $action): string
    {
        return self::ACTION_TEXT[$action ?? 'belum_tahu'] ?? self::ACTION_TEXT['belum_tahu'];
    }
}
