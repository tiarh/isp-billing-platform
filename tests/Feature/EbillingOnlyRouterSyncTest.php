<?php

namespace Tests\Feature;

use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Services\MikrotikService;
use App\Services\RouterSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Canary;
use Tests\TestCase;

class EbillingOnlyRouterSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_does_not_create_customer_for_unmatched_mikrotik_secret(): void
    {
        $router = $this->router();
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'router.only', 'profile' => '10MB'],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, $result['mapped']);
        $this->assertSame(1, $result['unmatched_mikrotik']);
        $this->assertSame(1, $result['orphaned']);
        $this->assertSame(1, $result['staged_router_only']);
        $this->assertDatabaseHas('router_staged_customers', [
            'router_id' => $router->id,
            'pppoe_user' => 'router.only',
            'profile' => '10MB',
            'status' => 'unmatched',
        ]);
    }

    public function test_sync_links_existing_ebilling_customer_by_exact_pppoe_and_preserves_billing_fields(): void
    {
        $router = $this->router(['isolation_profile' => 'isolirebilling']);
        $package = Package::create([
            'name' => 'eBilling Package',
            'code' => 'PKG-EBILLING',
            'price' => 150000,
        ]);
        $customer = Customer::create([
            'code' => 'CUST-001',
            'name' => 'Abdul Roshid',
            'phone' => '081234567890',
            'address' => 'Original Address',
            'pppoe_user' => 'abdulroshid',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            [
                'name' => 'abdulroshid',
                'profile' => '20MB',
                'password' => Canary::of('router'),
                'comment' => 'router address',
            ],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);
        $customer->refresh();

        $this->assertSame(1, $result['mapped']);
        $this->assertSame($router->id, $customer->router_id);
        $this->assertSame('20MB', $customer->mikrotik_profile);
        $this->assertSame('CUST-001', $customer->code);
        $this->assertSame('Abdul Roshid', $customer->name);
        $this->assertSame('081234567890', $customer->phone);
        $this->assertSame('Original Address', $customer->address);
        $this->assertSame($package->id, $customer->package_id);
        $this->assertSame('active', $customer->status);
        $this->assertNotSame('canary-router', $customer->pppoe_password);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: int}>
     */
    public static function isolationProfileStatusProvider(): array
    {
        return [
            'terminated stays terminated' => ['terminated', 'isolirebilling', 'terminated', 0, 1],
            'pending stays pending' => ['pending_installation', 'isolirebilling', 'pending_installation', 0, 1],
            'suspended stays suspended' => ['suspended', 'isolirebilling', 'suspended', 0, 1],
            'inactive stays inactive' => ['inactive', 'isolirebilling', 'inactive', 0, 1],
            'active on isolation profile becomes isolated' => ['active', 'isolirebilling', 'isolated', 1, 0],
            'isolated on isolation profile stays isolated' => ['isolated', 'isolirebilling', 'isolated', 0, 0],
            'isolated on normal profile becomes active' => ['isolated', '20MB', 'active', 1, 0],
            'terminated on normal profile stays terminated' => ['terminated', '20MB', 'terminated', 0, 0],
            'active on normal profile stays active' => ['active', '20MB', 'active', 0, 0],
        ];
    }

    #[DataProvider('isolationProfileStatusProvider')]
    public function test_isolation_profile_only_drives_active_isolated_transitions(
        string $startStatus,
        string $secretProfile,
        string $expectedStatus,
        int $expectedSynced,
        int $expectedSkipped
    ): void {
        Queue::fake();
        $router = $this->router(['isolation_profile' => 'isolirebilling']);
        $package = $this->package('PKG-ISO-'.uniqid());
        $customer = Customer::create([
            'code' => 'CUST-ISO-'.uniqid(),
            'name' => 'Status Guard',
            'phone' => '081200000001',
            'address' => 'Addr',
            'pppoe_user' => 'status.guard',
            'package_id' => $package->id,
            'status' => $startStatus,
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'status.guard', 'profile' => $secretProfile],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame($expectedStatus, $customer->fresh()->status);
        $this->assertSame($expectedSynced, $result['synced_status']);
        $this->assertSame($expectedSkipped, $result['status_skipped_non_live']);
        $this->assertSame($secretProfile, $customer->fresh()->mikrotik_profile);
        Queue::assertNotPushed(EnsureCustomerCurrentInvoiceJob::class);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function midScanStatusProvider(): array
    {
        return [
            'reconnected to active mid-scan not re-isolated' => ['isolated', 'active', '20MB'],
            'terminated mid-scan not resurrected to active' => ['active', 'terminated', '20MB'],
            'isolated by billing mid-scan not flipped back' => ['active', 'isolated', '20MB'],
            'terminated mid-scan not isolated by profile' => ['active', 'terminated', 'isolirebilling'],
        ];
    }

    #[DataProvider('midScanStatusProvider')]
    public function test_status_changed_mid_scan_is_not_overwritten(string $scanStatus, string $changedTo, string $profile): void
    {
        $router = $this->router(['isolation_profile' => 'isolirebilling']);
        $customer = Customer::create([
            'code' => 'CUST-MID-'.uniqid(),
            'name' => 'Mid Scan',
            'phone' => '081200000003',
            'address' => 'Addr',
            'pppoe_user' => 'mid.scan',
            'package_id' => $this->package('PKG-MID-'.uniqid())->id,
            'status' => $scanStatus,
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'mid.scan', 'profile' => $profile],
        ]);

        // Simulates another process changing status after the scan read it
        // but before the sync rows are written.
        $service = new class($mikrotik, $customer->id, $changedTo) extends RouterSyncService
        {
            public function __construct($mikrotik, private int $customerId, private string $changedTo)
            {
                parent::__construct($mikrotik);
            }

            protected function markStagedSecretMatched(Router $router, string $username, Customer $customer): bool
            {
                Customer::whereKey($this->customerId)->update(['status' => $this->changedTo]);

                return parent::markStagedSecretMatched($router, $username, $customer);
            }
        };

        $result = $service->syncCustomers($router);

        $fresh = $customer->fresh();
        $this->assertSame($changedTo, $fresh->status);
        $this->assertSame(1, $result['status_skipped_changed_mid_scan']);
        $this->assertSame(0, $result['synced_status']);
        $this->assertSame($profile, $fresh->mikrotik_profile);
        $this->assertSame('synced', $fresh->mikrotik_sync_status);
    }

    public function test_unchanged_status_does_not_count_as_changed_mid_scan(): void
    {
        $router = $this->router(['isolation_profile' => 'isolirebilling']);
        Customer::create([
            'code' => 'CUST-MID-OK',
            'name' => 'Mid Scan OK',
            'phone' => '081200000004',
            'address' => 'Addr',
            'pppoe_user' => 'mid.ok',
            'package_id' => $this->package('PKG-MID-OK')->id,
            'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'mid.ok', 'profile' => 'isolirebilling'],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame(0, $result['status_skipped_changed_mid_scan']);
        $this->assertSame(1, $result['synced_status']);
        $this->assertSame('isolated', Customer::where('pppoe_user', 'mid.ok')->value('status'));
    }

    public function test_terminated_customer_on_isolation_profile_gets_no_monthly_invoice(): void
    {
        $router = $this->router(['isolation_profile' => 'isolirebilling']);
        $package = $this->package('PKG-TERM-INV');
        $customer = Customer::create([
            'code' => 'CUST-TERM-INV',
            'name' => 'Terminated Customer',
            'phone' => '081200000002',
            'address' => 'Addr',
            'pppoe_user' => 'term.inv',
            'package_id' => $package->id,
            'status' => 'terminated',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'term.inv', 'profile' => 'isolirebilling'],
        ]);

        (new RouterSyncService($mikrotik))->syncCustomers($router);
        $this->artisan('billing:generate', ['--catch-up' => true])->assertExitCode(0);

        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertSame(0, Invoice::where('customer_id', $customer->id)->count());
    }

    public function test_sync_ignores_imported_customer_rows(): void
    {
        $router = $this->router();
        $package = $this->package('PKG-IMPORTED');
        Customer::create([
            'code' => 'IMP-ABC123',
            'name' => 'Imported Row',
            'phone' => '',
            'address' => 'Imported Address',
            'pppoe_user' => 'same-user',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'same-user', 'profile' => '10MB'],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame(0, $result['mapped']);
        $this->assertSame(1, $result['unmatched_mikrotik']);
        $importedCustomer = Customer::first();
        $this->assertNull($importedCustomer->router_id);
        $this->assertSame('unknown', $importedCustomer->mikrotik_sync_status);
        $this->assertDatabaseHas('router_staged_customers', [
            'router_id' => $router->id,
            'pppoe_user' => 'same-user',
            'status' => 'unmatched',
        ]);
    }

    public function test_existing_staged_router_secret_is_marked_matched_after_customer_is_created(): void
    {
        $router = $this->router();
        $package = $this->package('PKG-STAGED-MATCH');
        RouterStagedCustomer::create([
            'router_id' => $router->id,
            'pppoe_user' => 'later.customer',
            'profile' => '10MB',
            'status' => 'unmatched',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);
        $customer = Customer::create([
            'code' => 'CUST-STAGED',
            'name' => 'Later Customer',
            'phone' => '080000000099',
            'address' => 'Matched Address',
            'pppoe_user' => 'later.customer',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'later.customer', 'profile' => '20MB'],
        ]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame(1, $result['mapped']);
        $this->assertSame(1, $result['staged_matched']);
        $this->assertDatabaseHas('router_staged_customers', [
            'router_id' => $router->id,
            'pppoe_user' => 'later.customer',
            'matched_customer_id' => $customer->id,
            'status' => 'matched',
        ]);
    }

    public function test_staged_router_secret_missing_from_later_scan_is_marked_gone(): void
    {
        $router = $this->router();
        RouterStagedCustomer::create([
            'router_id' => $router->id,
            'pppoe_user' => 'gone.user',
            'profile' => '10MB',
            'status' => 'unmatched',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);

        $this->assertSame(1, $result['staged_gone']);
        $this->assertDatabaseHas('router_staged_customers', [
            'router_id' => $router->id,
            'pppoe_user' => 'gone.user',
            'status' => 'gone',
        ]);
    }

    public function test_assigned_ebilling_customer_missing_from_router_is_marked_missing_without_changing_router_fields(): void
    {
        $router = $this->router();
        $package = $this->package('PKG-MISSING');
        $customer = Customer::create([
            'code' => 'CUST-002',
            'name' => 'Missing User',
            'phone' => '080000000002',
            'address' => 'Missing Address',
            'pppoe_user' => 'missing.user',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'mikrotik_profile' => 'OLD',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([]);

        $result = (new RouterSyncService($mikrotik))->syncCustomers($router);
        $customer->refresh();

        $this->assertSame(1, $result['not_found_ebilling']);
        $this->assertSame('OLD', $customer->mikrotik_profile);
        $this->assertSame($router->id, $customer->router_id);
        $this->assertSame('missing', $customer->mikrotik_sync_status);
        $this->assertNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_customer_without_router_or_pppoe_remains_unknown_after_router_scan(): void
    {
        $router = $this->router();
        $package = $this->package('PKG-UNKNOWN');
        $customer = Customer::create([
            'code' => 'CUST-003',
            'name' => 'Unknown User',
            'phone' => '080000000003',
            'address' => 'Unknown Address',
            'pppoe_user' => '',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([]);

        (new RouterSyncService($mikrotik))->syncCustomers($router);
        $customer->refresh();

        $this->assertSame('unknown', $customer->mikrotik_sync_status);
        $this->assertNull($customer->mikrotik_synced_at);
        $this->assertNull($customer->mikrotik_sync_checked_at);
    }

    public function test_billing_generation_excludes_imported_rows(): void
    {
        $package = Package::create([
            'name' => 'Billable Package',
            'code' => 'PKG-BILLABLE',
            'price' => 100000,
        ]);

        $realCustomer = Customer::create([
            'code' => 'CUST-BILLABLE',
            'name' => 'Real Customer',
            'phone' => '080000000003',
            'address' => 'Real Address',
            'pppoe_user' => 'real.customer',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $importedCustomer = Customer::create([
            'code' => 'IMP-BILL',
            'name' => 'Imported Customer',
            'phone' => '',
            'address' => 'Imported Address',
            'pppoe_user' => 'imported.customer',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $this->artisan('billing:generate', [
            '--month' => now()->format('Y-m'),
        ])->assertExitCode(0);

        $this->assertDatabaseHas('invoices', ['customer_id' => $realCustomer->id]);
        $this->assertDatabaseMissing('invoices', ['customer_id' => $importedCustomer->id]);
    }

    private function router(array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name' => 'Test Router '.uniqid(),
            'ip_address' => '198.18.9.'.rand(1, 254),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ], $overrides));
    }

    private function package(string $code): Package
    {
        return Package::create([
            'name' => $code,
            'code' => $code,
            'price' => 100000,
        ]);
    }
}
