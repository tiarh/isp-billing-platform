<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use App\Services\PsbMessageParser;
use App\Services\PsbRecapCommandParser;
use App\Services\Report\DailyReportMessages;
use App\Services\TechnicianProgressParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class DailyReportMessageTest extends TestCase
{
    use RefreshDatabase;

    private DailyReportMessages $messages;

    private CarbonImmutable $day;

    private CarbonImmutable $sentAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messages = new DailyReportMessages;
        $this->day = CarbonImmutable::parse('2026-10-14', 'Asia/Jakarta');
        $this->sentAt = CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta');
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return [
            'psb' => [
                'total' => 9,
                'by_status' => ['aktif' => 7, 'belum aktif' => 2, 'isolir' => 0, 'putus' => 0],
                'by_source' => ['WA' => 8, 'Non-WA' => 1],
                'by_area' => ['KLAMPOK' => 5, 'GADANG' => 3, 'BIRU' => 1],
                'excluded_migration' => 0,
            ],
            'psb_mtd' => ['from' => '2026-10-01', 'to' => '2026-10-14', 'total' => 142],
            'migration' => [
                'selesai' => ['lapangan' => 12, 'impor' => 0, 'total' => 12, 'by_area' => ['KLAMPOK' => 10, 'GADANG' => 2]],
                'masuk' => 14,
                'belum' => ['total' => 37, 'tunggu_sn' => 20, 'perlu_cek' => 9, 'gagal' => 5, 'setengah_jalan' => 0, 'proses' => 3],
            ],
            'errors' => [],
        ];
    }

    public function test_snapshot_of_the_standard_report(): void
    {
        $expected = implode("\n", [
            '*LAPORAN HARIAN SKYNET*',
            'Data: Rabu, 14/10/2026 (00:00-23:59 WIB)',
            'Dikirim: 15/10/2026 08:03 WIB',
            '',
            '*PSB (pasang baru)*',
            'Total kemarin: 9 pelanggan',
            'Aktif 7 | Belum aktif 2 | Isolir 0 | Putus 0',
            'Sumber: WA 8 | Non-WA 1',
            'Per area:',
            '- KLAMPOK: 5',
            '- GADANG: 3',
            '- BIRU: 1',
            'Bulan Oktober (1-14 Okt): 142 pelanggan',
            '',
            '*Migrasi*',
            'Selesai kemarin: 12 pelanggan',
            '- KLAMPOK: 10',
            '- GADANG: 2',
            'Masuk kemarin: 14',
            'Belum selesai (posisi 08:03, status DB): 37',
            'Tunggu SN 20 | Perlu cek 9 | Gagal 5 | Setengah jalan 0 | Proses 3',
            '',
            'Rincian PSB: ketik #rekap psb semua kemarin',
        ]);

        $this->assertSame($expected, $this->messages->daily($this->report(), $this->day, $this->sentAt, true));
    }

    public function test_footer_is_only_present_when_the_recap_command_is_enabled(): void
    {
        $this->assertStringNotContainsString('#rekap', $this->messages->daily($this->report(), $this->day, $this->sentAt, false));
    }

    public function test_bulk_import_excluded_migration_and_catch_up_time(): void
    {
        $r = $this->report();
        $r['migration']['selesai']['impor'] = 40;
        $r['psb']['excluded_migration'] = 3;

        $text = $this->messages->daily($r, $this->day, CarbonImmutable::parse('2026-10-15 10:03', 'Asia/Jakarta'), false);

        $this->assertStringContainsString('Selesai kemarin: 12 pelanggan (+40 impor data, tidak dihitung lapangan)', $text);
        $this->assertStringContainsString('Dikecualikan (hasil migrasi): 3', $text);
        $this->assertStringContainsString('Dikirim: 15/10/2026 10:03 WIB', $text);
    }

    public function test_zero_values_omit_area_lists(): void
    {
        $r = $this->report();
        $r['psb']['total'] = 0;
        $r['psb']['by_area'] = [];
        $r['migration']['selesai'] = ['lapangan' => 0, 'impor' => 0, 'total' => 0, 'by_area' => []];
        $r['migration']['belum']['total'] = 0;

        $text = $this->messages->daily($r, $this->day, $this->sentAt, false);

        $this->assertStringContainsString('Total kemarin: 0 pelanggan', $text);
        $this->assertStringNotContainsString('Per area:', $text);
        $this->assertStringNotContainsString('Tunggu SN', $text);
    }

    public function test_failed_sections_are_noted_without_details(): void
    {
        $r = $this->report();
        $r['migration'] = null;
        $r['psb'] = null;

        $text = $this->messages->daily($r, $this->day, $this->sentAt, false);

        $this->assertStringContainsString('(data migrasi gagal dibaca, tim teknis sudah dicatat)', $text);
        $this->assertStringContainsString('(data PSB gagal dibaca, tim teknis sudah dicatat)', $text);
    }

    public function test_top_ten_areas_plus_lainnya_and_hard_length_cap_with_200_areas(): void
    {
        $r = $this->report();
        $areas = [];
        for ($i = 1; $i <= 200; $i++) {
            $areas['AREA'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)] = 201 - $i;
        }
        $r['psb']['by_area'] = $areas;
        $r['migration']['selesai']['by_area'] = $areas;

        $text = $this->messages->daily($r, $this->day, $this->sentAt, true);

        $this->assertLessThanOrEqual(DailyReportMessages::MAX_CHARS, mb_strlen($text));
        $this->assertSame(2, substr_count($text, '- lainnya: '));
        $this->assertStringContainsString('- AREA010: 191', $text);
        $this->assertStringNotContainsString('AREA011', $text);
    }

    public function test_text_is_ascii_and_has_no_pii_canaries(): void
    {
        $r = $this->report();
        // A hostile area name: non-ASCII, a command tag and formatting characters.
        $r['psb']['by_area'] = ["KLAMPO\u{00e9}K #psb *bold*" => 1];

        $text = $this->messages->daily($r, $this->day, $this->sentAt, true);

        $this->assertSame(1, preg_match('/^[\x20-\x7E\n]+$/', $text));
        $this->assertStringContainsString('- KLAMPOK psb bold: 1', $text);
        // The only "#" is the footer hint; no phone-like digit run; no credentials vocabulary.
        $this->assertSame(1, substr_count($text, '#'));
        $this->assertSame(0, preg_match('/\d{9,}/', $text));
        $this->assertStringNotContainsString('pppoe', strtolower($text));
        $this->assertFalse((new PsbMessageParser)->isBotTrigger($text));
    }

    public function test_text_is_never_a_trigger_for_any_bot_parser(): void
    {
        Setting::set('psb_recap_cmd_enabled', '1');
        foreach ([true, false] as $footer) {
            $text = $this->messages->daily($this->report(), $this->day, $this->sentAt, $footer);

            $this->assertFalse((new PsbMessageParser)->isBotTrigger($text), 'psb trigger');
            $this->assertFalse((new PsbMessageParser)->isNearMissPsb($text), 'psb near miss');
            $this->assertFalse((new CustomerMigrationMessageParser)->isBotTrigger($text), 'migration trigger');
            $this->assertFalse((new PsbRecapCommandParser)->matches($text), 'recap command');
            $this->assertFalse(app(TechnicianProgressParser::class)->isTrigger($text), 'technician progress trigger');
            $this->assertStringNotContainsStringIgnoringCase('progress', $text);
            $this->assertStringNotContainsStringIgnoringCase('progres', $text);
            foreach (explode("\n", $text) as $line) {
                $this->assertStringStartsNotWith('#', $line);
            }
        }
    }

    public function test_weekday_and_month_names_cover_the_calendar(): void
    {
        $r = $this->report();
        $sunday = CarbonImmutable::parse('2026-12-27', 'Asia/Jakarta');
        $r['psb_mtd'] = ['from' => '2026-12-01', 'to' => '2026-12-27', 'total' => 5];

        $text = $this->messages->daily($r, $sunday, $this->sentAt, false);

        $this->assertStringContainsString('Data: Minggu, 27/12/2026', $text);
        $this->assertStringContainsString('Bulan Desember (1-27 Des): 5 pelanggan', $text);
    }
}
