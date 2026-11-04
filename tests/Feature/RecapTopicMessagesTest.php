<?php

namespace Tests\Feature;

use App\Services\CustomerMigrationMessageParser;
use App\Services\PsbMessageParser;
use App\Services\Recap\RecapTopicMessages;
use App\Services\TechnicianProgressParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class RecapTopicMessagesTest extends TestCase
{
    private function messages(): RecapTopicMessages
    {
        return new RecapTopicMessages;
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function data(array $over = []): array
    {
        $now = CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta');

        $priority = [];
        foreach (['BUDI CONTOH', 'SITI CONTOH'] as $i => $name) {
            $priority[] = [
                'status' => 'offline', 'kind' => 'los', 'customer_id' => $i + 1, 'name' => $name, 'area' => 'KLAMPOK', 'odp' => 'ODP-KLP-03',
                'days' => 4, 'rx' => null, 'rank' => 1, 'long' => true,
            ];
        }
        $priority[] = ['status' => 'weak_rx', 'kind' => 'online', 'customer_id' => 3, 'name' => 'ANDI', 'area' => 'KLAMPOK', 'odp' => '', 'days' => null, 'rx' => -31.2, 'rank' => 6, 'long' => false];

        return array_replace_recursive([
            'topic' => 'onu', 'all' => false, 'area_id' => 1, 'area_label' => 'KLAMPOK', 'generated_at' => $now,
            'data_at' => $now->subMinutes(3), 'date_ignored' => false, 'weak_dbm' => -27.0, 'long_days' => 3, 'usage_stale' => false,
            'counts' => [
                'aktif' => 412, 'terpantau' => 396, 'belum_terpetakan' => 10, 'ambigu' => 4, 'data_basi' => 2, 'offline' => 9, 'los' => 4,
                'mati_listrik' => 3, 'lain' => 2, 'offline_lama' => 3, 'tiket' => 2, 'ex_migrasi' => 1, 'ex_penarikan' => 0, 'ex_cek_pemetaan' => 1,
                'weak' => 17, 'weak_worst' => -31.2, 'shared_onu' => 0,
            ],
            'by_olt' => [['name' => 'RA', 'terpantau' => 300, 'offline' => 6, 'weak' => 10, 'stale' => 1, 'last' => $now->subMinutes(3), 'stale_olt' => false]],
            'by_area' => ['KLAMPOK' => ['aktif' => 412, 'terpantau' => 396, 'offline' => 9, 'weak' => 17]],
            'by_odp' => ['ODP-KLP-03' => 5],
            'priority' => $priority, 'problem_total' => 3,
        ], $over);
    }

    public function test_area_recap_matches_the_agreed_sample(): void
    {
        $text = $this->messages()->onuRecap($this->data());

        $this->assertSame(implode("\n", [
            '*REKAP KESEHATAN ONU - KLAMPOK*',
            'Posisi: 15/10/2026 09:12 WIB (data OLT terakhir 09:09)',
            'Pelanggan aktif: 412 | terpantau: 396 | belum terpetakan: 14 | data basi: 2',
            '',
            '*Offline: 9 pelanggan*',
            'LOS (kabel) 4 | Mati listrik 3 | Lain/tidak terbaca 2',
            'Offline >= 3 hari (perkiraan): 3',
            'Ada tiket terbuka: 2',
            'Dikecualikan: sedang migrasi 1 | penarikan 0 | cek pemetaan 1',
            '',
            '*RX lemah (< -27 dBm): 17 pelanggan*',
            'Terburuk: -31.2 dBm',
            '',
            '*Per ODP (5 teratas, offline + RX lemah)*',
            'ODP-KLP-03: 5',
            '',
            '*Per OLT*',
            '- RA: terpantau 300 | offline 6 | RX lemah 10 | data basi 1',
            '',
            '*Prioritas (3 teratas)*',
            '1. BUDI CONTOH - LOS 4 hari - ODP-KLP-03',
            '2. SITI CONTOH - LOS 4 hari - ODP-KLP-03',
            '3. ANDI - RX -31.2 dBm',
            '',
            'Daftar lengkap ada di file Excel.',
        ]), $text);
    }

    public function test_semua_mode_has_no_names_and_lists_areas(): void
    {
        $text = $this->messages()->onuRecap($this->data(['all' => true, 'area_label' => 'SEMUA AREA']));

        $this->assertStringNotContainsString('BUDI', $text);
        $this->assertStringNotContainsString('Prioritas', $text);
        $this->assertStringContainsString('*Per area (15 teratas)*', $text);
        $this->assertStringContainsString('- KLAMPOK: offline 9 | RX lemah 17', $text);
    }

    public function test_all_normal_stale_olt_date_ignored_and_usage_stale_notes(): void
    {
        $normal = $this->messages()->onuRecap(array_merge($this->data([
            'counts' => ['offline' => 0, 'weak' => 0, 'weak_worst' => null, 'los' => 0, 'mati_listrik' => 0, 'lain' => 0, 'tiket' => 0],
        ]), ['by_odp' => [], 'priority' => [], 'problem_total' => 0]));
        $this->assertStringContainsString('Semua ONU terpantau normal.', $normal);
        $this->assertStringNotContainsString('Daftar lengkap', $normal);

        $stale = $this->messages()->onuRecap($this->data([
            'date_ignored' => true, 'usage_stale' => true,
            'by_olt' => [['name' => 'RA', 'stale_olt' => true, 'last' => CarbonImmutable::parse('2026-10-15 05:00', 'Asia/Jakarta')]],
        ]));
        $this->assertStringContainsString('(tanggal diabaikan: rekap ONU selalu kondisi saat ini)', $stale);
        $this->assertStringContainsString('Catatan: data OLT RA sudah lama (terakhir 15/10 05:00).', $stale);
        $this->assertStringContainsString('Lama offline belum bisa dihitung', $stale);
        $this->assertStringNotContainsString('perkiraan', $stale);
    }

    public function test_dirty_db_text_cannot_inject_a_command_or_formatting(): void
    {
        $data = $this->data(['area_label' => "KLAMPOK #psb\nbantu"]);
        $data['priority'][0]['name'] = '#migrasi cek #5 *x* _y_';
        $data['priority'][0]['odp'] = '/psb #pppoe cari';
        $data['by_olt'][0]['name'] = "RA\n#psb bantu";

        $text = $this->messages()->onuRecap($data);

        foreach (['#migrasi', '#psb', '#pppoe', '/psb'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
        $this->assertStringNotContainsString("\n#", $text);
    }

    public function test_range_limit_text_follows_the_max_days_setting(): void
    {
        $m = $this->messages();
        $this->assertStringContainsString('maksimal 92 hari / 3 bulan', $m->rangeTooLong(92));
        $this->assertStringContainsString('maksimal 31 hari / 1 bulan', $m->rangeTooLong(31));
        $this->assertStringContainsString('maksimal 7 hari)', $m->rangeTooLong(7));
        $this->assertStringContainsString('Maksimal 3 bulan.', $m->usage('migrasi', 92));
        $this->assertStringContainsString('Maksimal 1 bulan.', $m->usage('migrasi', 31));
        $this->assertStringContainsString('Maksimal 7 hari.', $m->usage('migrasi', 7));
    }

    public function test_every_reply_is_echo_safe_ascii_and_short(): void
    {
        $m = $this->messages();
        $texts = [
            $m->onuRecap($this->data()),
            $m->onuRecap($this->data(['all' => true, 'area_label' => 'SEMUA AREA'])),
            $m->usage('onu'), $m->areaAmbiguous('kl', ['KLAMPOK', 'KLAMPIS']), $m->areaUnmatched('zzz', ['KLAMPOK']),
            $m->invalidDate('onu'), $m->rateLimitedGroup(), $m->rateLimitedSender(), $m->documentFailed(), $m->internalError(),
            implode("\n", $m->dailyOnuSection($this->data(), CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta'))),
            implode("\n", $m->dailyOnuSection(null, CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta'))),
        ];

        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;
        $progress = new TechnicianProgressParser;
        foreach ($texts as $text) {
            $this->assertLessThanOrEqual(3500, mb_strlen($text));
            $this->assertSame(1, preg_match('/^[\x0A\x20-\x7E]+$/', $text), 'ASCII only: '.$text);
            $this->assertStringNotContainsString('#migrasi', $text);
            $this->assertStringNotContainsString('#psb', $text);
            $this->assertStringNotContainsString('#pppoe', $text);
            $this->assertStringNotContainsString('/migrasi', $text);
            $this->assertStringStartsNotWith('#', $text);
            $this->assertFalse($psb->isBotTrigger($text), 'PSB trigger: '.$text);
            $this->assertFalse($migration->isBotTrigger($text), 'migration trigger: '.$text);
            $this->assertFalse($progress->isTrigger($text), 'technician progress trigger: '.$text);
            $this->assertStringNotContainsStringIgnoringCase('progress', $text);
            foreach (explode("\n", $text) as $line) {
                $this->assertStringStartsNotWith('#', ltrim($line));
            }
        }
    }

    public function test_a_long_priority_list_is_trimmed_to_fit(): void
    {
        $data = $this->data();
        $data['priority'] = [];
        for ($i = 0; $i < 12; $i++) {
            $data['priority'][] = ['status' => 'offline', 'kind' => 'los', 'customer_id' => $i, 'name' => str_repeat('NAMA', 7), 'area' => 'KLAMPOK', 'odp' => str_repeat('ODP', 8), 'days' => 5, 'rx' => null, 'rank' => 1, 'long' => true];
        }
        $data['by_olt'] = [];
        for ($i = 0; $i < 40; $i++) {
            $data['by_olt'][] = ['name' => 'OLT-'.$i, 'terpantau' => 100, 'offline' => 5, 'weak' => 5, 'stale' => 2, 'last' => null, 'stale_olt' => false];
        }

        $text = $this->messages()->onuRecap($data);

        $this->assertLessThanOrEqual(3500, mb_strlen($text));
    }

    public function test_daily_onu_section(): void
    {
        $lines = $this->messages()->dailyOnuSection($this->data(), CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta'));

        $this->assertSame([
            '*Kesehatan ONU (posisi 08:03)*',
            'Terpantau 396 dari 412 pelanggan aktif (belum terpetakan 14, data basi 2)',
            'Offline: 9 (LOS 4 | Mati listrik 3 | Lain 2)',
            'Offline >= 3 hari (perkiraan): 3',
            'RX lemah (< -27 dBm): 17',
            'Per OLT:',
            '- RA: offline 6 | RX lemah 10 | data basi 1',
            'Area teratas:',
            '- KLAMPOK: offline 9 | RX lemah 17',
        ], $lines);
        $this->assertStringNotContainsString('BUDI', implode("\n", $lines));
    }

    public function test_dbm_formatting(): void
    {
        $this->assertSame('-27', RecapTopicMessages::dbm(-27.0));
        $this->assertSame('-31.2', RecapTopicMessages::dbm(-31.2));
        $this->assertSame('-30', RecapTopicMessages::dbm(-30.0));
        $this->assertSame('-27.5', RecapTopicMessages::dbm(-27.5));
    }
}
