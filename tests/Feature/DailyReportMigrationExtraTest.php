<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Setting;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Report\DailyReportMessages;
use App\Services\Report\DailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

/**
 * Daily report "Migrasi" section: the auto-reconcile details exist only behind
 * recap_migration_olt_evidence_enabled; with the flag OFF data and text are exactly what they were.
 */
#[Group('blocking-psb')]
class DailyReportMigrationExtraTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
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

    private function seedMigrations(): void
    {
        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        foreach ([['m1', null], ['m2', json_encode(['auto_reconcile' => ['version' => 1]])]] as $i => [$user, $meta]) {
            DB::table('customer_migrations')->insert([
                'status' => 'synced', 'source' => 'wa', 'message_id' => 'dx-'.$i, 'pppoe_user' => $user, 'area_id' => $area->id,
                'meta' => $meta, 'synced_at' => '2026-10-14 03:00:00', 'created_at' => '2026-10-13 20:00:00', 'updated_at' => '2026-10-14 03:00:00',
            ]);
        }
        $this->fixture(['status' => 'failed']);
    }

    public function test_flag_off_has_no_extra_key_and_the_text_is_unchanged(): void
    {
        $this->seedMigrations();

        $report = app(DailyReportService::class)->build($this->day);
        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, true);

        $this->assertArrayNotHasKey('extra', $report['migration']);
        $this->assertStringContainsString('Selesai kemarin: 2 pelanggan', $text);
        $this->assertStringNotContainsString('otomatis', $text);
        $this->assertStringNotContainsString('Perlu dicek admin', $text);
        $this->assertStringNotContainsString('ONU sudah online', $text);
        $this->assertStringNotContainsString('Rincian migrasi', $text);
    }

    public function test_flag_on_adds_the_split_and_the_admin_line(): void
    {
        $this->seedMigrations();
        Setting::set('recap_migration_olt_evidence_enabled', '1');

        $report = app(DailyReportService::class)->build($this->day);
        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, true, false, true);

        $this->assertSame(['db' => 1, 'otomatis' => 1, 'online_pending' => 0, 'perlu_dicek' => 1], $report['migration']['extra']);
        $this->assertSame([], $report['errors']);
        $this->assertStringContainsString('Selesai kemarin: 2 pelanggan (status DB 1 + otomatis 1)', $text);
        $this->assertStringContainsString('Perlu dicek admin: 1', $text);
        $this->assertStringNotContainsString('ONU sudah online', $text, 'zero is not printed');
        $this->assertStringContainsString('Rincian migrasi: ketik #rekap migrasi semua kemarin', $text);
    }

    public function test_the_plain_numbers_do_not_change_when_the_flag_is_on(): void
    {
        $this->seedMigrations();
        $off = app(DailyReportService::class)->build($this->day);

        Setting::set('recap_migration_olt_evidence_enabled', '1');
        $on = app(DailyReportService::class)->build($this->day);
        unset($on['migration']['extra']);

        $this->assertEquals($off, $on);
    }

    public function test_message_lines_for_an_online_pending_count(): void
    {
        $report = [
            'psb' => ['total' => 0, 'by_status' => [], 'by_source' => [], 'excluded_migration' => 0, 'by_area' => []],
            'psb_mtd' => null,
            'migration' => [
                'selesai' => ['lapangan' => 12, 'impor' => 0, 'total' => 12, 'by_area' => ['KLAMPOK' => 12]],
                'masuk' => 14,
                'belum' => ['total' => 3, 'tunggu_sn' => 3, 'perlu_cek' => 0, 'gagal' => 0, 'setengah_jalan' => 0, 'proses' => 0],
                'extra' => ['db' => 10, 'otomatis' => 2, 'online_pending' => 7, 'perlu_dicek' => 0],
            ],
            'errors' => [],
        ];

        $text = (new DailyReportMessages)->daily($report, $this->day, $this->sentAt, false);

        $this->assertStringContainsString('Selesai kemarin: 12 pelanggan (status DB 10 + otomatis 2)', $text);
        $this->assertStringContainsString('ONU sudah online, status DB belum diperbarui: 7', $text);
        $this->assertStringNotContainsString('Perlu dicek admin', $text);
    }

    public function test_an_evidence_failure_keeps_the_plain_section(): void
    {
        $this->seedMigrations();
        Setting::set('recap_migration_olt_evidence_enabled', '1');
        $failing = new class extends MigrationAutoReconcileService
        {
            public function evaluate(?array $ids = null, ?array $settings = null, ?Carbon $now = null): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->bind(MigrationAutoReconcileService::class, fn () => $failing);

        $report = app(DailyReportService::class)->build($this->day);

        $this->assertArrayNotHasKey('extra', $report['migration']);
        $this->assertSame(2, $report['migration']['selesai']['lapangan']);
        $this->assertNotEmpty($report['errors']);
    }
}
