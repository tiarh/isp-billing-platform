<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\Report\DailyReportMessages;
use App\Services\Report\DailyReportService;
use App\Services\Report\PsbDailyDetailStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Daily report: the "Draft PSB dan kendala" section exists only behind report_daily_psb_detail_enabled (or the
 * dry-run option --psb-detail). With the flag OFF the report data, text, zero-day rule and ledger are what they were.
 */
#[Group('blocking-core')]
class DailyReportPsbDetailSectionTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private CarbonImmutable $day;

    private CarbonImmutable $sentAt;

    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta');
        $this->sentAt = CarbonImmutable::parse('2026-10-22 08:03', 'Asia/Jakarta');
        $this->travelTo($this->sentAt);

        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('getConnectionState')->andReturn('open');
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->sent[] = [$jid, $text];

            return ['success' => true, 'message_id' => 'WAMID-1'];
        });
        $this->app->instance(EvolutionApiService::class, $evo);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function psbCustomer(): void
    {
        $area = Area::query()->first() ?? Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $package = Package::query()->first() ?? Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        Customer::create([
            'code' => 'PS-1', 'name' => 'Pelanggan Satu', 'address' => 'Dusun Contoh', 'package_id' => $package->id,
            'area_id' => $area->id, 'pppoe_user' => 'userps1', 'status' => 'active', 'join_date' => '2026-10-21',
        ]);
    }

    private function openDraft(): void
    {
        DB::table('psb_drafts')->insert([
            'status' => 'waiting_sn', 'message_id' => 'sec-'.uniqid(), 'created_at' => '2026-10-17 03:00:00', 'updated_at' => '2026-10-17 03:00:00',
        ]);
    }

    private function enableLive(): void
    {
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_daily_enabled', '1');
        Setting::set('psb_wa_group_ids', [self::P]);
    }

    public function test_flag_off_has_no_psb_detail_key_and_the_text_is_unchanged(): void
    {
        $this->psbCustomer();
        $this->openDraft();

        $built = app(DailyReportService::class)->build($this->day);

        $this->assertSame(['psb', 'psb_mtd', 'migration', 'errors'], array_keys($built));
        $text = (new DailyReportMessages)->daily($built, $this->day, $this->sentAt, true);
        $this->assertStringNotContainsString('Draft PSB dan kendala', $text);
        $this->assertStringContainsString("\n\n*Migrasi*\n", $text);
    }

    public function test_flag_off_report_text_is_byte_identical_to_the_golden_text(): void
    {
        $this->psbCustomer();
        $this->openDraft();

        $built = app(DailyReportService::class)->build($this->day);
        $text = (new DailyReportMessages)->daily($built, $this->day, $this->sentAt, true);

        $golden = implode("\n", [
            '*LAPORAN HARIAN SKYNET*',
            'Data: Rabu, 21/10/2026 (00:00-23:59 WIB)',
            'Dikirim: 22/10/2026 08:03 WIB',
            '',
            '*PSB (pasang baru)*',
            'Total kemarin: 1 pelanggan',
            'Aktif 1 | Belum aktif 0 | Isolir 0 | Putus 0',
            'Sumber: WA 0 | Non-WA 1',
            'Per area:',
            '- KLAMPOK: 1',
            'Bulan Oktober (1-21 Okt): 1 pelanggan',
            '',
            '*Migrasi*',
            'Selesai kemarin: 0 pelanggan',
            'Masuk kemarin: 0',
            'Belum selesai (posisi 08:03, status DB): 0',
            '',
            'Rincian PSB: ketik #rekap psb semua kemarin',
        ]);
        $this->assertSame($golden, $text);
        $this->assertSame(['psb', 'psb_mtd', 'migration', 'errors'], array_keys($built));
    }

    public function test_row_cap_hit_is_logged_and_a_normal_day_is_not(): void
    {
        Log::spy();
        $this->openDraft();
        app(PsbDailyDetailStats::class)->collect($this->day, null, 3);
        Log::shouldNotHaveReceived('warning');

        $now = '2026-10-17 03:00:00';
        $rows = [];
        for ($i = 0; $i < PsbDailyDetailStats::ROW_LIMIT; $i++) {
            $rows[] = ['status' => 'waiting_sn', 'message_id' => 'cap-'.$i, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('psb_drafts')->insert($chunk);
        }

        app(PsbDailyDetailStats::class)->collect($this->day, null, 3);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'psb detail row cap hit') && ($context['part'] ?? null) === 'open')
            ->once();
    }

    public function test_flag_on_adds_the_section_between_psb_and_migrasi(): void
    {
        Setting::set('report_daily_psb_detail_enabled', '1');
        $this->psbCustomer();
        $this->openDraft();

        $built = app(DailyReportService::class)->build($this->day);

        $this->assertSame([], $built['errors']);
        $this->assertSame(1, $built['psb_detail']['open']['total']);
        $this->assertSame(1, $built['psb_detail']['open']['stuck']);
        $text = (new DailyReportMessages)->daily($built, $this->day, $this->sentAt, true);
        $psb = strpos($text, '*PSB (pasang baru)*');
        $detail = strpos($text, '*Draft PSB dan kendala*');
        $migrasi = strpos($text, '*Migrasi*');
        $this->assertTrue($psb < $detail && $detail < $migrasi);
        $this->assertStringContainsString('Macet lebih dari 3 hari: 1', $text);
        $this->assertStringNotContainsString('NIK', $text);
    }

    public function test_the_stuck_days_setting_is_used_and_clamped(): void
    {
        Setting::set('report_daily_psb_detail_enabled', '1');
        Setting::set('report_psb_stuck_days', '7');
        $this->openDraft(); // 5 days old

        $this->assertSame(0, app(DailyReportService::class)->build($this->day)['psb_detail']['open']['stuck']);
        $this->assertSame(7, app(DailyReportService::class)->build($this->day)['psb_detail']['open']['stuck_days']);

        Setting::set('report_psb_stuck_days', '500');
        $this->assertSame(14, app(DailyReportService::class)->build($this->day)['psb_detail']['open']['stuck_days']);
    }

    public function test_force_option_overrides_the_flag_both_ways(): void
    {
        $service = app(DailyReportService::class);
        $this->assertArrayHasKey('psb_detail', $service->buildWithDetail($this->day));

        Setting::set('report_daily_psb_detail_enabled', '1');
        $this->assertArrayNotHasKey('psb_detail', $service->buildWithDetail($this->day, false));
        $this->assertArrayHasKey('psb_detail', $service->build($this->day));
    }

    public function test_section_failure_is_noted_and_does_not_block_the_other_sections(): void
    {
        Setting::set('report_daily_psb_detail_enabled', '1');
        $this->psbCustomer();
        $this->app->bind(PsbDailyDetailStats::class, fn () => new class extends PsbDailyDetailStats
        {
            public function collect(CarbonImmutable $day, ?CarbonImmutable $now = null, int $stuckDays = self::DEFAULT_STUCK_DAYS): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $built = app(DailyReportService::class)->build($this->day);

        $this->assertNull($built['psb_detail']);
        $this->assertContains('psb_detail:RuntimeException', $built['errors']);
        $this->assertNotNull($built['psb']);
        $this->assertNotNull($built['migration']);
        $text = (new DailyReportMessages)->daily($built, $this->day, $this->sentAt, false);
        $this->assertStringContainsString("*Draft PSB dan kendala*\n(data draft gagal dibaca, tim teknis sudah dicatat)", $text);
        $this->assertFalse(app(DailyReportService::class)->bothFailed($built));
    }

    public function test_an_empty_day_is_still_not_sent_even_with_stuck_drafts(): void
    {
        Setting::set('report_daily_psb_detail_enabled', '1');
        $this->enableLive();
        $this->openDraft();

        $built = app(DailyReportService::class)->build($this->day);
        $this->assertSame(1, $built['psb_detail']['open']['stuck']);
        $this->assertTrue(app(DailyReportService::class)->isZeroDay($built));

        $this->artisan('report:daily-wa')->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertSame('skipped', WaReportDelivery::query()->where('group_jid', self::R)->value('status'));
    }

    public function test_live_run_sends_the_section_once_and_never_to_a_psb_overlap_group(): void
    {
        Setting::set('report_daily_psb_detail_enabled', '1');
        $this->enableLive();
        Setting::set('report_wa_group_ids', [self::R, self::P]);
        $this->psbCustomer();
        $this->openDraft();

        $this->artisan('report:daily-wa')->assertSuccessful();
        $this->artisan('report:daily-wa')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::R, $this->sent[0][0]);
        $this->assertStringContainsString('*Draft PSB dan kendala*', $this->sent[0][1]);
        $this->assertSame('sent', WaReportDelivery::query()->where('group_jid', self::R)->value('status'));
        $row = WaReportDelivery::query()->where('group_jid', self::P)->first();
        $this->assertSame('skipped', $row->status);
        $this->assertSame([], array_values(array_filter($this->sent, fn (array $s): bool => $s[0] === self::P)));
    }

    public function test_dry_run_option_prints_the_section_without_the_flag_and_writes_nothing(): void
    {
        $this->enableLive();
        $this->psbCustomer();
        $this->openDraft();

        $this->artisan('report:daily-wa', ['--dry-run' => true, '--date' => '2026-10-21'])
            ->doesntExpectOutputToContain('Draft PSB dan kendala')
            ->assertSuccessful();

        $this->artisan('report:daily-wa', ['--dry-run' => true, '--psb-detail' => true, '--date' => '2026-10-21'])
            ->expectsOutputToContain("*Draft PSB dan kendala*\nDraft masuk kemarin: 0 (jadi pelanggan 0, batal 0)\nBelum selesai (posisi 08:03): 1")
            ->assertSuccessful();

        $this->assertSame(0, WaReportDelivery::query()->count());
        $this->assertSame([], $this->sent);
        $this->assertNull(Setting::get('report_daily_psb_detail_enabled'));
    }
}
