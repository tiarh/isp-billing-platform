<?php

namespace App\Services\Report;

use App\Services\Recap\RecapTopicMessages;
use Carbon\CarbonImmutable;

/**
 * Plain-text WA message of the daily Skynet Report. ASCII only, *bold* only, never any
 * customer-level data: counts and area names only. Must never look like a bot command or a technician progress report
 * (no "progress"/"progres" word, no line starting with "#").
 */
class DailyReportMessages
{
    public const MAX_CHARS = 3500;

    private const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const MONTHS_SHORT = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const MONTHS_LONG = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * @param  array{psb: ?array<string, mixed>, psb_mtd: ?array<string, mixed>, migration: ?array<string, mixed>, errors: list<string>}  $report
     * @param  CarbonImmutable  $day  reported WIB day
     * @param  CarbonImmutable  $sentAt  real send time (WIB)
     */
    public function daily(array $report, CarbonImmutable $day, CarbonImmutable $sentAt, bool $footer, bool $onuFooter = false, bool $migrasiFooter = false): string
    {
        $day = $day->timezone('Asia/Jakarta');
        $sentAt = $sentAt->timezone('Asia/Jakarta');

        $lines = [
            '*LAPORAN HARIAN SKYNET*',
            'Data: '.self::DAYS[(int) $day->format('w')].', '.$day->format('d/m/Y').' (00:00-23:59 WIB)',
            'Dikirim: '.$sentAt->format('d/m/Y H:i').' WIB',
            '',
        ];

        array_push($lines, ...$this->psbSection($report['psb'] ?? null, $report['psb_mtd'] ?? null));
        // "Draft PSB dan kendala": present only when the builder added the key (flag report_daily_psb_detail_enabled).
        if (array_key_exists('psb_detail', $report)) {
            $lines[] = '';
            array_push($lines, ...$this->psbDetailSection($report['psb_detail'], $sentAt));
        }
        $lines[] = '';
        array_push($lines, ...$this->migrationSection($report['migration'] ?? null, $sentAt));

        // "Kesehatan ONU": present only when the builder added the key (flag report_daily_onu_enabled).
        if (array_key_exists('onu', $report)) {
            $lines[] = '';
            array_push($lines, ...(new RecapTopicMessages)->dailyOnuSection($report['onu'], $sentAt));
        }

        if ($footer || $onuFooter || $migrasiFooter) {
            $lines[] = '';
            if ($footer) {
                $lines[] = 'Rincian PSB: ketik #rekap psb semua kemarin';
            }
            if ($migrasiFooter) {
                $lines[] = 'Rincian migrasi: ketik #rekap migrasi semua kemarin';
            }
            if ($onuFooter) {
                $lines[] = 'Rincian ONU: ketik #rekap onu semua';
            }
        }

        $text = implode("\n", $lines);
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS - 4).'...';
        }

        return $text;
    }

    /**
     * @param  ?array<string, mixed>  $psb
     * @param  ?array<string, mixed>  $mtd
     * @return list<string>
     */
    private function psbSection(?array $psb, ?array $mtd): array
    {
        $lines = ['*PSB (pasang baru)*'];
        if ($psb === null) {
            $lines[] = '(data PSB gagal dibaca, tim teknis sudah dicatat)';

            return $lines;
        }

        $total = (int) $psb['total'];
        $lines[] = 'Total kemarin: '.$total.' pelanggan';
        $s = $psb['by_status'];
        $lines[] = 'Aktif '.($s['aktif'] ?? 0).' | Belum aktif '.($s['belum aktif'] ?? 0).' | Isolir '.($s['isolir'] ?? 0).' | Putus '.($s['putus'] ?? 0);
        $lines[] = 'Sumber: WA '.($psb['by_source']['WA'] ?? 0).' | Non-WA '.($psb['by_source']['Non-WA'] ?? 0);
        if ((int) ($psb['excluded_migration'] ?? 0) > 0) {
            $lines[] = 'Dikecualikan (hasil migrasi): '.(int) $psb['excluded_migration'];
        }
        if ($total > 0 && ! empty($psb['by_area'])) {
            $lines[] = 'Per area:';
            foreach (MigrationDailyStats::topAreas($psb['by_area']) as $label => $count) {
                $lines[] = '- '.self::clean((string) $label).': '.$count;
            }
        }
        if ($mtd !== null) {
            $from = CarbonImmutable::parse((string) $mtd['from']);
            $to = CarbonImmutable::parse((string) $mtd['to']);
            $lines[] = 'Bulan '.self::MONTHS_LONG[(int) $to->format('n')]
                .' ('.$from->format('j').'-'.$to->format('j').' '.self::MONTHS_SHORT[(int) $to->format('n')].'): '
                .(int) $mtd['total'].' pelanggan';
        }

        return $lines;
    }

    /**
     * Counts only. Lines with 0 are hidden, except the first two.
     *
     * @param  ?array<string, mixed>  $d
     * @return list<string>
     */
    private function psbDetailSection(?array $d, CarbonImmutable $sentAt): array
    {
        $lines = ['*Draft PSB dan kendala*'];
        if ($d === null) {
            $lines[] = '(data draft gagal dibaca, tim teknis sudah dicatat)';

            return $lines;
        }

        $masuk = $d['masuk'];
        $lines[] = 'Draft masuk kemarin: '.(int) $masuk['total'].' (jadi pelanggan '.(int) $masuk['jadi'].', batal '.(int) $masuk['batal'].')';

        $open = $d['open'];
        $lines[] = 'Belum selesai (posisi '.$sentAt->format('H:i').'): '.(int) $open['total'];
        if ((int) $open['total'] > 0) {
            $stages = [];
            foreach ($open['stages'] as $label => $count) {
                $stages[] = self::clean((string) $label).' '.(int) $count;
            }
            $lines[] = implode(' | ', $stages);
            foreach (MigrationDailyStats::topAreas($open['by_area'], 5) as $label => $count) {
                $lines[] = '- '.self::clean((string) $label).': '.(int) $count;
            }
            if ((int) $open['stuck'] > 0) {
                $lines[] = 'Macet lebih dari '.(int) $open['stuck_days'].' hari: '.(int) $open['stuck'];
            }
        }

        $failed = $d['failed'];
        if ((int) $failed['total'] > 0) {
            $parts = [];
            foreach ($failed['buckets'] as $label => $count) {
                $parts[] = self::clean((string) $label).' '.(int) $count;
            }
            $lines[] = 'Gagal kemarin: '.(int) $failed['total'].' ('.implode(', ', $parts).')';
        }

        return $lines;
    }

    /**
     * @param  ?array<string, mixed>  $m
     * @return list<string>
     */
    private function migrationSection(?array $m, CarbonImmutable $sentAt): array
    {
        $lines = ['*Migrasi*'];
        if ($m === null) {
            $lines[] = '(data migrasi gagal dibaca, tim teknis sudah dicatat)';

            return $lines;
        }

        $selesai = $m['selesai'];
        $head = 'Selesai kemarin: '.(int) $selesai['lapangan'].' pelanggan';
        $extra = is_array($m['extra'] ?? null) ? $m['extra'] : null;
        if ($extra !== null && (int) $selesai['lapangan'] > 0) {
            $head .= ' (status DB '.(int) $extra['db'].' + otomatis '.(int) $extra['otomatis'].')';
        }
        if ((int) $selesai['impor'] > 0) {
            $head .= ' (+'.(int) $selesai['impor'].' impor data, tidak dihitung lapangan)';
        }
        $lines[] = $head;
        if ((int) $selesai['lapangan'] > 0) {
            foreach (MigrationDailyStats::topAreas($selesai['by_area']) as $label => $count) {
                $lines[] = '- '.self::clean((string) $label).': '.$count;
            }
        }
        $lines[] = 'Masuk kemarin: '.(int) $m['masuk'];

        $b = $m['belum'];
        $lines[] = 'Belum selesai (posisi '.$sentAt->format('H:i').', status DB): '.(int) $b['total'];
        if ((int) $b['total'] > 0) {
            $lines[] = 'Tunggu SN '.(int) $b['tunggu_sn'].' | Perlu cek '.(int) $b['perlu_cek'].' | Gagal '.(int) $b['gagal'].' | Setengah jalan '.(int) $b['setengah_jalan'].' | Proses '.(int) $b['proses'];
        }
        if ($extra !== null) {
            if ((int) $extra['online_pending'] > 0) {
                $lines[] = 'ONU sudah online, status DB belum diperbarui: '.(int) $extra['online_pending'];
            }
            if ((int) $extra['perlu_dicek'] > 0) {
                $lines[] = 'Perlu dicek admin: '.(int) $extra['perlu_dicek'];
            }
        }

        return $lines;
    }

    /** Area names come from the DB: keep them single-line and free of command prefixes. */
    private static function clean(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        // No "#" anywhere (a mid-line "#psb" would still match the PSB trigger regex), no formatting chars.
        $label = (string) preg_replace('/[^\x20-\x7E]|[#*_~]/', '', $label);
        $label = ltrim($label, "/!- \t");

        return mb_substr($label, 0, 28);
    }
}
