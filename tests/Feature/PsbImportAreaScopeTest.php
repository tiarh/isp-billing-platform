<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class PsbImportAreaScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('psb_import_candidates')) {
            Schema::create('psb_import_candidates', function ($table) {
                $table->id();
                $table->string('customer_code');
                $table->string('name');
                $table->string('pppoe_user')->nullable();
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('area_name')->nullable();
                $table->string('package_name')->nullable();
                $table->string('speed')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->string('status')->default('staged');
                $table->string('match_status')->nullable();
                $table->text('match_notes')->nullable();
                $table->unsignedBigInteger('matched_customer_id')->nullable();
                $table->unsignedBigInteger('resolved_area_id')->nullable();
                $table->unsignedBigInteger('resolved_package_id')->nullable();
                $table->unsignedBigInteger('resolved_router_id')->nullable();
                $table->unsignedBigInteger('applied_customer_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_scoped_admin_cannot_apply_psb_candidate_outside_assigned_area(): void
    {
        $areaA = Area::create(['code' => 'A', 'name' => 'Area A']);
        $areaB = Area::create(['code' => 'B', 'name' => 'Area B']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        $candidateId = DB::table('psb_import_candidates')->insertGetId([
            'source_file' => 'test.xlsx',
            'customer_code' => 'PSB-B001',
            'name' => 'Pelanggan B',
            'pppoe_user' => 'psb-b001',
            'address' => 'Alamat B',
            'status' => 'staged',
            'match_status' => 'staged',
            'resolved_area_id' => $areaB->id,
            'resolved_package_id' => $package->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)
            ->post(route('psb-imports.apply', $candidateId))
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', ['code' => 'PSB-B001']);
    }
}
