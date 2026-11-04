<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaAlias;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\PsbDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-core')]
class ReassignPsbLocalityAreasCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ACK = 'GO BACKFILL AREA LOKALITAS';

    private Area $from;

    private Area $klampok;

    private Area $purwodadi;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = Area::create(['name' => 'FROM-AREA', 'code' => 'from-area']);
        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->purwodadi = Area::create(['name' => 'SKYNET-PURWODADI', 'code' => 'purwodadi']);
        AreaAlias::create(['area_id' => $this->klampok->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);
        $this->package = Package::create(['name' => 'Pkg', 'code' => 'PKG-REASSIGN', 'price' => 100000]);
    }

    /** @param array<string, mixed> $meta */
    private function psbCustomer(string $code, string $daerah, array $meta = [], string $status = 'active', string $draftStatus = PsbDraft::STATUS_RECEIVED): Customer
    {
        $customer = Customer::create([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000001',
            'address' => 'Alamat rahasia',
            'pppoe_user' => strtolower($code),
            'pppoe_password' => Canary::of('pppoe'),
            'package_id' => $this->package->id,
            'area_id' => $this->from->id,
            'status' => $status,
        ]);
        PsbDraft::create([
            'status' => $draftStatus,
            'group_jid' => 'ops@g.us',
            'full_name' => $code,
            'daerah' => $daerah,
            'area_id' => $this->from->id,
            'customer_id' => $customer->id,
            'sn' => 'SN'.random_int(100000, 999999),
            'meta' => $meta,
        ]);

        return $customer;
    }

    private function areaOf(Customer $c): ?int
    {
        return Customer::withTrashed()->whereKey($c->id)->value('area_id');
    }

    private function apply(array $extra = []): PendingCommand
    {
        return $this->artisan('areas:reassign-psb-locality', array_merge([
            '--from-area' => $this->from->id,
            '--apply' => true,
            '--confirm' => self::ACK,
        ], $extra));
    }

    public function test_dry_run_writes_nothing_and_csv_has_no_pii_columns(): void
    {
        $c = $this->psbCustomer('C-DRY', 'Kelampok');
        $csv = storage_path('app/reassign_'.uniqid().'.csv');

        $this->artisan('areas:reassign-psb-locality', ['--from-area' => $this->from->id, '--csv' => $csv])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame($this->from->id, $this->areaOf($c));
        $this->assertDatabaseMissing('activity_log', ['event' => 'area_reassigned']);

        $this->assertFileExists($csv);
        $content = (string) file_get_contents($csv);
        $header = strtolower(strtok($content, "\n"));
        $this->assertSame('customer_id,customer_code,current_area,target_area,matched_key,source,skip_reason', trim($header));
        foreach (['phone', 'address', 'pppoe', 'password', 'nik'] as $needle) {
            $this->assertStringNotContainsString($needle, $header);
        }
        $this->assertStringNotContainsString('080000000001', $content);
        $this->assertStringNotContainsString('rahasia', $content);
        $this->assertStringContainsString('C-DRY', $content);
        @unlink($csv);
    }

    public function test_apply_without_or_with_wrong_confirm_refuses(): void
    {
        $c = $this->psbCustomer('C-NOACK', 'Kelampok');

        $this->artisan('areas:reassign-psb-locality', ['--from-area' => $this->from->id, '--apply' => true])
            ->assertFailed();
        $this->artisan('areas:reassign-psb-locality', ['--from-area' => $this->from->id, '--apply' => true, '--confirm' => 'go backfill area lokalitas'])
            ->assertFailed();

        $this->assertSame($this->from->id, $this->areaOf($c));
    }

    public function test_from_area_is_required(): void
    {
        $this->artisan('areas:reassign-psb-locality')->assertFailed();
    }

    public function test_apply_moves_only_eligible_customers_and_drafts_and_logs_activity(): void
    {
        $kl = $this->psbCustomer('C-KL', 'Kelampok RT 3');
        $pw = $this->psbCustomer('C-PW', 'Purwodadi');
        $dismantled = $this->psbCustomer('C-DIS', 'Kelampok', [], 'terminated');
        $archived = $this->psbCustomer('C-ARC', 'Kelampok');
        $archived->delete();
        $failedDraft = $this->psbCustomer('C-FAIL', 'Kelampok', [], 'active', PsbDraft::STATUS_FAILED);
        $other = Customer::create([
            'code' => 'C-NOPSB', 'name' => 'x', 'phone' => '1', 'address' => 'a', 'pppoe_user' => 'nopsb',
            'package_id' => $this->package->id, 'area_id' => $this->from->id, 'status' => 'active',
        ]);

        $this->apply()->expectsOutputToContain('2 pelanggan dipindah')->assertSuccessful();

        $this->assertSame($this->klampok->id, $this->areaOf($kl));
        $this->assertSame($this->purwodadi->id, $this->areaOf($pw));
        foreach ([$dismantled, $archived, $failedDraft, $other] as $untouched) {
            $this->assertSame($this->from->id, $this->areaOf($untouched), $untouched->code);
        }

        $draft = PsbDraft::where('customer_id', $kl->id)->first();
        $this->assertSame($this->klampok->id, (int) $draft->area_id);
        $this->assertSame('reassign', $draft->meta['area_resolution']['source']);
        $this->assertSame($this->from->id, (int) PsbDraft::where('customer_id', $dismantled->id)->value('area_id'));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Customer::class,
            'subject_id' => $kl->id,
            'event' => 'area_reassigned',
            'causer_id' => null,
        ]);
        $log = Activity::where('subject_id', $kl->id)->where('event', 'area_reassigned')->first();
        $this->assertSame($this->from->id, $log->properties['old_area_id']);
        $this->assertSame($this->klampok->id, $log->properties['new_area_id']);
        $this->assertSame('areas:reassign-psb-locality', $log->properties['command']);
        $this->assertSame(2, Activity::where('event', 'area_reassigned')->count());
    }

    public function test_skip_reasons_akuisisi_ambiguous_manual_unmatched_same_area(): void
    {
        $akuisisi = $this->psbCustomer('C-AKU', 'Kelampok');
        CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'manual_fiberhome',
            'message_id' => 'MSG-AKU',
            'customer_id' => $akuisisi->id,
        ]);
        $manual = $this->psbCustomer('C-MAN', 'Kelampok', ['area_manual' => $this->purwodadi->id]);
        $unmatched = $this->psbCustomer('C-UNM', 'Planet Mars');
        // Two areas share the key "bedali" -> ambiguous.
        Area::create(['name' => 'BEDALI', 'code' => 'bedali-a']);
        Area::create(['name' => 'SKYNET-BEDALI', 'code' => 'bedali-b']);
        $ambiguous = $this->psbCustomer('C-AMB', 'Bedali');
        $same = $this->psbCustomer('C-SAME', 'From Area');

        $csv = storage_path('app/reassign_'.uniqid().'.csv');
        $this->apply(['--csv' => $csv])->assertSuccessful();

        foreach ([$akuisisi, $manual, $unmatched, $ambiguous, $same] as $c) {
            $this->assertSame($this->from->id, $this->areaOf($c), $c->code);
        }

        $rows = [];
        foreach (array_slice(file($csv, FILE_IGNORE_NEW_LINES), 1) as $line) {
            $cols = str_getcsv($line);
            $rows[$cols[1]] = $cols[6];
        }
        @unlink($csv);
        $this->assertSame('akuisisi', $rows['C-AKU']);
        $this->assertSame('manual_area', $rows['C-MAN']);
        $this->assertSame('unmatched', $rows['C-UNM']);
        $this->assertSame('ambiguous', $rows['C-AMB']);
        $this->assertSame('same_area', $rows['C-SAME']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'area_reassigned']);
    }

    public function test_csv_path_guard_refuses_existing_outside_and_dotdot_paths(): void
    {
        $c = $this->psbCustomer('C-GUARD', 'Kelampok');
        $existing = storage_path('app/reassign_exists_'.uniqid().'.csv');
        file_put_contents($existing, 'keep');

        $this->apply(['--csv' => $existing])->expectsOutputToContain('sudah ada')->assertFailed();
        $this->assertSame('keep', file_get_contents($existing));
        @unlink($existing);

        $outside = sys_get_temp_dir().'/reassign_out_'.uniqid().'.csv';
        $this->apply(['--csv' => $outside])->expectsOutputToContain('harus di dalam')->assertFailed();
        $this->assertFileDoesNotExist($outside);

        $public = storage_path('app/public/reassign_pub_'.uniqid().'.csv');
        $this->apply(['--csv' => $public])->expectsOutputToContain('publik')->assertFailed();
        $this->assertFileDoesNotExist($public);

        $this->apply(['--csv' => 'sub/../../escape.csv'])->expectsOutputToContain('..')->assertFailed();

        // Nothing was applied when the CSV guard failed.
        $this->assertSame($this->from->id, $this->areaOf($c));
    }

    public function test_relative_csv_is_written_under_storage_app(): void
    {
        $this->psbCustomer('C-REL', 'Kelampok');
        $name = 'reassign_rel_'.uniqid().'.csv';

        $this->artisan('areas:reassign-psb-locality', ['--from-area' => $this->from->id, '--csv' => $name])->assertSuccessful();

        $this->assertFileExists(storage_path('app/'.$name));
        @unlink(storage_path('app/'.$name));
    }

    public function test_csv_write_failure_returns_failure(): void
    {
        $this->psbCustomer('C-WFAIL', 'Kelampok');
        $blocker = storage_path('app/reassign_blocker_'.uniqid());
        file_put_contents($blocker, 'x');

        // Parent "directory" is a regular file, so the CSV cannot be created.
        $this->apply(['--csv' => $blocker.'/out.csv'])->expectsOutputToContain('Tidak bisa menulis CSV')->assertFailed();
        @unlink($blocker);
    }

    public function test_cancelled_drafts_and_akuisisi_target_are_not_moved(): void
    {
        $cancelled = $this->psbCustomer('C-CANC', 'Kelampok', [], 'active', PsbDraft::STATUS_CANCELLED);
        $akuisisiArea = Area::create(['name' => 'SKYNET-RANDUAGUNG', 'code' => 'skynet-randuagung']);
        AreaAlias::create(['area_id' => $akuisisiArea->id, 'alias' => 'Randuagung', 'alias_key' => 'randuagung']);
        $toAkuisisi = $this->psbCustomer('C-TOAKU', 'Randuagung');

        $csv = storage_path('app/reassign_'.uniqid().'.csv');
        $this->apply(['--csv' => $csv])->expectsOutputToContain('0 pelanggan dipindah')->assertSuccessful();

        $this->assertSame($this->from->id, $this->areaOf($cancelled));
        $this->assertSame($this->from->id, $this->areaOf($toAkuisisi));
        $content = (string) file_get_contents($csv);
        @unlink($csv);
        $this->assertStringNotContainsString('C-CANC', $content);
        $this->assertStringContainsString('target_akuisisi', $content);
    }

    public function test_only_customer_limits_scope_and_second_run_is_idempotent(): void
    {
        $a = $this->psbCustomer('C-ONE', 'Kelampok');
        $b = $this->psbCustomer('C-TWO', 'Kelampok');

        $this->apply(['--only-customer' => $a->id])->expectsOutputToContain('1 pelanggan dipindah')->assertSuccessful();
        $this->assertSame($this->klampok->id, $this->areaOf($a));
        $this->assertSame($this->from->id, $this->areaOf($b));

        $this->apply(['--only-customer' => $a->id])->expectsOutputToContain('0 pelanggan dipindah')->assertSuccessful();
        $this->apply()->expectsOutputToContain('1 pelanggan dipindah')->assertSuccessful();
        $this->apply()->expectsOutputToContain('0 pelanggan dipindah')->assertSuccessful();

        $this->assertSame(2, Activity::where('event', 'area_reassigned')->count());
    }
}
