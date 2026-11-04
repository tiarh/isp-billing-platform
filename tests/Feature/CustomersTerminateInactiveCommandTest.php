<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
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
class CustomersTerminateInactiveCommandTest extends TestCase
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

        $this->router = Router::create(['name' => 'TI', 'ip_address' => '127.0.32.1', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
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
        $package = Package::firstOrCreate(['code' => 'TI-PKG'], ['name' => 'Guard', 'price' => 0]);

        return Customer::create([
            'code' => $code, 'name' => 'Nama '.$code, 'phone' => '0815'.abs(crc32($code)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-'.$code,
            'router_id' => $this->router->id, 'is_online' => $online, 'pppoe_online_checked_at' => now()->subMinutes(2),
        ]);
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $online = $this->customer('TI-01', 'inactive', true);
        $idle = $this->customer('TI-02', 'inactive', false);
        $before = DB::table('customers')->get()->toJson();
        $activity = Activity::count();

        $this->artisan('customers:terminate-inactive')
            ->expectsOutputToContain('nothing written): allow=1 deny=1 unknown=0')
            ->expectsOutputToContain('customer='.$online->id.' code=TI-01 decision=deny')
            ->expectsOutputToContain('customer='.$idle->id.' code=TI-02 decision=allow')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('customers')->get()->toJson());
        $this->assertSame($activity, Activity::count());
    }

    public function test_execute_without_actor_is_refused(): void
    {
        $idle = $this->customer('TI-03', 'inactive', false);

        $this->artisan('customers:terminate-inactive', ['--execute' => true])->assertFailed();

        $this->assertSame('inactive', $idle->fresh()->status);
    }

    public function test_execute_terminates_only_allowed_logs_activity_and_is_idempotent(): void
    {
        $actor = User::factory()->create();
        $online = $this->customer('TI-04', 'inactive', true);
        $idle = $this->customer('TI-05', 'inactive', false);
        $active = $this->customer('TI-06', 'active', false);

        $this->artisan('customers:terminate-inactive', ['--execute' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('terminated=1 skipped=1')
            ->assertSuccessful();

        $this->assertSame('inactive', $online->fresh()->status);
        $this->assertSame('active', $active->fresh()->status);
        $terminated = Customer::withTrashed()->find($idle->id);
        $this->assertSame('terminated', $terminated->status);
        $this->assertNull($terminated->deleted_at, 'never soft-deleted');

        $log = Activity::where('event', 'terminated_inactive_cleanup')->get();
        $this->assertCount(1, $log);
        $this->assertSame($idle->id, (int) $log[0]->subject_id);
        $this->assertSame('inactive', $log[0]->properties['previous_status']);
        $this->assertSame('allow', $log[0]->properties['guard']['verdict']);
        $this->assertSame($actor->id, $log[0]->properties['actor']['id']);

        $this->artisan('customers:terminate-inactive', ['--execute' => true, '--actor' => $actor->email])->assertSuccessful();
        $this->assertCount(1, Activity::where('event', 'terminated_inactive_cleanup')->get());
    }

    public function test_execute_with_onu_serial_dispatches_nothing_and_touches_no_device_state(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $olt = Olt::create(['name' => 'TI OLT', 'code' => 'TI-OLT', 'vendor' => 'zte_c300']);
        $customer = $this->customer('TI-09', 'inactive', false);
        $customer->forceFill(['onu_serial' => 'ZTEGTI000009', 'olt_id' => $olt->id])->save();
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:9', 'serial_number' => 'ZTEGTI000009',
            'state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => now()->subMinutes(3),
        ]);
        $onuBefore = DB::table('olt_onus')->get()->toJson();
        $routerBefore = DB::table('routers')->get()->toJson();

        $this->artisan('customers:terminate-inactive', ['--execute' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('terminated=1 skipped=0')
            ->assertSuccessful();

        $this->assertSame('terminated', $customer->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertSame($onuBefore, DB::table('olt_onus')->get()->toJson());
        $this->assertSame($routerBefore, DB::table('routers')->get()->toJson());
    }

    public function test_actor_must_be_admin_or_superadmin(): void
    {
        $this->customer('TI-10', 'inactive', false);
        $noc = User::factory()->create(['role' => 'teknisi']);

        $this->artisan('customers:terminate-inactive', ['--execute' => true, '--actor' => (string) $noc->id])->assertFailed();
    }

    public function test_invalid_ids_or_max_id_are_rejected_instead_of_selecting_all(): void
    {
        $customer = $this->customer('TI-11', 'inactive', false);

        foreach (['C123', '', '1,x', '0', '5,,6'] as $bad) {
            $this->artisan('customers:terminate-inactive', ['--ids' => $bad])->assertFailed();
        }
        $this->artisan('customers:terminate-inactive', ['--max-id' => 'abc'])->assertFailed();
        $this->assertSame('inactive', $customer->fresh()->status);
    }

    public function test_dry_run_counts_allowed_with_no_evidence_separately(): void
    {
        $this->customer('TI-12', 'inactive', false);

        $this->artisan('customers:terminate-inactive')
            ->expectsOutputToContain('reasons=allowed[onu_na]')
            ->expectsOutputToContain('allowed with no evidence (a signal was not applicable): 1')
            ->assertSuccessful();
    }

    public function test_ids_and_max_id_limit_the_selection(): void
    {
        $a = $this->customer('TI-07', 'inactive', false);
        $b = $this->customer('TI-08', 'inactive', false);

        $this->artisan('customers:terminate-inactive', ['--ids' => (string) $b->id])
            ->expectsOutputToContain('allow=1 deny=0')
            ->assertSuccessful();
        $this->artisan('customers:terminate-inactive', ['--max-id' => (string) $a->id])
            ->expectsOutputToContain('allow=1 deny=0')
            ->assertSuccessful();
    }
}
