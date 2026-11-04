<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Canary;
use Tests\TestCase;

class CustomerMikrotikSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_mikrotik_sync_fields_exist_and_are_mass_assignable(): void
    {
        $package = $this->package();

        $customer = Customer::create([
            'code' => 'SYNC-FIELDS',
            'name' => 'Sync Fields',
            'phone' => '080000000001',
            'address' => 'Sync Address',
            'pppoe_user' => 'sync.fields',
            'package_id' => $package->id,
            'status' => 'active',
            'mikrotik_sync_status' => 'synced',
            'mikrotik_synced_at' => now(),
            'mikrotik_sync_checked_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumns('customers', [
            'mikrotik_sync_status',
            'mikrotik_synced_at',
            'mikrotik_sync_checked_at',
        ]));
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_customers_index_can_filter_synced_mikrotik_customers(): void
    {
        $this->assertCustomerSyncFilterReturns('synced');
    }

    public function test_customers_index_can_filter_missing_mikrotik_customers(): void
    {
        $this->assertCustomerSyncFilterReturns('missing');
    }

    public function test_customers_index_can_filter_unknown_mikrotik_customers(): void
    {
        $this->assertCustomerSyncFilterReturns('unknown');
    }

    private function assertCustomerSyncFilterReturns(string $status): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $customers = $this->seedCustomersForSyncFilters();

        $response = $this->actingAs($user)->get(route('customers.index', [
            'mikrotik_sync' => $status,
        ]));

        $response->assertOk();
        $rows = $response->viewData('page')['props']['customers']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($customers[$status]->id, $rows[0]['id']);
        $this->assertSame($status, $rows[0]['mikrotik_sync_status']);
        $this->assertArrayHasKey('router', $rows[0]);
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomersForSyncFilters(): array
    {
        $package = $this->package();
        $router = Router::create([
            'name' => 'Filter Router',
            'ip_address' => '198.18.23.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);

        return [
            'synced' => $this->customer($package, $router, 'SYNC-001', 'sync.one', 'synced'),
            'missing' => $this->customer($package, $router, 'SYNC-002', 'sync.two', 'missing'),
            'unknown' => $this->customer($package, null, 'SYNC-003', 'sync.three', 'unknown'),
        ];
    }

    private function customer(Package $package, ?Router $router, string $code, string $pppoe, string $syncStatus): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => $code,
            'phone' => '08000000'.substr($code, -1),
            'address' => $code.' Address',
            'pppoe_user' => $pppoe,
            'package_id' => $package->id,
            'router_id' => $router?->id,
            'status' => 'active',
            'mikrotik_sync_status' => $syncStatus,
            'mikrotik_synced_at' => $syncStatus === 'synced' ? now() : null,
            'mikrotik_sync_checked_at' => $syncStatus !== 'unknown' ? now() : null,
        ]);
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'Sync Package',
            'code' => 'SYNC-PKG-'.uniqid(),
            'price' => 100000,
        ]);
    }
}
