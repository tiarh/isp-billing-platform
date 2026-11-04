<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\Report\DailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Numbers of the daily Skynet Report. D = 2026-10-14 (WIB), "now" = 15 Oct 08:03 WIB.
 */
#[Group('blocking-psb')]
class DailyReportDataTest extends TestCase
{
    use RefreshDatabase;

    private Area $klampok;

    private Area $biru;

    private Package $package;

    private int $seq = 0;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 08:03', 'Asia/Jakarta'));
        $this->day = CarbonImmutable::parse('2026-10-14', 'Asia/Jakarta');
        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->biru = Area::create(['name' => 'SKYNET-BIRU', 'code' => 'biru']);
        $this->package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function customer(string $joinDate, array $over = []): Customer
    {
        $this->seq++;

        return Customer::create(array_merge([
            'code' => 'DR-'.$this->seq,
            'name' => 'Pelanggan '.$this->seq,
            'address' => 'Dusun Klampok RT 03 RW 05',
            'package_id' => $this->package->id,
            'area_id' => $this->klampok->id,
            'pppoe_user' => 'userdr'.$this->seq,
            'status' => 'active',
            'join_date' => $joinDate,
        ], $over));
    }

    /**
     * Raw insert so created_at / synced_at are exact UTC strings.
     *
     * @param  array<string, mixed>  $over
     */
    private function migration(array $over = []): int
    {
        $this->seq++;

        return (int) DB::table('customer_migrations')->insertGetId(array_merge([
            'status' => 'synced',
            'source' => 'wa',
            'message_id' => 'dr-mig-'.$this->seq,
            'customer_id' => null,
            'pppoe_user' => 'migdr'.$this->seq,
            'area_id' => null,
            'synced_at' => '2026-10-14 03:00:00',
            'created_at' => '2026-10-13 20:00:00',
            'updated_at' => '2026-10-14 03:00:00',
        ], $over));
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationStats(): array
    {
        $report = app(DailyReportService::class)->build($this->day);
        $this->assertSame([], $report['errors']);

        return $report['migration'];
    }

    public function test_psb_total_equals_rekap_psb_semua_for_the_same_day(): void
    {
        $this->customer('2026-10-14');
        $this->customer('2026-10-14', ['area_id' => $this->biru->id, 'status' => 'isolated']);
        $this->customer('2026-10-14', ['status' => 'terminated']);
        $this->customer('2026-10-13');
        $this->customer('2026-10-15');
        $this->customer('2026-10-14')->delete();

        $report = app(DailyReportService::class)->build($this->day);

        $request = (new PsbRecapCommandParser)->parse('#rekap psb semua 14/10');
        $recap = app(PsbAreaRecapService::class)->collect($request, null, 'SEMUA AREA');

        $this->assertSame(3, $report['psb']['total']);
        $this->assertSame($recap['total'], $report['psb']['total']);
        $this->assertSame($recap['by_status'], $report['psb']['by_status']);
        $this->assertSame($recap['by_source'], $report['psb']['by_source']);
        $this->assertSame(1, $report['psb']['by_status']['putus']);
        $this->assertSame(1, $report['psb']['by_status']['isolir']);
    }

    public function test_psb_wa_and_non_wa_sources_are_split(): void
    {
        $withDraft = $this->customer('2026-10-14');
        PsbDraft::create(['status' => 'applied', 'message_id' => 'dr-draft-1', 'customer_id' => $withDraft->id, 'full_name' => 'x', 'area_id' => $this->klampok->id]);
        $this->customer('2026-10-14');

        $psb = app(DailyReportService::class)->build($this->day)['psb'];

        $this->assertSame(['WA' => 1, 'Non-WA' => 1], $psb['by_source']);
    }

    public function test_month_to_date_includes_the_first_of_the_month(): void
    {
        $this->customer('2026-09-30');
        $this->customer('2026-10-01');
        $this->customer('2026-10-14');
        $this->customer('2026-10-15');

        $mtd = app(DailyReportService::class)->build($this->day)['psb_mtd'];

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-14', 'total' => 2], $mtd);
    }

    public function test_month_to_date_on_the_first_covers_the_previous_whole_month(): void
    {
        $this->customer('2026-09-01');
        $this->customer('2026-09-30');
        $this->customer('2026-10-01');

        $mtd = app(DailyReportService::class)->build(CarbonImmutable::parse('2026-09-30', 'Asia/Jakarta'))['psb_mtd'];

        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'total' => 2], $mtd);
    }

    public function test_migration_selesai_window_edges_in_utc(): void
    {
        // WIB day 14 Oct = 13 Oct 17:00:00 UTC .. 14 Oct 16:59:59 UTC
        $this->migration(['synced_at' => '2026-10-13 16:59:59']);
        $this->migration(['synced_at' => '2026-10-13 17:00:00']);
        $this->migration(['synced_at' => '2026-10-14 16:59:59']);
        $this->migration(['synced_at' => '2026-10-14 17:00:00']);

        $this->assertSame(2, $this->migrationStats()['selesai']['lapangan']);
    }

    public function test_cancelled_is_excluded_and_bulk_is_split_from_field(): void
    {
        $this->migration(['area_id' => $this->klampok->id]);
        $this->migration(['status' => 'cancelled', 'area_id' => $this->klampok->id]);
        $this->migration(['source' => 'excel_import', 'area_id' => $this->klampok->id]);
        $this->migration(['source' => 'activity_log_fhtt']);

        $selesai = $this->migrationStats()['selesai'];

        $this->assertSame(1, $selesai['lapangan']);
        $this->assertSame(2, $selesai['impor']);
        $this->assertSame(3, $selesai['total']);
        $this->assertSame(['KLAMPOK' => 1], $selesai['by_area']);
    }

    public function test_same_customer_twice_counts_once(): void
    {
        $customer = $this->customer('2026-09-01');
        $this->migration(['customer_id' => $customer->id, 'pppoe_user' => null]);
        $this->migration(['customer_id' => $customer->id, 'pppoe_user' => null, 'synced_at' => '2026-10-14 05:00:00']);

        $this->assertSame(1, $this->migrationStats()['selesai']['lapangan']);
    }

    public function test_synced_status_without_synced_at_is_not_counted_as_selesai(): void
    {
        $this->migration(['status' => 'synced', 'synced_at' => null]);

        $this->assertSame(0, $this->migrationStats()['selesai']['total']);
    }

    public function test_area_falls_back_to_the_customer_area_then_to_tanpa_area(): void
    {
        $customer = $this->customer('2026-09-01', ['area_id' => $this->biru->id]);
        $this->migration(['customer_id' => $customer->id, 'pppoe_user' => null, 'area_id' => null]);
        $this->migration(['area_id' => $this->klampok->id]);
        $this->migration(['area_id' => null]);

        $by = $this->migrationStats()['selesai']['by_area'];

        $this->assertSame(['BIRU' => 1, 'KLAMPOK' => 1, 'Tanpa area' => 1], array_replace(['BIRU' => 0, 'KLAMPOK' => 0, 'Tanpa area' => 0], $by));
    }

    public function test_masuk_counts_created_in_window_excluding_bulk_and_cancelled(): void
    {
        $this->migration(['status' => 'received', 'synced_at' => null, 'created_at' => '2026-10-14 01:00:00']);
        $this->migration(['status' => 'received', 'synced_at' => null, 'created_at' => '2026-10-14 17:00:00']);
        $this->migration(['status' => 'received', 'synced_at' => null, 'created_at' => '2026-10-14 02:00:00', 'source' => 'excel_import']);
        $this->migration(['status' => 'cancelled', 'synced_at' => null, 'created_at' => '2026-10-14 02:00:00']);

        $this->assertSame(1, $this->migrationStats()['masuk']);
    }

    public function test_backlog_uses_latest_row_per_identity_and_buckets(): void
    {
        $customer = $this->customer('2026-09-01');
        // identity A: old failed row, newest row synced -> not backlog
        $this->migration(['customer_id' => $customer->id, 'pppoe_user' => null, 'status' => 'failed', 'synced_at' => null, 'updated_at' => '2026-10-10 00:00:00']);
        $this->migration(['customer_id' => $customer->id, 'pppoe_user' => null, 'status' => 'synced', 'synced_at' => '2026-10-12 00:00:00', 'updated_at' => '2026-10-12 00:00:00']);
        // others
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null]);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null]);
        $this->migration(['status' => 'needs_review', 'synced_at' => null]);
        $this->migration(['status' => 'failed', 'synced_at' => null]);
        $this->migration(['status' => 'partially_executed', 'synced_at' => null]);
        $this->migration(['status' => 'received', 'synced_at' => null]);
        $this->migration(['status' => 'olt_ok', 'synced_at' => null]);
        $this->migration(['status' => 'cancelled', 'synced_at' => null]);

        $belum = $this->migrationStats()['belum'];

        $this->assertSame(['total' => 7, 'tunggu_sn' => 2, 'perlu_cek' => 1, 'gagal' => 1, 'setengah_jalan' => 1, 'proses' => 2], $belum);
    }

    public function test_building_the_report_writes_nothing(): void
    {
        $this->customer('2026-10-14');
        $this->migration();
        $before = [DB::table('customers')->count(), DB::table('customer_migrations')->count(), DB::table('psb_drafts')->count()];
        Http::fake();

        app(DailyReportService::class)->build($this->day);

        $this->assertSame($before, [DB::table('customers')->count(), DB::table('customer_migrations')->count(), DB::table('psb_drafts')->count()]);
        Http::assertNothingSent();
    }

    public function test_zero_day_rule(): void
    {
        $service = app(DailyReportService::class);

        $this->assertTrue($service->isZeroDay($service->build($this->day)));

        $this->migration(['status' => 'received', 'synced_at' => null, 'created_at' => '2026-10-14 01:00:00']);
        $this->assertFalse($service->isZeroDay($service->build($this->day)), 'migrasi masuk > 0 is not a zero day');
    }

    public function test_zero_day_is_not_assumed_when_a_section_failed(): void
    {
        $service = app(DailyReportService::class);

        $this->assertFalse($service->isZeroDay(['psb' => null, 'migration' => ['selesai' => ['total' => 0], 'masuk' => 0]]));
        $this->assertFalse($service->isZeroDay(['psb' => ['total' => 0], 'migration' => null]));
    }
}
