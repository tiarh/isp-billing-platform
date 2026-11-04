<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Transaction;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Support\Canary;
use Tests\TestCase;

class ArchiveCustomersNotOnMikrotikTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    public function test_dry_run_reports_candidates_without_deleting(): void
    {
        [$router, $package] = [$this->router('Dry Router'), $this->package()];
        $kept = $this->customer($package, 'CUST-KEEP', 'keep.user', ['router_id' => $router->id]);
        $candidate = $this->customer($package, 'CUST-ARCHIVE', 'archive.user', ['router_id' => $router->id]);
        $disabledMatch = $this->customer($package, 'CUST-DISABLED', 'disabled.user', ['router_id' => $router->id]);
        $blank = $this->customer($package, 'CUST-BLANK', '', ['router_id' => $router->id]);

        $this->mockMikrotik([
            $router->id => [
                ['name' => 'keep.user', 'disabled' => 'false'],
                ['name' => 'disabled.user', 'disabled' => 'true'],
            ],
        ]);

        $this->artisan('customers:archive-not-on-mikrotik')
            ->expectsOutput('Checking router connection Dry Router (10.99.0.1)... attempt 1/5 succeeded')
            ->expectsOutput('Auditing Dry Router (10.99.0.1)... attempt 1/5 succeeded')
            ->expectsTable(['Metric', 'Count'], [
                ['Routers audited', 1],
                ['eBilling customers total', 4],
                ['MikroTik PPPoE total', 2],
                ['MikroTik enabled total', 1],
                ['MikroTik disabled total', 1],
                ['Matched kept', 2],
                ['Disabled matches kept', 1],
                ['Archive candidates', 2],
            ])
            ->assertExitCode(0);

        foreach ([$kept, $candidate, $disabledMatch, $blank] as $customer) {
            $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
        }
    }

    public function test_apply_soft_deletes_unmatched_customers_and_preserves_invoice_history(): void
    {
        $this->allowCustomerLifecycleGuard();
        [$router, $package] = [$this->router('Apply Router'), $this->package()];
        $kept = $this->customer($package, 'CUST-KEEP', 'keep.user', ['router_id' => $router->id]);
        $candidate = $this->customer($package, 'CUST-ARCHIVE', 'archive.user', ['router_id' => $router->id]);
        $invoice = Invoice::create([
            'customer_id' => $candidate->id,
            'period' => '2026-01-01',
            'amount' => 100000,
            'status' => 'paid',
            'due_date' => '2026-01-20',
            'generated_at' => now(),
        ]);
        Transaction::create([
            'reference' => 'ARCHIVE-TRX-001',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->mockMikrotik([
            $router->id => [
                ['name' => 'keep.user', 'disabled' => 'false'],
            ],
        ]);

        $this->artisan('customers:archive-not-on-mikrotik', [
            '--apply' => true,
            '--backup-confirmed' => true,
        ])->expectsOutput('Soft-deleted archive candidates: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', ['id' => $kept->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('customers', ['id' => $candidate->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'customer_id' => $candidate->id]);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'reference' => 'ARCHIVE-TRX-001']);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Customer::class,
            'subject_id' => $candidate->id,
            'description' => 'archived_not_on_mikrotik',
        ]);
    }

    public function test_apply_requires_backup_confirmation(): void
    {
        $this->router('Backup Router');

        $this->artisan('customers:archive-not-on-mikrotik', ['--apply' => true])
            ->expectsOutput('Refusing to apply: pass --backup-confirmed after taking a production database backup.')
            ->assertExitCode(1);
    }

    public function test_archive_aborts_without_mutation_when_router_fails(): void
    {
        [$router, $package] = [$this->router('Fail Router'), $this->package()];
        $customer = $this->customer($package, 'CUST-FAIL', 'fail.user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andThrow(new \RuntimeException('router offline'));
        $mikrotik->shouldReceive('disconnect')->once();
        $mikrotik->shouldNotReceive('getPPPSecrets');
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('customers:archive-not-on-mikrotik', [
            '--apply' => true,
            '--backup-confirmed' => true,
            '--retries' => 1,
        ])->expectsOutput('Checking router connection Fail Router (10.99.0.1)... attempt 1/1 failed: router offline')
            ->expectsOutput('Archive aborted because one or more router connections failed.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_archive_retries_router_audit_before_continuing(): void
    {
        [$router, $package] = [$this->router('Retry Router'), $this->package()];
        $kept = $this->customer($package, 'CUST-RETRY-KEEP', 'retry.keep', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $connectAttempts = 0;
        $mikrotik->shouldReceive('connect')
            ->times(3)
            ->withArgs(fn ($attemptRouter, array $options) => $attemptRouter->id === $router->id
                && $options['timeout'] === 15
                && $options['attempts'] === 1)
            ->andReturnUsing(function () use (&$connectAttempts, $mikrotik) {
                $connectAttempts++;

                if ($connectAttempts === 1) {
                    throw new \RuntimeException('Stream timed out');
                }

                return $mikrotik;
            });
        $mikrotik->shouldReceive('getPPPSecrets')
            ->once()
            ->andReturn([
                ['name' => 'retry.keep', 'disabled' => 'false'],
            ]);
        $mikrotik->shouldReceive('disconnect')->times(3);
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('customers:archive-not-on-mikrotik', [
            '--retries' => 2,
            '--retry-delay' => 0,
        ])
            ->expectsOutput('Checking router connection Retry Router (10.99.0.1)... attempt 1/2 failed: Stream timed out')
            ->expectsOutput('Retrying in 0 seconds...')
            ->expectsOutput('Checking router connection Retry Router (10.99.0.1)... attempt 2/2 succeeded')
            ->expectsOutput('Auditing Retry Router (10.99.0.1)... attempt 1/2 succeeded')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', ['id' => $kept->id, 'deleted_at' => null]);
    }

    public function test_archive_aborts_without_mutation_when_duplicate_mikrotik_usernames_exist_across_routers(): void
    {
        $routerA = $this->router('Duplicate A', '10.99.0.1');
        $routerB = $this->router('Duplicate B', '10.99.0.2');
        $package = $this->package();
        $customer = $this->customer($package, 'CUST-DUP', 'archive.user', ['router_id' => $routerA->id]);

        $this->mockMikrotik([
            $routerA->id => [['name' => 'same.user', 'disabled' => 'false']],
            $routerB->id => [['name' => 'same.user', 'disabled' => 'false']],
        ]);

        $this->artisan('customers:archive-not-on-mikrotik', [
            '--apply' => true,
            '--backup-confirmed' => true,
        ])->expectsOutput('Archive aborted because duplicate MikroTik PPPoE usernames exist across routers.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    private function mockMikrotik(array $secretsByRouter): void
    {
        $mikrotik = Mockery::mock(MikrotikService::class);

        foreach ($secretsByRouter as $routerId => $secrets) {
            $mikrotik->shouldReceive('connect')
                ->twice()
                ->withArgs(fn ($router, array $options = []) => $router->id === $routerId)
                ->andReturnSelf();
            $mikrotik->shouldReceive('getPPPSecrets')
                ->once()
                ->andReturn($secrets);
            $mikrotik->shouldReceive('disconnect')->twice();
        }

        $this->app->instance(MikrotikService::class, $mikrotik);
    }

    private function router(string $name, string $ip = '10.99.0.1'): Router
    {
        return Router::create([
            'name' => $name,
            'ip_address' => $ip,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'Archive Package',
            'code' => 'PKG-ARCHIVE-'.uniqid(),
            'price' => 100000,
        ]);
    }

    private function customer(Package $package, string $code, string $pppoeUser, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000000',
            'address' => 'Archive Address',
            'pppoe_user' => $pppoeUser,
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }
}
