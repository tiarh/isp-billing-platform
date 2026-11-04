<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\Recap\RecapRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Data definition of "#rekap psb": customers (area_id + join_date WIB, not
 * deleted) are the truth; drafts only label WA / Non-WA.
 */
#[Group('blocking-psb')]
class PsbRecapCommandDataTest extends TestCase
{
    use RefreshDatabase;

    private Area $klampok;

    private Area $other;

    private Package $package;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // 14 Oct 2026 00:30 WIB (= 13 Oct 17:30 UTC): the WIB "today" is the 14th.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 00:30', 'Asia/Jakarta'));

        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->other = Area::create(['name' => 'SKYNET-BIRU', 'code' => 'biru']);
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
            'code' => 'RC-'.$this->seq,
            'name' => 'Pelanggan '.$this->seq,
            'address' => 'Dusun Klampok RT 03 RW 05',
            'package_id' => $this->package->id,
            'area_id' => $this->klampok->id,
            'pppoe_user' => 'user'.$this->seq,
            'status' => 'active',
            'join_date' => $joinDate,
        ], $over));
    }

    private function draft(Customer $customer, array $over = []): PsbDraft
    {
        $this->seq++;

        return PsbDraft::create(array_merge([
            'status' => 'applied',
            'message_id' => 'draft-msg-'.$this->seq,
            'customer_id' => $customer->id,
            'full_name' => $customer->name,
            'rt' => '03',
            'rw' => '05',
            'odp' => 'ODP-K-1',
            'area_id' => $customer->area_id,
        ], $over));
    }

    /**
     * @return array<string, mixed>
     */
    private function recap(string $command): array
    {
        $request = (new PsbRecapCommandParser)->parse($command);
        $this->assertInstanceOf(RecapRequest::class, $request);
        $this->assertNull($request->error);

        $service = app(PsbAreaRecapService::class);
        $area = $service->resolveArea($request);

        return $service->collect($request, $area['area_id'], $area['area_name']);
    }

    public function test_wib_date_boundaries_with_the_clock_at_0030_wib(): void
    {
        $this->customer('2026-09-30');
        $this->customer('2026-10-01');
        $this->customer('2026-10-14');
        $this->customer('2026-10-15');

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $this->assertSame(2, $data['total']);
        $this->assertSame(['2026-10-01' => 1, '2026-10-14' => 1], $data['by_date']);
        $this->assertSame('2026-10-01', $data['from']);
        $this->assertSame('2026-10-14', $data['to']);
    }

    public function test_default_range_is_first_of_month_until_wib_today(): void
    {
        $this->customer('2026-09-30');
        $this->customer('2026-10-01');
        $this->customer('2026-10-14');

        $data = $this->recap('#rekap psb klampok');

        $this->assertSame(2, $data['total']);
        $this->assertSame('2026-10-14', $data['to']);
    }

    public function test_soft_deleted_customers_are_excluded(): void
    {
        $this->customer('2026-10-05');
        $deleted = $this->customer('2026-10-06');
        $deleted->delete();

        $this->assertSame(1, $this->recap('#rekap psb klampok 1-14 okt')['total']);
    }

    public function test_status_buckets_and_terminated_counts_as_putus(): void
    {
        $this->customer('2026-10-02', ['status' => 'active']);
        $this->customer('2026-10-03', ['status' => 'pending_installation']);
        $this->customer('2026-10-04', ['status' => 'isolated']);
        $this->customer('2026-10-05', ['status' => 'terminated']);

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $this->assertSame(4, $data['total']);
        $this->assertSame(
            ['aktif' => 1, 'belum aktif' => 1, 'isolir' => 1, 'putus' => 1],
            $data['by_status'],
        );
        $this->assertSame('putus', PsbAreaRecapService::statusBucket('dismantled'));
        $this->assertSame('putus', PsbAreaRecapService::statusBucket('terminated'));
        $this->assertSame('isolir', PsbAreaRecapService::statusBucket('isolir'));
    }

    public function test_migration_customers_are_excluded_but_cancelled_or_later_migrations_are_not(): void
    {
        $counted = $this->customer('2026-10-02');

        // Created by a migration on 5 Oct (WIB): excluded.
        $migrated = $this->customer('2026-10-05');
        CustomerMigration::query()->forceCreate([
            'status' => 'synced',
            'customer_id' => $migrated->id,
            'created_at' => CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Jakarta')->utc(),
            'updated_at' => now(),
        ]);

        // Migration row was cancelled: still a PSB.
        $cancelled = $this->customer('2026-10-06');
        CustomerMigration::query()->forceCreate([
            'status' => 'cancelled',
            'customer_id' => $cancelled->id,
            'created_at' => CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Jakarta')->utc(),
            'updated_at' => now(),
        ]);

        // Joined BEFORE its migration (a real PSB that was migrated later): still counted.
        $earlier = $this->customer('2026-10-03');
        CustomerMigration::query()->forceCreate([
            'status' => 'synced',
            'customer_id' => $earlier->id,
            'created_at' => CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Jakarta')->utc(),
            'updated_at' => now(),
        ]);

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['excluded_migration']);
        $ids = array_column($data['rows'], 'customer_id');
        $this->assertContains($counted->id, $ids);
        $this->assertContains($cancelled->id, $ids);
        $this->assertContains($earlier->id, $ids);
        $this->assertNotContains($migrated->id, $ids);
    }

    public function test_migration_exclusion_keeps_a_new_customer_with_a_psb_draft_made_on_or_before_the_migration_day(): void
    {
        $migrateOn = fn (Customer $c, string $day) => CustomerMigration::query()->forceCreate([
            'status' => 'synced',
            'customer_id' => $c->id,
            'created_at' => CarbonImmutable::parse($day.' 15:00', 'Asia/Jakarta')->utc(),
            'updated_at' => now(),
        ]);

        // (1) Old customer whose join_date was reset by a migration, no PSB draft: excluded.
        $old = $this->customer('2026-10-10');
        $migrateOn($old, '2026-10-10');

        // (2) Genuinely new: PSB draft (WA) and migration on the same WIB day: counted.
        $sameDay = $this->customer('2026-10-10');
        $draft = $this->draft($sameDay);
        $draft->forceFill(['created_at' => CarbonImmutable::parse('2026-10-10 09:00', 'Asia/Jakarta')->utc()])->save();
        $migrateOn($sameDay, '2026-10-10');

        // (3) PSB on 3 Oct, migration on 10 Oct: counted (join before migration).
        $earlier = $this->customer('2026-10-03');
        $this->draft($earlier);
        $migrateOn($earlier, '2026-10-10');

        // A migration-made draft (meta.migration_id) is not evidence of a PSB.
        $migDraftOnly = $this->customer('2026-10-11');
        $this->draft($migDraftOnly, ['meta' => ['migration_id' => 99]]);
        $migrateOn($migDraftOnly, '2026-10-11');

        // A draft created AFTER the migration day is not evidence either.
        $lateDraft = $this->customer('2026-10-12');
        $late = $this->draft($lateDraft);
        $late->forceFill(['created_at' => CarbonImmutable::parse('2026-10-13 09:00', 'Asia/Jakarta')->utc()])->save();
        $migrateOn($lateDraft, '2026-10-12');

        $data = $this->recap('#rekap psb klampok 1-14 okt');
        $ids = array_column($data['rows'], 'customer_id');

        $this->assertNotContains($old->id, $ids);
        $this->assertContains($sameDay->id, $ids);
        $this->assertContains($earlier->id, $ids);
        $this->assertNotContains($migDraftOnly->id, $ids);
        $this->assertNotContains($lateDraft->id, $ids);
        $this->assertSame(2, $data['total']);
        $this->assertSame(3, $data['excluded_migration']);
    }

    public function test_cancelled_draft_and_its_replacement_are_not_double_counted(): void
    {
        $customer = $this->customer('2026-10-07');
        $this->draft($customer, ['status' => 'cancelled']);
        $replacement = $this->draft($customer, ['status' => 'applied']);

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $this->assertSame(1, $data['total']);
        $this->assertSame(['WA' => 1, 'Non-WA' => 0], $data['by_source']);
        $this->assertSame($replacement->id, $data['rows'][0]['draft_id']);
        $this->assertSame('applied', $data['rows'][0]['draft_status']);
    }

    public function test_a_customer_with_only_a_cancelled_draft_is_still_counted_as_non_wa(): void
    {
        $customer = $this->customer('2026-10-07');
        $this->draft($customer, ['status' => 'cancelled']);

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $this->assertSame(1, $data['total']);
        $this->assertSame('Non-WA', $data['rows'][0]['source']);
    }

    public function test_draft_area_does_not_decide_the_area_the_customer_does(): void
    {
        // Draft says another area (like draft area 458 vs customer area 427 in prod).
        $customer = $this->customer('2026-10-08');
        $this->draft($customer, ['area_id' => $this->other->id, 'daerah' => 'BIRU']);

        $klampok = $this->recap('#rekap psb klampok 1-14 okt');
        $biru = $this->recap('#rekap psb biru 1-14 okt');

        $this->assertSame(1, $klampok['total']);
        $this->assertSame(0, $biru['total']);
        $this->assertSame('WA', $klampok['rows'][0]['source']);
        $this->assertSame('KLAMPOK', $klampok['rows'][0]['area']);
        $this->assertStringContainsString('BIRU', $klampok['rows'][0]['note']);
    }

    public function test_customer_without_a_draft_is_labelled_non_wa_and_rt_rw_fall_back_to_the_address(): void
    {
        $this->customer('2026-10-09', ['address' => 'Dusun Klampok RT 07 RW 02']);

        $data = $this->recap('#rekap psb klampok 1-14 okt');

        $row = $data['rows'][0];
        $this->assertSame('Non-WA', $row['source']);
        $this->assertNull($row['draft_id']);
        $this->assertSame('07', $row['rt']);
        $this->assertSame('02', $row['rw']);
        $this->assertSame(['RW 02' => 1], $data['by_rw']);
    }

    public function test_draft_rt_rw_odp_win_over_the_address(): void
    {
        $customer = $this->customer('2026-10-09', ['address' => 'RT 07 RW 02']);
        $this->draft($customer, ['rt' => '3', 'rw' => '9', 'odp' => 'ODP-X']);

        $row = $this->recap('#rekap psb klampok 1-14 okt')['rows'][0];

        $this->assertSame('03', $row['rt']);
        $this->assertSame('09', $row['rw']);
        $this->assertSame('ODP-X', $row['odp']);
    }

    public function test_semua_mode_counts_every_area_and_breaks_down_per_area(): void
    {
        $this->customer('2026-10-02');
        $this->customer('2026-10-03');
        $this->customer('2026-10-04', ['area_id' => $this->other->id]);
        $this->customer('2026-10-05', ['area_id' => null]);

        $data = $this->recap('#rekap psb semua 1-14 okt');

        $this->assertTrue($data['all']);
        $this->assertSame(4, $data['total']);
        $this->assertSame(['KLAMPOK' => 2, 'BIRU' => 1, 'Tanpa area' => 1], $data['by_area']);
    }

    public function test_rows_never_carry_phone_nik_or_password(): void
    {
        $this->customer('2026-10-02', [
            'phone' => '081200000044',
            'nik' => '3573012345678901',
            'pppoe_password' => 'canary-pw',
        ]);

        $data = $this->recap('#rekap psb klampok 1-14 okt');
        $json = json_encode($data['rows']);

        foreach (['081200000044', '3573012345678901', 'canary-pw'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $json);
        }
        foreach (['phone', 'nik', 'pppoe_password'] as $key) {
            $this->assertArrayNotHasKey($key, $data['rows'][0]);
        }
    }

    public function test_max_days_setting_is_read_by_the_parser(): void
    {
        Setting::set('psb_recap_cmd_max_days', '10');

        $request = (new PsbRecapCommandParser)->parse('#rekap psb klampok 1-14 okt');

        $this->assertSame(RecapRequest::ERROR_RANGE_TOO_LONG, $request->error);
        $this->assertSame(10, $request->maxDays);
    }

    public function test_the_recap_writes_nothing_to_business_tables(): void
    {
        $customer = $this->customer('2026-10-02');
        $this->draft($customer);
        $before = [Customer::withTrashed()->count(), PsbDraft::count(), CustomerMigration::count()];
        $updatedAt = Customer::query()->find($customer->id)->updated_at->toIso8601String();

        $this->recap('#rekap psb klampok 1-14 okt');
        $this->recap('#rekap psb semua');

        $this->assertSame($before, [Customer::withTrashed()->count(), PsbDraft::count(), CustomerMigration::count()]);
        $this->assertSame($updatedAt, Customer::query()->find($customer->id)->updated_at->toIso8601String());
    }
}
