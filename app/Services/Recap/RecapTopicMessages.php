<?php

namespace App\Services\Recap;

use App\Models\Setting;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;

/**
 * Plain-text WA replies of the "#rekap <topic>" commands (non-psb topics) and the daily
 * "Kesehatan ONU" section. ASCII only, *bold* only. Echo safety: no text here contains
 * "#migrasi", "#psb" or "#pppoe", and no line starts with "#" (a reply that came back through
 * the webhook must never trigger another bot). PII: never phone, NIK, address, coordinates,
 * PPPoE password, raw text or photo paths; "semua" mode carries no customer names at all.
 */
class RecapTopicMessages
{
    public const MAX_CHARS = 3500;

    /** @param ?int $maxDays  null = the migrasi command's max-days setting (psb_recap_cmd_max_days) */
    public function usage(string $topic, ?int $maxDays = null): string
    {
        $maxDays ??= (int) Setting::get('psb_recap_cmd_max_days', RecapCommandParser::DEFAULT_MAX_DAYS);
        $months = self::approxMonths($maxDays);
        $limit = $months >= 1 ? $months.' bulan' : max(1, $maxDays).' hari';

        return match ($topic) {
            RecapTopics::ONU => implode("\n", [
                '*Rekap kesehatan ONU per daerah*',
                'Format: *#rekap onu <daerah|semua>*',
                'Contoh:',
                '- #rekap onu klampok',
                '- #rekap onu semua',
                'Hasil = kondisi ONU saat ini (tanggal tidak dipakai).',
                'Hasil: ringkasan di chat + file Excel daftar ONU bermasalah.',
            ]),
            RecapTopics::MIGRASI => implode("\n", [
                '*Rekap migrasi per daerah*',
                'Format: *#rekap migrasi <daerah|semua> [tanggal]*',
                'Contoh:',
                '- #rekap migrasi klampok 1-14 okt',
                '- #rekap migrasi semua kemarin',
                'Tanpa tanggal = tanggal 1 bulan ini sampai hari ini. Maksimal '.$limit.'.',
                'Hasil: ringkasan di chat + file Excel.',
            ]),
            default => 'Perintah rekap tidak dikenal.',
        };
    }

    /** @param list<string> $names */
    public function areaAmbiguous(string $query, array $names): string
    {
        return implode("\n", [
            'Daerah "'.self::clean($query, 40).'" cocok dengan lebih dari satu area:',
            implode(', ', array_map(fn (string $n): string => self::clean($n, 30), array_slice($names, 0, 8))),
            'Ketik ulang dengan nama area yang lebih lengkap.',
        ]);
    }

    /** @param list<string> $names */
    public function areaUnmatched(string $query, array $names): string
    {
        $lines = ['Daerah "'.self::clean($query, 40).'" tidak ditemukan.'];
        if ($names !== []) {
            $lines[] = 'Maksud Anda: '.implode(', ', array_map(fn (string $n): string => self::clean($n, 30), array_slice($names, 0, 5))).'?';
        }
        $lines[] = 'Cek ejaan, atau minta admin menambah alias area di eBilling.';

        return implode("\n", $lines);
    }

    public function rangeTooLong(int $maxDays): string
    {
        $months = self::approxMonths($maxDays);

        return 'Rentang tanggal terlalu panjang (maksimal '.$maxDays.' hari'.($months >= 1 ? ' / '.$months.' bulan' : '').'). Persempit tanggalnya.';
    }

    /** Whole months closest to a max-days setting (92 days = 3 months); 0 when under ~2 weeks. */
    private static function approxMonths(int $maxDays): int
    {
        return (int) round(max(1, $maxDays) / 30.4);
    }

    public function invalidDate(string $topic): string
    {
        if ($topic === RecapTopics::MIGRASI) {
            return implode("\n", [
                'Tanggal tidak valid. Contoh yang bisa dipakai:',
                '- #rekap migrasi klampok 1-14 okt',
                '- #rekap migrasi semua kemarin',
                '- #rekap migrasi gadang 12 okt',
            ]);
        }

        return 'Tanggal tidak valid. Rekap '.($topic === RecapTopics::ONU ? 'ONU' : $topic).' selalu kondisi saat ini, cukup ketik daerahnya.';
    }

    public function rateLimitedGroup(): string
    {
        return 'Rekap baru saja diminta di grup ini. Tunggu sekitar 20 detik lalu ketik lagi.';
    }

    public function rateLimitedSender(): string
    {
        return 'Batas rekap tercapai (maksimal 10 per jam per orang). Coba lagi nanti.';
    }

    public function documentFailed(): string
    {
        return 'File Excel gagal dikirim, coba ketik ulang command-nya.';
    }

    public function internalError(): string
    {
        return 'Rekap gagal diproses karena kesalahan internal. Coba ketik ulang command-nya sebentar lagi.';
    }

    /**
     * @param  array<string, mixed>  $data  MigrationRecapService::collect()
     */
    public function migrasiRecap(array $data): string
    {
        $text = $this->migrasiText($data, true);
        if (mb_strlen($text) > self::MAX_CHARS - 100) {
            $text = $this->migrasiText($data, false);
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS - 40).'...'."\n".'(dipotong, lihat file Excel)';
        }

        return $text;
    }

    /** @param array<string, mixed> $data */
    private function migrasiText(array $data, bool $withNames): string
    {
        $label = self::clean((string) $data['area_label'], 40);
        if ((int) $data['total'] === 0) {
            return 'Tidak ada migrasi tercatat untuk '.$label.' pada periode ini.';
        }

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        $s = $data['selesai'];
        $b = $data['belum'];
        $evidence = ! empty($data['evidence']);

        $lines = [
            '*REKAP MIGRASI - '.$label.'*',
            'Periode: '.$from->format('d/m/Y').' - '.$to->format('d/m/Y').' ('.$data['days'].' hari)',
            'Dibuat: '.OpsTime::format($data['generated_at']),
            '',
            '*Selesai: '.$s['lapangan'].' pelanggan*',
        ];
        if ($evidence && $s['lapangan'] > 0) {
            $lines[] = 'Status DB '.$s['db'].' | Otomatis (ONU + PPPoE online) '.$s['auto'];
        }
        if ($s['impor'] > 0) {
            $lines[] = '(+'.$s['impor'].' impor data, tidak dihitung lapangan)';
        }
        $lines[] = '*Masuk: '.$data['masuk'].' laporan*';
        $lines[] = '';
        $lines[] = '*Belum selesai (posisi sekarang, semua tanggal): '.$b['total'].'*';
        if ($b['total'] > 0) {
            $parts = [];
            foreach (MigrationRecapService::bucketLabels() as $key => $text) {
                $parts[] = $text.' '.$b[$key];
            }
            $lines[] = implode(' | ', $parts);
        }
        if ($evidence) {
            if ($data['online_pending'] > 0) {
                $lines[] = 'ONU sudah online, status DB belum diperbarui: '.$data['online_pending'];
            }
            if ($data['perlu_dicek'] > 0) {
                $lines[] = 'Perlu dicek admin: '.$data['perlu_dicek'];
            }
        }
        if (! empty($data['evidence_failed'])) {
            $lines[] = '(bukti ONU online gagal dibaca, angka status DB tetap benar)';
        }

        if ($s['by_date'] !== []) {
            $parts = [];
            foreach ($s['by_date'] as $date => $n) {
                $parts[] = CarbonImmutable::parse((string) $date)->format('d/m').': '.$n;
            }
            $lines[] = '';
            $lines[] = '*Per tanggal selesai*';
            $lines[] = implode(' | ', $parts);
        }
        if ($s['by_olt'] !== []) {
            $lines[] = '';
            $lines[] = '*Per OLT baru*';
            foreach ($s['by_olt'] as $olt => $n) {
                $lines[] = '- '.self::clean((string) $olt, 20).': '.$n;
            }
        }
        if ($s['by_tech'] !== []) {
            $lines[] = '';
            $lines[] = '*Per teknisi (10 teratas)*';
            foreach (array_slice($s['by_tech'], 0, MigrationRecapService::TOP_TECH, true) as $tech => $n) {
                $lines[] = '- '.self::clean((string) $tech, 28).': '.$n;
            }
        }
        if ($data['all'] && $s['by_area'] !== []) {
            $lines[] = '';
            $lines[] = '*Per area ('.MigrationRecapService::TOP_AREAS.' teratas)*';
            foreach (array_slice($s['by_area'], 0, MigrationRecapService::TOP_AREAS, true) as $area => $n) {
                $lines[] = '- '.self::clean((string) $area, 24).': '.$n;
            }
        }

        if ($withNames && ! $data['all'] && $data['total'] <= MigrationRecapService::NAMES_MAX) {
            $lines[] = '';
            $lines[] = '*Daftar nama*';
            foreach ($data['people'] as $i => $p) {
                $date = $p['synced'] !== '' ? $p['synced'] : $p['created'];
                $state = match (true) {
                    $p['sel'] && $p['bulk'] => 'selesai (impor)',
                    $p['sel'] && $p['auto'] => 'selesai (otomatis)',
                    $p['sel'] => 'selesai (DB)',
                    $p['open'] => 'belum - '.mb_strtolower(MigrationRecapService::bucketLabels()[$p['bucket']] ?? 'proses'),
                    default => 'masuk',
                };
                $rw = $p['rw'] !== '' ? ' - RW '.self::clean((string) $p['rw'], 6) : '';
                $lines[] = ($i + 1).'. '.($date !== '' ? CarbonImmutable::parse($date)->format('d/m').' ' : '')
                    .self::clean(mb_strtoupper((string) ($p['name'] !== '' ? $p['name'] : 'TANPA NAMA')), 28).' - '.$state.$rw;
            }
        }

        $lines[] = '';
        $lines[] = 'File Excel (rincian lengkap) dikirim setelah pesan ini.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data  OnuHealthService::collect()
     */
    public function onuRecap(array $data): string
    {
        $limit = OnuHealthService::TOP_PRIORITY_TEXT;
        $text = $this->onuText($data, $limit);
        while ($limit > 0 && mb_strlen($text) > self::MAX_CHARS - 100) {
            $limit = $limit > 3 ? 3 : 0;
            $text = $this->onuText($data, $limit);
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS - 40).'...'."\n".'(dipotong, lihat file Excel)';
        }

        return $text;
    }

    /** @param array<string, mixed> $data */
    private function onuText(array $data, int $priorityLimit): string
    {
        $c = $data['counts'];
        $all = (bool) $data['all'];
        $generated = $data['generated_at'];
        $head = 'Posisi: '.OpsTime::format($generated);
        $head .= $data['data_at'] !== null ? ' (data OLT terakhir '.OpsTime::local($data['data_at'])->format('H:i').')' : ' (data OLT belum ada)';

        $lines = [
            '*REKAP KESEHATAN ONU - '.self::clean((string) $data['area_label'], 40).'*',
            $head,
            'Pelanggan aktif: '.$c['aktif'].' | terpantau: '.$c['terpantau'].' | belum terpetakan: '.($c['belum_terpetakan'] + $c['ambigu']).' | data basi: '.$c['data_basi'],
        ];
        if (! empty($data['date_ignored'])) {
            $lines[] = '(tanggal diabaikan: rekap ONU selalu kondisi saat ini)';
        }
        foreach ($data['by_olt'] as $o) {
            if (! empty($o['stale_olt']) && ($o['terpantau'] + $o['stale']) > 0) {
                $lines[] = 'Catatan: data OLT '.self::clean((string) $o['name'], 20).' sudah lama'.($o['last'] !== null ? ' (terakhir '.OpsTime::local($o['last'])->format('d/m H:i').')' : '').'.';
            }
        }
        if ($c['aktif'] === 0) {
            $lines[] = '';
            $lines[] = 'Tidak ada pelanggan aktif untuk '.self::clean((string) $data['area_label'], 40).'.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '*Offline: '.$c['offline'].' pelanggan*';
        if ($c['offline'] > 0) {
            $lines[] = 'LOS (kabel) '.$c['los'].' | Mati listrik '.$c['mati_listrik'].' | Lain/tidak terbaca '.$c['lain'];
            if (! empty($data['usage_stale'])) {
                $lines[] = 'Lama offline belum bisa dihitung (data trafik belum diperbarui).';
            } else {
                $lines[] = 'Offline >= '.$data['long_days'].' hari (perkiraan): '.$c['offline_lama'];
            }
            if ($c['tiket'] > 0) {
                $lines[] = 'Ada tiket terbuka: '.$c['tiket'];
            }
        }
        if ($c['ex_migrasi'] + $c['ex_penarikan'] + $c['ex_cek_pemetaan'] > 0) {
            $lines[] = 'Dikecualikan: sedang migrasi '.$c['ex_migrasi'].' | penarikan '.$c['ex_penarikan'].' | cek pemetaan '.$c['ex_cek_pemetaan'];
        }

        $lines[] = '';
        $lines[] = '*RX lemah (< '.self::dbm((float) $data['weak_dbm']).' dBm): '.$c['weak'].' pelanggan*';
        if ($c['weak'] > 0 && $c['weak_worst'] !== null) {
            $lines[] = 'Terburuk: '.self::dbm((float) $c['weak_worst']).' dBm';
        }
        if ($c['offline'] === 0 && $c['weak'] === 0) {
            $lines[] = 'Semua ONU terpantau normal.';
        }

        if ($data['by_odp'] !== []) {
            $parts = [];
            foreach ($data['by_odp'] as $odp => $n) {
                $parts[] = self::clean((string) $odp, 24).': '.$n;
            }
            $lines[] = '';
            $lines[] = '*Per ODP ('.OnuHealthService::TOP_ODP.' teratas, offline + RX lemah)*';
            $lines[] = implode(' | ', $parts);
        }

        if ($data['by_olt'] !== []) {
            $lines[] = '';
            $lines[] = '*Per OLT*';
            foreach ($data['by_olt'] as $o) {
                $lines[] = '- '.self::clean((string) $o['name'], 20).': terpantau '.$o['terpantau'].' | offline '.$o['offline'].' | RX lemah '.$o['weak']
                    .($o['stale'] > 0 ? ' | data basi '.$o['stale'] : '');
            }
        }

        if ($all) {
            $rows = [];
            foreach ($data['by_area'] as $label => $a) {
                if ($a['offline'] + $a['weak'] > 0) {
                    $rows[] = '- '.self::clean((string) $label, 24).': offline '.$a['offline'].' | RX lemah '.$a['weak'];
                }
            }
            if ($rows !== []) {
                $lines[] = '';
                $lines[] = '*Per area (15 teratas)*';
                array_push($lines, ...array_slice($rows, 0, 15));
            }
        } elseif ($priorityLimit > 0) {
            $list = [];
            foreach ($data['priority'] as $it) {
                if (! in_array($it['status'], ['offline', 'weak_rx'], true)) {
                    continue;
                }
                $list[] = $it;
                if (count($list) >= $priorityLimit) {
                    break;
                }
            }
            if ($list !== []) {
                $lines[] = '';
                $lines[] = '*Prioritas ('.count($list).' teratas)*';
                foreach ($list as $i => $it) {
                    $lines[] = ($i + 1).'. '.self::clean(mb_strtoupper((string) $it['name']), 28).' - '.self::itemCondition($it)
                        .($it['odp'] !== '' ? ' - '.self::clean((string) $it['odp'], 24) : '');
                }
            }
        }

        if ($data['problem_total'] > 0) {
            $lines[] = '';
            $lines[] = 'Daftar lengkap ada di file Excel.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $it */
    private static function itemCondition(array $it): string
    {
        if ($it['status'] === 'weak_rx') {
            return 'RX '.self::dbm((float) $it['rx']).' dBm';
        }
        $label = match ($it['kind']) {
            OnuHealthClassifier::LOS => 'LOS',
            OnuHealthClassifier::MATI_LISTRIK => 'Mati listrik',
            default => 'Offline',
        };
        if ($it['days'] === null) {
            return $label;
        }

        return $label.' '.($it['days'] > OnuHealthService::USAGE_LOOKBACK_DAYS ? '> '.OnuHealthService::USAGE_LOOKBACK_DAYS : $it['days']).' hari';
    }

    /**
     * Daily report "Kesehatan ONU" section lines (counts and area / OLT names only).
     *
     * @param  ?array<string, mixed>  $onu  OnuHealthService::collect(), null = failed to read
     * @return list<string>
     */
    public function dailyOnuSection(?array $onu, CarbonImmutable $sentAt): array
    {
        $lines = ['*Kesehatan ONU (posisi '.$sentAt->timezone(OpsTime::TZ)->format('H:i').')*'];
        if ($onu === null) {
            $lines[] = '(data ONU gagal dibaca, tim teknis sudah dicatat)';

            return $lines;
        }

        $c = $onu['counts'];
        $lines[] = 'Terpantau '.$c['terpantau'].' dari '.$c['aktif'].' pelanggan aktif (belum terpetakan '.($c['belum_terpetakan'] + $c['ambigu']).', data basi '.$c['data_basi'].')';
        $lines[] = 'Offline: '.$c['offline'].($c['offline'] > 0 ? ' (LOS '.$c['los'].' | Mati listrik '.$c['mati_listrik'].' | Lain '.$c['lain'].')' : '');
        if ($c['offline'] > 0) {
            $lines[] = ! empty($onu['usage_stale'])
                ? 'Offline >= '.$onu['long_days'].' hari: belum bisa dihitung (data trafik belum diperbarui)'
                : 'Offline >= '.$onu['long_days'].' hari (perkiraan): '.$c['offline_lama'];
        }
        $lines[] = 'RX lemah (< '.self::dbm((float) $onu['weak_dbm']).' dBm): '.$c['weak'];

        if ($onu['by_olt'] !== []) {
            $lines[] = 'Per OLT:';
            foreach ($onu['by_olt'] as $o) {
                $lines[] = '- '.self::clean((string) $o['name'], 20).': offline '.$o['offline'].' | RX lemah '.$o['weak']
                    .($o['stale'] > 0 ? ' | data basi '.$o['stale'] : '').(! empty($o['stale_olt']) ? ' (data lama)' : '');
            }
        }

        $areas = [];
        foreach ($onu['by_area'] as $label => $a) {
            if ($a['offline'] + $a['weak'] > 0) {
                $areas[] = '- '.self::clean((string) $label, 24).': offline '.$a['offline'].' | RX lemah '.$a['weak'];
            }
        }
        if ($areas !== []) {
            $lines[] = 'Area teratas:';
            array_push($lines, ...array_slice($areas, 0, 5));
        }

        return $lines;
    }

    public static function dbm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /** DB text in a reply: single line, ASCII, no "#" (a mid-line "#psb" would still be a trigger), no formatting chars. */
    public static function clean(string $label, int $max = 28): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = (string) preg_replace('/[^\x20-\x7E]|[#*_~]/', '', $label);
        $label = ltrim($label, "/!- \t");

        return mb_substr($label, 0, $max);
    }
}
