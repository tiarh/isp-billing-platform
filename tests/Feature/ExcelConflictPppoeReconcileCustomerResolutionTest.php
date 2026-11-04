<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigrationImportRow;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\ExcelCustomerConflictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Canary;
use Tests\TestCase;

class ExcelConflictPppoeReconcileCustomerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_modify_customers(): void
    {
        $this->seedReferenceData();
        $this->stageRow(184, 'KRNPT111', 'BUDI TESTA', 'BUDIA_RT30_RW05');
        $correct = Customer::create($this->customerPayload('KRNPTFM01', 'BUDI TESTA', 'BUDIA_RT30_RW05'));
        $wrong = Customer::create($this->customerPayload('KRNPT111', 'BUDI TESTA', 'USER_111'));

        $preview = app(ExcelCustomerConflictResolver::class)->preview();

        $this->assertSame($correct->id, collect($preview)->firstWhere('correct_row', 184)['target_customer_id']);
        $this->assertNull($wrong->fresh()->deleted_at);
        $this->assertSame('KRNPTFM01', $correct->fresh()->code);
    }

    public function test_apply_keeps_pppoe_identity_and_soft_deletes_wrong_code_holder(): void
    {
        $this->seedReferenceData();
        foreach ([35, 1487, 125, 192, 311, 1186, 184, 185, 701, 876] as $row) {
            $this->stageRow($row, 'CODE'.$row, 'Customer '.$row, 'USER_'.$row);
        }
        foreach ([31, 122, 131, 145, 182, 316] as $row) {
            $this->stageRow($row, 'WRONG'.$row, 'Wrong '.$row, 'WRONG_USER_'.$row);
        }

        $eni = Customer::create($this->customerPayload('KRNPTFM01', 'BUDI TESTA', 'USER_184'));
        $existingPackageId = $eni->package_id;
        $row184 = CustomerMigrationImportRow::where('row_number', 184)->firstOrFail();
        $raw184 = $row184->raw_data;
        $raw184['package_name'] = 'Legacy package label without exact master';
        $row184->update(['raw_data' => $raw184]);
        $wrong = Customer::create($this->customerPayload('KRNPT111', 'BUDI TESTA', 'USER_111'));
        $actor = User::factory()->create();

        $result = app(ExcelCustomerConflictResolver::class)->apply($actor->id, 'Test deterministic conflict cleanup');

        $this->assertCount(10, $result);
        $this->assertSame('CODE184', $eni->fresh()->code);
        $this->assertSame($existingPackageId, $eni->fresh()->package_id);
        $this->assertNotNull(Customer::withTrashed()->findOrFail($wrong->id)->deleted_at);
        $this->assertSame(
            'resolved',
            CustomerMigrationImportRow::where('row_number', 184)->value('status')
        );
        $this->assertSame(
            'discarded',
            CustomerMigrationImportRow::where('row_number', 31)->value('status')
        );
    }

    private function seedReferenceData(): void
    {
        Area::create(['name' => 'krian', 'code' => 'krian']);
        Router::create([
            'name' => 'Krian',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        Package::create([
            'code' => 'TEST',
            'name' => 'Test Package',
            'price' => 100000,
            'is_active' => true,
        ]);
    }

    private function stageRow(int $row, string $code, string $name, string $pppoe): void
    {
        CustomerMigrationImportRow::create([
            'batch_key' => 'excel-master-conflicts:2026-07-24',
            'source_label' => 'test',
            'row_number' => $row,
            'classification' => 'needs_review',
            'classification_source' => 'system_match',
            'project' => 'krian',
            'area' => 'krian',
            'sub_area' => '-',
            'rw' => 'RW 1',
            'full_name' => $name,
            'pppoe_user' => $pppoe,
            'status' => 'staged',
            'match_status' => 'identity_conflict',
            'raw_data' => [
                'code' => $code,
                'legacy_id' => (string) $row,
                'name' => $name,
                'pppoe_user' => $pppoe,
                'package_name' => 'Test Package',
                'router_name' => 'Krian',
                'status' => 'active',
                'join_date' => '2026-07-01',
                'due_day' => 20,
                'address' => 'Test',
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(string $code, string $name, string $pppoe): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'address' => 'Test',
            'pppoe_user' => $pppoe,
            'package_id' => Package::firstOrFail()->id,
            'area_id' => Area::firstOrFail()->id,
            'router_id' => Router::firstOrFail()->id,
            'status' => 'active',
            'join_date' => '2026-07-01',
            'due_day' => 20,
            'is_online' => false,
        ];
    }
}
