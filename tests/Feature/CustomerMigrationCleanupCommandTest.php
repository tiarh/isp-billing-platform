<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerMigrationCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_archives_stale_pending_and_failed_without_touching_synced(): void
    {
        $package = Package::create([
            'code' => 'STARTLITE',
            'name' => 'STARTLITE',
            'profile_name' => '200M+ STARTLITE',
            'speed' => '200M',
            'price' => 200000,
            'billing_cycle' => 'monthly',
        ]);
        $customer = Customer::create([
            'code' => 'CLEANUP-001',
            'name' => 'ARIFIN',
            'phone' => '081234567890',
            'address' => 'RW 7',
            'package_id' => $package->id,
            'pppoe_user' => 'USERY_RT5_RW7_09',
            'status' => 'active',
        ]);
        $synced = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'customer_id' => $customer->id,
            'full_name' => 'ARIFIN',
            'pppoe_user' => 'USERY_RT5_RW7_09',
            'new_sn' => 'FHTTGOOD',
        ]);
        $staleFailed = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_FAILED,
            'customer_id' => $customer->id,
            'full_name' => 'ARIFIN',
            'pppoe_user' => 'USERY_RT5_RW7_09',
            'new_sn' => 'FHTTGOOD',
            'last_error' => 'gagal lama',
        ]);
        DB::table('customer_migrations')->where('id', $staleFailed->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $freshReview = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'full_name' => 'BARU REVIEW',
        ]);

        $this->artisan('migrations:cleanup-stale --apply --days=1')
            ->assertExitCode(0);

        $this->assertSame(CustomerMigration::STATUS_SYNCED, $synced->fresh()->status);
        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $staleFailed->fresh()->status);
        $this->assertSame('archived_as_cancelled', $staleFailed->fresh()->meta['cleanup_archive']['status']);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $freshReview->fresh()->status);
    }

    public function test_cleanup_staging_removes_only_resolved_or_applied_rows(): void
    {
        $applied = CustomerMigrationImportRow::create([
            'batch_key' => 'b1',
            'row_number' => 1,
            'status' => 'applied',
            'match_status' => 'migrated',
            'applied_at' => now(),
        ]);
        $review = CustomerMigrationImportRow::create([
            'batch_key' => 'b1',
            'row_number' => 2,
            'status' => 'staged',
            'match_status' => 'needs_review',
        ]);

        $this->artisan('migrations:cleanup-stale --apply --cleanup-staging')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('customer_migration_import_rows', ['id' => $applied->id]);
        $this->assertDatabaseHas('customer_migration_import_rows', ['id' => $review->id]);
    }

    public function test_migration_index_defaults_to_active_and_hides_cancelled_archive(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'full_name' => 'TAMPIL SYNCED',
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_CANCELLED,
            'full_name' => 'ARSIP LAMA',
        ]);

        $this->actingAs($user)
            ->get(route('customer-migrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CustomerMigrations/Index')
                ->where('filters.status', 'active')
                ->has('migrations', 1)
                ->where('migrations.0.full_name', 'TAMPIL SYNCED')
            );
    }

    public function test_activation_reconcile_archives_wa_review_when_exact_synced_sibling_exists(): void
    {
        $synced = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'manual_fiberhome',
            'customer_id' => 9001,
            'new_sn' => 'FHTT-SYNC-001',
        ]);
        $review = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'customer_id' => 9001,
            'new_sn' => 'fhtt-sync-001',
            'router_id' => 1,
            'new_olt_id' => 1,
        ]);

        $this->artisan('ops:reconcile-activation-status --days=1 --resolve-migration-siblings')
            ->assertExitCode(0);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $review->fresh()->status);

        $this->artisan('ops:reconcile-activation-status --apply --days=1 --resolve-migration-siblings')
            ->assertExitCode(0);
        $fresh = $review->fresh();
        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $fresh->status);
        $this->assertSame($synced->id, $fresh->meta['sync_reconcile']['sibling_id']);
    }
}
