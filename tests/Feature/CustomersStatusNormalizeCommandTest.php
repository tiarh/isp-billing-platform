<?php

namespace Tests\Feature;

use App\Console\Commands\CustomersStatusNormalize;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Monitoring\RouterPppoeReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomersStatusNormalizeCommandTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    /** @var array<string, mixed> Grafana live PPPoE sessions (lowercase user => row); trashed customers rely on this. */
    private array $grafanaUsers = ['someone-else' => ['ip' => '198.18.2.9']];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturnUsing(fn () => ['status' => 'ok', 'fetched_at' => now()->toIso8601String(), 'reason' => null, 'users' => $this->grafanaUsers]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $this->router = Router::create(['name' => 'SN', 'ip_address' => '127.0.33.1', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
        foreach (['2026-10-16', '2026-10-15'] as $day) {
            DB::table('pppoe_usage_daily')->insert([
                'usage_date_wib' => $day, 'router_id' => $this->router->id, 'pppoe_user' => 'hb', 'customer_id' => null,
                'download_bytes' => 0, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
                'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function archived(string $code, string $status, bool $online = false): Customer
    {
        $package = Package::firstOrCreate(['code' => 'SN-PKG'], ['name' => 'Guard', 'price' => 0]);
        $customer = Customer::create([
            'code' => $code, 'name' => 'Nama '.$code, 'phone' => '0815'.abs(crc32($code)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-'.$code,
            'router_id' => $this->router->id, 'is_online' => $online, 'pppoe_online_checked_at' => now()->subMinutes(2),
        ]);
        $customer->delete();

        return $customer;
    }

    private function invoice(Customer $customer, int $amount = 150000, string $period = '2026-07-01'): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id, 'period' => $period, 'amount' => $amount, 'status' => 'unpaid',
            'due_date' => '2026-07-25', 'generated_at' => now(),
        ]);
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $row = $this->archived('SN-01', 'inactive');
        $before = DB::table('customers')->get()->toJson();
        $activity = Activity::count();

        $this->artisan('customers:status-normalize')
            ->expectsOutputToContain('customer='.$row->id.' code=SN-01 status=inactive')
            ->doesntExpectOutputToContain('would skip')
            ->expectsOutputToContain('dry run, nothing written): rows=1')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('customers')->get()->toJson());
        $this->assertSame($activity, Activity::count());
    }

    public function test_execute_requires_actor_and_admin(): void
    {
        $row = $this->archived('SN-02', 'active');
        $noc = User::factory()->create(['role' => 'teknisi']);

        $this->artisan('customers:status-normalize', ['--execute' => true])->assertFailed();
        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $noc->id])->assertFailed();

        $this->assertSame('active', Customer::withTrashed()->find($row->id)->status);
    }

    public function test_execute_terminates_withtrashed_rows_keeps_deleted_at_logs_activity_and_is_idempotent(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $inactive = $this->archived('SN-03', 'inactive');
        $active = $this->archived('SN-04', 'active');
        $isolated = $this->archived('SN-05', 'isolated');
        $done = $this->archived('SN-06', 'terminated');
        $live = Customer::create([
            'code' => 'SN-07', 'name' => 'Live', 'phone' => '081500000007', 'address' => 'RW 1',
            'package_id' => $inactive->package_id, 'status' => 'inactive', 'router_id' => $this->router->id, 'pppoe_user' => 'u-live',
        ]);
        $deletedAt = Customer::withTrashed()->find($inactive->id)->deleted_at;

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('rows=3 terminated=3 skipped=0')
            ->assertSuccessful();

        foreach ([$inactive, $active, $isolated] as $row) {
            $fresh = Customer::withTrashed()->find($row->id);
            $this->assertSame('terminated', $fresh->status);
            $this->assertNotNull($fresh->deleted_at, 'stays archived');
        }
        $this->assertTrue($deletedAt->equalTo(Customer::withTrashed()->find($inactive->id)->deleted_at));
        $this->assertSame('inactive', $live->fresh()->status, 'non-archived rows untouched');
        $this->assertSame('terminated', Customer::withTrashed()->find($done->id)->status);

        $log = Activity::where('description', 'status_normalize_2_2')->orderBy('subject_id')->get();
        $this->assertCount(3, $log);
        $byId = $log->keyBy('subject_id');
        $this->assertSame('inactive', $byId[$inactive->id]->properties['from']);
        $this->assertSame('active', $byId[$active->id]->properties['from']);
        $this->assertSame('terminated', $byId[$active->id]->properties['to']);
        $this->assertNotEmpty($byId[$active->id]->properties['reason']);
        $this->assertSame($actor->id, (int) $byId[$active->id]->causer_id);

        Queue::assertNothingPushed();

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => $actor->email])
            ->expectsOutputToContain('rows=0 terminated=0 skipped=0')
            ->assertSuccessful();
        $this->assertCount(3, Activity::where('description', 'status_normalize_2_2')->get());
    }

    public function test_write_runs_inside_backstop_allow_with_the_status_normalize_source(): void
    {
        $actor = User::factory()->create();
        $row = $this->archived('SN-08', 'inactive');

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $actor->id])->assertSuccessful();

        $this->assertSame('terminated', Customer::withTrashed()->find($row->id)->status);
        $this->assertSame('status_normalize_2_2', CustomersStatusNormalize::SOURCE);
        // Allowed writes never produce a backstop violation row.
        $this->assertSame(0, Activity::where('description', 'lifecycle_backstop_violation')->count());
        $this->assertSame(0, Activity::where('event', 'lifecycle_backstop_violation')->count());
    }

    public function test_guard_deny_is_skipped_and_listed(): void
    {
        $actor = User::factory()->create();
        $this->grafanaUsers['u-sn-09'] = ['ip' => '198.18.2.10'];
        $online = $this->archived('SN-09', 'active', true);
        $idle = $this->archived('SN-10', 'active', false);

        $this->artisan('customers:status-normalize')
            ->expectsOutputToContain('code=SN-09 status=active deleted_at=2026-10-16 03:00:00 decision=deny')
            ->assertSuccessful();

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('rows=2 terminated=1 skipped=1')
            ->assertSuccessful();

        $this->assertSame('active', Customer::withTrashed()->find($online->id)->status);
        $this->assertSame('terminated', Customer::withTrashed()->find($idle->id)->status);
        $this->assertCount(1, Activity::where('description', 'status_normalize_2_2')->get());
    }

    public function test_unknown_verdict_without_grafana_evidence_is_explained_and_skipped(): void
    {
        $actor = User::factory()->create();
        $row = $this->archived('SN-30', 'inactive');
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $this->artisan('customers:status-normalize')
            ->expectsOutputToContain('decision=unknown')
            ->expectsOutputToContain('Dilewati: data trafik/ONU tidak ada, belum bisa dipastikan pelanggan sudah tidak memakai.')
            ->assertSuccessful();

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('rows=1 terminated=0 skipped=1')
            ->assertSuccessful();
        $this->assertSame('inactive', Customer::withTrashed()->find($row->id)->status);
    }

    public function test_limit_and_ids_are_respected_and_validated(): void
    {
        $actor = User::factory()->create();
        $a = $this->archived('SN-11', 'inactive');
        $b = $this->archived('SN-12', 'inactive');
        $c = $this->archived('SN-13', 'inactive');

        $this->artisan('customers:status-normalize', ['--execute' => true, '--actor' => (string) $actor->id, '--limit' => '2'])
            ->expectsOutputToContain('rows=2 terminated=2')
            ->assertSuccessful();
        $this->assertSame('inactive', Customer::withTrashed()->find($c->id)->status);

        $this->artisan('customers:status-normalize', ['--ids' => (string) $c->id])
            ->expectsOutputToContain('rows=1')
            ->assertSuccessful();
        $this->artisan('customers:status-normalize', ['--ids' => (string) $a->id])
            ->expectsOutputToContain('rows=0')
            ->assertSuccessful();

        foreach (['x', '0', '1,,2'] as $bad) {
            $this->artisan('customers:status-normalize', ['--ids' => $bad])->assertFailed();
        }
        foreach (['0', 'abc'] as $bad) {
            $this->artisan('customers:status-normalize', ['--limit' => $bad])->assertFailed();
        }
        $this->assertNotNull($b);
    }

    public function test_void_dup_invoices_dry_run_lists_and_writes_nothing(): void
    {
        $dup = $this->archived('DUP-1328-KRN486', 'inactive');
        $inv = $this->invoice($dup, 150000);
        $before = DB::table('invoices')->get()->toJson();
        $activity = Activity::count();

        $this->artisan('customers:status-normalize', ['--void-dup-invoices' => true])
            ->expectsOutputToContain('invoice='.$inv->id.' customer='.$dup->id.' code=DUP-1328-KRN486 period=2026-07-01 amount=150000 result=dry-run')
            ->expectsOutputToContain('invoices=1 total=150000')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('invoices')->get()->toJson());
        $this->assertSame($activity, Activity::count());
    }

    public function test_void_dup_invoices_execute_voids_only_unpaid_without_transactions_of_dup_customers(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $dup = $this->archived('DUP-1353-KRN521', 'inactive');
        $target = $this->invoice($dup, 150000, '2026-06-01');
        $paid = $this->invoice($dup, 150000, '2026-05-01');
        $paid->update(['status' => 'paid']);
        $withTx = $this->invoice($dup, 150000, '2026-04-01');
        Transaction::create([
            'reference' => 'SN-TRX-1', 'invoice_id' => $withTx->id, 'amount' => 1000, 'channel' => 'manual',
            'method' => 'cash', 'status' => 'pending', 'paid_at' => now(),
        ]);
        $other = $this->archived('SN-20', 'inactive');
        $otherInvoice = $this->invoice($other);

        $this->artisan('customers:status-normalize', ['--void-dup-invoices' => true, '--execute' => true, '--actor' => (string) $actor->id])
            ->assertFailed();
        $this->assertSame('unpaid', $target->fresh()->status, 'execute without --ids voids nothing');
        $this->assertSame(0, Activity::where('description', 'Invoice voided')->count());

        $this->artisan('customers:status-normalize', ['--void-dup-invoices' => true, '--execute' => true, '--actor' => (string) $actor->id, '--ids' => (string) $dup->id])
            ->expectsOutputToContain('invoices=1 total=150000 voided=1 skipped=0')
            ->assertSuccessful();

        $this->assertSame('void', $target->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('unpaid', $withTx->fresh()->status);
        $this->assertSame('unpaid', $otherInvoice->fresh()->status);
        $this->assertSame('inactive', Customer::withTrashed()->find($dup->id)->status, 'status step not run in void mode');

        $log = Activity::where('description', 'Invoice voided')->get();
        $this->assertCount(1, $log);
        $this->assertSame('akun duplikat DUP (2.2)', $log[0]->properties['reason']);
        Queue::assertNothingPushed();
    }
}
