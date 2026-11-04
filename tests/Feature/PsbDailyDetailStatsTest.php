<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Services\Report\PsbDailyDetailStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Counts behind the daily report section "Draft PSB dan kendala". D = 2026-10-21 (WIB), "posisi" = 22 Oct 08:03 WIB.
 * Drafts are raw rows so created_at / updated_at are exact UTC strings.
 */
#[Group('blocking-core')]
class PsbDailyDetailStatsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $day;

    private CarbonImmutable $now;

    private Area $klampok;

    private Area $krian;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta');
        $this->now = CarbonImmutable::parse('2026-10-22 08:03', 'Asia/Jakarta');
        $this->travelTo($this->now);
        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->krian = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'krian']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function draft(array $over = [], string $createdUtc = '2026-10-21 03:00:00', ?string $updatedUtc = null): int
    {
        $this->seq++;
        if (isset($over['meta']) && is_array($over['meta'])) {
            $over['meta'] = json_encode($over['meta']);
        }

        return (int) DB::table('psb_drafts')->insertGetId(array_merge([
            'status' => 'received',
            'message_id' => 'pd-'.$this->seq,
            'created_at' => $createdUtc,
            'updated_at' => $updatedUtc ?? $createdUtc,
        ], $over));
    }

    private function customer(Area $area): int
    {
        $this->seq++;
        $package = Package::query()->first() ?? Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);

        return Customer::create([
            'code' => 'PD-'.$this->seq, 'name' => 'Pelanggan '.$this->seq, 'address' => 'Dusun Contoh', 'package_id' => $package->id,
            'area_id' => $area->id, 'pppoe_user' => 'userpd'.$this->seq, 'status' => 'active', 'join_date' => '2026-10-21',
        ])->id;
    }

    /** @return array<string, mixed> */
    private function collect(int $stuckDays = 3): array
    {
        return app(PsbDailyDetailStats::class)->collect($this->day, $this->now, $stuckDays);
    }

    public function test_received_counts_use_the_wib_day_and_split_customer_and_cancelled(): void
    {
        // WIB D = 20 Oct 17:00:00Z .. 21 Oct 16:59:59Z
        $this->draft(['status' => 'olt_ok'], '2026-10-20 17:00:00');
        $this->draft(['status' => 'received'], '2026-10-21 16:59:59');
        $this->draft(['status' => 'cancelled'], '2026-10-21 05:00:00');
        $this->draft(['status' => 'waiting_sn', 'customer_id' => $this->customer($this->klampok)], '2026-10-21 05:00:00');
        $this->draft(['status' => 'olt_ok'], '2026-10-20 16:59:59'); // D-1 in WIB
        $this->draft(['status' => 'olt_ok'], '2026-10-21 17:00:00'); // D+1 in WIB
        $this->draft(['status' => 'olt_ok', 'meta' => ['migration_id' => 5]], '2026-10-21 05:00:00'); // migration, not PSB

        $this->assertSame(['total' => 4, 'jadi' => 2, 'batal' => 1], $this->collect()['masuk']);
    }

    public function test_htb_secret_ok_with_pppoe_user_is_success_and_full_olt_secret_ok_is_waiting_for_olt(): void
    {
        $this->draft(['status' => 'secret_ok', 'activation_mode' => 'pppoe_only', 'pppoe_user' => 'userhtb'], '2026-10-21 05:00:00');
        $this->draft(['status' => 'secret_ok', 'activation_mode' => 'pppoe_vlan', 'pppoe_user' => 'userhtb2'], '2026-10-21 05:00:00');
        $this->draft(['status' => 'secret_ok', 'activation_mode' => 'full_olt', 'pppoe_user' => 'userfull'], '2026-10-21 05:00:00');
        $this->draft(['status' => 'secret_ok', 'activation_mode' => 'pppoe_only'], '2026-10-21 05:00:00'); // HTB but no pppoe_user: still open

        $r = $this->collect();

        $this->assertSame(2, $r['open']['total']);
        $this->assertSame(['Tunggu OLT' => 2], $r['open']['stages']);
        $this->assertSame(2, $r['masuk']['jadi']);
    }

    public function test_open_set_has_all_stages_in_fixed_order_and_skips_finished_cancelled_and_migration(): void
    {
        $this->draft(['status' => 'correction_pending_review']);
        $this->draft(['status' => 'secret_ok', 'activation_mode' => 'full_olt']);
        $this->draft(['status' => 'pending_vlan']);
        $this->draft(['status' => 'waiting_sn']);
        $this->draft(['status' => 'waiting_sn']);
        $this->draft(['status' => 'received']);
        $this->draft(['status' => 'olt_ok']);
        $this->draft(['status' => 'applied']);
        $this->draft(['status' => 'cancelled']);
        $this->draft(['status' => 'failed']);
        $this->draft(['status' => 'waiting_sn', 'meta' => ['migration_id' => 9]]);

        $open = $this->collect()['open'];

        $this->assertSame(6, $open['total']);
        $this->assertSame(
            ['Baru masuk' => 1, 'Tunggu SN' => 2, 'Tunggu VLAN' => 1, 'Tunggu OLT' => 1, 'Cek koreksi' => 1],
            $open['stages'],
        );
        $this->assertSame(['Baru masuk', 'Tunggu SN', 'Tunggu VLAN', 'Tunggu OLT', 'Cek koreksi'], array_keys($open['stages']));
    }

    public function test_open_window_is_14_days(): void
    {
        // now = 21 Oct 2026 01:03Z + ... : 22 Oct 08:03 WIB = 22 Oct 01:03Z; minus 14 d = 8 Oct 01:03Z
        $this->draft(['status' => 'waiting_sn'], '2026-10-08 01:04:00');
        $this->draft(['status' => 'waiting_sn'], '2026-10-08 01:02:00');
        $this->draft(['status' => 'waiting_sn'], '2026-09-01 01:00:00');

        $this->assertSame(1, $this->collect()['open']['total']);
    }

    public function test_stuck_means_older_than_n_days_and_n_is_clamped(): void
    {
        $this->draft(['status' => 'waiting_sn'], '2026-10-18 01:00:00'); // 4 d old
        $this->draft(['status' => 'waiting_sn'], '2026-10-19 01:03:00'); // 3 d exactly: not stuck
        $this->draft(['status' => 'waiting_sn'], '2026-10-21 01:00:00'); // 1 d

        $this->assertSame(1, $this->collect(3)['open']['stuck']);
        $this->assertSame(3, $this->collect(3)['open']['stuck_days']);
        $this->assertSame(2, $this->collect(2)['open']['stuck']);
        // clamp 1..14
        $this->assertSame(1, $this->collect(0)['open']['stuck_days']);
        $this->assertSame(14, $this->collect(99)['open']['stuck_days']);
        $this->assertSame(1, $this->collect(-5)['open']['stuck_days']);
    }

    public function test_areas_prefer_the_customer_area_then_the_draft_area_then_tanpa_area(): void
    {
        $this->draft(['status' => 'waiting_sn', 'customer_id' => $this->customer($this->klampok), 'area_id' => $this->krian->id]);
        $this->draft(['status' => 'waiting_sn', 'area_id' => $this->krian->id]);
        $this->draft(['status' => 'waiting_sn', 'area_id' => $this->krian->id]);
        $this->draft(['status' => 'waiting_sn']);

        $this->assertSame(
            ['SKYNET-KRIAN' => 2, 'SKYNET-KLAMPOK' => 1, 'Tanpa area' => 1],
            $this->collect()['open']['by_area'],
        );
    }

    public function test_failure_buckets(): void
    {
        $upd = '2026-10-21 04:00:00';
        $this->draft(['status' => 'timeout'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'last_error' => 'Perlu area untuk pelanggan ini'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'sn' => 'FHTT00000001', 'activation_mode' => 'full_olt', 'last_error' => 'x'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'sn' => 'FHTT00000002', 'last_error' => 'x'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'sn' => 'FHTT00000003', 'olt_at' => '2026-10-21 03:00:00', 'last_error' => 'x'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'last_error' => 'field kosong'], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'failed', 'sn' => 'FHTT00000004', 'activation_mode' => 'pppoe_only'], '2026-10-20 20:00:00', $upd);
        // not counted: updated outside D, migration draft, other status
        $this->draft(['status' => 'failed'], '2026-10-10 20:00:00', '2026-10-20 16:59:59');
        $this->draft(['status' => 'failed', 'meta' => ['migration_id' => 1]], '2026-10-20 20:00:00', $upd);
        $this->draft(['status' => 'waiting_sn'], '2026-10-20 20:00:00', $upd);

        $failed = $this->collect()['failed'];

        $this->assertSame(7, $failed['total']);
        $this->assertSame(
            ['timeout SN' => 1, 'perlu area' => 1, 'gagal di OLT' => 2, 'gagal lain' => 3],
            $failed['buckets'],
        );
    }

    public function test_output_carries_no_text_from_the_drafts(): void
    {
        $this->draft(['status' => 'failed', 'full_name' => 'Nama Rahasia', 'phone' => '081234567890', 'last_error' => 'Perlu area RAHASIA'], '2026-10-20 20:00:00', '2026-10-21 04:00:00');

        $json = (string) json_encode($this->collect());

        foreach (['Nama Rahasia', '081234567890', 'RAHASIA'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_it_only_reads_and_stays_within_six_queries(): void
    {
        $this->draft(['status' => 'waiting_sn', 'customer_id' => $this->customer($this->klampok)]);
        $this->draft(['status' => 'failed'], '2026-10-20 20:00:00', '2026-10-21 04:00:00');

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->collect();

        $this->assertLessThanOrEqual(6, count($queries));
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)), $sql);
        }
    }

    public function test_row_guard_caps_the_open_scan_at_2000(): void
    {
        $rows = [];
        for ($i = 1; $i <= 2050; $i++) {
            $rows[] = ['status' => 'received', 'message_id' => 'bulk-'.$i, 'created_at' => '2026-10-21 03:00:00', 'updated_at' => '2026-10-21 03:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('psb_drafts')->insert($chunk);
        }

        $r = $this->collect();

        $this->assertSame(PsbDailyDetailStats::ROW_LIMIT, $r['open']['total']);
        $this->assertSame(PsbDailyDetailStats::ROW_LIMIT, $r['masuk']['total']);
    }
}
