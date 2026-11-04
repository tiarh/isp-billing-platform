<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigrationImportRow;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\ExcelCustomerConflictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Support\Canary;
use Tests\TestCase;
use ZipArchive;

/**
 * Roadmap 1.3 L4b: enforce-mode coverage for the remaining wrapped writers
 * (archive-not-on-mikrotik, scope-to-xlsx, Excel conflict resolver). Each guarded
 * write must (a) go through under backstop enforce AND log mode when the guard
 * allows although local evidence says "in use" (only the LifecycleBackstop::allow
 * wrapper keeps it legal, so no violation is recorded), and (b) be refused (row
 * untouched) when the guard denies, in both modes.
 */
#[Group('blocking-core')]
class LifecycleBackstopEnforceWritersTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private int $seq = 0;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function violations(): int
    {
        return DB::table('activity_log')->where('event', 'lifecycle_backstop_violation')->count();
    }

    private function package(): Package
    {
        return Package::firstOrCreate(['code' => 'ENF-PKG'], ['name' => 'Enforce', 'price' => 100000]);
    }

    private function router(): Router
    {
        return Router::firstOrCreate(['name' => 'Enforce Router'], [
            'ip_address' => '198.18.25.1', 'username' => 'a', 'password' => Canary::of('p'), 'port' => 8728, 'is_active' => true,
        ]);
    }

    /** In-use evidence the backstop would act on if the write were unwrapped. */
    private function inUseCustomer(string $code, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code, 'name' => $code, 'phone' => '0800000'.(++$this->seq), 'address' => 'Alamat',
            'package_id' => $this->package()->id, 'status' => 'active', 'pppoe_user' => 'enf-'.$this->seq,
            'is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(2),
        ], $extra));
    }

    /** @return list<string> */
    private static function modes(): array
    {
        return ['enforce', 'log'];
    }

    // ------------------------------------------------------------------
    // customers:archive-not-on-mikrotik (snapshot mode: no router I/O)
    // ------------------------------------------------------------------

    public function test_archive_not_on_mikrotik_guard_allow_writes_in_both_modes_without_violation(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            $this->allowCustomerLifecycleGuard();
            $this->router();
            $customer = $this->inUseCustomer('ARC-'.$mode, ['router_id' => null]);

            $this->artisan('customers:archive-not-on-mikrotik', ['--use-snapshot' => true, '--apply' => true, '--backup-confirmed' => true])
                ->assertExitCode(0);

            $this->assertTrue(Customer::withTrashed()->find($customer->id)->trashed(), $mode);
        }
        $this->assertSame(0, $this->violations());
    }

    public function test_archive_not_on_mikrotik_guard_deny_refuses_in_both_modes(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            $this->denyCustomerLifecycleGuard();
            $this->router();
            $customer = $this->inUseCustomer('ARCD-'.$mode, ['router_id' => null]);

            $this->artisan('customers:archive-not-on-mikrotik', ['--use-snapshot' => true, '--apply' => true, '--backup-confirmed' => true])
                ->assertExitCode(0);

            $this->assertFalse(Customer::withTrashed()->find($customer->id)->trashed(), $mode);
        }
    }

    // ------------------------------------------------------------------
    // billing:scope-customers-xlsx
    // ------------------------------------------------------------------

    private function xlsx(array $activeCodes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scope').'.xlsx';
        $this->files[] = $path;
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>ID Pelanggan</t></is></c><c r="B1" t="inlineStr"><is><t>Status</t></is></c></row>';
        foreach (array_values($activeCodes) as $i => $code) {
            $r = $i + 2;
            $rows .= '<row r="'.$r.'"><c r="A'.$r.'" t="inlineStr"><is><t>'.$code.'</t></is></c><c r="B'.$r.'" t="inlineStr"><is><t>Aktif</t></is></c></row>';
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    public function test_scope_to_xlsx_guard_allow_writes_in_both_modes_without_violation(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            $this->allowCustomerLifecycleGuard();
            $keep = $this->inUseCustomer('SCK-'.$mode);
            $out = $this->inUseCustomer('SCO-'.$mode);

            $this->artisan('billing:scope-customers-xlsx', ['path' => $this->xlsx([$keep->code])])->assertExitCode(0);

            $this->assertTrue(Customer::withTrashed()->find($out->id)->trashed(), $mode);
            $this->assertFalse(Customer::withTrashed()->find($keep->id)->trashed(), $mode);
        }
        $this->assertSame(0, $this->violations());
    }

    public function test_scope_to_xlsx_guard_deny_refuses_in_both_modes(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            $this->denyCustomerLifecycleGuard();
            $keep = $this->inUseCustomer('SDK-'.$mode);
            $out = $this->inUseCustomer('SDO-'.$mode);

            $this->artisan('billing:scope-customers-xlsx', ['path' => $this->xlsx([$keep->code])])->assertExitCode(0);

            $this->assertFalse(Customer::withTrashed()->find($out->id)->trashed(), $mode);
        }
    }

    public function test_scope_to_xlsx_dry_run_writes_nothing_under_enforce(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $this->allowCustomerLifecycleGuard();
        $keep = $this->inUseCustomer('SDRK');
        $out = $this->inUseCustomer('SDRO');

        $this->artisan('billing:scope-customers-xlsx', ['path' => $this->xlsx([$keep->code]), '--dry-run' => true])->assertExitCode(0);

        $this->assertFalse(Customer::withTrashed()->find($out->id)->trashed());
    }

    // ------------------------------------------------------------------
    // ExcelCustomerConflictResolver (own offline check = its guard)
    // ------------------------------------------------------------------

    private function seedResolver(): void
    {
        Area::create(['name' => 'krian', 'code' => 'krian']);
        Router::create(['name' => 'Krian', 'ip_address' => '127.0.0.1', 'username' => 'test', 'password' => Canary::of('test'), 'is_active' => true]);
        Package::create(['code' => 'TEST', 'name' => 'Test Package', 'price' => 100000, 'is_active' => true]);
        foreach ([35, 1487, 125, 192, 311, 1186, 184, 185, 701, 876] as $row) {
            $this->stageRow($row, 'CODE'.$row, 'Customer '.$row, 'USER_'.$row);
        }
        foreach ([31, 122, 131, 145, 182, 316] as $row) {
            $this->stageRow($row, 'WRONG'.$row, 'Wrong '.$row, 'WRONG_USER_'.$row);
        }
    }

    private function stageRow(int $row, string $code, string $name, string $pppoe): void
    {
        CustomerMigrationImportRow::create([
            'batch_key' => 'excel-master-conflicts:2026-07-24', 'source_label' => 'test', 'row_number' => $row,
            'classification' => 'needs_review', 'classification_source' => 'system_match', 'project' => 'krian',
            'area' => 'krian', 'sub_area' => '-', 'rw' => 'RW 1', 'full_name' => $name, 'pppoe_user' => $pppoe,
            'status' => 'staged', 'match_status' => 'identity_conflict',
            'raw_data' => [
                'code' => $code, 'legacy_id' => (string) $row, 'name' => $name, 'pppoe_user' => $pppoe,
                'package_name' => 'Test Package', 'router_name' => 'Krian', 'status' => 'active',
                'join_date' => '2026-07-01', 'due_day' => 20, 'address' => 'Test',
            ],
            'meta' => [],
        ]);
    }

    private function resolverCustomer(string $code, string $pppoe, bool $online): Customer
    {
        return Customer::create([
            'code' => $code, 'name' => 'BUDI TESTA', 'address' => 'Test', 'pppoe_user' => $pppoe,
            'package_id' => Package::firstOrFail()->id, 'area_id' => Area::firstOrFail()->id,
            'router_id' => Router::firstOrFail()->id, 'status' => 'active', 'join_date' => '2026-07-01',
            'due_day' => 20, 'is_online' => $online,
            'pppoe_online_checked_at' => $online ? now()->subMinutes(2) : null,
        ]);
    }

    public function test_excel_conflict_resolver_wrapped_write_goes_through_under_enforce_and_log(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            DB::table('customer_migration_import_rows')->delete();
            DB::table('customers')->delete();
            $this->seedResolverRowsOnly();
            $this->resolverCustomer('KRNPTFM01', 'USER_184', false);
            // Offline PPPoE (the resolver's own guard) but a fresh online ONU row: only the wrapper keeps the soft-delete legal.
            $wrong = $this->resolverCustomer('KRNPT111', 'USER_111', false);
            $wrong->forceFill(['onu_serial' => 'ZTEGENF'.strtoupper($mode[0]).'0001'])->save();
            $olt = Olt::firstOrCreate(['code' => 'ENF-OLT'], ['name' => 'Enf OLT', 'vendor' => 'zte_c300']);
            OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:'.$this->seq++, 'serial_number' => $wrong->onu_serial,
                'state' => 'online', 'phase_state' => 'working', 'state_collected_at' => now()->subMinutes(2)]);

            app(ExcelCustomerConflictResolver::class)->apply(User::factory()->create()->id, 'Test enforce wrapper');

            $this->assertNotNull(Customer::withTrashed()->findOrFail($wrong->id)->deleted_at, $mode);
        }
        $this->assertSame(0, $this->violations());
    }

    public function test_excel_conflict_resolver_refuses_an_online_wrong_customer_in_both_modes(): void
    {
        foreach (self::modes() as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            DB::table('customer_migration_import_rows')->delete();
            DB::table('customers')->delete();
            $this->seedResolverRowsOnly();
            $this->resolverCustomer('KRNPTFM01', 'USER_184', false);
            $wrong = $this->resolverCustomer('KRNPT111', 'USER_111', true);

            try {
                app(ExcelCustomerConflictResolver::class)->apply(User::factory()->create()->id, 'Test enforce refuse');
                $this->fail("{$mode}: online wrong customer must block apply");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('masih online', $e->getMessage());
            }

            $this->assertNull(Customer::withTrashed()->findOrFail($wrong->id)->deleted_at, $mode);
            $this->assertSame('active', $wrong->fresh()->status, $mode);
        }
    }

    /** Reference data once, staged rows per iteration. */
    private function seedResolverRowsOnly(): void
    {
        if (Area::query()->count() === 0) {
            $this->seedResolver();

            return;
        }
        foreach ([35, 1487, 125, 192, 311, 1186, 184, 185, 701, 876] as $row) {
            $this->stageRow($row, 'CODE'.$row, 'Customer '.$row, 'USER_'.$row);
        }
        foreach ([31, 122, 131, 145, 182, 316] as $row) {
            $this->stageRow($row, 'WRONG'.$row, 'Wrong '.$row, 'WRONG_USER_'.$row);
        }
    }
}
