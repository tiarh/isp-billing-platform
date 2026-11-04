<?php

namespace Tests\Feature;

use App\Http\Controllers\OltController;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Services\OltHiosoService;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-2 (B6): the OLT UI "Hapus ONU" is behind the lifecycle guard.
 * deny/unknown => 409 with the guard message and NO device command; only a
 * superadmin with a written reason (min 20) may override, logged to the
 * activity log.
 */
#[Group('blocking-core')]
class OltOnuDeleteLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private const REF = '1/2/1:3';

    private const SERIAL = 'FHTT0000A004';

    /** @var list<string> */
    private array $deviceDeletes = [];

    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $this->deviceDeletes = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function seedOlt(string $vendor = 'zte_c300', string $liveState = 'offline'): void
    {
        $this->olt = Olt::create(['name' => 'OLT UI', 'code' => 'OLT-UI', 'vendor' => $vendor]);
        OltOnu::create([
            'olt_id' => $this->olt->id, 'onu_ref' => self::REF, 'serial_number' => self::SERIAL,
            'state' => 'offline', 'phase_state' => 'DyingGasp', 'state_collected_at' => now()->subMinutes(2),
        ]);

        $live = Mockery::mock(OnuLiveReadService::class);
        if (str_contains($vendor, 'hioso')) {
            $live->shouldNotReceive('read');
        } else {
            $live->shouldReceive('read')->andReturn(['ok' => true, 'absent' => false, 'serial' => self::SERIAL, 'state' => $liveState, 'label' => $liveState]);
        }
        $this->app->instance(OnuLiveReadService::class, $live);

        $service = fn (string $class) => Mockery::mock($class);
        $capture = function (string $onuRef): array {
            $this->deviceDeletes[] = $onuRef;

            return ['success' => true];
        };
        $zte = $service(OltZteC300Service::class);
        $zte->shouldReceive('deleteOnu')->andReturnUsing(fn ($olt, $ref) => $capture($ref));
        $this->app->instance(OltZteC300Service::class, $zte);
        $hioso = $service(OltHiosoService::class);
        $hioso->shouldReceive('deleteOnu')->andReturnUsing(fn ($olt, $ref) => $capture($ref));
        $this->app->instance(OltHiosoService::class, $hioso);
    }

    private function customer(array $o = []): Customer
    {
        $router = Router::create([
            'name' => 'UI router', 'ip_address' => '127.0.31.1', 'username' => 't', 'password' => 't', 'is_active' => true,
        ]);
        $package = Package::firstOrCreate(['code' => 'UI-PKG'], ['name' => 'UI', 'price' => 0]);

        return Customer::create([
            'code' => 'UI-1', 'name' => 'UI customer', 'phone' => '080000004', 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => 'isolated', 'pppoe_user' => 'ui-user-1',
            'router_id' => $router->id, 'olt_id' => $this->olt->id, 'onu_serial' => self::SERIAL,
            'is_online' => $o['is_online'] ?? false,
            'pppoe_online_checked_at' => now()->subMinutes(2),
        ]);
    }

    private function rollupAlive(Customer $customer): void
    {
        foreach (['2026-10-14', '2026-10-13'] as $i => $day) {
            DB::table('pppoe_usage_daily')->insert([
                'usage_date_wib' => $day, 'router_id' => $customer->router_id, 'pppoe_user' => 'heartbeat-'.$i,
                'customer_id' => null, 'download_bytes' => 0, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
                'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
            ]);
        }
    }

    private function deleteViaUi(?User $user, array $payload = []): JsonResponse
    {
        $this->actingAs($user ?? User::factory()->create(['role' => 'admin']));
        $request = Request::create('/api/olts/'.$this->olt->id.'/onu', 'DELETE', ['onu_ref' => self::REF] + $payload);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('olt_provisioning_gate_checked', true);

        $response = app(OltController::class)->deleteOnu($request, $this->olt);
        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // ------------------------------------------------------------------

    public function test_guard_deny_returns_409_with_message_and_sends_no_device_command(): void
    {
        $this->seedOlt();
        $this->denyLifecycleGuard();

        $response = $this->deleteViaUi($this->admin());
        $data = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('lifecycle_guard_denied', $data['error_code']);
        $this->assertStringContainsString('Sesi PPPoE masih aktif', $data['message']);
        $this->assertFalse($data['can_override']);
        $this->assertSame([], $this->deviceDeletes);
        $this->assertSame(1, OltOnu::query()->count());
    }

    public function test_superadmin_sees_can_override_on_the_409(): void
    {
        $this->seedOlt();
        $this->denyLifecycleGuard();

        $data = $this->deleteViaUi($this->admin('superadmin'))->getData(true);

        $this->assertTrue($data['can_override']);
        $this->assertSame(20, $data['override_reason_min']);
    }

    public function test_non_superadmin_cannot_override(): void
    {
        $this->seedOlt();
        $this->denyLifecycleGuard();

        $response = $this->deleteViaUi($this->admin(), ['override' => true, 'override_reason' => str_repeat('alasan ', 5)]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('lifecycle_guard_override_superadmin_required', $response->getData(true)['error_code']);
        $this->assertSame([], $this->deviceDeletes);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_superadmin_override_needs_a_reason_of_at_least_20_chars(): void
    {
        $this->seedOlt();
        $this->denyLifecycleGuard();

        $response = $this->deleteViaUi($this->admin('superadmin'), ['override' => true, 'override_reason' => 'terlalu pendek']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->deviceDeletes);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_superadmin_override_with_reason_deletes_and_writes_the_activity_log(): void
    {
        $this->seedOlt();
        $this->denyLifecycleGuard();
        $su = $this->admin('superadmin');
        $reason = 'Pelanggan sudah cabut, diverifikasi teknisi lapangan.';

        $response = $this->deleteViaUi($su, ['override' => true, 'override_reason' => $reason]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::REF], $this->deviceDeletes);
        $log = Activity::query()->where('event', 'olt_onu_delete_guard_override')->firstOrFail();
        $this->assertSame($su->id, (int) $log->causer_id);
        $this->assertSame($reason, $log->properties['reason']);
        $this->assertSame(self::REF, $log->properties['onu_ref']);
        $this->assertSame('deny', $log->properties['guard']['verdict']);
    }

    public function test_override_flag_is_ignored_when_the_guard_allows(): void
    {
        $this->seedOlt();
        $this->allowLifecycleGuard();

        $response = $this->deleteViaUi($this->admin(), ['override' => true, 'override_reason' => 'x']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::REF], $this->deviceDeletes);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_suharto_regression_online_customer_with_offline_looking_onu_is_refused_by_the_real_guard(): void
    {
        $this->seedOlt();
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $this->customer(['is_online' => true]);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Sesi PPPoE masih aktif', $response->getData(true)['message']);
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_suharto_terminated_and_soft_deleted_owner_with_traffic_yesterday_is_refused(): void
    {
        $this->seedOlt();
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $customer = $this->customer();
        $this->rollupAlive($customer);
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => '2026-10-13', 'router_id' => $customer->router_id, 'pppoe_user' => 'ui-user-1',
            'customer_id' => $customer->id, 'download_bytes' => 50 * 1048576, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
            'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
        ]);
        $customer->update(['status' => 'terminated']);
        $customer->delete();

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('dalam 2 hari terakhir', $response->getData(true)['message']);
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_owner_stored_in_the_hex_gpon_form_is_found(): void
    {
        $this->seedOlt();
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $customer = $this->customer(['is_online' => true]);
        $customer->update(['onu_serial' => '464854540000A004']);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Sesi PPPoE masih aktif', $response->getData(true)['message']);
    }

    public function test_stale_inventory_serial_and_live_serial_owners_are_both_checked(): void
    {
        $this->seedOlt();
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        // The ref now holds another ONU (live serial differs from the inventory row).
        $live = Mockery::mock(OnuLiveReadService::class);
        $live->shouldReceive('read')->andReturn(['ok' => true, 'absent' => false, 'serial' => 'FHTT0000A006', 'state' => 'offline', 'label' => 'offline']);
        $this->app->instance(OnuLiveReadService::class, $live);
        $customer = $this->customer(['is_online' => true]);
        $customer->update(['onu_serial' => 'FHTT0000A006']);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_no_serial_at_all_is_unknown_not_allowed(): void
    {
        $this->seedOlt();
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        OltOnu::query()->delete();
        $live = Mockery::mock(OnuLiveReadService::class);
        $live->shouldReceive('read')->andReturn(['ok' => true, 'absent' => false, 'serial' => null, 'state' => 'offline', 'label' => 'offline']);
        $this->app->instance(OnuLiveReadService::class, $live);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('unknown', $response->getData(true)['guard']['verdict']);
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_guard_exception_is_a_409_unknown_not_a_500(): void
    {
        $this->seedOlt();
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canDeleteOnuRef')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $this->customer();

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_live_onu_online_is_refused_by_the_real_guard(): void
    {
        $this->seedOlt('zte_c300', 'online');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_hioso_unknown_onu_is_tolerated_when_pppoe_off_and_traffic_zero_d1(): void
    {
        $this->seedOlt('hioso_htb');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);
        $customer = $this->customer();
        $this->rollupAlive($customer);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::REF], $this->deviceDeletes);
    }

    public function test_hioso_with_traffic_in_the_last_two_days_is_refused(): void
    {
        $this->seedOlt('hioso_htb');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $customer = $this->customer();
        $this->rollupAlive($customer);
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => '2026-10-13', 'router_id' => $customer->router_id, 'pppoe_user' => 'ui-user-1',
            'customer_id' => $customer->id, 'download_bytes' => 50 * 1048576, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
            'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
        ]);

        $response = $this->deleteViaUi($this->admin());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('dalam 2 hari terakhir', $response->getData(true)['message']);
        $this->assertSame([], $this->deviceDeletes);
    }

    public function test_frontend_contract_override_textarea_checkbox_and_red_button(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Olts/Show.tsx'));

        foreach ([
            "data.error_code === 'lifecycle_guard_denied'",
            'data.can_override === true',
            'override: true, override_reason: deleteOverrideReason.trim()',
            'id="delete-override-reason"',
            'Tetap hapus (override)',
            'variant="destructive"',
            'deleteOverrideChecked',
        ] as $needle) {
            $this->assertStringContainsString($needle, $show);
        }
        // The phrase gate stays: DELETE ONU is still required next to the override.
        $this->assertStringContainsString('live_confirm: deleteConfirmation', $show);
    }

    public function test_guard_is_asked_in_manual_mode_not_automated_with_the_vendor(): void
    {
        $this->seedOlt();
        $this->customer();
        $seen = [];
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canDeleteOnuRef')->andReturnUsing(function (string $mode, $customer, $read, GuardContext $ctx, $twin, $vendor) use (&$seen) {
            $seen = [$mode, $customer?->onu_serial, $read['state'], $ctx->automated, $vendor];

            return GuardDecision::fromSignals('delete_onu_manual', []);
        });
        $this->app->instance(CustomerLifecycleGuard::class, $guard);

        $this->assertSame(200, $this->deleteViaUi($this->admin())->getStatusCode());
        $this->assertSame(['manual', self::SERIAL, 'offline', false, 'zte_c300'], $seen);
    }
}
