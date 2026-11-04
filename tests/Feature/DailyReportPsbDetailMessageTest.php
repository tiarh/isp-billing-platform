<?php

namespace Tests\Feature;

use App\Services\Report\DailyReportMessages;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Text of the "Draft PSB dan kendala" section (fake data only). */
#[Group('blocking-core')]
class DailyReportPsbDetailMessageTest extends TestCase
{
    private CarbonImmutable $day;

    private CarbonImmutable $sentAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta');
        $this->sentAt = CarbonImmutable::parse('2026-10-22 08:03', 'Asia/Jakarta');
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'psb' => [
                'total' => 12, 'by_status' => ['aktif' => 10, 'belum aktif' => 2, 'isolir' => 0, 'putus' => 0],
                'by_source' => ['WA' => 11, 'Non-WA' => 1], 'excluded_migration' => 0, 'by_area' => ['KLAMPOK' => 7, 'KRIAN' => 3, 'SENTUL' => 2],
            ],
            'psb_mtd' => ['from' => '2026-10-01', 'to' => '2026-10-21', 'total' => 168],
            'migration' => [
                'selesai' => ['lapangan' => 5, 'impor' => 0, 'total' => 5, 'by_area' => ['KRIAN NON BUMDES' => 3, 'SRIGADING' => 2]],
                'masuk' => 4,
                'belum' => ['total' => 228, 'tunggu_sn' => 2, 'perlu_cek' => 70, 'gagal' => 4, 'setengah_jalan' => 0, 'proses' => 152],
            ],
            'errors' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        return [
            'masuk' => ['total' => 14, 'jadi' => 11, 'batal' => 1],
            'open' => [
                'total' => 6,
                'stages' => ['Baru masuk' => 1, 'Tunggu SN' => 2, 'Tunggu VLAN' => 1, 'Tunggu OLT' => 1, 'Cek koreksi' => 1],
                'by_area' => ['KLAMPOK' => 3, 'KRIAN' => 2, 'Tanpa area' => 1],
                'stuck' => 2,
                'stuck_days' => 3,
            ],
            'failed' => ['total' => 3, 'buckets' => ['gagal di OLT' => 2, 'timeout SN' => 1]],
        ];
    }

    public function test_the_section_sits_between_psb_and_migrasi_with_the_exact_wording(): void
    {
        $report = $this->report();
        $report['psb_detail'] = $this->detail();

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, true);

        $expected = "*Draft PSB dan kendala*\n"
            ."Draft masuk kemarin: 14 (jadi pelanggan 11, batal 1)\n"
            ."Belum selesai (posisi 08:03): 6\n"
            ."Baru masuk 1 | Tunggu SN 2 | Tunggu VLAN 1 | Tunggu OLT 1 | Cek koreksi 1\n"
            ."- KLAMPOK: 3\n"
            ."- KRIAN: 2\n"
            ."- Tanpa area: 1\n"
            ."Macet lebih dari 3 hari: 2\n"
            ."Gagal kemarin: 3 (gagal di OLT 2, timeout SN 1)\n"
            ."\n*Migrasi*";
        $this->assertStringContainsString("Bulan Oktober (1-21 Okt): 168 pelanggan\n\n".$expected, $text);
        $this->assertStringNotContainsString('NIK', $text);
    }

    public function test_zero_lines_are_hidden_except_the_first_two(): void
    {
        $report = $this->report();
        $report['psb_detail'] = [
            'masuk' => ['total' => 0, 'jadi' => 0, 'batal' => 0],
            'open' => ['total' => 0, 'stages' => [], 'by_area' => [], 'stuck' => 0, 'stuck_days' => 3],
            'failed' => ['total' => 0, 'buckets' => []],
        ];

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, false);

        $this->assertStringContainsString("*Draft PSB dan kendala*\nDraft masuk kemarin: 0 (jadi pelanggan 0, batal 0)\nBelum selesai (posisi 08:03): 0\n\n*Migrasi*", $text);
        $this->assertStringNotContainsString('Macet', $text);
        $this->assertStringNotContainsString('Gagal kemarin', $text);
    }

    public function test_only_the_top_5_areas_then_lainnya(): void
    {
        $report = $this->report();
        $detail = $this->detail();
        $detail['open']['total'] = 28;
        $detail['open']['by_area'] = ['A1' => 7, 'A2' => 6, 'A3' => 5, 'A4' => 4, 'A5' => 3, 'A6' => 2, 'A7' => 1];
        $report['psb_detail'] = $detail;

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, false);

        $this->assertStringContainsString("- A5: 3\n- lainnya: 3\n", $text);
        $this->assertStringNotContainsString('- A6', $text);
    }

    public function test_a_failed_section_shows_a_note_and_keeps_the_others(): void
    {
        $report = $this->report();
        $report['psb_detail'] = null;

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, false);

        $this->assertStringContainsString("*Draft PSB dan kendala*\n(data draft gagal dibaca, tim teknis sudah dicatat)\n\n*Migrasi*\nSelesai kemarin: 5 pelanggan", $text);
        $this->assertStringContainsString('*PSB (pasang baru)*', $text);
    }

    public function test_ascii_only_short_and_no_command_lookalikes_even_with_40_dirty_areas(): void
    {
        $report = $this->report();
        $detail = $this->detail();
        $areas = [];
        for ($i = 1; $i <= 40; $i++) {
            $areas['#psb *Area* '.$i.' ~tebal~ '.str_repeat('X', 40)."\xC3\xA9"] = 41 - $i;
        }
        $detail['open']['by_area'] = $areas;
        $detail['open']['total'] = array_sum($areas);
        $report['psb_detail'] = $detail;

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, true);

        $this->assertLessThanOrEqual(DailyReportMessages::MAX_CHARS, mb_strlen($text));
        $this->assertSame(1, preg_match('/^[\x0A\x20-\x7E]+$/', $text), 'ASCII only');
        $section = substr($text, (int) strpos($text, '*Draft PSB dan kendala*'), (int) strpos($text, '*Migrasi*') - (int) strpos($text, '*Draft PSB dan kendala*'));
        $this->assertStringNotContainsString('#', $section);
        $this->assertStringNotContainsString('progres', strtolower($section));
        $this->assertSame(0, preg_match('/(\+?62|0)8\d{7,}/', $section), 'no phone pattern');
        $this->assertSame(0, preg_match('/\d{16}/', $section), 'no 16-digit run');
        foreach (explode("\n", $section) as $line) {
            $this->assertStringStartsNotWith('#', $line);
        }
        $this->assertStringContainsString('- psb Area 1 tebal XXXXXXXXXXX: 40', $text);
        $this->assertStringContainsString('- lainnya: ', $text);
    }
}
