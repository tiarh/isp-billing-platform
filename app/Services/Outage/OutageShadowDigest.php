<?php

namespace App\Services\Outage;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\MonitoringAdmissionGate;
use App\Support\WaGroupJid;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap 2.4 PR-4: the daily shadow summary. Counts and OLT / PON labels only plus a Detail link (never customer names,
 * numbers or ids). Sent only to outage_shadow_log_jid through the same guard as the detector summary
 * (OutageShadowNotifier::refusalReason: never a PSB group; Report / ops only with the allow flag).
 * Default OFF (setting outage_shadow_digest_enabled).
 */
class OutageShadowDigest
{
    public const CUMULATIVE_DAYS = 14;

    public static function enabled(): bool
    {
        try {
            return in_array(Setting::get('outage_shadow_digest_enabled'), [1, '1', true, 'true', 'on', 'yes'], true);
        } catch (\Throwable) {
            return false; // fail closed
        }
    }

    /** Schedule `when()` filter: must never throw. */
    public static function shouldRun(): bool
    {
        try {
            return self::enabled() && app(MonitoringAdmissionGate::class)->allowsMonitoringDashboard();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Match tickets and build the summary text (no send).
     *
     * @return array{text:string,report:array<string,mixed>,matcher:array<string,int>}
     */
    public function compose(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $reporter = new OutageShadowReporter;

        // dryRun: matches are computed but not written to outage_signals.
        $matcher = (new OutageTicketMatcher)->run($now->copy()->subDays(self::CUMULATIVE_DAYS), $now, $dryRun);
        $day = $reporter->build($now->copy()->subDay(), $now);
        $cum = $reporter->build($now->copy()->subDays(self::CUMULATIVE_DAYS), $now);

        return ['text' => $this->text($now, $day, $cum), 'report' => ['day' => $day, 'cumulative' => $cum], 'matcher' => $matcher];
    }

    /**
     * @param  array<string,mixed>  $day
     * @param  array<string,mixed>  $cum
     */
    public function text(Carbon $now, array $day, array $cum): string
    {
        $lbl = ['mass_los' => 'massal', 'single_los' => 'LOS individual', 'down_unknown' => 'offline tanpa sebab', 'weak_rx' => 'redaman', 'pppoe_down' => 'PPPoE mati'];
        $lines = ['[SHADOW 2.4] Ringkasan harian '.$now->copy()->timezone('Asia/Jakarta')->format('d/m/Y')];

        $opened = 0;
        $parts = [];
        foreach ($day['signals'] as $type => $s) {
            $opened += (int) $s['opened'];
            if (isset($lbl[$type]) && $s['would_ticket'] > 0) {
                $parts[] = $lbl[$type].' '.$s['would_ticket'];
            }
        }
        $wouldTotal = array_sum(array_map(fn ($s) => (int) $s['would_ticket'], $day['signals']));
        $lines[] = '24 jam: kejadian dicatat '.$opened.', seharusnya tiket '.$wouldTotal.($parts !== [] ? ' ('.implode(', ', $parts).')' : '');

        $perDay = 0.0;
        foreach ($cum['signals'] as $s) {
            $perDay += (float) $s['would_ticket_per_day'];
        }
        $lines[] = 'Perkiraan tiket per hari ('.(int) $cum['days'].' hari): '.round($perDay, 1);

        $would = 0;
        $matched = 0;
        $tp = 0;
        $fp = 0;
        foreach ($cum['signals'] as $s) {
            $would += (int) $s['would_ticket'];
            $matched += (int) $s['matched'];
            $tp += (int) $s['label_tp'];
            $fp += (int) $s['label_fp'];
        }
        $lines[] = 'Presisi (cocok tiket nyata): '.($would > 0 ? round($matched / $would * 100).'% ('.$matched.'/'.$would.')' : 'belum ada data')
            .'; label NOC: '.(($tp + $fp) > 0 ? round($tp / ($tp + $fp) * 100).'% ('.$tp.' benar, '.$fp.' salah)' : 'belum ada');

        $r = $cum['recall'];
        $lines[] = 'Recall: '.($r['recall'] !== null ? round($r['recall'] * 100).'% ('.$r['covered'].'/'.$r['monitorable_tickets'].' tiket pelanggan terpantau)' : 'belum ada data');

        $leads = [];
        foreach ($cum['signals'] as $s) {
            if ($s['lead_time_median_min'] !== null) {
                $leads[] = (float) $s['lead_time_median_min'];
            }
        }
        if ($leads !== []) {
            sort($leads);
            $lines[] = 'Lead time (median dari median per jenis): '.(int) round($leads[intdiv(count($leads), 2)]).' menit';
        }

        if ($cum['coverage']['ratio'] !== null) {
            $lines[] = 'Cakupan pelanggan aktif terpantau: '.round($cum['coverage']['ratio'] * 100).'%';
        }

        if ($day['top_pon'] !== []) {
            $lines[] = '3 PON teratas: '.implode('; ', array_map(fn ($p) => $p['olt'].' PON '.$p['pon'].' ('.$p['count'].')', $day['top_pon']));
        }

        // RCA-1 (lines only when there is something to say, so a quiet day keeps its old text).
        $sup = $cum['suppression'] ?? [];
        if ((int) ($sup['psb_customers_aged_out'] ?? 0) > 0 || (int) ($sup['migration_customers_aged_out'] ?? 0) > 0) {
            $lines[] = 'Tahan PSB/migrasi: PSB '.(int) $sup['psb_customers_suppressing'].' menahan (≤'.(int) $sup['psb_days'].' hari, '.(int) $sup['psb_customers_aged_out'].' diabaikan); migrasi '
                .(int) $sup['migration_customers_suppressing'].' menahan (≤'.(int) $sup['migration_days'].' hari, '.(int) $sup['migration_customers_aged_out'].' diabaikan)';
        }
        $weekly = $day['weak_rx_weekly'] ?? [];
        if ((int) ($weekly['count'] ?? 0) > 0) {
            $lines[] = 'Redaman lemah daftar mingguan: '.(int) $weekly['count'].' pelanggan di luar batas '.(int) $weekly['max_per_day'].' tiket/hari';
        }
        // An OLT that is both "data tidak terbaca" and "PERINGATAN data basi" gets one line: the warning below (it has the minutes).
        $staleNames = array_map(fn ($o) => (string) ($o['olt'] ?? ''), (array) ($day['stale_olts'] ?? []));
        foreach ((array) ($day['data_blind']['open'] ?? []) as $b) {
            if (($b['kind'] ?? 'olt') !== 'router' && in_array((string) ($b['label'] ?? ''), $staleNames, true)) {
                continue;
            }
            $lines[] = 'Data tidak terbaca: '.$b['label'].' - '.$b['reason'];
        }

        $ic = $cum['incident_closes'] ?? [];
        if ((int) ($ic['auto_closed'] ?? 0) > 0) {
            $lines[] = 'Tiket gangguan tutup: '.(int) $ic['with_cause'].' dengan penyebab, '.(int) $ic['auto_closed'].' Ditutup otomatis';
        }

        foreach ($day['stale_olts'] as $o) {
            $lines[] = 'PERINGATAN data basi: '.$o['olt'].($o['minutes_since_success'] !== null ? ' (sukses terakhir '.$o['minutes_since_success'].' menit lalu)' : ' (belum ada run sukses)');
        }

        // Link to the Deteksi Gangguan tab for the same day window (counts only here; no names).
        $to = $now->copy()->timezone('Asia/Jakarta');
        $lines[] = 'Detail: '.rtrim((string) config('app.url'), '/')
            .route('monitoring.index', ['tab' => 'deteksi', 'from' => $to->copy()->subDay()->format('Y-m-d'), 'to' => $to->format('Y-m-d')], false);

        $lines[] = '(hanya dicatat; tidak ada tiket dan tidak ada WA ke pelanggan)';

        return implode("\n", $lines);
    }

    /**
     * Compose + send once per WIB day to outage_shadow_log_jid. Returns a status code:
     * sent|disabled|no_jid|refused:<reason>|quiet_hours|already_sent|failed
     */
    public function send(?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now) : now();

        if (! self::enabled()) {
            return 'disabled';
        }
        $jid = OutageSettings::shadowLogJid();
        if ($jid === '') {
            return 'no_jid';
        }
        $reason = (new OutageShadowNotifier)->refusalReason($jid, OutageSettings::shadowAllowReportGroup());
        if ($reason !== null) {
            Log::warning('[Outage] shadow digest not sent: target refused', ['reason' => $reason]);

            return 'refused:'.$reason;
        }
        if (OutageSettings::isQuietHour((int) $now->copy()->timezone('Asia/Jakarta')->format('G'))) {
            return 'quiet_hours';
        }

        $key = 'outage-shadow-digest:'.$now->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        if (! Cache::add($key, 1, now()->addDays(2))) {
            return 'already_sent';
        }

        try {
            $text = $this->compose($now)['text'];
            $result = app(EvolutionApiService::class)->forChannel('ops')->sendToGroup(WaGroupJid::normalize($jid), $text);
            if (($result['success'] ?? false) === true) {
                return 'sent';
            }
            Log::warning('[Outage] shadow digest send failed');
        } catch (\Throwable $e) {
            Log::warning('[Outage] shadow digest error', ['exception_class' => $e::class]);
        }
        Cache::forget($key); // let a retry run send it

        return 'failed';
    }
}
