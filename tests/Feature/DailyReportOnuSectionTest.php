<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Recap\OnuHealthService;
use App\Services\Report\DailyReportMessages;
use App\Services\Report\DailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Daily report: the "Kesehatan ONU" section exists only behind report_daily_onu_enabled; with the
 * flag OFF the report data and text are exactly what they were before this change.
 */
#[Group('blocking-psb')]
class DailyReportOnuSectionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $day;

    private CarbonImmutable $sentAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::parse('2026-10-14', 'Asia/Jakarta');
        $this->sentAt = CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta');
        $this->travelTo($this->sentAt);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'psb' => [
                'total' => 9, 'by_status' => ['aktif' => 7, 'belum aktif' => 2, 'isolir' => 0, 'putus' => 0],
                'by_source' => ['WA' => 8, 'Non-WA' => 1], 'excluded_migration' => 0, 'by_area' => ['KLAMPOK' => 5, 'GADANG' => 3, 'BIRU' => 1],
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

    /** @return array<string, mixed> */
    private function onu(): array
    {
        return [
            'topic' => 'onu', 'all' => true, 'area_label' => 'SEMUA AREA', 'weak_dbm' => -27.0, 'long_days' => 3, 'usage_stale' => false,
            'counts' => [
                'aktif' => 100, 'terpantau' => 90, 'belum_terpetakan' => 5, 'ambigu' => 1, 'data_basi' => 4, 'offline' => 3, 'los' => 1,
                'mati_listrik' => 1, 'lain' => 1, 'offline_lama' => 1, 'weak' => 2,
            ],
            'by_olt' => [['name' => 'RA', 'offline' => 3, 'weak' => 2, 'stale' => 4, 'stale_olt' => false]],
            'by_area' => ['KLAMPOK' => ['offline' => 3, 'weak' => 2]],
        ];
    }

    public function test_flag_off_the_built_report_has_no_onu_key_and_the_text_is_unchanged(): void
    {
        $built = app(DailyReportService::class)->build($this->day);

        $this->assertSame(['psb', 'psb_mtd', 'migration', 'errors'], array_keys($built));

        $messages = new DailyReportMessages;
        $plain = $messages->daily($this->report(), $this->day, $this->sentAt, true);
        $this->assertSame($plain, $messages->daily($this->report(), $this->day, $this->sentAt, true, false));
        $this->assertStringNotContainsString('ONU', $plain);
        $this->assertStringEndsWith("*Migrasi*\nSelesai kemarin: 12 pelanggan\n- KLAMPOK: 10\n- GADANG: 2\nMasuk kemarin: 14\nBelum selesai (posisi 08:03, status DB): 37\nTunggu SN 20 | Perlu cek 9 | Gagal 5 | Setengah jalan 0 | Proses 3\n\nRincian PSB: ketik #rekap psb semua kemarin", $plain);
    }

    public function test_flag_on_adds_the_section_between_migration_and_the_footer(): void
    {
        Setting::set('report_daily_onu_enabled', '1');
        $built = app(DailyReportService::class)->build($this->day);
        $this->assertArrayHasKey('onu', $built);
        $this->assertIsArray($built['onu']);

        $report = $this->report();
        $report['onu'] = $this->onu();
        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, true, true);

        $migrasi = strpos($text, '*Migrasi*');
        $onu = strpos($text, '*Kesehatan ONU (posisi 08:03)*');
        $footer = strpos($text, 'Rincian PSB');
        $this->assertTrue($migrasi < $onu && $onu < $footer);
        $this->assertStringContainsString("Offline: 3 (LOS 1 | Mati listrik 1 | Lain 1)\n", $text);
        $this->assertStringEndsWith("Rincian PSB: ketik #rekap psb semua kemarin\nRincian ONU: ketik #rekap onu semua", $text);
        $this->assertLessThanOrEqual(DailyReportMessages::MAX_CHARS, mb_strlen($text));
    }

    public function test_the_onu_footer_is_independent_of_the_psb_footer(): void
    {
        $text = (new DailyReportMessages)->daily($this->report(), $this->day, $this->sentAt, false, true);

        $this->assertStringContainsString('Rincian ONU: ketik #rekap onu semua', $text);
        $this->assertStringNotContainsString('#rekap psb', $text);
    }

    public function test_onu_failure_is_noted_and_does_not_block_the_other_sections(): void
    {
        Setting::set('report_daily_onu_enabled', '1');
        $this->app->bind(OnuHealthService::class, fn () => new class extends OnuHealthService
        {
            public function collect(?int $areaId, string $areaLabel, ?CarbonImmutable $now = null, bool $dateIgnored = false): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $built = app(DailyReportService::class)->build($this->day);

        $this->assertNull($built['onu']);
        $this->assertContains('onu:RuntimeException', $built['errors']);
        $this->assertNotNull($built['psb']);
        $this->assertNotNull($built['migration']);

        $text = (new DailyReportMessages)->daily($this->report() + ['onu' => null], $this->day, $this->sentAt, false);
        $this->assertStringContainsString("*Kesehatan ONU (posisi 08:03)*\n(data ONU gagal dibaca, tim teknis sudah dicatat)", $text);
    }

    public function test_the_onu_section_never_changes_the_zero_day_decision(): void
    {
        $service = app(DailyReportService::class);
        $zero = $this->report();
        $zero['psb']['total'] = 0;
        $zero['migration']['selesai']['total'] = 0;
        $zero['migration']['masuk'] = 0;

        $this->assertTrue($service->isZeroDay($zero));
        $zero['onu'] = $this->onu();
        $this->assertTrue($service->isZeroDay($zero));
        $this->assertFalse($service->bothFailed($zero));
    }

    public function test_dry_run_prints_the_section_only_with_the_flag(): void
    {
        $this->artisan('report:daily-wa', ['--dry-run' => true, '--date' => '2026-10-14'])
            ->doesntExpectOutputToContain('Kesehatan ONU')
            ->assertSuccessful();

        Setting::set('report_daily_onu_enabled', '1');
        $this->artisan('report:daily-wa', ['--dry-run' => true, '--date' => '2026-10-14'])
            ->expectsOutputToContain('Kesehatan ONU (posisi')
            ->assertSuccessful();
    }
}
