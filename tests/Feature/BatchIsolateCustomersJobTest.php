<?php

namespace Tests\Feature;

use App\Jobs\BatchIsolateCustomersJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * A large isolation wave must hit a router over ONE connection, not one API
 * login per customer. BatchIsolateCustomersJob isolates every eligible customer
 * of a router over a single connect()/disconnect() pair.
 */
class BatchIsolateCustomersJobTest extends TestCase
{
    use RefreshDatabase;

    private function router(): Router
    {
        return Router::create([
            'name' => 'Batch Router '.uniqid(),
            'ip_address' => '10.20.20.'.rand(1, 254),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
            'radius_enabled' => false,
        ]);
    }

    private function customer(Router $router, string $user): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-BATCH',
            'name' => 'Batch',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        $customer = Customer::create([
            'code' => 'BATCH-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Batch Customer',
            'phone' => '081234567890',
            'address' => 'Batch Address',
            'pppoe_user' => $user,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            // The join month is never billable (BillingEligibilityService) and
            // join_date defaults to today, so join before the invoiced period.
            'join_date' => now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(),
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->subMonths(1)->startOfMonth()->toDateString(),
            'amount' => 100000,
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        return $customer;
    }

    public function test_it_isolates_all_customers_over_a_single_connection(): void
    {
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');
        Setting::set('billing_grace_period_days', 3, 'integer', 'billing');

        $router = $this->router();
        $a = $this->customer($router, 'batch.a');
        $b = $this->customer($router, 'batch.b');
        $c = $this->customer($router, 'batch.c');

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldReceive('isolateUser')->times(3)->andReturn(true);

        $radius = Mockery::mock(RadiusUserService::class);
        $radius->shouldReceive('syncCustomer')->andReturn(['synced' => true]);

        $items = [
            ['customer_id' => $a->id, 'invoice_id' => $a->invoices()->first()->id],
            ['customer_id' => $b->id, 'invoice_id' => $b->invoices()->first()->id],
            ['customer_id' => $c->id, 'invoice_id' => $c->invoices()->first()->id],
        ];

        (new BatchIsolateCustomersJob($router->id, $items))->handle(
            $mikrotik,
            $radius,
            app(BillingEligibilityService::class),
            app(BillingEnforcementLedger::class),
        );

        $this->assertSame('isolated', $a->refresh()->status);
        $this->assertSame('isolated', $b->refresh()->status);
        $this->assertSame('isolated', $c->refresh()->status);
    }

    public function test_ineligible_customers_are_skipped_without_isolating(): void
    {
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');

        $router = $this->router();
        $paid = $this->customer($router, 'batch.paid');
        $paid->invoices()->update(['status' => 'paid']);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldNotReceive('isolateUser');

        $radius = Mockery::mock(RadiusUserService::class);

        $items = [['customer_id' => $paid->id, 'invoice_id' => (int) $paid->invoices()->first()->id]];

        (new BatchIsolateCustomersJob($router->id, $items))->handle(
            $mikrotik,
            $radius,
            app(BillingEligibilityService::class),
            app(BillingEnforcementLedger::class),
        );

        $this->assertSame('active', $paid->refresh()->status);
    }
}
