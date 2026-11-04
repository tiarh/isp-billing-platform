<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Recap\MigrasiRecapCommandParser;
use App\Services\Recap\MigrationRecapService;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapTopicMessages;
use App\Services\Report\MigrationDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

/**
 * "#rekap migrasi" numbers. "now" = 15 Oct 2026 09:12 WIB, reported day D = 14 Oct (WIB).
 */
#[Group('blocking-psb')]
class MigrationRecapDataTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    private Area $klampok;

    private Area $gadang;

    private int $seq = 0;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta'));
        $this->day = CarbonImmutable::parse('2026-10-14', 'Asia/Jakarta');
        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->gadang = Area::create(['name' => 'SKYNET-GADANG', 'code' => 'gadang']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/recap/migrasi-test'));
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * Raw insert so created_at / synced_at are exact UTC strings.
     *
     * @param  array<string, mixed>  $over
     */
    private function migration(array $over = []): int
    {
        $this->seq++;
        if (isset($over['meta']) && is_array($over['meta'])) {
            $over['meta'] = json_encode($over['meta']);
        }

        return (int) DB::table('customer_migrations')->insertGetId(array_merge([
            'status' => 'synced',
            'source' => 'wa',
            'message_id' => 'mr-'.$this->seq,
            'group_jid' => 'activation@g.us',
            'sender_name' => 'Andi',
            'customer_id' => null,
            'pppoe_user' => 'mig'.$this->seq,
            'full_name' => 'Siti Contoh '.$this->seq,
            'area_id' => $this->klampok->id,
            'synced_at' => '2026-10-14 03:00:00',
            'created_at' => '2026-10-13 20:00:00',
            'updated_at' => '2026-10-14 03:00:00',
        ], $over));
    }

    private function request(string $text): RecapRequest
    {
        return (new MigrasiRecapCommandParser)->parse($text, $this->day->addDay()->setTime(9, 12));
    }

    /** @return array<string, mixed> */
    private function collect(string $text, ?Area $area = null, bool $evidence = false): array
    {
        return app(MigrationRecapService::class)->collect(
            $this->request($text),
            $area?->id,
            $area ? strtoupper((string) $area->code) : 'SEMUA AREA',
            null,
            $evidence,
        );
    }

    public function test_numbers_equal_the_daily_stats_for_a_single_day_and_all_areas(): void
    {
        $this->migration(['pppoe_user' => 'a1']);
        $this->migration(['pppoe_user' => 'a2', 'area_id' => $this->gadang->id]);
        $this->migration(['pppoe_user' => 'a2', 'synced_at' => '2026-10-14 04:00:00']); // same identity twice
        $this->migration(['pppoe_user' => 'imp1', 'source' => 'excel_import']);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null, 'pppoe_user' => 'w1', 'created_at' => '2026-10-14 02:00:00']);
        $this->migration(['status' => 'failed', 'synced_at' => null, 'pppoe_user' => 'f1']);
        $this->migration(['status' => 'partially_executed', 'synced_at' => null, 'pppoe_user' => 'p1']);
        $this->migration(['status' => 'needs_review', 'synced_at' => null, 'pppoe_user' => 'n1']);
        $this->migration(['status' => 'received', 'synced_at' => null, 'pppoe_user' => 'r1']);
        $this->migration(['status' => 'cancelled', 'synced_at' => null, 'pppoe_user' => 'c1', 'created_at' => '2026-10-14 02:00:00']);
        $this->migration(['pppoe_user' => 'old1', 'synced_at' => '2026-10-10 03:00:00', 'created_at' => '2026-10-09 03:00:00']);

        $stats = app(MigrationDailyStats::class)->collect($this->day);
        $data = $this->collect('#rekap migrasi semua kemarin');

        $this->assertSame($stats['selesai']['lapangan'], $data['selesai']['lapangan']);
        $this->assertSame($stats['selesai']['impor'], $data['selesai']['impor']);
        $this->assertSame($stats['selesai']['by_area'], $data['selesai']['by_area']);
        $this->assertSame($stats['masuk'], $data['masuk']);
        $this->assertSame($stats['belum'], $data['belum']);
        $this->assertSame(2, $data['selesai']['lapangan']);
        $this->assertSame(1, $data['selesai']['impor']);
        $this->assertFalse($data['evidence']);
    }

    public function test_area_filter_uses_migration_area_then_customer_area(): void
    {
        $package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        $customer = Customer::create(['code' => 'C1', 'name' => 'Pelanggan', 'address' => 'x', 'package_id' => $package->id, 'area_id' => $this->klampok->id, 'pppoe_user' => 'viacust', 'status' => 'active']);

        $this->migration(['pppoe_user' => 'k1']);
        $this->migration(['pppoe_user' => 'k2', 'area_id' => null, 'customer_id' => $customer->id]);
        $this->migration(['pppoe_user' => 'g1', 'area_id' => $this->gadang->id]);
        $this->migration(['pppoe_user' => 'none', 'area_id' => null]);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null, 'pppoe_user' => 'kw', 'created_at' => '2026-10-14 02:00:00']);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null, 'pppoe_user' => 'gw', 'area_id' => $this->gadang->id, 'created_at' => '2026-10-14 02:00:00']);

        $klampok = $this->collect('#rekap migrasi klampok kemarin', $this->klampok);
        $this->assertSame(2, $klampok['selesai']['lapangan']);
        $this->assertSame(1, $klampok['belum']['tunggu_sn']);
        $this->assertSame(3, $klampok['masuk']); // k1, k2, kw
        $this->assertSame(['2026-10-14' => 2], $klampok['selesai']['by_date']);

        $all = $this->collect('#rekap migrasi semua kemarin');
        $this->assertSame(4, $all['selesai']['lapangan']);
        $this->assertSame(['KLAMPOK' => 2, 'GADANG' => 1, 'Tanpa area' => 1], $all['selesai']['by_area']);
    }

    public function test_range_per_date_and_technician(): void
    {
        $this->migration(['pppoe_user' => 'd1', 'synced_at' => '2026-10-02 03:00:00', 'sender_name' => 'Andi']);
        $this->migration(['pppoe_user' => 'd2', 'synced_at' => '2026-10-02 04:00:00', 'sender_name' => 'Andi']);
        $this->migration(['pppoe_user' => 'd3', 'synced_at' => '2026-10-05 04:00:00', 'sender_name' => 'Budi']);
        // 2026-10-14 17:30 UTC = 15 Oct 00:30 WIB: outside a range that ends 14 Oct
        $this->migration(['pppoe_user' => 'd4', 'synced_at' => '2026-10-14 17:30:00']);

        $data = $this->collect('#rekap migrasi klampok 1-14 okt', $this->klampok);

        $this->assertSame(3, $data['selesai']['lapangan']);
        $this->assertSame(14, $data['days']);
        $this->assertSame(['2026-10-02' => 2, '2026-10-05' => 1], $data['selesai']['by_date']);
        $this->assertSame(['Andi' => 2, 'Budi' => 1], $data['selesai']['by_tech']);
    }

    public function test_auto_reconciled_rows_are_counted_as_completed_with_their_own_split(): void
    {
        $this->migration(['pppoe_user' => 'm1']);
        $this->migration(['pppoe_user' => 'm2', 'meta' => ['auto_reconcile' => ['version' => 1, 'reason' => 'otomatis: ONU + PPPoE online']]]);
        $this->migration(['pppoe_user' => 'm3', 'meta' => ['auto_reconcile' => ['version' => 1]]]);

        $data = $this->collect('#rekap migrasi semua kemarin', null, true);

        $this->assertSame(3, $data['selesai']['lapangan']);
        $this->assertSame(1, $data['selesai']['db']);
        $this->assertSame(2, $data['selesai']['auto']);

        $text = (new RecapTopicMessages)->migrasiRecap($data);
        $this->assertStringContainsString('*Selesai: 3 pelanggan*', $text);
        $this->assertStringContainsString('Status DB 1 | Otomatis (ONU + PPPoE online) 2', $text);
    }

    public function test_evidence_reuses_the_auto_reconcile_evaluator_rules(): void
    {
        $eligible = $this->fixture(['status' => 'needs_review']);
        $admin = $this->fixture(['status' => 'failed']);
        $bad = $this->fixture(['status' => 'needs_review'], [], ['state' => 'offline', 'phase_state' => 'LOS']);

        $kinds = [];
        foreach (app(MigrationAutoReconcileService::class)->evaluate()['verdicts'] as $v) {
            $kinds[$v->migrationId] = $v->kind;
        }
        $this->assertSame('eligible', $kinds[$eligible['migration']->id]);
        $this->assertSame('perlu_dicek_admin', $kinds[$admin['migration']->id]);
        $this->assertSame('skip', $kinds[$bad['migration']->id]);

        $data = $this->collect('#rekap migrasi semua 1-15 okt', null, true);

        $this->assertTrue($data['evidence']);
        $this->assertSame(1, $data['online_pending']);
        $this->assertSame(1, $data['perlu_dicek']);
        $this->assertSame(3, $data['belum']['total']);

        $text = (new RecapTopicMessages)->migrasiRecap($data);
        $this->assertStringContainsString('ONU sudah online, status DB belum diperbarui: 1', $text);
        $this->assertStringContainsString('Perlu dicek admin: 1', $text);

        $off = $this->collect('#rekap migrasi semua 1-15 okt', null, false);
        $this->assertSame(0, $off['online_pending']);
        $this->assertStringNotContainsString('ONU sudah online', (new RecapTopicMessages)->migrasiRecap($off));
    }

    public function test_evidence_off_never_runs_the_evaluator_and_failure_keeps_db_numbers(): void
    {
        $this->migration(['pppoe_user' => 'm1']);
        $failing = new class extends MigrationAutoReconcileService
        {
            public function evaluate(?array $ids = null, ?array $settings = null, ?Carbon $now = null): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->bind(MigrationAutoReconcileService::class, fn () => $failing);

        $off = $this->collect('#rekap migrasi semua kemarin', null, false);
        $this->assertSame(1, $off['selesai']['lapangan']);
        $this->assertFalse($off['evidence_failed']);

        $on = $this->collect('#rekap migrasi semua kemarin', null, true);
        $this->assertTrue($on['evidence_failed']);
        $this->assertSame(1, $on['selesai']['lapangan']);
        $this->assertStringContainsString('bukti ONU online gagal dibaca', (new RecapTopicMessages)->migrasiRecap($on));
    }

    public function test_collect_is_read_only(): void
    {
        $this->fixture(['status' => 'needs_review']);
        $this->migration(['pppoe_user' => 'm1']);
        $snapshot = fn (): array => [
            DB::table('customer_migrations')->orderBy('id')->get()->toJson(),
            DB::table('customers')->orderBy('id')->get()->toJson(),
            DB::table('activity_log')->count(),
        ];
        $before = $snapshot();

        $this->collect('#rekap migrasi semua 1-15 okt', null, true);

        $this->assertSame($before, $snapshot());
    }

    public function test_zero_data_text(): void
    {
        $data = $this->collect('#rekap migrasi klampok kemarin', $this->klampok);

        $this->assertSame(0, $data['total']);
        $this->assertSame('Tidak ada migrasi tercatat untuk KLAMPOK pada periode ini.', (new RecapTopicMessages)->migrasiRecap($data));
    }

    public function test_text_lists_names_for_one_area_and_never_for_semua_and_has_no_pii(): void
    {
        $this->migration(['pppoe_user' => 'pp-secret-1', 'full_name' => 'Siti #psb Aminah', 'rw' => '03', 'phone' => '0812CANARYPHONE', 'nik' => 'CANARYNIK1234', 'raw_text' => 'CANARY-RAW', 'last_error' => 'CANARY-ERR', 'tikor' => '-7.1,110.1']);

        $one = (new RecapTopicMessages)->migrasiRecap($this->collect('#rekap migrasi klampok kemarin', $this->klampok));
        $this->assertStringContainsString('*REKAP MIGRASI - KLAMPOK*', $one);
        $this->assertStringContainsString('Periode: 14/10/2026 - 14/10/2026 (1 hari)', $one);
        $this->assertStringContainsString('*Daftar nama*', $one);
        $this->assertStringContainsString('SITI PSB AMINAH - selesai (DB) - RW 03', $one);
        $this->assertStringNotContainsString('#', $one);

        $all = (new RecapTopicMessages)->migrasiRecap($this->collect('#rekap migrasi semua kemarin'));
        $this->assertStringNotContainsString('SITI', $all);
        $this->assertStringContainsString('*Per area (15 teratas)*', $all);

        foreach ([$one, $all] as $text) {
            foreach (['CANARY', '-7.1', 'activation@g.us', 'pp-secret-1'] as $needle) {
                $this->assertStringNotContainsString($needle, $text);
            }
        }
    }

    public function test_excel_has_one_row_per_identity_and_no_pii(): void
    {
        $this->migration(['pppoe_user' => 's1', 'full_name' => 'Siti Contoh', 'phone' => '0812CANARYPHONE', 'nik' => 'CANARYNIK1234', 'raw_text' => 'CANARY-RAW', 'last_error' => 'CANARY-ERR', 'tikor' => 'CANARY-TIKOR', 'ktp_photo_path' => 'CANARY/ktp.jpg']);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null, 'pppoe_user' => 'w1', 'full_name' => 'Wati', 'created_at' => '2026-10-14 02:00:00', 'new_sn' => 'FHTT1234']);

        $data = $this->collect('#rekap migrasi klampok kemarin', $this->klampok);
        $this->assertSame(2, $data['total']);

        File::ensureDirectoryExists(storage_path('app/recap/migrasi-test'));
        $path = storage_path('app/recap/migrasi-test/test.xlsx');
        app(MigrationRecapService::class)->buildXlsx($data, $path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml .= $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertStringContainsString('Siti Contoh', $xml);
        $this->assertStringContainsString('Wati', $xml);
        $this->assertStringContainsString('FHTT1234', $xml);
        $this->assertStringContainsString('Per tanggal selesai', $xml);
        $this->assertStringNotContainsString('CANARY', $xml);
    }

    public function test_excel_flags_a_newer_open_migration_and_keeps_date_counts_static(): void
    {
        $this->migration(['pppoe_user' => 'same1', 'full_name' => 'Dewi Lestari']);
        $this->migration(['status' => 'waiting_sn', 'synced_at' => null, 'pppoe_user' => 'same1', 'full_name' => 'Dewi Lestari', 'created_at' => '2026-10-14 05:00:00', 'updated_at' => '2026-10-14 06:00:00']);
        $this->migration(['pppoe_user' => 'imp9', 'source' => 'excel_import']); // bulk row, in the Data sheet date column

        $data = $this->collect('#rekap migrasi klampok kemarin', $this->klampok);

        File::ensureDirectoryExists(storage_path('app/recap/migrasi-test'));
        $path = storage_path('app/recap/migrasi-test/newer-open.xlsx');
        app(MigrationRecapService::class)->buildXlsx($data, $path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml .= $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertStringContainsString('Migrasi baru terbuka', $xml);
        $this->assertStringContainsString('ada migrasi baru yang masih terbuka (status waiting_sn)', $xml);
        // Per tanggal selesai counts field rows only (as the WA text): no recalculating COUNTIF over the date column.
        $this->assertStringNotContainsString('COUNTIF(Data!D', $xml);
        $this->assertStringContainsString('COUNTIF(Data!F', $xml); // other sections stay formulas
    }

    public function test_daily_extra_matches_the_command_numbers(): void
    {
        $this->migration(['pppoe_user' => 'm1']);
        $this->migration(['pppoe_user' => 'm2', 'meta' => ['auto_reconcile' => ['version' => 1]]]);
        $this->fixture(['status' => 'failed']);

        $extra = app(MigrationRecapService::class)->dailyExtra($this->day);

        $this->assertSame(['db' => 1, 'otomatis' => 1, 'online_pending' => 0, 'perlu_dicek' => 1], $extra);
    }
}
