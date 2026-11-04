<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationCanonicalReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_index_exposes_canonical_totals_and_sender_without_device_write(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'wa',
            'sender_name' => 'Teknisi A',
            'message_id' => 'canonical-read-model-synced',
            'full_name' => 'Pelanggan Synced',
            'pppoe_user' => 'CANONICAL_SYNCED',
            'new_sn' => 'FHTTCANONICAL001',
            'rw' => '04',
            'rt' => '01',
            'synced_at' => now(),
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'sender_name' => 'Teknisi B',
            'message_id' => 'canonical-read-model-review',
            'full_name' => 'Pelanggan Review',
            'pppoe_user' => 'CANONICAL_REVIEW',
            'new_sn' => 'FHTTCANONICAL002',
            'rw' => '04',
            'rt' => '02',
        ]);

        $before = CustomerMigration::count();
        $response = $this->actingAs($admin)->get(route('customer-migrations.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total', 2)
            ->where('overallStats.synced', 1)
            ->where('overallStats.needs_review', 1)
            ->where('overallStats.technicians.0.name', 'Teknisi A')
            ->where('stats.by_rw.0.rw', '04')
            ->where('stats.by_rw.0.rts.01.total', 1)
            ->where('stats.by_rw.0.rts.02.total', 1)
        );

        $this->assertSame($before, CustomerMigration::count());
    }

    public function test_migration_index_reconciles_online_onu_from_inventory_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $olt = Olt::create(['name' => 'Snapshot OLT', 'code' => 'SNAPSHOT-OLT']);

        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'message_id' => 'canonical-read-model-live',
            'full_name' => 'Pelanggan Live',
            'pppoe_user' => 'CANONICAL_LIVE',
            'new_olt_id' => $olt->id,
            'new_sn' => 'FHTTCANONICAL003',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'serial_number' => 'FHTT CANONICAL 003',
            'state' => 'online',
        ]);

        $this->actingAs($admin)
            ->get(route('customer-migrations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('migrations.0.id', $migration->id)
                ->where('migrations.0.status', CustomerMigration::STATUS_SYNCED)
                ->where('migrations.0.status_source', 'olt_snapshot+pppoe')
            );
    }
}
