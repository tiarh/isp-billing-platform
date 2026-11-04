<?php

namespace Tests\Feature;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Http\Controllers\OltController;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\Olt;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceRecoveryOltCleanupService;
use App\Services\DeviceRecoveryService;
use App\Services\HuaweiAutoOnuDeleteService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiOnuMaintenanceService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

/**
 * Huawei auto-delete removes the ONT's service-ports first. Fake SSH transcripts
 * only: no real OLT / SSH I/O.
 *
 * Gate: the unattended DeleteDismantledOnuJob path (deleteForCase without
 * explicitOperatorRun) needs the flag huawei_olt_auto_delete_enabled; operator
 * CLI runs (olt:delete-duplicate-onus --execute, device-recovery:delete-onus
 * --execute, retry) use the service-port sequence WITHOUT the flag.
 */
#[Group('blocking-core')]
class HuaweiAutoOnuDeleteTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private const SN = 'HWTC0000A005';

    private const REF = '0/0/0:1';

    private const NEW_REF = '0/0/5:3';

    private const MISSING = "  Failure: The ONT does not exist\nMA5608T#";

    private const NO_SERVICE_PORT = "  Failure: No service virtual port can be operated\nMA5608T(config)#";

    private const OWNER_TABLE = <<<'CLI'
  INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI   FLOW  FLOW       RX   TX   STATE
        ID   ATTR     TYPE                    TYPE  PARA
  ---------------------------------------------------------------------------
      2  101 common   gpon 0/0 /0  1    1     vlan  101        200M 200M up
      3   69 common   gpon 0/0 /0  1    2     vlan  69         -    -    up
      4  201 common   gpon 0/0 /0  1    3     vlan  201        -    -    up
  ---------------------------------------------------------------------------
  Total : 3
MA5608T(config)#
CLI;

    private const OWNER_FAILURE = "  Failure: This configured object has some service virtual ports\nMA5608T(config-if-gpon-0/0)#";

    private const BUSY_FAILURE = "  Failure: System is busy\nMA5608T(config)#";

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowLifecycleGuard();
        $this->allowCustomerLifecycleGuard();
        config([
            'olt-provisioning.dry_run' => false,
            'olt-provisioning.kill_switch' => false,
            'device-recovery.mode' => 'write',
        ]);
        HwAutoFake::reset();
        $this->bindLiveReadAndLegacySpy();
        $this->noOpsAlert();

        DB::table('packages')->insert(['id' => 1, 'code' => 'PKG-1', 'name' => 'Paket Test', 'price' => 100000, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------ harness

    private static function info(string $state, string $sn, int $port = 0, int $id = 1): string
    {
        return <<<CLI
  F/S/P                   : 0/0/{$port}
  ONT-ID                  : {$id}
  Control flag            : active
  Run state               : {$state}
  Config state            : normal
  Match state             : match
  SN                      : {$sn}
  Description             : TEST_ONT
MA5608T#
CLI;
    }

    /** Live read-back + legacy-delete spy (must stay unused when the new path runs). */
    private function bindLiveReadAndLegacySpy(): void
    {
        $this->app->bind(HuaweiOltOnlyProvisioningService::class, fn () => new class(new MikrotikService) extends HuaweiOltOnlyProvisioningService
        {
            public function onuDetail(Olt $olt, string $onuRef): array
            {
                $live = HwAutoFake::$live[$onuRef] ?? null;

                return ['onu' => ['serial_number' => $live['serial'] ?? null, 'state' => $live['state'] ?? 'offline']];
            }

            public function deleteOnu(Olt $olt, string $onuRef, ?string $serial = null, array $approval = []): array
            {
                HwAutoFake::$legacyCalls[] = [$olt->id, $onuRef, $serial];
                if (HwAutoFake::$legacyBusy) {
                    throw new HuaweiOltSessionBusyException;
                }
                unset(HwAutoFake::$live[$onuRef]);

                return ['success' => true, 'verified_absent' => true, 'device_write' => true];
            }
        });
    }

    private function noOpsAlert(): void
    {
        $this->mock(OpsAlertService::class, fn ($m) => $m->shouldNotReceive('send'));
    }

    private function expectOpsAlerts(int $times): void
    {
        $this->mock(OpsAlertService::class, fn ($m) => $m->shouldReceive('send')->times($times)->andReturn(true));
    }

    /**
     * @param  array<string, list<string>|string>  $script
     * @param  list<int>  $busyConnects
     */
    private function maintenance(array $script = [], array $busyConnects = []): object
    {
        $script += [
            'display ont info 0 0 0 1' => [self::info('offline', self::SN), self::MISSING],
            'display service-port port 0/0/0 ont 1' => [self::OWNER_TABLE, self::NO_SERVICE_PORT],
        ];
        $sessions = array_fill(0, 8, $this->createMock(SSH2::class));

        $svc = new class(new MikrotikService, $script, $busyConnects, $sessions) extends HuaweiOnuMaintenanceService
        {
            /** @var list<string> */
            public array $commands = [];

            public int $connects = 0;

            public int $deleteCalls = 0;

            /** @var array<string, int> */
            private array $served = [];

            /** @param array<string, list<string>|string> $script */
            public function __construct(MikrotikService $mikrotik, private array $script, private array $busy, private array $sessions)
            {
                parent::__construct($mikrotik);
            }

            public function deleteOnuWithServicePorts(Olt $olt, string $onuRef, string $expectedSerial, bool $allowOnline = false, string $operation = 'ui_delete_onu'): array
            {
                $this->deleteCalls++;

                return parent::deleteOnuWithServicePorts($olt, $onuRef, $expectedSerial, $allowOnline, $operation);
            }

            protected function connect(Olt $olt): SSH2
            {
                $this->connects++;
                if (in_array($this->connects, $this->busy, true)) {
                    throw new HuaweiOltSessionBusyException;
                }

                return $this->sessions[$this->connects] ?? $this->sessions[0];
            }

            protected function pauseBetweenSessionAttempts(int $attempt): void {}

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;
                $entry = $this->script[$command] ?? null;
                if ($entry === null) {
                    $out = $command."\nMA5608T#";
                } else {
                    $list = is_array($entry) ? $entry : [$entry];
                    $n = $this->served[$command] ?? 0;
                    $this->served[$command] = $n + 1;
                    $out = $list[min($n, count($list) - 1)];
                }

                if ($out instanceof \Throwable) {
                    throw $out;
                }
                if (preg_match('/^ont delete (\d+) (\d+)$/', $command, $m) === 1 && ! str_contains($out, 'Failure')) {
                    unset(HwAutoFake::$live["0/0/{$m[1]}:{$m[2]}"]);
                }

                return $out;
            }

            /** @return list<string> */
            public function writes(): array
            {
                return array_values(array_filter(
                    $this->commands,
                    static fn (string $c): bool => preg_match('/^(undo |ont delete|save)/', $c) === 1,
                ));
            }
        };
        $this->app->instance(HuaweiOnuMaintenanceService::class, $svc);

        return $svc;
    }

    // ------------------------------------------------------------ recovery seed

    private function seedRecovery(string $vendor = 'huawei_ma5608t', string $ref = self::REF, string $serial = self::SN): DeviceRecoveryCase
    {
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        DB::table('olts')->insert(['id' => 11, 'name' => 'OLT GADANG 3', 'code' => 'OLT-11', 'vendor' => $vendor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert([
            'id' => 10, 'code' => 'CUST-10', 'name' => 'Customer 10', 'phone' => '081200000010', 'address' => 'x',
            'pppoe_user' => 'user10', 'package_id' => 1, 'onu_serial' => $serial, 'olt_id' => 11, 'status' => 'terminated',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('olt_onus')->insert([
            'olt_id' => 11, 'serial_number' => $serial, 'onu_ref' => $ref, 'onu_name' => 'ONU', 'pon_port' => '0/0/0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        HwAutoFake::$live[$ref] = ['serial' => $serial, 'state' => 'offline'];

        $actor = User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-10', 'customer_id' => 10, 'status' => 'closed', 'type' => 'other', 'priority' => 'normal',
            'subject' => 'Dismantle', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $caseId = DB::table('device_recovery_cases')->insertGetId([
            'ticket_id' => $ticketId, 'customer_id' => 10, 'area_id' => null, 'status' => DeviceRecoveryService::STATUS_RECEIVED,
            'created_by' => $actor->id, 'received_at' => now(), 'version' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DeviceRecoveryCase::query()->findOrFail($caseId);
    }

    private function cleanup(): DeviceRecoveryOltCleanupService
    {
        return app(DeviceRecoveryOltCleanupService::class);
    }

    private function record(DeviceRecoveryCase $case): array
    {
        return (array) $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY];
    }

    // ------------------------------------------------------------ adapter gate

    public function test_adapter_gate_matrix(): void
    {
        $huawei = new Olt(['name' => 'H', 'vendor' => 'huawei_ma5608t']);
        $zte = new Olt(['name' => 'Z', 'vendor' => 'zte_c300']);
        $adapter = app(HuaweiAutoOnuDeleteService::class);

        self::assertFalse($adapter->enabled($huawei, false), 'job path, flag absent');
        self::assertTrue($adapter->enabled($huawei, true), 'operator path needs no flag');
        Setting::set('huawei_olt_auto_delete_enabled', '1', 'boolean', 'ops');
        self::assertTrue($adapter->enabled($huawei, false), 'job path, flag on');
        self::assertFalse($adapter->enabled($zte, false));
        self::assertFalse($adapter->enabled($zte, true));
        config(['device-recovery.mode' => 'read_only']);
        self::assertFalse($adapter->enabled($huawei, true));
        self::assertFalse($adapter->enabled($huawei, false));
    }

    // ------------------------------------------------------------ recovery

    public function test_job_path_flag_on_removes_service_ports_then_deletes_and_audits(): void
    {
        Setting::set('huawei_olt_auto_delete_enabled', '1', 'boolean', 'ops');
        $case = $this->seedRecovery();
        $svc = $this->maintenance();

        $result = $this->cleanup()->deleteForCase($case);

        self::assertSame('deleted', $result['status'], json_encode($result));
        self::assertSame([], HwAutoFake::$legacyCalls, 'legacy bare ont delete must not run');
        self::assertSame([
            'undo service-port 2', 'undo service-port 3', 'undo service-port 4', 'ont delete 0 1', 'save',
        ], $svc->writes());
        $record = $this->record($case);
        self::assertTrue($record['ok']);
        self::assertSame('service_port', $record['huawei_path']);
        self::assertCount(3, $record['removed_service_ports']);
        self::assertCount(3, $record['rebuild_hint']);
        self::assertFalse($record['save_pending']);
        self::assertSame(0, DB::table('olt_onus')->count(), 'inventory purged after read-back');
        self::assertSame(1, DB::table('olt_operation_logs')->where('operation', 'auto_delete_onu:device_recovery')->where('status', 'success')->count());
        self::assertSame(1, DB::table('activity_log')->where('event', 'olt_onu_huawei_auto_delete')->count());
        self::assertFalse(array_key_exists('outputs', $record));
    }

    public function test_job_path_flag_off_keeps_legacy_delete_unchanged(): void
    {
        $case = $this->seedRecovery();
        $svc = $this->maintenance();

        $result = $this->cleanup()->deleteForCase($case);

        self::assertSame('deleted', $result['status']);
        self::assertSame([[11, self::REF, self::SN]], HwAutoFake::$legacyCalls);
        self::assertSame(0, $svc->connects);
        self::assertSame(0, $svc->deleteCalls);
        self::assertArrayNotHasKey('huawei_path', $this->record($case));
        self::assertSame(0, DB::table('olt_operation_logs')->count());
    }

    public function test_operator_cli_path_uses_service_ports_even_when_flag_is_off(): void
    {
        $case = $this->seedRecovery();
        $svc = $this->maintenance();
        $actor = User::factory()->create();

        $result = $this->cleanup()->deleteForCase($case, $actor, explicitOperatorRun: true);

        self::assertSame('deleted', $result['status']);
        self::assertSame([], HwAutoFake::$legacyCalls);
        self::assertSame(1, $svc->deleteCalls);
        self::assertContains('undo service-port 2', $svc->commands);
        self::assertSame('service_port', $this->record($case)['huawei_path']);
        self::assertSame($actor->id, DB::table('olt_operation_logs')->value('user_id'));
    }

    public function test_undo_failure_on_second_index_is_partial_keeps_inventory_and_alerts_once(): void
    {
        $case = $this->seedRecovery();
        $svc = $this->maintenance(['undo service-port 3' => self::BUSY_FAILURE]);
        $this->expectOpsAlerts(1);

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('delete_failed', $result['status']);
        self::assertNotContains('ont delete 0 1', $svc->commands);
        $record = $this->record($case);
        self::assertFalse($record['ok']);
        self::assertSame('delete_failed', $record['reason']);
        self::assertTrue($record['partial']);
        self::assertSame('command_rejected', $record['huawei_error_code']);
        self::assertCount(1, $record['rebuild_hint']);
        self::assertSame(1, DB::table('olt_onus')->count(), 'inventory kept');
        self::assertArrayNotHasKey('_wa', $record);
        self::assertTrue($this->cleanup()->isRetryable($case->fresh()));
    }

    public function test_ont_delete_rejected_then_retry_only_sends_ont_delete_and_keeps_previous_hint(): void
    {
        $case = $this->seedRecovery();
        $failing = $this->maintenance(['ont delete 0 1' => self::OWNER_FAILURE]);
        $this->expectOpsAlerts(1);
        $actor = User::factory()->create();

        $result = $this->cleanup()->deleteForCase($case, $actor, explicitOperatorRun: true);

        self::assertSame('delete_failed', $result['status']);
        self::assertNotContains('save', $failing->commands);
        $record = $this->record($case);
        self::assertSame('service_port_still_present', $record['huawei_error_code']);
        self::assertCount(3, $record['rebuild_hint']);
        self::assertTrue($this->cleanup()->isRetryable($case->fresh()));

        // Retry: the service-ports are already gone, only `ont delete` remains.
        $retry = $this->maintenance([
            'display service-port port 0/0/0 ont 1' => [self::NO_SERVICE_PORT],
        ]);
        $retried = $this->cleanup()->retryUnverified($case->fresh(), $actor);

        self::assertSame('deleted', $retried['status']);
        self::assertSame(['ont delete 0 1', 'save'], $retry->writes());
        $after = $this->record($case);
        self::assertTrue($after['ok']);
        self::assertSame([], $after['rebuild_hint']);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertCount(3, $after['previous_rebuild_hints'][0]);
        self::assertSame(1, DB::table('olt_operation_logs')->where('operation', 'auto_delete_onu:device_recovery_retry')->count());
    }

    public function test_previous_rebuild_hints_survive_a_later_legacy_path_run(): void
    {
        $case = $this->seedRecovery();
        $this->maintenance(['undo service-port 3' => self::BUSY_FAILURE]);
        $this->expectOpsAlerts(1);
        $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);
        self::assertCount(1, $this->record($case)['rebuild_hint']);

        // Unattended job path with the Huawei flag off = legacy record (no huawei_* keys).
        $result = $this->cleanup()->deleteForCase($case->fresh());

        self::assertSame('deleted', $result['status']);
        $after = $this->record($case);
        self::assertTrue($after['ok']);
        self::assertArrayNotHasKey('huawei_path', $after);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertCount(1, $after['previous_rebuild_hints'][0]);
    }

    public function test_previous_rebuild_hints_survive_a_thrown_error(): void
    {
        $case = $this->seedRecovery();
        $this->maintenance(['undo service-port 3' => self::BUSY_FAILURE]);
        $this->expectOpsAlerts(1);
        $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        // Legacy path throws (session busy) -> delete_error record built in the catch block.
        HwAutoFake::$legacyBusy = true;
        $this->cleanup()->deleteForCase($case->fresh());

        $after = $this->record($case);
        self::assertSame('delete_error', $after['reason']);
        self::assertFalse($after['ok']);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertCount(1, $after['previous_rebuild_hints'][0]);
    }

    /** Case with a failed earlier run whose record holds one rebuild hint. */
    private function seedCaseWithEarlierHint(bool $ok = false): DeviceRecoveryCase
    {
        $case = $this->seedRecovery();
        $case->forceFill(['meta' => [DeviceRecoveryOltCleanupService::META_KEY => [
            'ok' => $ok,
            'reason' => $ok ? 'readback_failed' : 'delete_failed',
            'olt_id' => 11,
            'serial' => self::SN,
            'onu_ref' => self::REF,
            'rebuild_hint' => [['index' => 2, 'vlan' => 101]],
        ]]])->save();

        return $case->fresh();
    }

    public function test_previous_rebuild_hints_survive_a_skip_record(): void
    {
        $case = $this->seedCaseWithEarlierHint();
        HwAutoFake::$live[self::REF]['state'] = 'online';

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('skipped_onu_online', $result['status']);
        $after = $this->record($case);
        self::assertSame('onu_online', $after['reason']);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertSame(2, $after['previous_rebuild_hints'][0][0]['index']);
    }

    public function test_previous_rebuild_hints_survive_a_guard_hold_record(): void
    {
        $case = $this->seedCaseWithEarlierHint();
        $this->denyLifecycleGuard();
        $this->expectOpsAlerts(1);

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('skipped_guard_hold', $result['status']);
        $after = $this->record($case);
        self::assertSame('guard_hold', $after['reason']);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertSame(2, $after['previous_rebuild_hints'][0][0]['index']);
    }

    public function test_previous_rebuild_hints_survive_deleted_verified_on_retry(): void
    {
        $case = $this->seedCaseWithEarlierHint(ok: true);
        $live = \Mockery::mock(OnuLiveReadService::class);
        $live->shouldReceive('read')->andReturn(['ok' => true, 'absent' => true, 'serial' => null, 'state' => 'unknown', 'label' => 'absent']);
        $this->app->instance(OnuLiveReadService::class, $live);

        $result = $this->cleanup()->retryUnverified($case, User::factory()->create());

        self::assertSame('deleted_verified_on_retry', $result['status']);
        $after = $this->record($case);
        self::assertSame('deleted_verified_on_retry', $after['reason']);
        self::assertCount(1, $after['previous_rebuild_hints']);
        self::assertSame(2, $after['previous_rebuild_hints'][0][0]['index']);
    }

    public function test_session_busy_before_any_command_is_olt_busy_and_records_nothing(): void
    {
        Setting::set('huawei_olt_auto_delete_enabled', '1', 'boolean', 'ops');
        $case = $this->seedRecovery();
        $svc = $this->maintenance([], [1]);

        $result = $this->cleanup()->deleteForCase($case);

        self::assertSame('olt_busy', $result['status']);
        self::assertSame([], $svc->commands);
        self::assertSame(0, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->count());
        self::assertArrayNotHasKey(DeviceRecoveryOltCleanupService::META_KEY, (array) $case->fresh()->meta);
        self::assertSame(0, DB::table('olt_operation_logs')->count());
        self::assertSame([], HwAutoFake::$legacyCalls);
    }

    public function test_config_dry_run_sends_no_ssh_and_records_dry_run_code(): void
    {
        config(['olt-provisioning.dry_run' => true]);
        $case = $this->seedRecovery();
        $svc = $this->maintenance();

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('delete_failed', $result['status']);
        self::assertSame(0, $svc->connects);
        self::assertSame('dry_run', $this->record($case)['huawei_error_code']);
        self::assertSame(1, DB::table('olt_onus')->count());
    }

    public function test_save_pending_sets_cache_marker_and_record_field(): void
    {
        $case = $this->seedRecovery();
        $this->maintenance(['save' => self::BUSY_FAILURE]);
        $olt = Olt::query()->findOrFail(11);
        Cache::forget(OltController::huaweiSavePendingKey($olt));

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('deleted', $result['status']);
        self::assertTrue($this->record($case)['save_pending']);
        self::assertNotNull(Cache::get(OltController::huaweiSavePendingKey($olt)));
    }

    public function test_zte_recovery_is_unchanged_even_with_flag_on(): void
    {
        Setting::set('huawei_olt_auto_delete_enabled', '1', 'boolean', 'ops');
        $case = $this->seedRecovery('zte_c300', '1/2/1:3', 'FHTT1234');
        $this->app->bind(HuaweiOnuMaintenanceService::class, fn () => throw new \LogicException('maintenance must not be resolved for ZTE'));
        $this->app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                $gone = in_array($onuRef, HwAutoFake::$zteDeleted, true);

                return ZteOnuState::probe($onuRef, $gone ? null : 'DyingGasp', $gone ? null : 'FHTT1234');
            }

            public function deleteOnu(Olt $olt, string $onuRef): array
            {
                HwAutoFake::$zteDeleted[] = $onuRef;

                return ['success' => true, 'verified_absent' => true];
            }
        });

        $result = $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertSame('deleted', $result['status']);
        self::assertSame(['1/2/1:3'], HwAutoFake::$zteDeleted);
        $record = $this->record($case);
        foreach (array_keys($record) as $key) {
            self::assertStringStartsNotWith('huawei_', (string) $key);
        }
        self::assertArrayNotHasKey('rebuild_hint', $record);
        self::assertArrayNotHasKey('partial', $record);
    }

    // ------------------------------------------------------------ duplicate command

    private function seedDuplicate(): void
    {
        DB::table('olts')->insert(['id' => 11, 'name' => 'OLT GADANG 3', 'code' => 'OLT-11', 'vendor' => 'huawei_ma5608t', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([self::REF, self::NEW_REF] as $ref) {
            DB::table('olt_onus')->insert([
                'olt_id' => 11, 'serial_number' => self::SN, 'onu_ref' => $ref, 'onu_name' => 'ONU',
                'pon_port' => '0/0/0', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        HwAutoFake::$live[self::REF] = ['serial' => self::SN, 'state' => 'offline'];
        HwAutoFake::$live[self::NEW_REF] = ['serial' => self::SN, 'state' => 'online'];
    }

    /** @return array{0: int, 1: string} */
    private function dup(array $extra = []): array
    {
        $actor = User::factory()->create();
        $code = Artisan::call('olt:delete-duplicate-onus', [
            '--olt' => [11], '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ] + $extra);

        return [$code, Artisan::output()];
    }

    public function test_dup_execute_huawei_removes_only_the_offline_twins_service_ports_without_flag(): void
    {
        $this->seedDuplicate();
        $svc = $this->maintenance();

        [$code, $out] = $this->dup();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('deleted=1 delete_failed=0', $out);
        self::assertSame([], HwAutoFake::$legacyCalls);
        self::assertSame(['undo service-port 2', 'undo service-port 3', 'undo service-port 4', 'ont delete 0 1', 'save'], $svc->writes());
        self::assertSame([self::NEW_REF], DB::table('olt_onus')->pluck('onu_ref')->all());
        self::assertSame(1, DB::table('olt_operation_logs')->where('operation', 'auto_delete_onu:duplicate_cleanup')->count());
    }

    public function test_dup_scope_mismatch_aborts_with_zero_undo(): void
    {
        $this->seedDuplicate();
        $foreign = str_replace('gpon 0/0 /0  1    2', 'gpon 0/0 /0  2    2', self::OWNER_TABLE);
        $svc = $this->maintenance(['display service-port port 0/0/0 ont 1' => [$foreign]]);

        [$code, $out] = $this->dup();

        self::assertSame(1, $code, $out);
        self::assertSame([], $svc->writes());
        self::assertStringContainsString('service_port_scope_mismatch', $out);
        self::assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_dup_ont_delete_failure_shows_partial_detail_alerts_and_fails(): void
    {
        $this->seedDuplicate();
        $this->maintenance(['ont delete 0 1' => self::OWNER_FAILURE]);
        $this->expectOpsAlerts(1);

        [$code, $out] = $this->dup();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('delete_failed=1', $out);
        self::assertStringContainsString('service_port_still_present', $out);
        self::assertStringContainsString('partial: rebuild_hint logged', $out);
        self::assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_dup_session_busy_stops_the_run_and_writes_nothing(): void
    {
        $this->seedDuplicate();
        $svc = $this->maintenance([], [1]);

        [$code, $out] = $this->dup();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('is busy', $out);
        self::assertSame([], $svc->writes());
        self::assertSame(2, DB::table('olt_onus')->count());
        self::assertSame(0, DB::table('olt_operation_logs')->count());
    }

    public function test_dup_dry_run_only_previews_service_ports_and_never_writes(): void
    {
        $this->seedDuplicate();
        $svc = $this->maintenance();

        $code = Artisan::call('olt:delete-duplicate-onus', ['--olt' => [11]]);
        $out = Artisan::output();

        self::assertSame(0, $code, $out);
        self::assertSame(0, $svc->deleteCalls);
        self::assertSame([], $svc->writes());
        self::assertSame(['display service-port port 0/0/0 ont 1'], $svc->commands);
        self::assertStringContainsString('service_ports=3', $out);
        self::assertStringContainsString('huawei_path[olt 11]=service_port', $out);
        self::assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_dup_dry_run_survives_a_failed_preview(): void
    {
        $this->seedDuplicate();
        $svc = $this->maintenance([], [1]);

        $code = Artisan::call('olt:delete-duplicate-onus', ['--olt' => [11]]);
        $out = Artisan::output();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('service_ports=unknown', $out);
        self::assertStringContainsString('would_delete=1', $out);
        self::assertSame([], $svc->writes());
    }

    public function test_flag_off_session_busy_on_legacy_path_stays_delete_error(): void
    {
        $case = $this->seedRecovery();
        $svc = $this->maintenance();
        HwAutoFake::$legacyBusy = true;

        $result = $this->cleanup()->deleteForCase($case);

        self::assertSame('delete_failed', $result['status']);
        self::assertSame('delete_error', $this->record($case)['reason']);
        self::assertSame(0, $svc->connects);
        self::assertSame(1, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->count());
    }

    public function test_partial_alert_uses_the_same_dedupe_key_and_24h_window_each_time(): void
    {
        $case = $this->seedRecovery();
        $this->mock(OpsAlertService::class, fn ($m) => $m->shouldReceive('send')->twice()->andReturnUsing(function (string $msg, string $key, int $seconds): bool {
            HwAutoFake::$alerts[] = [$key, $seconds, $msg];

            return true;
        }));
        $actor = User::factory()->create();

        $this->maintenance(['undo service-port 3' => self::BUSY_FAILURE]);
        $this->cleanup()->deleteForCase($case, $actor, explicitOperatorRun: true);
        $this->maintenance(['undo service-port 3' => self::BUSY_FAILURE]);
        $this->cleanup()->retryUnverified($case->fresh(), $actor);

        self::assertSame(
            [['huawei-auto-delete-partial:11:0/0/0:1', 86400], ['huawei-auto-delete-partial:11:0/0/0:1', 86400]],
            array_map(static fn (array $a): array => [$a[0], $a[1]], HwAutoFake::$alerts),
        );
    }

    public function test_partial_alert_says_state_unknown_when_nothing_is_listed_as_removed(): void
    {
        $case = $this->seedRecovery();
        $gone = "  Failure: The service virtual port does not exist\nMA5608T(config)#";
        $this->mock(OpsAlertService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturnUsing(function (string $msg): bool {
            HwAutoFake::$alerts[] = ['', 0, $msg];

            return true;
        }));
        $this->maintenance([
            'undo service-port 2' => $gone, 'undo service-port 3' => $gone, 'undo service-port 4' => $gone,
            'display service-port port 0/0/0 ont 1' => [self::OWNER_TABLE, self::NO_SERVICE_PORT],
            'ont delete 0 1' => new \RuntimeException('transport'),
        ]);

        $this->cleanup()->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        self::assertCount(1, HwAutoFake::$alerts);
        self::assertStringContainsString('tidak pasti', HwAutoFake::$alerts[0][2]);
        self::assertStringNotContainsString('0 service-port sudah dihapus', HwAutoFake::$alerts[0][2]);
    }

    public function test_adapter_is_never_enabled_for_zte_olt(): void
    {
        Setting::set('huawei_olt_auto_delete_enabled', '1', 'boolean', 'ops');
        $zte = new Olt(['name' => 'Z', 'vendor' => 'zte_c300']);
        $this->app->bind(HuaweiOnuMaintenanceService::class, fn () => throw new \LogicException('maintenance must not be resolved for ZTE'));

        self::assertFalse(app(HuaweiAutoOnuDeleteService::class)->enabled($zte, true));
        self::assertFalse(app(HuaweiAutoOnuDeleteService::class)->enabled($zte, false));
    }
}

final class HwAutoFake
{
    /** @var array<string, array{serial: string, state: string}> */
    public static array $live = [];

    /** @var list<array<int, mixed>> */
    public static array $legacyCalls = [];

    /** @var list<string> */
    public static array $zteDeleted = [];

    public static bool $legacyBusy = false;

    /** @var list<array{0: string, 1: int, 2: string}> */
    public static array $alerts = [];

    public static function reset(): void
    {
        self::$alerts = [];
        self::$legacyBusy = false;
        self::$zteDeleted = [];
        self::$live = [];
        self::$legacyCalls = [];
    }
}
