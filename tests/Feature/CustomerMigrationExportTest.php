<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMigrationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_synced_at_normalizes_stale_status_without_writing_the_row(): void
    {
        $migration = new CustomerMigration([
            'status' => CustomerMigration::STATUS_OLT_OK,
            'synced_at' => now(),
        ]);

        $this->assertTrue($migration->isSynced());
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->displayStatus());
    }

    public function test_waiting_sn_without_synced_at_stays_waiting(): void
    {
        $migration = new CustomerMigration([
            'status' => CustomerMigration::STATUS_WAITING_SN,
        ]);

        $this->assertFalse($migration->isSynced());
        $this->assertSame(CustomerMigration::STATUS_WAITING_SN, $migration->displayStatus());
    }

    public function test_migration_csv_is_read_only_and_uses_normalized_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_OLT_OK,
            'source' => 'uat',
            'full_name' => 'Migrasi Test',
            'new_sn' => 'FHTTCSV001',
            'synced_at' => now(),
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_WAITING_SN,
            'source' => 'uat',
            'full_name' => 'Menunggu Test',
            'new_sn' => 'FHTTCSV002',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('customer-migrations.export', ['status' => 'all']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Migrasi Test', $csv);
        $this->assertStringContainsString('synced', $csv);
        $this->assertStringContainsString('Menunggu Test', $csv);
        $this->assertSame(2, CustomerMigration::count());
    }
}
