<?php

namespace Tests\Feature;

use App\Console\Commands\CustomersIsolateList;
use App\Jobs\BatchIsolateCustomersJob;
use App\Models\BillingEnforcementAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomersIsolateListCommandTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $this->router = Router::create(['name' => 'IL', 'ip_address' => '127.0.34.1', 'username' => 'u', 'password' => 'canary-pw', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function customer(string $code, array $overrides = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'IL-PKG'], ['name' => 'Iso', 'price' => 100000]);

        return Customer::create(array_merge([
            'code' => $code, 'name' => 'Nama '.$code, 'phone' => '0815'.abs(crc32($code)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => 'active', 'pppoe_user' => 'u-'.$code,
            'router_id' => $this->router->id, 'join_date' => '2026-01-01',
        ], $overrides));
    }

    private function overdue(Customer $customer, string $status = 'unpaid'): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id, 'period' => '2026-08-01', 'amount' => 100000,
            'due_date' => '2026-08-10', 'status' => $status, 'generated_at' => now(),
        ]);
    }

    private function mikrotik(callable $configure): void
    {
        $mikrotik = Mockery::mock(MikrotikService::class);
        $configure($mikrotik);
        $this->app->instance(MikrotikService::class, $mikrotik);
    }

    public function test_dry_run_changes_nothing_and_never_touches_the_router(): void
    {
        $this->mikrotik(fn ($m) => $m->shouldNotReceive('connect'));
        $c = $this->customer('IL-01');
        $this->overdue($c);
        $before = DB::table('customers')->get()->toJson();

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-01'])
            ->expectsOutputToContain('code=IL-01 status=active router='.$this->router->id.' pppoe=u-IL-01 overdue_invoices=1 overdue_sum=100000 already_isolated=no ledger=none decision=eligible would=isolate result=dry-run')
            ->expectsOutputToContain('eligible=1 skipped=0')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('customers')->get()->toJson());
    }

    public function test_skips_paid_no_router_already_isolated_and_unknown(): void
    {
        $this->mikrotik(fn ($m) => $m->shouldNotReceive('connect'));
        $actor = User::factory()->create(['role' => 'admin']);
        $paid = $this->customer('IL-02');
        $this->overdue($paid, 'paid');
        $noRouter = $this->customer('IL-03', ['router_id' => null]);
        $this->overdue($noRouter);
        $isolated = $this->customer('IL-04', ['status' => 'isolated']);
        $this->overdue($isolated);

        $this->artisan('customers:isolate-list', [
            '--codes' => 'IL-02,IL-03,IL-04,IL-NOPE', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0',
        ])
            ->expectsOutputToContain('code=IL-02 status=active router='.$this->router->id.' pppoe=u-IL-02 overdue_invoices=0 overdue_sum=0 already_isolated=no decision=skip reasons=no_overdue_invoice result=skipped')
            ->expectsOutputToContain('code=IL-03 status=active router=- pppoe=u-IL-03 overdue_invoices=1 overdue_sum=100000 already_isolated=no decision=skip reasons=no_router')
            ->expectsOutputToContain('code=IL-04 status=isolated router='.$this->router->id.' pppoe=u-IL-04 overdue_invoices=1 overdue_sum=100000 already_isolated=yes decision=skip reasons=status_isolated')
            ->expectsOutputToContain('code=IL-NOPE decision=not_found')
            ->expectsOutputToContain('isolated=0 failed=0')
            ->assertSuccessful();

        $this->assertSame('active', $paid->fresh()->status);
    }

    public function test_execute_requires_admin_actor(): void
    {
        $c = $this->customer('IL-05');
        $this->overdue($c);
        $tech = User::factory()->create(['role' => 'technician']);

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-05', '--execute' => true])->assertFailed();
        $this->artisan('customers:isolate-list', ['--codes' => 'IL-05', '--execute' => true, '--actor' => (string) $tech->id])->assertFailed();
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_execute_isolates_each_eligible_customer_once_and_one_failure_does_not_stop_the_rest(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $bad = $this->customer('IL-06');
        $good = $this->customer('IL-07');
        $this->overdue($bad);
        $this->overdue($good);

        $this->mikrotik(function ($m) {
            $m->shouldReceive('connect')->twice()->andReturnSelf();
            $m->shouldReceive('isolateUser')->once()->with('u-IL-06')->andThrow(new \RuntimeException('router timeout'));
            $m->shouldReceive('isolateUser')->once()->with('u-IL-07')->andReturn(true);
            $m->shouldReceive('disconnect')->twice();
        });

        $this->artisan('customers:isolate-list', [
            '--codes' => 'IL-06,IL-07', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0',
        ])
            ->expectsOutputToContain('code=IL-06 status=active router='.$this->router->id.' pppoe=u-IL-06 overdue_invoices=1 overdue_sum=100000 already_isolated=no ledger=none decision=eligible result=failed error=RuntimeException')
            ->expectsOutputToContain('code=IL-07 status=active router='.$this->router->id.' pppoe=u-IL-07 overdue_invoices=1 overdue_sum=100000 already_isolated=no ledger=none decision=eligible result=isolated')
            ->expectsOutputToContain('isolated=1 failed=1')
            ->assertSuccessful();

        $this->assertSame('active', $bad->fresh()->status);
        $this->assertSame('isolated', $good->fresh()->status);
    }

    private function ledger(Customer $customer, Invoice $invoice, string $status): BillingEnforcementAction
    {
        return BillingEnforcementAction::create([
            'idempotency_key' => app(BillingEnforcementLedger::class)->key($customer->id, $invoice->id, 'isolate'),
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'action' => 'isolate',
            'policy_version' => BillingEligibilityService::POLICY_VERSION, 'status' => $status,
            'completed_at' => now()->subDays(5), 'metadata' => ['router_id' => 99, 'old_key' => 'keep'],
        ]);
    }

    private function okRouter(string $user): void
    {
        $this->mikrotik(function ($m) use ($user) {
            $m->shouldReceive('connect')->once()->andReturnSelf();
            $m->shouldReceive('isolateUser')->once()->with($user)->andReturn(true);
            $m->shouldReceive('disconnect')->once();
        });
    }

    public function test_succeeded_ledger_row_is_shown_in_dry_run_rearmed_on_execute_and_isolates(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('IL-08');
        $invoice = $this->overdue($c);
        $row = $this->ledger($c, $invoice, 'succeeded');

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-08'])
            ->expectsOutputToContain('ledger=succeeded decision=eligible would=rearm_ledger_and_isolate result=dry-run')
            ->assertSuccessful();
        $this->assertSame('succeeded', $row->fresh()->status);

        $this->okRouter('u-IL-08');
        $this->artisan('customers:isolate-list', ['--codes' => 'IL-08', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('ledger=succeeded decision=eligible result=isolated')
            ->assertSuccessful();

        $this->assertSame('isolated', $c->fresh()->status);
        $row = $row->fresh();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame('cli_isolate_list', $row->metadata['rearm']['rearmed_by']);
        $this->assertSame($actor->id, $row->metadata['rearm']['actor_id']);
        $this->assertSame('succeeded', $row->metadata['rearm']['previous_status']);
        $this->assertSame('keep', $row->metadata['old_key'], 'existing metadata is merged, not replaced');

        $log = Activity::where('event', 'customer_isolated_from_list')->get();
        $this->assertCount(1, $log);
        $this->assertSame($actor->id, (int) $log[0]->causer_id);
        $this->assertTrue($log[0]->properties['rearmed_ledger']);
    }

    public function test_failed_ledger_row_is_retried(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('IL-09');
        $invoice = $this->overdue($c);
        $row = $this->ledger($c, $invoice, 'failed');
        $this->okRouter('u-IL-09');

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-09', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('ledger=failed decision=eligible result=isolated')
            ->assertSuccessful();

        $this->assertSame('isolated', $c->fresh()->status);
        $this->assertSame('succeeded', $row->fresh()->status);
    }

    public function test_running_ledger_row_is_skipped_and_never_touched(): void
    {
        $this->mikrotik(fn ($m) => $m->shouldNotReceive('connect'));
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('IL-10');
        $invoice = $this->overdue($c);
        $row = $this->ledger($c, $invoice, 'running');
        $before = $row->fresh()->toJson();

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-10', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('ledger=running decision=skip reasons=ledger_running result=skipped')
            ->assertSuccessful();

        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame($before, $row->fresh()->toJson());
    }

    public function test_handler_skip_after_rearm_prints_ledger_left_pending(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('IL-15');
        $invoice = $this->overdue($c);
        $row = $this->ledger($c, $invoice, 'succeeded');
        $this->app->make(ConsoleKernel::class)->registerCommand(new NoopIsolateListCommand);

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-15', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('result=skipped reasons=ledger_done ledger_left=pending')
            ->assertSuccessful();

        $this->assertSame('active', $c->fresh()->status);
        $row = $row->fresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame('keep', $row->metadata['old_key']);
        $this->assertSame('succeeded', $row->metadata['rearm']['previous_status']);
    }

    public function test_skipped_while_router_batch_lock_is_held(): void
    {
        $this->mikrotik(fn ($m) => $m->shouldNotReceive('connect'));
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('IL-14');
        $this->overdue($c);
        $lock = Cache::lock('laravel-queue-overlap:'.BatchIsolateCustomersJob::class.':batch-isolate-router:'.$this->router->id, 60);
        $this->assertTrue($lock->get());

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-14', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('result=skipped reasons=router_batch_running')
            ->assertSuccessful();

        $lock->release();
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_policy_not_eligible_cases_are_skipped(): void
    {
        $this->mikrotik(fn ($m) => $m->shouldNotReceive('connect'));
        $actor = User::factory()->create(['role' => 'admin']);
        $rp0 = Package::create(['code' => 'IL-FREE', 'name' => 'Free', 'price' => 0]);
        $free = $this->customer('IL-11', ['package_id' => $rp0->id]);
        $this->overdue($free);
        $grace = $this->customer('IL-12');
        Invoice::create([
            'customer_id' => $grace->id, 'period' => '2026-10-01', 'amount' => 100000,
            'due_date' => '2026-10-12', 'status' => 'unpaid', 'generated_at' => now(),
        ]);

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-11,IL-12', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('reasons=policy_not_eligible:price_zero_exempt')
            ->expectsOutputToContain('reasons=policy_not_eligible:current_unpaid_grace')
            ->expectsOutputToContain('isolated=0 failed=0')
            ->assertSuccessful();

        $this->assertSame('active', $free->fresh()->status);
        $this->assertSame('active', $grace->fresh()->status);
    }

    public function test_radius_failure_after_router_isolation_is_reported_as_isolated_radius_failed(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->router->update(['radius_enabled' => true]);
        $this->app->instance(RadiusUserService::class, new FailingRadiusUserService);
        $c = $this->customer('IL-13');
        $this->overdue($c);
        $this->okRouter('u-IL-13');

        $this->artisan('customers:isolate-list', ['--codes' => 'IL-13', '--execute' => true, '--actor' => (string) $actor->id, '--sleep' => '0'])
            ->expectsOutputToContain('result=isolated_radius_failed')
            ->assertSuccessful();

        $this->assertSame('isolated', $c->fresh()->status);
        $this->assertSame(1, Activity::where('event', 'customer_isolated_from_list')->count());
    }
}

class NoopIsolateListCommand extends CustomersIsolateList
{
    protected function runIsolation(Customer $customer, int $invoiceId): void
    {
        // handler that does nothing (e.g. skipped by its own policy re-check)
    }
}

class FailingRadiusUserService extends RadiusUserService
{
    public function syncCustomer(Customer $customer): array
    {
        return ['synced' => false, 'reason' => 'test'];
    }
}
