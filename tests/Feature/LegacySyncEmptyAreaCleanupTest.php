<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use App\Services\LegacySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacySyncEmptyAreaCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_unreferenced_empty_areas_after_legacy_sync(): void
    {
        $deleteMe = Area::create(['name' => 'Delete Me', 'code' => 'delete-me']);
        $withActiveCustomer = Area::create(['name' => 'With Active Customer', 'code' => 'with-active-customer']);
        $withDeletedCustomer = Area::create(['name' => 'With Deleted Customer', 'code' => 'with-deleted-customer']);
        $withAssignedUser = Area::create(['name' => 'With Assigned User', 'code' => 'with-assigned-user']);
        $withCampaign = Area::create(['name' => 'With Campaign', 'code' => 'with-campaign']);

        $packageId = DB::table('packages')->insertGetId([
            'code' => 'PKG-TEST',
            'name' => 'Test Package',
            'price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            [
                'code' => 'ACTIVE-001',
                'name' => 'Active Customer',
                'phone' => '081234567890',
                'address' => 'Active Address',
                'area_id' => $withActiveCustomer->id,
                'package_id' => $packageId,
                'pppoe_user' => 'active-001',
                'status' => 'active',
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'DELETED-001',
                'name' => 'Deleted Customer',
                'phone' => '081234567891',
                'address' => 'Deleted Address',
                'area_id' => $withDeletedCustomer->id,
                'package_id' => $packageId,
                'pppoe_user' => 'deleted-001',
                'status' => 'terminated',
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $user = User::factory()->create();
        $user->areas()->attach($withAssignedUser);

        DB::table('wa_campaigns')->insert([
            'name' => 'Campaign',
            'message_template' => 'Hello',
            'target_type' => 'area',
            'target_area_id' => $withCampaign->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedAreas = app(LegacySyncService::class)->cleanupEmptyAreas();

        $this->assertSame(['Delete Me'], $deletedAreas);
        $this->assertDatabaseMissing('areas', ['id' => $deleteMe->id]);
        $this->assertDatabaseHas('areas', ['id' => $withActiveCustomer->id]);
        $this->assertDatabaseHas('areas', ['id' => $withDeletedCustomer->id]);
        $this->assertDatabaseHas('areas', ['id' => $withAssignedUser->id]);
        $this->assertDatabaseHas('areas', ['id' => $withCampaign->id]);
    }
}
