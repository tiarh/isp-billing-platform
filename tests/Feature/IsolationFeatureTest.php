<?php

namespace Tests\Feature;

use App\Jobs\BatchIsolateCustomersJob;
use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\BillingEnforcementAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FreezesMidMonthClock;
use Tests\Support\Canary;
use Tests\TestCase;

class IsolationFeatureTest extends TestCase
{
    use FreezesMidMonthClock;
    use RefreshDatabase;

    public function test_admin_isolate_route_runs_realtime_mikrotik_action_without_dispatching_job(): void
    {
        Bus::fake();
        $customer = $this->customer(['router_id' => $this->router()->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('isolateCustomerNow')
            ->once()
            ->with(Mockery::on(fn ($value) => $value->is($customer)), 10)
            ->andReturnUsing(function (Customer $customer) {
                $customer->update([
                    'status' => 'isolated',
                    'mikrotik_profile' => $customer->router->isolation_profile ?: 'isolirebilling',
                    'mikrotik_sync_status' => 'synced',
                    'mikrotik_synced_at' => now(),
                    'mikrotik_sync_checked_at' => now(),
                ]);
            });
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->actingAs($admin)
            ->post(route('customers.isolate', $customer))
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertNotDispatched(IsolateCustomerJob::class);
        $customer->refresh();
        $this->assertSame('isolated', $customer->status);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_admin_reconnect_route_runs_realtime_mikrotik_action_without_dispatching_job(): void
    {
        Bus::fake();
        $customer = $this->customer([
            'router_id' => $this->router()->id,
            'status' => 'isolated',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('reconnectCustomerNow')
            ->once()
            ->with(Mockery::on(fn ($value) => $value->is($customer)), 10)
            ->andReturnUsing(function (Customer $customer) {
                $customer->update([
                    'status' => 'active',
                    'mikrotik_profile' => $customer->previous_profile
                        ?: $customer->package?->mikrotik_profile
                        ?: $customer->mikrotik_profile
                        ?: 'default',
                    'mikrotik_sync_status' => 'synced',
                    'mikrotik_synced_at' => now(),
                    'mikrotik_sync_checked_at' => now(),
                ]);
            });
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->actingAs($admin)
            ->post(route('customers.reconnect', $customer))
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertNotDispatched(ReconnectCustomerJob::class);
        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_failed_realtime_manual_isolate_keeps_customer_active_and_returns_error(): void
    {
        Bus::fake();
        $customer = $this->customer(['router_id' => $this->router()->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('isolateCustomerNow')
            ->once()
            ->andThrow(new \RuntimeException('router timeout'));
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->actingAs($admin)
            ->post(route('customers.isolate', $customer))
            ->assertRedirect()
            ->assertSessionHas('error');

        Bus::assertNotDispatched(IsolateCustomerJob::class);
        $this->assertSame('active', $customer->refresh()->status);
    }

    public function test_manual_isolate_without_router_keeps_status_and_returns_error(): void
    {
        Bus::fake();
        $customer = $this->customer(['router_id' => null]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('customers.isolate', $customer))
            ->assertRedirect()
            ->assertSessionHas('error');

        Bus::assertNotDispatched(IsolateCustomerJob::class);
        $this->assertSame('active', $customer->refresh()->status);
    }

    public function test_manual_reconnect_without_router_keeps_status_and_returns_error(): void
    {
        Bus::fake();
        $customer = $this->customer([
            'router_id' => null,
            'status' => 'isolated',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('customers.reconnect', $customer))
            ->assertRedirect()
            ->assertSessionHas('error');

        Bus::assertNotDispatched(ReconnectCustomerJob::class);
        $this->assertSame('isolated', $customer->refresh()->status);
    }

    public function test_overdue_command_dispatches_isolation_for_active_overdue_customer_only(): void
    {
        Bus::fake();
        $router = $this->router();
        // The join month is never billable (BillingEligibilityService), and the
        // model defaults join_date to today, so the fixture joins months ago.
        $joined = now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString();
        $active = $this->customer(['router_id' => $router->id, 'status' => 'active', 'join_date' => $joined]);
        $isolated = $this->customer([
            'code' => 'CUST-ISO',
            'pppoe_user' => 'already.iso',
            'router_id' => $router->id,
            'status' => 'isolated',
            'join_date' => $joined,
        ]);

        $this->invoice($active, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);
        $this->invoice($isolated, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);

        // Live path: dry-run setting must be off (default prod seeder keeps dry-run ON)
        Setting::set('billing_enforcement_dry_run', '0', 'boolean', 'billing');
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        // Isolation is batched per router (one MikroTik connection), so the
        // per-customer job is not used when the customer has a router.
        Bus::assertNotDispatched(IsolateCustomerJob::class);
        Bus::assertDispatched(BatchIsolateCustomersJob::class, 1);
        Bus::assertDispatched(BatchIsolateCustomersJob::class, function (BatchIsolateCustomersJob $job) use ($active, $router) {
            return $job->routerId === $router->id
                && collect($job->items)->contains(fn ($item) => (int) $item['customer_id'] === (int) $active->id);
        });
    }

    public function test_overdue_command_dry_run_setting_does_not_dispatch_isolation(): void
    {
        Bus::fake();
        $router = $this->router();
        $active = $this->customer([
            'router_id' => $router->id,
            'status' => 'active',
            'join_date' => now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(),
        ]);
        $this->invoice($active, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);

        Setting::set('billing_enforcement_dry_run', '1', 'boolean', 'billing');
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        Bus::assertNotDispatched(IsolateCustomerJob::class);
        Bus::assertNotDispatched(BatchIsolateCustomersJob::class);
        $this->assertSame('active', $active->refresh()->status);
    }

    public function test_manual_payment_dispatches_reconnect_for_isolated_paid_customer(): void
    {
        Bus::fake();
        $customer = $this->customer([
            'router_id' => $this->router()->id,
            'status' => 'isolated',
        ]);
        $invoice = $this->invoice($customer, ['amount' => 100000, 'status' => 'unpaid']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertRedirect();

        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    #[Group('blocking-billing')]
    public function test_paying_one_of_multiple_unpaid_invoices_reconnects_isolated_customer(): void
    {
        Bus::fake();
        $customer = $this->customer([
            'router_id' => $this->router()->id,
            'status' => 'isolated',
        ]);
        $paidInvoice = $this->invoice($customer, [
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'status' => 'unpaid',
        ]);
        $unpaidInvoice = $this->invoice($customer, [
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'status' => 'unpaid',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('payments.store', $paidInvoice), [
            'invoice_confirmation' => $paidInvoice->code,
            'customer_confirmation' => $paidInvoice->customer->code,
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertRedirect();

        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
        $this->assertSame('paid', $paidInvoice->refresh()->status);
        $this->assertSame('unpaid', $unpaidInvoice->refresh()->status);
    }

    public function test_isolation_job_saves_previous_profile_and_uses_router_isolation_profile(): void
    {
        $router = $this->router(['isolation_profile' => 'ISOLIR-CUSTOM']);
        $customer = $this->customer(['router_id' => $router->id, 'pppoe_user' => 'user.iso']);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->with(Mockery::on(fn ($value) => $value->is($router)))->andReturnSelf();
        $mikrotik->shouldReceive('isolateUser')->once()->with('user.iso')->andReturnUsing(function () use ($customer) {
            $customer->update(['previous_profile' => '20MB']);

            return true;
        });
        $mikrotik->shouldReceive('disconnect')->once();

        (new IsolateCustomerJob($customer))->handle($mikrotik);

        $this->assertSame('isolated', $customer->refresh()->status);
        $this->assertSame('20MB', $customer->previous_profile);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_reconnect_job_restores_profile_and_clears_previous_profile(): void
    {
        $router = $this->router();
        $customer = $this->customer([
            'router_id' => $router->id,
            'status' => 'isolated',
            'previous_profile' => '20MB',
            'mikrotik_profile' => '10MB',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->with(Mockery::on(fn ($value) => $value->is($router)))->andReturnSelf();
        $mikrotik->shouldReceive('reconnectUser')->once()->with($customer->pppoe_user, '20MB')->andReturnUsing(function () use ($customer) {
            $customer->update(['previous_profile' => null]);

            return true;
        });
        $mikrotik->shouldReceive('disconnect')->once();

        (new ReconnectCustomerJob($customer))->handle($mikrotik);

        $this->assertSame('active', $customer->refresh()->status);
        $this->assertNull($customer->previous_profile);
        $this->assertSame('20MB', $customer->mikrotik_profile);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
        $this->assertNotNull($customer->mikrotik_sync_checked_at);
    }

    public function test_reconnect_keeps_saved_profile_when_session_kick_fails(): void
    {
        $router = $this->router();
        $customer = $this->customer([
            'router_id' => $router->id,
            'status' => 'isolated',
            'previous_profile' => '20MB',
            'mikrotik_profile' => '10MB',
            'pppoe_user' => 'reconnect-kick-failure',
        ]);
        $service = new FakeIsolationMikrotikService([
            ['name' => 'default'],
            ['name' => '20MB'],
        ], [
            '.id' => '*1',
            'name' => $customer->pppoe_user,
            'profile' => 'isolirebilling',
        ]);
        $service->failKick = true;
        $service->connect($router);

        try {
            $service->reconnectUser($customer->pppoe_user, 'default');
            $this->fail('A reconnect kick failure must be raised for worker retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('active session kick failed', $exception->getMessage());
        } finally {
            $this->assertSame('20MB', $customer->refresh()->previous_profile);
        }
    }

    public function test_reconnect_uses_strict_kick_while_isolate_keeps_best_effort_kick(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/MikrotikService.php'));

        $this->assertStringContainsString(
            'public function kickUser(string $username, bool $failOnError = false): void',
            $source,
        );
        $this->assertStringContainsString('$this->kickUser($username, true);', $source);
        $this->assertStringContainsString('$this->kickUser($username);', $source);
        $this->assertStringContainsString('if ($failOnError) {', $source);
    }

    public function test_mikrotik_service_uses_router_profile_and_restores_saved_profile(): void
    {
        $router = $this->router(['isolation_profile' => 'ISOLIR-CUSTOM']);
        $customer = $this->customer(['router_id' => $router->id, 'pppoe_user' => 'service.user']);
        $service = new FakeIsolationMikrotikService([
            ['name' => 'default'],
            ['name' => 'isolir-custom'],
            ['name' => '20MB'],
        ], [
            '.id' => '*1',
            'name' => 'service.user',
            'profile' => '20MB',
        ]);
        $service->connect($router);

        $this->assertTrue($service->isolateUser('service.user'));
        $this->assertSame('isolir-custom', $service->lastSetProfile);
        $this->assertSame('20MB', $customer->refresh()->previous_profile);

        $service->secret['profile'] = 'isolir-custom';
        $this->assertTrue($service->reconnectUser('service.user', 'default'));
        $this->assertSame('20MB', $service->lastSetProfile);
        $this->assertNull($customer->refresh()->previous_profile);
    }

    public function test_live_isolation_command_preflight_does_not_dispatch_jobs_without_yes(): void
    {
        Bus::fake();
        $router = $this->router();
        $customer = $this->customer(['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPPPSecret')->once()->with($customer->pppoe_user)->andReturn([
            '.id' => '*1',
            'name' => $customer->pppoe_user,
            'profile' => '20MB',
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('network:test-isolation', ['customer_id' => $customer->id])
            ->expectsOutputToContain('Preflight only')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_isolate_job_with_missing_device_data_does_not_throw(): void
    {
        // customers.pppoe_user is NOT NULL in the schema, so "missing PPPoE"
        // is an empty username; the job guards on ! pppoe_user for both.
        $customer = $this->customer(['router_id' => null, 'pppoe_user' => '']);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldNotReceive('connect');

        // Missing router/PPPoE is permanent: the job must stop quietly instead
        // of throwing a retryable exception into failed_jobs every day.
        (new IsolateCustomerJob($customer))->handle($mikrotik);

        $this->assertSame('active', $customer->refresh()->status);
    }

    public function test_isolate_job_with_missing_device_data_marks_ledger_failed(): void
    {
        // Join before the invoiced month: the join month is never billable, and
        // the job only reaches the ledger after the policy says "isolate".
        $customer = $this->customer([
            'router_id' => null,
            'pppoe_user' => '',
            'join_date' => now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(),
        ]);
        $invoice = $this->invoice($customer, ['due_date' => now()->subDays(10), 'status' => 'unpaid']);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldNotReceive('connect');

        (new IsolateCustomerJob($customer, $invoice->id))->handle(
            $mikrotik,
            null,
            app(BillingEligibilityService::class),
            app(BillingEnforcementLedger::class),
        );

        $row = BillingEnforcementAction::query()
            ->where('customer_id', $customer->id)
            ->where('action', 'isolate')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('unavailable', (string) $row->error);
        $this->assertSame('active', $customer->refresh()->status);
    }

    public function test_reconnect_job_with_missing_device_data_does_not_throw(): void
    {
        $customer = $this->customer([
            'router_id' => null,
            'pppoe_user' => '',
            'status' => 'isolated',
        ]);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldNotReceive('connect');

        (new ReconnectCustomerJob($customer))->handle($mikrotik);

        $this->assertSame('isolated', $customer->refresh()->status);
    }

    public function test_reconnect_job_with_missing_device_data_marks_ledger_failed(): void
    {
        $customer = $this->customer([
            'router_id' => null,
            'pppoe_user' => '',
            'status' => 'isolated',
        ]);
        $invoice = $this->invoice($customer, ['status' => 'paid', 'due_date' => now()->subDays(10)]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldNotReceive('connect');

        (new ReconnectCustomerJob($customer, $invoice->id))->handle(
            $mikrotik,
            null,
            app(BillingEligibilityService::class),
            app(BillingEnforcementLedger::class),
        );

        $row = BillingEnforcementAction::query()
            ->where('customer_id', $customer->id)
            ->where('action', 'reconnect')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('unavailable', (string) $row->error);
        $this->assertSame('isolated', $customer->refresh()->status);
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

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-BASIC',
            'name' => 'Basic',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'CUST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Isolation Customer',
            'phone' => '081234567890',
            'address' => 'Isolation Address',
            'pppoe_user' => 'user.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }
}

class FakeIsolationMikrotikService extends MikrotikService
{
    public ?string $lastSetProfile = null;

    public bool $failKick = false;

    public function __construct(private array $profiles, public array $secret) {}

    public function connect(Router $router, array $options = []): self
    {
        $this->router = $router;

        return $this;
    }

    protected function ensureConnected(): void {}

    public function getProfiles(): array
    {
        return $this->profiles;
    }

    protected function findPPPSecret(string $username): ?array
    {
        return $this->secret['name'] === $username ? $this->secret : null;
    }

    protected function setPPPSecretProfile(array $secret, string $profile): void
    {
        $this->lastSetProfile = $profile;
        $this->secret['profile'] = $profile;
    }

    public function kickUser(string $username, bool $failOnError = false): void
    {
        if ($this->failKick) {
            throw new \RuntimeException('active session kick failed');
        }
    }
}
