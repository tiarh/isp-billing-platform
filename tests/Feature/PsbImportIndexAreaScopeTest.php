<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The admin web list/mapping endpoints for PSB import staging
 * (psb_import_candidates) must be area-scoped for per-area admins. This only
 * covers the admin web UI; the PSB / migration WhatsApp flows are untouched.
 */
#[Group('blocking-psb')]
class PsbImportIndexAreaScopeTest extends TestCase
{
    use RefreshDatabase;

    private Area $own;

    private Area $foreign;

    private int $ownRow;

    private int $foreignRow;

    private int $unresolvedRow;

    protected function setUp(): void
    {
        parent::setUp();

        // No migration owns this table in the repo; build the full shape the controller reads.
        Schema::dropIfExists('psb_import_candidates');
        Schema::create('psb_import_candidates', function ($table) {
            $table->id();
            $table->string('source_file')->nullable();
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
            $table->string('router_profile')->nullable();
            $table->unsignedBigInteger('matched_customer_id')->nullable();
            $table->unsignedBigInteger('matched_router_id')->nullable();
            $table->unsignedBigInteger('matched_router_staged_customer_id')->nullable();
            $table->unsignedBigInteger('resolved_area_id')->nullable();
            $table->unsignedBigInteger('resolved_package_id')->nullable();
            $table->unsignedBigInteger('resolved_router_id')->nullable();
            $table->unsignedBigInteger('applied_customer_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();
        });

        $this->own = Area::create(['code' => 'OWN', 'name' => 'Own Area']);
        $this->foreign = Area::create(['code' => 'FOR', 'name' => 'Foreign Area']);
        $this->ownRow = $this->candidate('PSB-OWN', $this->own->id);
        $this->foreignRow = $this->candidate('PSB-FOREIGN', $this->foreign->id);
        $this->unresolvedRow = $this->candidate('PSB-NOAREA', null);
    }

    public function test_scoped_admin_index_lists_and_counts_only_own_area_rows(): void
    {
        $this->actingAs($this->scopedAdmin())
            ->get(route('psb-imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PsbImports/Index')
                ->where('candidates.total', 1)
                ->where('candidates.data.0.id', $this->ownRow)
                ->where('summary.total', 1));
    }

    public function test_scoped_admin_search_cannot_reach_foreign_rows(): void
    {
        $this->actingAs($this->scopedAdmin())
            ->get(route('psb-imports.index', ['search' => 'PSB-FOREIGN']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidates.total', 0)->where('summary.total', 1));
    }

    public function test_scoped_admin_is_forbidden_to_apply_or_remap_foreign_and_unresolved_rows(): void
    {
        $scoped = $this->scopedAdmin();
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        foreach ([$this->foreignRow, $this->unresolvedRow] as $id) {
            $this->actingAs($scoped)->post(route('psb-imports.apply', $id))->assertForbidden();

            // Remapping a foreign row into the admin's own area must not be a way to steal it.
            $this->actingAs($scoped)->put(route('psb-imports.update-mapping', $id), [
                'resolved_area_id' => $this->own->id,
                'resolved_package_id' => $package->id,
            ])->assertForbidden();

            $this->assertDatabaseMissing('psb_import_candidates', ['id' => $id, 'resolved_area_id' => $this->own->id]);
        }
    }

    public function test_scoped_admin_can_still_remap_own_area_row(): void
    {
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        $this->actingAs($this->scopedAdmin())->put(route('psb-imports.update-mapping', $this->ownRow), [
            'resolved_area_id' => $this->own->id,
            'resolved_package_id' => $package->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('psb_import_candidates', ['id' => $this->ownRow, 'resolved_package_id' => $package->id]);
    }

    public function test_noc_superadmin_and_unscoped_admin_see_all_rows(): void
    {
        $users = [
            'noc' => User::factory()->create(['role' => 'noc']),
            'superadmin' => User::factory()->create(['role' => 'superadmin']),
            'unscoped admin' => User::factory()->create(['role' => 'admin']),
        ];

        foreach ($users as $label => $user) {
            $this->actingAs($user)
                ->get(route('psb-imports.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('candidates.total', 3)
                    ->where('summary.total', 3));
        }
    }

    private function scopedAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->areas()->attach($this->own);

        return $user;
    }

    private function candidate(string $code, ?int $areaId): int
    {
        return DB::table('psb_import_candidates')->insertGetId([
            'customer_code' => $code,
            'name' => 'Pelanggan '.$code,
            'pppoe_user' => strtolower($code),
            'status' => 'staged',
            'match_status' => 'staged',
            'resolved_area_id' => $areaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
