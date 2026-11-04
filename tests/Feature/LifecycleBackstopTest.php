<?php

namespace Tests\Feature;

use App\Exceptions\LifecycleBackstopException;
use App\Jobs\SendLifecycleBackstopAlertJob;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\LegacySyncService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Services\OpsAlertService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4d: A10 model backstop (log mode default) + A8 legacy
 * importer terminate suppression.
 */
#[Group('blocking-core')]
class LifecycleBackstopTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Perangkat sudah dicabut teknisi, sesi PPPoE sisa router.';

    private int $seq = 0;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        config(['lifecycle_guard.backstop_mode' => 'log']);
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $this->router = Router::create(['name' => 'BS', 'ip_address' => '127.0.34.1', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
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

    private function customer(string $status, bool $online = false, int $checkedMinutesAgo = 2, array $extra = []): Customer
    {
        $n = ++$this->seq;
        $package = Package::firstOrCreate(['code' => 'BS-PKG'], ['name' => 'Backstop', 'price' => 100000]);

        return Customer::create(array_merge([
            'code' => 'BS-'.$n, 'name' => 'Nama Rahasia'.$n, 'phone' => '0815'.abs(crc32('bs'.$n)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-bs'.$n,
            'router_id' => $this->router->id, 'is_online' => $online,
            'pppoe_online_checked_at' => now()->subMinutes($checkedMinutesAgo),
        ], $extra));
    }

    private function violations(): int
    {
        return Activity::where('event', 'lifecycle_backstop_violation')->count();
    }

    private function onlineOnu(string $serial, int $collectedMinutesAgo = 3, string $state = 'online'): void
    {
        $olt = Olt::firstOrCreate(['code' => 'BS-OLT'], ['name' => 'BS OLT', 'vendor' => 'zte_c300']);
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:'.(++$this->seq), 'serial_number' => $serial,
            'state' => $state, 'phase_state' => $state === 'online' ? 'working' : 'LOS',
            'state_collected_at' => now()->subMinutes($collectedMinutesAgo),
        ]);
    }

    private function allowDecision(): GuardDecision
    {
        return GuardDecision::fromSignals('terminate', [
            GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_offline_fresh', ''),
            GuardSignal::clear(GuardSignal::ONU, 'onu_offline_fresh', ''),
            GuardSignal::clear(GuardSignal::TRAFFIC, 'traffic_none', ''),
        ]);
    }

    // ------------------------------------------------------------------
    // unwrapped writes: log / enforce by mode
    // ------------------------------------------------------------------

    public function test_unwrapped_terminate_of_fresh_online_customer_logs_in_log_mode_and_still_writes(): void
    {
        Log::spy();
        $customer = $this->customer('active', true);

        $customer->update(['status' => 'terminated']);

        $this->assertSame('terminated', $customer->fresh()->status, 'log mode never changes behaviour');
        $this->assertSame(1, $this->violations());
        $log = Activity::where('event', 'lifecycle_backstop_violation')->firstOrFail();
        $this->assertSame($customer->id, (int) $log->subject_id);
        $this->assertSame('terminate', $log->properties['attempt']);
        $this->assertSame(['pppoe_in_use'], $log->properties['reasons']);
        $json = json_encode($log->properties);
        $this->assertStringNotContainsString('Nama Rahasia', $json);
        $this->assertStringNotContainsString($customer->phone, $json);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'violation'))->once();
    }

    public function test_unwrapped_terminate_throws_in_enforce_mode_with_t9_text(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $customer = $this->customer('active', true);

        try {
            $customer->update(['status' => 'terminated']);
            $this->fail('expected LifecycleBackstopException');
        } catch (LifecycleBackstopException $e) {
            $this->assertStringContainsString('Pagar terminate: pelanggan '.$customer->code.' masih terdeteksi dipakai', $e->getMessage());
            $this->assertStringContainsString('perubahan ke terminated/arsip ditolak. Gunakan menu Pelanggan (dengan pagar) atau minta superadmin.', $e->getMessage());
        }

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(0, $this->violations(), 'enforce mode throws instead of logging a violation');
    }

    public function test_default_mode_is_log(): void
    {
        $this->assertSame('log', config('lifecycle_guard.backstop_mode'));
    }

    public function test_unwrapped_soft_delete_of_online_non_terminated_customer_is_checked(): void
    {
        $customer = $this->customer('isolated', true);
        $customer->delete();
        $this->assertSame(1, $this->violations());
        $this->assertTrue(Customer::withTrashed()->find($customer->id)->trashed());

        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $other = $this->customer('isolated', true);
        $this->expectException(LifecycleBackstopException::class);
        $other->delete();
    }

    public function test_fresh_online_onu_in_olt_snapshot_counts_as_in_use(): void
    {
        $customer = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEGBS000001']);
        $this->onlineOnu('ZTEGBS000001');

        $customer->update(['status' => 'terminated']);

        $log = Activity::where('event', 'lifecycle_backstop_violation')->firstOrFail();
        $this->assertSame(['onu_in_use'], $log->properties['reasons']);
    }

    public function test_offline_stale_and_unproven_customers_have_no_violation(): void
    {
        $offline = $this->customer('active', false);
        $offline->update(['status' => 'terminated']);

        $stale = $this->customer('active', true, 90); // online flag 90 min old = not proof
        $stale->update(['status' => 'terminated']);

        $staleOnu = $this->customer('active', false, 2, ['onu_serial' => 'ZTEGBS000002']);
        $this->onlineOnu('ZTEGBS000002', 90);
        $staleOnu->update(['status' => 'terminated']);

        $offlineOnu = $this->customer('active', false, 2, ['onu_serial' => 'ZTEGBS000003']);
        $this->onlineOnu('ZTEGBS000003', 3, 'offline');
        $offlineOnu->update(['status' => 'terminated']);

        $this->assertSame(0, $this->violations());
    }

    public function test_other_changes_and_already_terminated_rows_are_not_checked(): void
    {
        $customer = $this->customer('active', true);
        $customer->update(['name' => 'Nama Baru', 'status' => 'isolated']);
        $this->assertSame(0, $this->violations());

        $terminated = $this->customer('terminated', true);
        $terminated->update(['name' => 'Ganti']);
        $terminated->delete(); // archiving an already terminated row is not checked
        $this->assertSame(0, $this->violations());
    }

    public function test_creating_terminated_stub_rows_is_never_checked(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);

        $stub = $this->customer('terminated', true);

        $this->assertSame('terminated', $stub->fresh()->status);
        $this->assertSame(0, $this->violations());
    }

    public function test_allow_scope_is_per_customer_nested_and_always_popped(): void
    {
        $a = $this->customer('active', true);
        $b = $this->customer('active', true);

        LifecycleBackstop::allow([$a->id], 'test', function () use ($a, $b): void {
            $a->update(['status' => 'terminated']);
            $b->update(['status' => 'terminated']); // not in the allow list
        });
        $this->assertSame(1, $this->violations());

        $c = $this->customer('active', true);
        try {
            LifecycleBackstop::allow([$c->id], 'test', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertFalse(LifecycleBackstop::isAllowed($c->id), 'allow list popped after an exception');

        LifecycleBackstop::allow(null, 'outer', function () use ($c): void {
            $this->assertTrue(LifecycleBackstop::isAllowed($c->id));
            LifecycleBackstop::allow([], 'inner', fn () => $this->assertTrue(LifecycleBackstop::isAllowed($c->id)));
        });
        $this->assertFalse(LifecycleBackstop::isAllowed($c->id));
    }

    // ------------------------------------------------------------------
    // wrapped writers never trigger (enforce mode makes a miss throw)
    // ------------------------------------------------------------------

    public function test_lifecycle_termination_service_terminate_and_sweeper_finalize_are_wrapped(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $service = app(LifecycleTerminationService::class);

        $direct = $this->customer('inactive', true);
        DB::transaction(fn () => $service->terminateOrDefer($direct->fresh(), $this->allowDecision(), 'warehouse_receive'));
        $this->assertSame('terminated', $direct->fresh()->status);

        $swept = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEGBS000004']);
        $this->onlineOnu('ZTEGBS000004');
        $row = CustomerPendingTermination::create([
            'customer_id' => $swept->id, 'source' => 'warehouse_receive', 'status' => 'pending',
            'hold_billing' => true, 'requested_at' => now(),
        ]);
        $this->assertTrue($service->finalize($row, $this->allowDecision()));
        $this->assertSame('terminated', $swept->fresh()->status);
        $this->assertSame(0, $this->violations());
    }

    public function test_customer_controller_archive_and_status_update_override_are_wrapped(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $actor = User::factory()->create(['role' => 'superadmin']);

        $archive = $this->customer('isolated', true);
        $this->actingAs($actor)->delete(route('customers.destroy', $archive), [
            'override' => true, 'override_reason' => self::REASON,
        ])->assertRedirect(route('customers.index'));
        $this->assertNotNull(Customer::withTrashed()->find($archive->id)->deleted_at);

        $update = $this->customer('isolated', true);
        $this->actingAs($actor)->put(route('customers.update', $update), [
            'name' => $update->name, 'address' => $update->address, 'package_id' => $update->package_id,
            'status' => 'terminated', 'lifecycle_override' => true, 'lifecycle_override_reason' => self::REASON,
        ])->assertSessionHasNoErrors();
        $this->assertSame('terminated', $update->fresh()->status);

        $this->assertSame(0, $this->violations());
    }

    public function test_ticket_dismantle_approval_override_is_wrapped(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $customer = $this->customer('isolated', true);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'gangguan' => 'Dismantle pelanggan', 'type' => 'request', 'status' => 'closed',
        ]);
        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.request', $ticket), [
            'lifecycle_action' => 'dismantle', 'reason' => 'Perangkat sudah ditarik',
        ]);
        $request = TicketActivity::where('action', 'customer_lifecycle_requested')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]), [
                'override' => true, 'override_reason' => self::REASON,
            ])->assertSessionHasNoErrors();

        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertSame(0, $this->violations());
    }

    public function test_terminate_inactive_command_is_wrapped(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $idle = $this->customer('inactive', false);

        $this->artisan('customers:terminate-inactive', ['--execute' => true, '--actor' => (string) $actor->id])->assertSuccessful();

        $this->assertSame(0, $this->violations());
        $this->assertSame('terminated', $idle->fresh()->status);
    }

    // ------------------------------------------------------------------
    // review items: enforce rendering (M1), serial twin (L1), fail-open + CLIs (L4)
    // ------------------------------------------------------------------

    private function unwrappedRoute(): void
    {
        Route::middleware('web')->post('/__backstop-test/{id}', function (int $id) {
            Customer::findOrFail($id)->update(['status' => 'terminated']);

            return response('ok');
        });
    }

    public function test_enforce_exception_renders_t9_for_web_inertia_and_json_instead_of_500(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $this->unwrappedRoute();
        $customer = $this->customer('active', true);
        $admin = User::factory()->create(['role' => 'admin']);
        $t9 = 'Pagar terminate: pelanggan '.$customer->code.' masih terdeteksi dipakai';

        $json = $this->actingAs($admin)->postJson('/__backstop-test/'.$customer->id);
        $json->assertStatus(422);
        $this->assertStringContainsString($t9, (string) $json->json('message'));

        $web = $this->actingAs($admin)->withHeader('Referer', url('/customers'))->post('/__backstop-test/'.$customer->id);
        $web->assertRedirect();
        $web->assertSessionHasErrors('lifecycle_guard');
        $this->assertStringContainsString($t9, session('errors')->first('lifecycle_guard'));

        // Last: withHeaders persists on the test instance.
        $inertia = $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true', 'Referer' => url('/customers')])->post('/__backstop-test/'.$customer->id);
        $inertia->assertSessionHasErrors('lifecycle_guard');
        $this->assertStringContainsString($t9, session('errors')->first('lifecycle_guard'));

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_gpon_ascii_hex_twin_serial_counts_as_evidence(): void
    {
        $customer = $this->customer('inactive', false, 2, ['onu_serial' => 'FHTT0000A028']);
        $this->onlineOnu('464854540000A028');

        $customer->update(['status' => 'terminated']);

        $this->assertSame(1, $this->violations());
    }

    public function test_hook_fails_open_when_evidence_cannot_be_read_in_both_modes(): void
    {
        // Evidence exists (online ONU rows, seeded before the failing listener) but cannot be read: writes proceed.
        $this->onlineOnu('ZTEGBS00FAILL');
        $this->onlineOnu('ZTEGBS00FAILE');
        foreach (['log', 'enforce'] as $mode) {
            config(['lifecycle_guard.backstop_mode' => $mode]);
            // Offline PPPoE: the olt_onus read is the only possible evidence (N7 keeps PPPoE evidence separately).
            $customer = $this->customer('active', false, 2, ['onu_serial' => 'ZTEGBS00FAIL'.strtoupper($mode[0])]);            // The olt_onus evidence read throws inside the backstop.
            DB::listen(function ($query): void {
                if (str_contains($query->sql, 'olt_onus')) {
                    throw new \RuntimeException('evidence read failed');
                }
            });

            $customer->update(['status' => 'terminated']);

            $this->assertSame('terminated', $customer->fresh()->status, $mode.' mode fails open');
        }
        $this->assertSame(0, $this->violations());
    }

    public function test_serial_match_is_tolerant_of_dashes_spaces_and_case(): void
    {
        $cases = [
            ['ZTEG0000A007', 'ZTEG-0000-A007'],
            ['ZTEG0000A008', 'zteg0000a008'],
            ['ZTEG0000A009', ' ZTEG 0000 A009 '],
            ['zteg-0000-a00A', 'ZTEG0000A00A'],
            ['FHTT-0000 A028', '464854540000A028'], // separators on the customer side + GPON hex twin
            ['FHTT0000A029', '4648-5454 0000 A029'], // separators on the OLT side + GPON hex twin
        ];
        foreach ($cases as [$customerSerial, $oltSerial]) {
            $this->onlineOnu($oltSerial);
            $customer = $this->customer('inactive', false, 2, ['onu_serial' => $customerSerial]);
            $before = $this->violations();

            $customer->update(['status' => 'terminated']);

            $this->assertSame($before + 1, $this->violations(), "{$customerSerial} vs {$oltSerial}");
        }
    }

    public function test_tolerant_serial_match_keeps_negatives_negative(): void
    {
        $this->onlineOnu('ZTEG-0000-A00D', 3, 'offline'); // offline row
        $this->onlineOnu('ZTEG-0000-A00C', 90);           // stale row
        $this->onlineOnu('ZTEG-C0A1-DDDD');               // different serial
        $a = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEG0000A00D']);
        $b = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEG0000A00C']);
        $c = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEG0000A00B']);

        foreach ([$a, $b, $c] as $customer) {
            $customer->update(['status' => 'terminated']);
        }

        $this->assertSame(0, $this->violations());
    }

    public function test_tolerant_match_uses_a_constant_number_of_queries(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->onlineOnu('ZTEG-NOISE-'.$i);
        }
        $customer = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEGNOMATCH1']);
        $onuQueries = 0;
        DB::listen(function ($query) use (&$onuQueries): void {
            if (str_contains($query->sql, 'olt_onus')) {
                $onuQueries++;
            }
        });

        $customer->update(['status' => 'terminated']);

        $this->assertSame(2, $onuQueries, 'exact pass + one tolerant pass, no per-row queries');
    }

    public function test_exact_serial_hit_skips_the_tolerant_pass(): void
    {
        $this->onlineOnu('ZTEG0000A006');
        $customer = $this->customer('inactive', false, 2, ['onu_serial' => 'ZTEG0000A006']);
        $onuQueries = 0;
        DB::listen(function ($query) use (&$onuQueries): void {
            if (str_contains($query->sql, 'olt_onus')) {
                $onuQueries++;
            }
        });

        $customer->update(['status' => 'terminated']);

        $this->assertSame(1, $onuQueries);
        $this->assertSame(1, $this->violations());
    }

    public function test_olt_onus_read_failure_keeps_pppoe_evidence(): void
    {
        $customer = $this->customer('active', true, 2, ['onu_serial' => 'ZTEGBS00N7AA']);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'olt_onus')) {
                throw new \RuntimeException('evidence read failed');
            }
        });

        $customer->update(['status' => 'terminated']);

        $this->assertSame('terminated', $customer->fresh()->status, 'log mode still writes');
        $this->assertSame(1, $this->violations());
        $this->assertSame(['pppoe_in_use'], Activity::where('event', 'lifecycle_backstop_violation')->firstOrFail()->properties['reasons']);
    }

    public function test_olt_onus_read_failure_still_enforces_on_pppoe_evidence(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $customer = $this->customer('active', true, 2, ['onu_serial' => 'ZTEGBS00N7BB']);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'olt_onus')) {
                throw new \RuntimeException('evidence read failed');
            }
        });

        try {
            $customer->update(['status' => 'terminated']);
            $this->fail('enforce must refuse on PPPoE evidence');
        } catch (LifecycleBackstopException $e) {
            $this->assertSame(['pppoe_in_use'], $e->reasons);
        }
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_api_path_without_json_accept_gets_json_422_in_enforce(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        Route::middleware('web')->post('/api/__backstop-test/{id}', function (int $id) {
            Customer::findOrFail($id)->update(['status' => 'terminated']);

            return response('ok');
        });
        $customer = $this->customer('active', true);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->withHeader('Accept', 'text/html')->post('/api/__backstop-test/'.$customer->id);

        $response->assertStatus(422);
        $this->assertStringContainsString('Pagar terminate', (string) $response->json('message'));
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_shipped_default_is_log_mode_and_blocks_nothing(): void
    {
        $default = (require base_path('config/lifecycle_guard.php'))['backstop_mode'];
        $this->assertSame('log', $default, 'with the env var unset the backstop is log mode');

        config(['lifecycle_guard.backstop_mode' => $default]);
        $customer = $this->customer('active', true, 2, ['onu_serial' => 'ZTEGBS0DEF01']);
        $this->onlineOnu('ZTEGBS0DEF01');

        $customer->update(['status' => 'terminated']);
        $this->assertSame('terminated', $customer->fresh()->status);
        $customer->delete();
        $this->assertTrue(Customer::withTrashed()->find($customer->id)->trashed());

        $other = $this->customer('active', true);
        $other->delete();
        $this->assertTrue(Customer::withTrashed()->find($other->id)->trashed());
        $this->assertGreaterThanOrEqual(2, $this->violations(), 'violations are logged, never blocked');
    }

    private function refuse(Customer $customer): void
    {
        try {
            $customer->update(['status' => 'terminated']);
            $this->fail('enforce must refuse');
        } catch (LifecycleBackstopException) {
            // expected
        }
    }

    public function test_enforce_refusal_queues_one_job_and_never_calls_the_ops_service_inline(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        Queue::fake();
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldNotReceive('send');
        $this->app->instance(OpsAlertService::class, $ops);
        $customer = $this->customer('active', true);

        $this->refuse($customer);
        $this->refuse($customer->fresh()); // same customer same day: deduped

        Queue::assertPushed(SendLifecycleBackstopAlertJob::class, 1);
        Queue::assertPushed(SendLifecycleBackstopAlertJob::class, fn ($job): bool => $job->queue === 'wa' && str_contains($job->dedupeKey, 'lifecycle-backstop-enforce:'));
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_many_refusals_dispatch_a_bounded_number_of_alert_jobs(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce', 'lifecycle_guard.backstop_alert_per_source_hour' => 3, 'lifecycle_guard.backstop_alert_per_hour' => 10]);
        Queue::fake();
        for ($i = 0; $i < 25; $i++) {
            $this->refuse($this->customer('active', true));
        }

        // Same source (this test method) -> per-source cap 3.
        Queue::assertPushed(SendLifecycleBackstopAlertJob::class, 3);
    }

    public function test_global_hourly_cap_bounds_alerts_across_sources(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce', 'lifecycle_guard.backstop_alert_per_source_hour' => 50, 'lifecycle_guard.backstop_alert_per_hour' => 4]);
        Queue::fake();
        for ($i = 0; $i < 12; $i++) {
            $this->refuse($this->customer('active', true));
        }

        Queue::assertPushed(SendLifecycleBackstopAlertJob::class, 4);
    }

    public function test_log_mode_never_queues_an_alert(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'log']);
        Queue::fake();
        $customer = $this->customer('active', true);

        $customer->update(['status' => 'terminated']);

        Queue::assertNothingPushed();
    }

    public function test_enforce_refusal_still_throws_when_dispatch_fails(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $this->app->instance(Dispatcher::class, $bus);
        $customer = $this->customer('active', true);

        $this->refuse($customer);

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_alert_job_failure_is_swallowed(): void
    {
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldReceive('send')->once()->with('msg', 'key', 86400, '', 'lifecycle_backstop')->andThrow(new \RuntimeException('wa down'));

        (new SendLifecycleBackstopAlertJob('msg', 'key'))->handle($ops);

        $this->assertTrue(true);
    }

    private function archiveAllowGuard(): void
    {
        $decision = GuardDecision::fromSignals('archive', [
            GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_offline_fresh', ''),
            GuardSignal::clear(GuardSignal::ONU, 'onu_offline_fresh', ''),
            GuardSignal::clear(GuardSignal::TRAFFIC, 'traffic_none', ''),
        ]);
        $guard = Mockery::mock(CustomerLifecycleGuard::class);
        $guard->shouldReceive('canArchive')->andReturn($decision);
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
    }

    public function test_archive_clis_are_wrapped_under_enforce(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        // Guard (mocked) allows although local evidence says "online": only the wrapper keeps the write legal.
        $this->archiveAllowGuard();

        $imported = $this->customer('inactive', true, 2, ['code' => 'IMP-BS-1']);
        $this->artisan('customers:cleanup-imported', ['--apply' => true])->assertSuccessful();
        $this->assertTrue(Customer::withTrashed()->find($imported->id)->trashed());

        $delinquent = $this->customer('isolated', true);
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $period) {
            Invoice::create([
                'customer_id' => $delinquent->id, 'period' => $period, 'amount' => 100000, 'status' => 'unpaid',
                'due_date' => Carbon::parse($period)->day(20)->toDateString(), 'generated_at' => now(),
            ]);
        }
        $this->artisan('customers:cleanup-delinquent', ['--apply' => true])->assertSuccessful();
        $this->assertTrue(Customer::withTrashed()->find($delinquent->id)->trashed());

        $this->assertSame(0, $this->violations());
    }

    // ------------------------------------------------------------------
    // PSB pin: pending_installation customers never trip the backstop
    // ------------------------------------------------------------------

    public function test_psb_pending_installation_create_and_activation_never_trip_the_backstop(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $psb = $this->customer('pending_installation', true, 2, ['onu_serial' => 'ZTEGBS000005']);
        $this->onlineOnu('ZTEGBS000005');

        $psb->update(['status' => 'active']);

        $this->assertSame('active', $psb->fresh()->status);
        $this->assertSame(0, $this->violations());
    }

    // ------------------------------------------------------------------
    // A8 legacy importer suppression
    // ------------------------------------------------------------------

    private function legacyRow(string $code, string $status): array
    {
        return [
            'id' => $code, 'name' => 'Legacy '.$code, 'phone' => '0800', 'address' => 'Addr', 'pppoe_user' => 'lg-'.$code,
            'status' => $status, 'join_date' => '2026-01-01', 'due_day' => 20, 'is_online' => false,
            'geo_lat' => null, 'geo_long' => null, 'ktp_photo_url' => null,
            'area' => ['name' => 'SUBNET-WAJAK'], 'package' => ['name' => 'Package A'],
        ];
    }

    public function test_legacy_sync_keeps_status_of_live_customers_and_reports_them(): void
    {
        $live = $this->customer('active', false, 2, ['code' => 'LEG-LIVE']);
        $isolated = $this->customer('isolated', false, 2, ['code' => 'LEG-ISO']);
        $terminated = $this->customer('terminated', false, 2, ['code' => 'LEG-TERM']);
        $archived = $this->customer('inactive', false, 2, ['code' => 'LEG-ARCH']);
        $archived->delete();

        Http::fake(['*/api/v1/customers' => Http::response([
            $this->legacyRow('LEG-LIVE', 'deleted'),
            $this->legacyRow('LEG-ISO', 'terminated'),
            $this->legacyRow('LEG-TERM', 'deleted'),
            $this->legacyRow('LEG-ARCH', 'deleted'),
            $this->legacyRow('LEG-NEW', 'deleted'),
        ])]);

        $liveWrites = [];
        DB::listen(function ($query) use (&$liveWrites): void {
            $sql = str_replace(['`', '"'], '', $query->sql);
            if (str_starts_with($sql, 'update customers') && in_array('LEG-LIVE', $query->bindings, true)) {
                $liveWrites[] = $sql;
            }
        });

        $service = app(LegacySyncService::class);
        $this->assertSame(5, $service->syncCustomers());

        // L3: the suppressed row is written by an update WITHOUT status/deleted_at columns.
        $this->assertNotEmpty($liveWrites);
        foreach ($liveWrites as $sql) {
            $this->assertStringNotContainsString('status =', $sql);
            $this->assertStringNotContainsString('deleted_at =', $sql);
        }

        $liveFresh = Customer::withTrashed()->find($live->id);
        $this->assertSame('active', $liveFresh->status);
        $this->assertNull($liveFresh->deleted_at);
        $this->assertSame('Legacy LEG-LIVE', $liveFresh->name, 'other fields still sync');
        $isoFresh = Customer::withTrashed()->find($isolated->id);
        $this->assertSame('isolated', $isoFresh->status);
        $this->assertNull($isoFresh->deleted_at);

        $this->assertEqualsCanonicalizing([$live->id, $isolated->id], $service->lastTerminateSuppressedIds());

        // Unchanged behaviour: already-terminated / archived / brand-new rows follow legacy.
        $termFresh = Customer::withTrashed()->find($terminated->id);
        $this->assertSame('terminated', $termFresh->status);
        $this->assertNotNull($termFresh->deleted_at);
        $this->assertTrue(Customer::withTrashed()->find($archived->id)->trashed());
        $new = Customer::withTrashed()->where('code', 'LEG-NEW')->firstOrFail();
        $this->assertSame('terminated', $new->status);
        $this->assertNotNull($new->deleted_at);
    }

    public function test_legacy_sync_still_applies_non_terminal_status_changes(): void
    {
        $customer = $this->customer('active', false, 2, ['code' => 'LEG-ACT']);
        Http::fake(['*/api/v1/customers' => Http::response([$this->legacyRow('LEG-ACT', 'isolated')])]);

        $service = app(LegacySyncService::class);
        $service->syncCustomers();

        $this->assertSame('isolated', $customer->fresh()->status);
        $this->assertSame([], $service->lastTerminateSuppressedIds());
    }

    public function test_staging_db_sync_keeps_live_customers_and_creates_terminated_rows_for_new_codes(): void
    {
        $live = $this->customer('active', false, 2, ['code' => 'STG-LIVE']);
        $path = tempnam(sys_get_temp_dir(), 'stg').'.db';
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE areas (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE packages (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, customer_id TEXT, period TEXT)');
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, code TEXT, name TEXT, address TEXT, phone TEXT, nik TEXT, pppoe_username TEXT, area_id INTEGER, package_id INTEGER, status TEXT, geo_lat REAL, geo_long REAL, join_date TEXT, due_day INTEGER, ktp_photo_url TEXT, is_online INTEGER)');
        $pdo->exec("INSERT INTO packages (id, name, price) VALUES (1, 'Package A', 100000)");
        $pdo->exec("INSERT INTO customers (code, name, address, phone, pppoe_username, package_id, status, due_day, is_online) VALUES ('STG-LIVE', 'Stg Live', 'A', '1', 'stg-live', 1, 'deleted', 20, 0)");
        $pdo->exec("INSERT INTO customers (code, name, address, phone, pppoe_username, package_id, status, due_day, is_online) VALUES ('STG-NEW', 'Stg New', 'A', '1', 'stg-new', 1, 'deleted', 20, 0)");
        unset($pdo);

        try {
            $this->artisan('ebilling:sync', ['--db' => $path])
                ->expectsOutputToContain('legacy_terminate_suppressed=1')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertSame('active', $live->fresh()->status);
        $this->assertSame('terminated', Customer::where('code', 'STG-NEW')->firstOrFail()->status);
    }
}
