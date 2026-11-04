<?php

namespace Tests\Feature;

use App\Http\Controllers\OltController;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltOperationLog;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\GenieAcsService;
use App\Services\HuaweiOnuMaintenanceService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Olt\OltProvisioningGuard;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Huawei ONU delete / rename / reboot from the OLT page: superadmin only,
 * behind the `huawei_olt_ui_write_enabled` setting. The maintenance service is
 * mocked (its SSH transcripts are covered by HuaweiOnuMaintenanceServiceTest).
 */
#[Group('blocking-billing')]
#[Group('blocking-olt-mysql')]
class OltHuaweiUiWriteTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private const REF = '0/0/15:2';

    private const SERIAL = 'HWTC0000A005';

    private Olt $olt;

    /** @var list<array{string, array<int, mixed>}> */
    private array $serviceCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serviceCalls = [];
        $this->olt = Olt::create(['name' => 'GADANG', 'code' => 'GDG', 'vendor' => 'huawei_ma5608t']);
        OltOnu::create([
            'olt_id' => $this->olt->id, 'onu_ref' => self::REF, 'serial_number' => self::SERIAL,
            'onu_name' => 'Huawei-15-2', 'state' => 'offline', 'phase_state' => 'DyingGasp',
        ]);
        $live = Mockery::mock(OnuLiveReadService::class);
        $live->shouldReceive('read')->andReturn(['ok' => true, 'absent' => false, 'serial' => self::SERIAL, 'state' => 'offline', 'label' => 'offline']);
        $this->app->instance(OnuLiveReadService::class, $live);
        $this->allowLifecycleGuard();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function enableFlag(bool $on = true): void
    {
        Setting::set('huawei_olt_ui_write_enabled', $on ? '1' : '0');
        Cache::flush();
    }

    private function user(string $role = 'superadmin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @param array<string, mixed> $overrides */
    private function mockService(array $overrides = []): void
    {
        $mock = Mockery::mock(HuaweiOnuMaintenanceService::class);
        $record = function (string $name) {
            return function (...$args) use ($name) {
                $this->serviceCalls[] = [$name, $args];
            };
        };
        $mock->shouldReceive('deleteOnuWithServicePorts')->andReturnUsing(function (...$args) use ($overrides, $record) {
            $record('delete')(...$args);

            return $overrides['delete'] ?? [
                'success' => true, 'device_write' => true, 'verified_absent' => true, 'already_absent' => false,
                'save_pending' => false, 'removed_service_ports' => [['index' => 2, 'vlan' => 101, 'gemport' => 1, 'user_vlan' => 101, 'state' => 'up']],
                'rebuild_hint' => ['service-port vlan 101 gpon 0/0/15 ont 2 gemport 1 multi-service user-vlan 101'],
            ];
        });
        $mock->shouldReceive('renameOnuDescription')->andReturnUsing(function (...$args) use ($overrides, $record) {
            $record('rename')(...$args);

            return $overrides['rename'] ?? [
                'success' => true, 'device_write' => true, 'verified_name' => true, 'unchanged' => false,
                'save_pending' => false, 'old_name' => 'Huawei-15-2', 'name' => $args[3],
            ];
        });
        $mock->shouldReceive('rebootOnuChecked')->andReturnUsing(function (...$args) use ($overrides, $record) {
            $record('reboot')(...$args);

            return $overrides['reboot'] ?? ['success' => true, 'device_write' => true, 'reset_sent' => true, 'verified_registered' => true];
        });
        $mock->shouldReceive('saveConfig')->andReturnUsing(function (...$args) use ($record) {
            $record('save')(...$args);

            return ['success' => true, 'device_write' => true, 'save_pending' => false];
        });
        $this->app->instance(HuaweiOnuMaintenanceService::class, $mock);

        $acs = Mockery::mock(GenieAcsService::class);
        $acs->shouldReceive('pushTagForSerial')->andReturn(['pushed' => true]);
        $this->app->instance(GenieAcsService::class, $acs);
    }

    private function act(string $action, ?User $user, array $payload = [], ?Olt $olt = null): JsonResponse
    {
        $olt ??= $this->olt;
        $user ??= $this->user();
        $this->actingAs($user);
        $method = $action === 'delete' ? 'DELETE' : 'POST';
        $request = Request::create('/api/olts/'.$olt->id.'/x', $method, $payload);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('olt_provisioning_gate_checked', true);

        $controller = app(OltController::class);
        $response = match ($action) {
            'delete' => $controller->deleteOnu($request, $olt),
            'rename' => $controller->renameOnu($request, $olt),
            'reboot' => $controller->rebootOnu($request, $olt),
            'save' => $controller->saveConfig($request, $olt),
        };
        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    // ------------------------------------------------------------------ gates

    public function test_flag_off_returns_423_for_every_action_and_never_calls_the_service(): void
    {
        $this->mockService();
        foreach ([
            ['delete', ['onu_ref' => self::REF]],
            ['rename', ['onu_ref' => self::REF, 'name' => 'NEW_NAME']],
            ['reboot', ['onu_ref' => self::REF]],
            ['save', []],
        ] as [$action, $payload]) {
            $response = $this->act($action, null, $payload);
            $this->assertSame(423, $response->getStatusCode(), $action);
            $this->assertSame('huawei_ui_write_disabled', $response->getData(true)['error_code']);
        }
        $this->assertSame([], $this->serviceCalls);
        $this->assertSame(1, OltOnu::query()->count());
    }

    public function test_non_superadmin_gets_403_even_with_flag_on(): void
    {
        $this->enableFlag();
        $this->mockService();
        foreach (['admin', 'noc'] as $role) {
            foreach ([
                ['delete', ['onu_ref' => self::REF]],
                ['rename', ['onu_ref' => self::REF, 'name' => 'NEW_NAME']],
                ['reboot', ['onu_ref' => self::REF]],
                ['save', []],
            ] as [$action, $payload]) {
                $response = $this->act($action, $this->user($role), $payload);
                $this->assertSame(403, $response->getStatusCode(), $role.' '.$action);
                $this->assertSame('superadmin_required', $response->getData(true)['error_code']);
            }
        }
        $this->assertSame([], $this->serviceCalls);
    }

    public function test_missing_inventory_serial_is_422_for_every_action(): void
    {
        $this->enableFlag();
        $this->mockService();
        OltOnu::query()->update(['serial_number' => null]);

        foreach ([
            ['delete', ['onu_ref' => self::REF]],
            ['rename', ['onu_ref' => self::REF, 'name' => 'NEW_NAME']],
            ['reboot', ['onu_ref' => self::REF]],
        ] as [$action, $payload]) {
            $response = $this->act($action, null, $payload);
            $this->assertSame(422, $response->getStatusCode(), $action);
            $this->assertSame('serial_unknown', $response->getData(true)['error_code']);
        }
        $this->assertSame([], $this->serviceCalls);
    }

    public function test_guard_denies_delete_with_409_and_superadmin_override_passes_allow_online(): void
    {
        $this->enableFlag();
        $this->mockService();
        $this->denyLifecycleGuard();

        $denied = $this->act('delete', null, ['onu_ref' => self::REF]);
        $this->assertSame(409, $denied->getStatusCode());
        $this->assertSame('lifecycle_guard_denied', $denied->getData(true)['error_code']);
        $this->assertSame([], $this->serviceCalls);

        $ok = $this->act('delete', null, [
            'onu_ref' => self::REF, 'override' => true, 'override_reason' => 'Pelanggan sudah cabut, diverifikasi teknisi lapangan.',
        ]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertCount(1, $this->serviceCalls);
        $this->assertTrue($this->serviceCalls[0][1][3], 'allowOnline only through the override');
        $this->assertSame(1, Activity::query()->where('event', 'olt_onu_delete_guard_override')->count());
    }

    public function test_busy_psb_lock_gives_409_and_never_calls_the_service(): void
    {
        $this->enableFlag();
        $this->mockService();
        $psb = Cache::lock('psb-huawei-olt-'.$this->olt->id, 60);
        $this->assertTrue($psb->get());

        try {
            foreach ([
                ['delete', ['onu_ref' => self::REF]],
                ['rename', ['onu_ref' => self::REF, 'name' => 'NEW_NAME']],
                ['reboot', ['onu_ref' => self::REF]],
            ] as [$action, $payload]) {
                $response = $this->act($action, null, $payload);
                $this->assertSame(409, $response->getStatusCode(), $action);
                $this->assertSame('olt_busy', $response->getData(true)['error_code']);
            }
        } finally {
            $psb->release();
        }
        $this->assertSame([], $this->serviceCalls);
        $this->assertSame(1, OltOnu::query()->count());
    }

    // ----------------------------------------------------------------- delete

    public function test_delete_success_purges_inventory_logs_and_writes_activity(): void
    {
        $this->enableFlag();
        $this->mockService();
        $su = $this->user();

        $response = $this->act('delete', $su, ['onu_ref' => self::REF]);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['delete', [$this->olt->id, self::REF, self::SERIAL, false]]], array_map(
            fn ($c) => [$c[0], [$c[1][0]->id, $c[1][1], $c[1][2], $c[1][3]]],
            $this->serviceCalls,
        ));
        $this->assertSame(1, $data['db_purged']);
        $this->assertSame(0, OltOnu::query()->count());
        $log = OltOperationLog::query()->where('operation', 'delete_onu')->firstOrFail();
        $this->assertSame('success', $log->status);
        $activity = Activity::query()->where('event', 'olt_onu_huawei_ui_delete')->firstOrFail();
        $this->assertSame($su->id, (int) $activity->causer_id);
        $this->assertSame(self::SERIAL, $activity->properties['serial']);
        $this->assertSame([2], array_column($activity->properties['removed_service_ports'], 'index'));
    }

    public function test_delete_not_verified_absent_does_not_purge(): void
    {
        $this->enableFlag();
        $this->mockService(['delete' => [
            'success' => true, 'device_write' => true, 'verified_absent' => false, 'readback_pending' => true,
            'save_pending' => true, 'removed_service_ports' => [],
        ]]);

        $data = $this->act('delete', null, ['onu_ref' => self::REF])->getData(true);

        $this->assertSame(0, $data['db_purged']);
        $this->assertSame(1, OltOnu::query()->count());
        $this->assertNotEmpty($data['warnings']);
    }

    public function test_delete_failure_maps_status_and_keeps_inventory(): void
    {
        $this->enableFlag();
        $this->mockService(['delete' => [
            'success' => false, 'device_write' => true, 'partial' => true, 'error_code' => 'service_port_still_present',
            'message' => 'x', 'removed_service_ports' => [],
        ]]);

        $response = $this->act('delete', null, ['onu_ref' => self::REF]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, OltOnu::query()->count());
        $this->assertSame('failed', OltOperationLog::query()->where('operation', 'delete_onu')->firstOrFail()->status);
    }

    public function test_partial_removal_is_409_with_rebuild_hint_in_response_and_activity_log(): void
    {
        $this->enableFlag();
        $hint = ['service-port vlan 101 gpon 0/0/15 ont 2 gemport 1 multi-service user-vlan 101 inbound traffic-table index 7'];
        $this->mockService(['delete' => [
            'success' => false, 'device_write' => true, 'partial' => true, 'error_code' => 'partial_service_ports_removed',
            'message' => '1 service-port sudah dihapus, ONU belum terhapus — periksa/kembalikan dengan petunjuk berikut',
            'removed_service_ports' => [['index' => 2]], 'rebuild_hint' => $hint,
        ]]);

        $response = $this->act('delete', null, ['onu_ref' => self::REF]);
        $data = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame($hint, $data['rebuild_hint']);
        $this->assertSame(1, OltOnu::query()->count());
        $activity = Activity::query()->where('event', 'olt_onu_huawei_ui_delete')->firstOrFail();
        $this->assertSame($hint, $activity->properties['rebuild_hint']);
        $this->assertSame('partial_service_ports_removed', $activity->properties['error_code']);
    }

    public function test_delete_keeps_a_row_whose_serial_belongs_to_a_newer_onu(): void
    {
        $this->enableFlag();
        $this->mockService();
        // Inventory serial is what the controller pins; a different row for the same ref/serial is the only purge target.
        OltOnu::query()->update(['serial_number' => self::SERIAL]);
        $other = OltOnu::create(['olt_id' => $this->olt->id, 'onu_ref' => '0/0/15:3', 'serial_number' => 'HWTC0000A003']);

        $this->act('delete', null, ['onu_ref' => self::REF]);

        $this->assertNotNull(OltOnu::query()->find($other->id));
    }

    // ----------------------------------------------------------------- rename

    public function test_rename_success_updates_inventory_acs_and_warns_on_pppoe_difference(): void
    {
        $this->enableFlag();
        $this->mockService();
        $router = Router::create(['name' => 'R', 'ip_address' => '192.0.2.10', 'username' => 't', 'password' => 'canary-pw', 'is_active' => true]);
        $package = Package::firstOrCreate(['code' => 'HW-PKG'], ['name' => 'HW', 'price' => 0]);
        Customer::create([
            'code' => 'HW-1', 'name' => 'USER MUZ', 'phone' => '080000005', 'address' => 'RW 1', 'package_id' => $package->id,
            'status' => 'active', 'pppoe_user' => 'usermuz2_rt01_rw01', 'router_id' => $router->id, 'olt_id' => $this->olt->id,
            'onu_serial' => self::SERIAL,
        ]);

        $response = $this->act('rename', null, ['onu_ref' => self::REF, 'name' => 'usermuz2 rt03']);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('USERMUZ2_RT03', $data['name']);
        $this->assertTrue($data['inventory_updated']);
        $this->assertTrue($data['acs_synced']);
        $this->assertSame('USERMUZ2_RT01_RW01', $data['suggested_name']);
        $this->assertNotEmpty($data['warnings'], 'name warning is advisory, not blocking');
        $row = OltOnu::query()->firstOrFail();
        $this->assertSame('USERMUZ2_RT03', $row->onu_name);
        $this->assertSame('manual_rename', $row->name_source);
        $this->assertSame(1, Activity::query()->where('event', 'olt_onu_huawei_ui_rename')->count());
    }

    public function test_rename_not_verified_leaves_inventory_untouched(): void
    {
        $this->enableFlag();
        $this->mockService(['rename' => [
            'success' => false, 'device_write' => true, 'error_code' => 'rename_not_verified', 'message' => 'x',
        ]]);

        $response = $this->act('rename', null, ['onu_ref' => self::REF, 'name' => 'NEW_NAME']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Huawei-15-2', OltOnu::query()->firstOrFail()->onu_name);
        $this->assertSame(0, Activity::query()->where('event', 'olt_onu_huawei_ui_rename')->count());
    }

    public function test_rename_sn_that_disagrees_with_inventory_is_refused(): void
    {
        $this->enableFlag();
        $this->mockService();

        $response = $this->act('rename', null, ['onu_ref' => self::REF, 'sn' => 'HWTC0000A004', 'name' => 'NEW_NAME']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->serviceCalls);
    }

    // ----------------------------------------------------------------- reboot

    public function test_reboot_success_passes_inventory_serial_and_logs(): void
    {
        $this->enableFlag();
        $this->mockService();

        $response = $this->act('reboot', null, ['onu_ref' => self::REF]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reboot', $this->serviceCalls[0][0]);
        $this->assertSame(self::SERIAL, $this->serviceCalls[0][1][2]);
        $this->assertSame('success', OltOperationLog::query()->where('operation', 'reboot_onu')->firstOrFail()->status);
        $this->assertSame(1, Activity::query()->where('event', 'olt_onu_huawei_ui_reboot')->count());
    }

    public function test_save_config_is_huawei_only_and_runs_through_the_service(): void
    {
        $this->enableFlag();
        $this->mockService();

        $this->assertSame(200, $this->act('save', null)->getStatusCode());
        $this->assertSame('save', $this->serviceCalls[0][0]);

        $zte = Olt::create(['name' => 'ZTE', 'code' => 'ZTE', 'vendor' => 'zte_c300']);
        $this->assertSame(422, $this->act('save', null, [], $zte)->getStatusCode());
        $this->assertCount(1, $this->serviceCalls);
    }

    public function test_save_pending_is_persisted_until_a_save_succeeds(): void
    {
        $this->enableFlag();
        $this->mockService(['delete' => [
            'success' => true, 'device_write' => true, 'verified_absent' => true, 'save_pending' => true, 'removed_service_ports' => [],
        ]]);
        $key = OltController::huaweiSavePendingKey($this->olt);

        $this->act('delete', null, ['onu_ref' => self::REF]);
        $this->assertNotNull(Cache::get($key));

        $this->act('save', null);
        $this->assertNull(Cache::get($key));
    }

    public function test_save_config_route_requires_the_typed_phrase(): void
    {
        $this->assertSame('SAVE OLT', OltProvisioningGuard::liveConfirmationFor('api.olts.save-config'));
        $this->assertStringContainsString('/save-config', route('api.olts.save-config', $this->olt->id));
    }

    // ------------------------------------------------------------ non-Huawei

    public function test_zte_delete_ignores_the_huawei_flag_and_uses_its_own_service(): void
    {
        $this->assertFalse(OltController::huaweiUiWriteEnabled());
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $this->allowLifecycleGuard();
        $zteOlt = Olt::create(['name' => 'ZTE', 'code' => 'ZTE', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $zteOlt->id, 'onu_ref' => '1/2/1:3', 'serial_number' => 'FHTT0000A004']);
        $zte = Mockery::mock(OltZteC300Service::class);
        $zte->shouldReceive('deleteOnu')->once()->andReturn(['success' => true]);
        $this->app->instance(OltZteC300Service::class, $zte);
        $maintenance = Mockery::mock(HuaweiOnuMaintenanceService::class);
        $maintenance->shouldNotReceive('deleteOnuWithServicePorts');
        $this->app->instance(HuaweiOnuMaintenanceService::class, $maintenance);

        $response = $this->act('delete', $this->user('admin'), ['onu_ref' => '1/2/1:3'], $zteOlt);

        $this->assertSame(200, $response->getStatusCode());
    }
}
