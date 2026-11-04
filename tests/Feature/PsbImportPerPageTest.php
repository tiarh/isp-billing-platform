<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Rows-per-page on the PSB import staging list (admin web UI only; PSB / migration WhatsApp flows untouched).
 */
#[Group('blocking-psb')]
class PsbImportPerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No migration owns this table in the repo; build the shape the controller reads.
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

        $rows = [];
        for ($i = 1; $i <= 70; $i++) {
            $rows[] = [
                'customer_code' => 'PSB-PP-'.$i,
                'name' => 'Calon '.$i,
                'status' => 'staged',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('psb_import_candidates')->insert($rows);
    }

    public function test_limit_changes_page_size_and_survives_page_two(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);

        $this->actingAs($admin)->get(route('psb-imports.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.per_page', 30)->where('filters.limit', 30)->has('candidates.data', 30));

        $this->actingAs($admin)->get(route('psb-imports.index', ['limit' => 50]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.per_page', 50)->where('filters.limit', 50)->has('candidates.data', 50));

        $this->actingAs($admin)->get(route('psb-imports.index', ['limit' => 12]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidates.per_page', 30));

        $this->actingAs($admin)->get(route('psb-imports.index', ['limit' => 50, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.per_page', 50)->where('candidates.current_page', 2)->has('candidates.data', 20)
                ->where('candidates.prev_page_url', fn ($url) => str_contains($url, 'limit=50')));
    }
}
