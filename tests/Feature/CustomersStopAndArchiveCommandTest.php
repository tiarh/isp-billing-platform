<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
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
class CustomersStopAndArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $this->router = Router::create(['name' => 'SA', 'ip_address' => '127.0.33.1', 'username' => 'u', 'password' => 'canary-pw', 'is_active' => true]);
        foreach (['2026-10-14', '2026-10-13'] as $day) {
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

    private function customer(string $code, string $status, bool $online): Customer
    {
        $package = Package::firstOrCreate(['code' => 'SA-PKG'], ['name' => 'Guard', 'price' => 100000]);

        return Customer::create([
            'code' => $code, 'name' => 'Nama '.$code, 'phone' => '0815'.abs(crc32($code)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-'.$code,
            'router_id' => $this->router->id, 'is_online' => $online, 'pppoe_online_checked_at' => now()->subMinutes(2),
        ]);
    }

    private function invoice(Customer $customer, float $amount = 125000): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id, 'period' => '2026-08-01', 'amount' => $amount,
            'due_date' => '2026-08-10', 'status' => 'unpaid', 'generated_at' => now(),
        ]);
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $idle = $this->customer('SA-01', 'active', false);
        $online = $this->customer('SA-02', 'active', true);
        $this->invoice($idle);
        $before = DB::table('customers')->get()->toJson();
        $activity = Activity::count();

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-01,SA-02'])
            ->expectsOutputToContain('nothing written): allow=1 deny=1')
            ->expectsOutputToContain('customer='.$idle->id.' code=SA-01 status=active decision=allow')
            ->expectsOutputToContain('customer='.$online->id.' code=SA-02 status=active decision=deny')
            ->expectsOutputToContain('Rp 125.000')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('customers')->get()->toJson());
        $this->assertSame($activity, Activity::count());
    }

    public function test_execute_archives_allowed_sets_terminated_keeps_invoices_and_logs_activity(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'admin']);
        $idle = $this->customer('SA-03', 'isolated', false);
        $online = $this->customer('SA-04', 'active', true);
        $invoice = $this->invoice($idle);

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-03,SA-04,SA-NOPE', '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'pemutusan',
        ])
            ->expectsOutputToContain('archived=1 skipped=2')
            ->expectsOutputToContain('code=SA-03 status=isolated decision=allow')
            ->expectsOutputToContain('code=SA-NOPE decision=not_found')
            ->assertSuccessful();

        $archived = Customer::withTrashed()->find($idle->id);
        $this->assertSame('terminated', $archived->status);
        $this->assertNotNull($archived->deleted_at);
        $this->assertNull(Customer::withTrashed()->find($online->id)->deleted_at);
        $this->assertSame('active', Customer::find($online->id)->status);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(1, Invoice::where('customer_id', $idle->id)->count());

        $log = Activity::where('event', 'customer_stopped_and_archived')->get();
        $this->assertCount(1, $log);
        $this->assertSame($actor->id, (int) $log[0]->causer_id);
        $this->assertSame('pemutusan', $log[0]->properties['reason']);
        $this->assertSame('isolated', $log[0]->properties['previous_status']);
        $this->assertSame('cli_stop_and_archive', $log[0]->properties['source']);
        $this->assertArrayNotHasKey('name', $log[0]->properties->toArray());
        Queue::assertNothingPushed();

        // Archived now: a second run reports it and changes nothing.
        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-03'])
            ->expectsOutputToContain('code=SA-03 decision=already_archived')
            ->assertSuccessful();
    }

    public function test_pppoe_identifier_resolves_customer_without_code(): void
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $idle = $this->customer('SA-05', 'active', false);
        $idle->forceFill(['code' => null])->save();

        $this->artisan('customers:stop-and-archive', [
            '--pppoe' => 'u-SA-05', '--execute' => true, '--actor' => $actor->email, '--reason' => 'x',
        ])->expectsOutputToContain('archived=1')->assertSuccessful();

        $this->assertNotNull(Customer::withTrashed()->find($idle->id)->deleted_at);
    }

    public function test_unknown_verdict_is_skipped_on_execute(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $noRouter = $this->customer('SA-09', 'active', false);
        $noRouter->forceFill(['router_id' => null])->save();

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-09', '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])
            ->expectsOutputToContain('code=SA-09 status=active decision=unknown')
            ->expectsOutputToContain('archived=0 skipped=1 failed=0')
            ->assertSuccessful();

        $fresh = Customer::find($noRouter->id);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_guard_is_rechecked_under_lock_and_deny_there_means_not_archived(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->customer('SA-10', 'active', false);

        $this->app->instance(CustomerLifecycleGuard::class, new class extends CustomerLifecycleGuard
        {
            private int $calls = 0;

            public function __construct() {}

            public function canArchive(Customer $customer, GuardContext $context): GuardDecision
            {
                $this->calls++;

                return GuardDecision::fromSignals('archive', [
                    $this->calls === 1
                        ? GuardSignal::clear(GuardSignal::PPPOE, 'ok', '')
                        : GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', 'online'),
                ]);
            }
        });

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-10', '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])
            ->expectsOutputToContain('code=SA-10 status=active decision=allow')
            ->expectsOutputToContain('archived=0 skipped=1 failed=0')
            ->assertSuccessful();

        $fresh = Customer::find($c->id);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }

    private function noRouter(string $code): Customer
    {
        $c = $this->customer($code, 'active', false);
        $c->forceFill(['router_id' => null])->save();

        return $c;
    }

    public function test_override_archives_no_router_unknown_and_logs_it(): void
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $c = $this->noRouter('SA-12');

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-12', '--allow-no-router-unknown' => true])
            ->expectsOutputToContain('override=would_apply result=dry-run')
            ->assertSuccessful();
        $this->assertNull(Customer::find($c->id)->deleted_at);

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-12', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'pemutusan',
        ])->expectsOutputToContain('archived=1 skipped=0')->assertSuccessful();

        $archived = Customer::withTrashed()->find($c->id);
        $this->assertSame('terminated', $archived->status);
        $this->assertNotNull($archived->deleted_at);
        $override = Activity::where('event', 'lifecycle_guard_override')->get();
        $this->assertCount(1, $override);
        $this->assertSame($actor->id, (int) $override[0]->causer_id);
        $this->assertSame('pemutusan', $override[0]->properties['reason']);
        $this->assertSame('cli_stop_and_archive', $override[0]->properties['source']);
        $this->assertSame('unknown', $override[0]->properties['guard']['verdict']);
        $this->assertTrue(Activity::where('event', 'customer_stopped_and_archived')->first()->properties['guard_override']);
    }

    public function test_override_not_used_without_flag_or_with_router_or_deny(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $plain = $this->noRouter('SA-13');
        $withRouter = $this->customer('SA-14', 'active', false);
        $withRouter->forceFill(['pppoe_user' => 'u-gone'])->save();
        $deny = $this->noRouter('SA-15');
        $deny->forceFill(['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(1)])->save();

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-13', '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->expectsOutputToContain('archived=0 skipped=1')->assertSuccessful();

        // Router present: verdict is not "no router unknown".
        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-14,SA-15', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($plain->id)->deleted_at);
        $this->assertNull(Customer::withTrashed()->find($deny->id)->deleted_at, 'deny is never overridden');
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_override_refused_for_fresh_online_onu_and_needs_reason(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $olt = Olt::create(['name' => 'SA OLT', 'code' => 'SA-OLT', 'vendor' => 'zte_c300']);
        $c = $this->noRouter('SA-16');
        $c->forceFill(['onu_serial' => 'ZTEGSA000016', 'olt_id' => $olt->id])->save();
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:16', 'serial_number' => 'ZTEGSA000016',
            'state' => 'online', 'phase_state' => 'working', 'state_collected_at' => now()->subMinutes(2),
        ]);

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-16', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->expectsOutputToContain('archived=0 skipped=1')->assertSuccessful();
        $this->assertNull(Customer::find($c->id)->deleted_at);

        $other = $this->noRouter('SA-17');
        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-17', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id,
        ])->assertFailed();
        $this->assertNull(Customer::find($other->id)->deleted_at);
    }

    public function test_override_refused_when_a_guard_signal_reports_an_evaluation_error(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->noRouter('SA-19');

        $this->app->instance(CustomerLifecycleGuard::class, new class extends CustomerLifecycleGuard
        {
            public function __construct() {}

            public function canArchive(Customer $customer, GuardContext $context): GuardDecision
            {
                return GuardDecision::fromSignals('archive', [
                    GuardSignal::unknown(GuardSignal::PPPOE, 'guard_failed', 'x'),
                ]);
            }
        });

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-19', '--allow-no-router-unknown' => true])
            ->expectsOutputToContain('override=not_applicable(guard_failed)')
            ->assertSuccessful();
        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-19', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->expectsOutputToContain('archived=0 skipped=1')->assertSuccessful();

        $this->assertNull(Customer::find($c->id)->deleted_at);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_override_log_text_uses_the_actor_role(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->noRouter('SA-20');

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-20', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->assertSuccessful();

        $text = Activity::where('event', 'lifecycle_guard_override')->value('description');
        $this->assertStringStartsWith('Admin override', (string) $text);
    }

    public function test_override_fails_closed_when_the_local_use_check_errors(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->noRouter('SA-18');
        $c->forceFill(['onu_serial' => 'ZTEGSA000018'])->save();

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'olt_onus')) {
                throw new \RuntimeException('olt_onus read failed');
            }
        });

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-18', '--allow-no-router-unknown' => true])
            // The same read error also breaks the guard's own ONU signal, so the guard error wins.
            ->expectsOutputToContain('override=not_applicable(guard_failed)')
            ->assertSuccessful();

        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-18', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->expectsOutputToContain('archived=0 skipped=1')->assertSuccessful();

        $fresh = Customer::find($c->id);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_override_refused_with_check_failed_when_only_the_strict_local_read_errors(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $c = $this->noRouter('SA-21');
        $c->forceFill(['onu_serial' => 'ZTEGSA000021'])->save();

        // A clean "no data" guard (no error marker), so only the strict local read can fail.
        $this->app->instance(CustomerLifecycleGuard::class, new class extends CustomerLifecycleGuard
        {
            public function __construct() {}

            public function canArchive(Customer $customer, GuardContext $context): GuardDecision
            {
                return GuardDecision::fromSignals('archive', [
                    GuardSignal::unknown(GuardSignal::PPPOE, 'pppoe_unknown', 'x'),
                ]);
            }
        });

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'olt_onus')) {
                throw new \RuntimeException('olt_onus read failed');
            }
        });

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-21', '--allow-no-router-unknown' => true])
            ->expectsOutputToContain('override=not_applicable(check_failed)')
            ->assertSuccessful();
        $this->artisan('customers:stop-and-archive', [
            '--codes' => 'SA-21', '--allow-no-router-unknown' => true, '--execute' => true, '--actor' => (string) $actor->id, '--reason' => 'x',
        ])->expectsOutputToContain('archived=0 skipped=1')->assertSuccessful();

        $fresh = Customer::find($c->id);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_duplicate_identifier_is_reported_and_nbsp_is_trimmed(): void
    {
        $this->customer('SA-11', 'active', false);

        $this->artisan('customers:stop-and-archive', ['--codes' => "\u{00A0}SA-11\u{00A0}", '--pppoe' => 'u-SA-11'])
            ->expectsOutputToContain('pppoe=u-SA-11 decision=duplicate result=skipped')
            ->expectsOutputToContain('duplicate=1')
            ->assertSuccessful();
    }

    public function test_execute_requires_actor_reason_and_admin(): void
    {
        $idle = $this->customer('SA-06', 'active', false);
        $plain = User::factory()->create(['role' => 'technician']);

        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-06', '--execute' => true, '--reason' => 'x'])->assertFailed();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-06', '--execute' => true, '--actor' => (string) $admin->id])->assertFailed();
        $this->artisan('customers:stop-and-archive', ['--codes' => 'SA-06', '--execute' => true, '--actor' => (string) $plain->id, '--reason' => 'x'])->assertFailed();

        $fresh = Customer::find($idle->id);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }
}
