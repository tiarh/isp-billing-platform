<?php

namespace Tests\Feature;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\LockBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Jobs\ResumePsbDraftJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\EvolutionApiService;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltCollectorService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\PsbOrchestratorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Roadmap 1.1 PR-C: the Huawei SSH session lock used `if (! $lock->block())`,
 * a dead branch (block() throws). A busy session must now throw
 * HuaweiOltSessionBusyException BEFORE any SSH connection/command exists, and
 * each caller must react safely. No real device is ever contacted.
 */
#[Group('blocking-psb')]
#[Group('blocking-core')]
class HuaweiSessionLockGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_KEY = 'huawei-olt-ssh-session:11';

    private const H1 = 'OLT Huawei sedang dipakai sesi lain. Belum ada perintah dikirim; coba lagi +-1 menit.';

    /** @var list<object> */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['locks.wait_cap_seconds' => 0, 'psb.olt_lock_wait_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
        parent::tearDown();
    }

    public function test_text_and_exception_contract(): void
    {
        $this->assertSame(self::H1, HuaweiOltSessionBusyException::MESSAGE);
        $e = new HuaweiOltSessionBusyException;
        $this->assertSame(self::H1, $e->userMessage());
        $this->assertInstanceOf(LockBusyException::class, $e);
        $this->assertNotInstanceOf(PsbLockBusyException::class, $e, 'must never make the WA job release()/replay');
    }

    // H1: busy on first connect => exception before any SSH object/command
    public function test_h1_busy_first_connect_throws_before_any_ssh_write(): void
    {
        $olt = $this->huaweiOlt();
        $lock = $this->hold(self::SESSION_KEY);
        $service = new HuaweiOltOnlyProvisioningService(new MikrotikService);

        try {
            $service->collectAutofind($olt);
            $this->fail('expected HuaweiOltSessionBusyException');
        } catch (HuaweiOltSessionBusyException $e) {
            $this->assertSame(self::H1, $e->getMessage());
        }

        // The holder still owns the lock (we never took or released it) and
        // no session lock leaked inside the service.
        $this->assertFalse(Cache::lock(self::SESSION_KEY, 5)->get());
        $property = new \ReflectionProperty(OltHuaweiMa5608tService::class, 'sessionLocks');
        $property->setAccessible(true);
        $this->assertSame([], $property->getValue($service));
        $lock->release();
        $this->held = [];
        $probe = Cache::lock(self::SESSION_KEY, 5);
        $this->assertTrue($probe->get());
        $probe->release();
    }

    public function test_h1_first_phase_connect_busy_is_rethrown_raw_with_nothing_sent(): void
    {
        $olt = $this->huaweiOlt();
        $this->hold(self::SESSION_KEY);
        $service = $this->phaseHarness(null);
        $outputs = [];

        try {
            $service->executeForTest($olt, ['config', 'interface gpon 0/0'], [1, 2], $outputs);
            $this->fail('expected HuaweiOltSessionBusyException');
        } catch (HuaweiOltSessionBusyException) {
            $this->assertSame([], $outputs);
            $this->assertSame([], $service->commands);
        }
    }

    // H2: PSB Gadang busy => #487 auto-resume, never FAILED
    public function test_h2_psb_gadang_busy_with_secret_defers_through_487_auto_resume(): void
    {
        Queue::fake();
        $evolution = $this->fakeEvolution();
        [$olt, $package, $area] = $this->gadangFixtures();
        $this->hold(self::SESSION_KEY);

        $draft = $this->gadangDraft($package, $area, [
            'status' => PsbDraft::STATUS_SECRET_OK,
            'pppoe_user' => 'HUAWEIBUSY',
            'secret_at' => now(),
        ]);

        $result = app(HuaweiAwarePsbOrchestratorService::class)->processDraft($draft->fresh());

        $draft->refresh();
        $this->assertSame($draft->id, $result->id);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertNotSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertCount(1, $evolution->sent, 'exactly one WA reply (oltBusyQueued)');
        $this->assertStringContainsString('antrian OLT sedang dipakai', $evolution->sent[0]);
        $this->assertNull($draft->olt_at);
        $this->assertSame('huawei', $draft->meta['olt_busy_resume']['vendor']);
        $this->assertSame('session', $draft->meta['olt_busy_resume']['scope']);
        $this->assertStringStartsWith('OLT antri:', (string) $draft->last_error);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
        $this->assertSame(11, (int) $olt->id);
    }

    public function test_h2_psb_gadang_busy_before_secret_is_never_failed_and_asks_manual_resend(): void
    {
        Queue::fake();
        Http::fake();
        [, $package, $area] = $this->gadangFixtures();
        $this->hold(self::SESSION_KEY);

        $draft = $this->gadangDraft($package, $area, ['status' => PsbDraft::STATUS_RECEIVED]);

        app(HuaweiAwarePsbOrchestratorService::class)->processDraft($draft->fresh());

        $draft->refresh();
        $this->assertNotSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->status, 'no false secret_ok without a secret');
        $this->assertNull($draft->secret_at);
        $this->assertStringContainsString('OLT Huawei sibuk', (string) $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta ?? []);
        Queue::assertNotPushed(ResumePsbDraftJob::class);
    }

    public function test_h2_busy_before_secret_manual_run_sends_exactly_one_wa_reply(): void
    {
        Queue::fake();
        $evolution = $this->fakeEvolution();
        [, $package, $area] = $this->gadangFixtures();
        $this->hold(self::SESSION_KEY);
        $draft = $this->gadangDraft($package, $area, ['status' => PsbDraft::STATUS_RECEIVED]);

        app(HuaweiAwarePsbOrchestratorService::class)->processDraft($draft->fresh());

        $this->assertCount(1, $evolution->sent, 'exactly one WA reply');
        $this->assertStringContainsString(self::H1, $evolution->sent[0]);
    }

    public function test_h2_busy_before_secret_in_poller_run_stays_quiet(): void
    {
        Queue::fake();
        $evolution = $this->fakeEvolution();
        [, $package, $area] = $this->gadangFixtures();
        $this->hold(self::SESSION_KEY);
        $draft = $this->gadangDraft($package, $area, [
            'status' => PsbDraft::STATUS_WAITING_SN,
            'sn_wait_until' => now()->addHour(),
        ]);

        app(HuaweiAwarePsbOrchestratorService::class)->pollWaitingSn();

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_WAITING_SN, $draft->status);
        $this->assertStringContainsString('OLT Huawei sibuk', (string) $draft->last_error);
        $this->assertSame([], $evolution->sent, 'poller must not resend the busy notice every run');
    }

    public function test_h2_provision_first_connect_busy_becomes_psb_olt_busy(): void
    {
        [$olt, $package, $area] = $this->gadangFixtures();
        $this->hold(self::SESSION_KEY);
        $draft = $this->gadangDraft($package, $area, [
            'status' => PsbDraft::STATUS_SECRET_OK,
            'pppoe_user' => 'HUAWEIBUSY',
            'secret_at' => now(),
        ]);

        // preflight is the first SSH user inside provision(): busy there.
        $this->expectException(PsbOltLockBusyException::class);
        app(HuaweiPsbProvisioningService::class)->provision($draft, $olt);
    }

    // H3: migration busy on first connect => nothing sent, ledger back to dry_run, H-1
    public function test_h3_migration_busy_first_connect_reverts_ledger_and_throws_h1(): void
    {
        $olt = $this->huaweiOlt();
        $this->hold(self::SESSION_KEY);
        config(['olt-provisioning.dry_run' => false, 'olt-provisioning.kill_switch' => false]);

        $plan = [
            'commands' => ['config', 'interface gpon 0/0', 'ont add 0 8 sn-auth "FHTT0000A045" omci desc "X"'],
            'core_commands' => ['config', 'interface gpon 0/0'],
            'optional_commands' => ['ont add 0 8 sn-auth "FHTT0000A045" omci desc "X"'],
            'optional_command_indexes' => [3],
            'command_hash' => 'hash-1',
        ];
        $huawei = new class(new MikrotikService, $plan, $olt) extends HuaweiOltOnlyProvisioningService
        {
            /** @param array<string, mixed> $plan */
            public function __construct(MikrotikService $mikrotik, private array $plan, private Olt $oltRow)
            {
                parent::__construct($mikrotik);
            }

            public function preflight(CustomerMigration $migration): array
            {
                return [
                    'eligible' => true,
                    'olt' => ['id' => (int) $this->oltRow->id],
                    'placement' => ['pon_port' => '0/0/0', 'onu_id' => 8, 'onu_ref' => '0/0/0:8'],
                    'plan' => $this->plan,
                ];
            }
        };

        $ledger = DeviceActionLog::query()->create([
            'domain' => 'migration', 'target_type' => 'customer_migration', 'target_id' => 1,
            'action' => 'execute', 'status' => 'dry_run', 'idempotency_key' => 'h3-'.uniqid(),
            'payload_hash' => 'x', 'requested_at' => now(),
        ]);
        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'h3-'.uniqid(),
            'raw_text' => '#migrasi',
            'pppoe_user' => 'H3USER',
            'new_sn' => 'FHTT0000A045',
            'last_error' => 'keep me',
            'meta' => [
                'migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
                'device_action_approval' => ['ledger_id' => $ledger->id, 'status' => 'dry_run_ready'],
                'device_action_preflight' => ['plan' => ['command_hash' => 'hash-1']],
            ],
        ]);

        $ledger->update(['target_id' => (string) $migration->id]);

        $service = Mockery::mock(CustomerMigrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('huaweiOltOnlyWriteFlags')->andReturn([
            'migration_actions' => true, 'global_read_only' => false, 'olt' => true, 'genieacs' => true, 'mikrotik' => false,
        ]);
        $service->shouldReceive('reply')->andReturnTrue();
        $property = new \ReflectionProperty(CustomerMigrationService::class, 'huaweiOltOnly');
        $property->setAccessible(true);
        $property->setValue($service, $huawei);

        try {
            $service->executeApprovedDeviceAction($migration->fresh(), null, 'Eksekusi uji kunci sesi Huawei');
            $this->fail('expected HuaweiOltSessionBusyException');
        } catch (HuaweiOltSessionBusyException $e) {
            $this->assertSame(self::H1, $e->userMessage());
            $this->assertInstanceOf(LockBusyException::class, $e);
        }

        $ledger->refresh();
        $this->assertSame('dry_run', $ledger->status, 'B6: reverted, not failed/manual reconciliation');
        $this->assertNull($ledger->executed_at);
        $this->assertNull(data_get($ledger->result, 'manual_reconciliation_required'));
        $migration->refresh();
        $this->assertSame('keep me', $migration->last_error);
        $this->assertNotSame(CustomerMigration::STATUS_FAILED, $migration->status);
        $this->assertSame('dry_run_ready', data_get($migration->meta, 'device_action_approval.status'));
    }

    // H3b: cross-router: MikroTik step already ran => never dry_run / "nothing sent"
    public function test_h3_cross_router_busy_after_secret_step_keeps_manual_reconciliation(): void
    {
        $olt = $this->huaweiOlt();
        $this->hold(self::SESSION_KEY);
        config(['olt-provisioning.dry_run' => false, 'olt-provisioning.kill_switch' => false]);

        $plan = [
            'commands' => ['config', 'interface gpon 0/0', 'ont add 0 8 sn-auth "FHTT0000A045" omci desc "X"'],
            'core_commands' => ['config', 'interface gpon 0/0'],
            'optional_commands' => ['ont add 0 8 sn-auth "FHTT0000A045" omci desc "X"'],
            'optional_command_indexes' => [3],
            'command_hash' => 'hash-1',
        ];
        $huawei = new class(new MikrotikService, $plan, $olt) extends HuaweiOltOnlyProvisioningService
        {
            /** @param array<string, mixed> $plan */
            public function __construct(MikrotikService $mikrotik, private array $plan, private Olt $oltRow)
            {
                parent::__construct($mikrotik);
            }

            public function preflight(CustomerMigration $migration): array
            {
                return [
                    'eligible' => true,
                    'cross_router' => true,
                    'router' => ['id' => 511],
                    'olt' => ['id' => (int) $this->oltRow->id],
                    'placement' => ['pon_port' => '0/0/0', 'onu_id' => 8, 'onu_ref' => '0/0/0:8'],
                    'plan' => $this->plan,
                ];
            }
        };

        $ledger = DeviceActionLog::query()->create([
            'domain' => 'migration', 'target_type' => 'customer_migration', 'target_id' => 1,
            'action' => 'execute', 'status' => 'dry_run', 'idempotency_key' => 'h3b-'.uniqid(),
            'payload_hash' => 'x', 'requested_at' => now(),
        ]);
        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'h3b-'.uniqid(),
            'raw_text' => '#migrasi',
            'pppoe_user' => 'H3USER',
            'new_sn' => 'FHTT0000A045',
            'meta' => [
                'migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
                'device_action_approval' => ['ledger_id' => $ledger->id, 'status' => 'dry_run_ready'],
                'device_action_preflight' => ['plan' => ['command_hash' => 'hash-1']],
            ],
        ]);

        $ledger->update(['target_id' => (string) $migration->id]);

        $service = Mockery::mock(CustomerMigrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('huaweiOltOnlyWriteFlags')->andReturn([
            'migration_actions' => true, 'global_read_only' => false, 'olt' => true, 'genieacs' => true, 'mikrotik' => false,
        ]);
        $service->shouldReceive('reply')->andReturnTrue();
        $service->shouldReceive('resolveCustomer')->andReturn(new Customer);
        $applied = 0;
        $service->shouldReceive('applyMigrationPppoeSecret')->andReturnUsing(function () use (&$applied): array {
            $applied++;

            return [];
        });
        $property = new \ReflectionProperty(CustomerMigrationService::class, 'huaweiOltOnly');
        $property->setAccessible(true);
        $property->setValue($service, $huawei);

        try {
            $service->executeApprovedDeviceAction($migration->fresh(), null, 'Eksekusi uji cross-router Huawei');
            $this->fail('expected a RuntimeException');
        } catch (HuaweiOltSessionBusyException) {
            $this->fail('cross-router busy after the MikroTik step must NOT be the "nothing sent" H-1 exception');
        } catch (\RuntimeException $e) {
            $expected = "Migrasi #{$migration->id}: PPPoE sudah pindah ke router target; OLT Huawei sibuk, belum ada perintah OLT. Perlu rekonsiliasi manual.";
            $this->assertSame($expected, $e->getMessage());
            $this->assertLessThanOrEqual(160, mb_strlen($e->getMessage()));
            $this->assertSame($expected, $migration->fresh()->last_error);
            $this->assertStringNotContainsString('Belum ada perintah dikirim; coba lagi', $e->getMessage());
            $this->assertNotInstanceOf(LockBusyException::class, $e);
        }

        $this->assertSame(1, $applied);
        $ledger->refresh();
        $this->assertSame('failed', $ledger->status, 'not reverted to dry_run');
        $this->assertTrue((bool) data_get($ledger->result, 'manual_reconciliation_required'));
        $this->assertSame('execution_failed', data_get($migration->fresh()->meta, 'device_action_approval.status'));
    }

    // H4: busy on a LATER phase (commands already sent) => unchanged manual reconciliation
    public function test_h4_later_phase_busy_stays_a_transport_failure(): void
    {
        $olt = $this->huaweiOlt();
        $service = $this->phaseHarness(2);
        $outputs = [];
        $commands = ['config', 'interface gpon 0/0', 'quit', 'service-port vlan 101 gpon 0/0/0 ont 8 gemport 1 multi-service user-vlan 101 tag-transform translate'];

        try {
            $service->executeForTest($olt, $commands, [1, 2, 3, 4], $outputs);
            $this->fail('expected HuaweiOltCommandException');
        } catch (HuaweiOltSessionBusyException) {
            $this->fail('a later-phase busy must NOT surface as the first-connect busy exception');
        } catch (HuaweiOltCommandException $e) {
            $this->assertSame('transport', $e->failureType);
            $this->assertSame('connect', $e->stage);
            $this->assertSame('HuaweiOltSessionBusyException', $e->causeClass);
            $this->assertNotInstanceOf(LockBusyException::class, $e);
        }
        $this->assertSame(['config', 'interface gpon 0/0'], $service->commands);
    }

    // H5: non-busy path unchanged (lock taken, released after a failed connect)
    public function test_h5_free_lock_is_taken_and_released_and_error_is_not_busy(): void
    {
        $olt = $this->huaweiOlt();
        $olt->forceFill(['management_ip' => '127.0.0.1', 'management_port' => 1])->save();
        $service = new HuaweiOltOnlyProvisioningService(new MikrotikService);

        try {
            $service->collectAutofind($olt->fresh());
            $this->fail('expected a transport failure');
        } catch (HuaweiOltSessionBusyException) {
            $this->fail('a free lock must not report busy');
        } catch (\Throwable) {
            // connection refused: any non-busy error is the unchanged behaviour
        }

        $probe = Cache::lock(self::SESSION_KEY, 5);
        $this->assertTrue($probe->get(), 'session lock must be released after a failed connect');
        $probe->release();
    }

    public function test_h5_happy_phase_still_sends_every_command_in_order(): void
    {
        $olt = $this->huaweiOlt();
        $service = $this->phaseHarness(null, true);
        $outputs = [];

        $service->executeForTest($olt, ['config', 'interface gpon 0/0'], [1, 2], $outputs);

        $this->assertSame(['config', 'interface gpon 0/0'], $service->commands);
        $this->assertCount(2, $outputs);
    }

    // Collector job: a busy Huawei session is a skipped run, not a hard failure
    public function test_collector_busy_session_is_skipped_not_failed(): void
    {
        // olt_collect_runs predates the tracked migrations; mirror production.
        if (! Schema::hasTable('olt_collect_runs')) {
            Schema::create('olt_collect_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->index();
                $table->string('tier', 32);
                $table->string('status', 16);
                $table->unsignedInteger('onus_total')->nullable();
                $table->unsignedInteger('onus_updated')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('meta')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
        $olt = $this->huaweiOlt();
        $this->hold(self::SESSION_KEY);

        $run = app(OltCollectorService::class)->runHuaweiInventorySync($olt);

        $this->assertSame('skipped', $run->status);
        $this->assertSame(self::H1, $run->error);
    }

    /** Records every WA group message; never touches the network. */
    private function fakeEvolution(): EvolutionApiService
    {
        $fake = new class extends EvolutionApiService
        {
            /** @var list<string> */
            public array $sent = [];

            public function forChannel(string $channel): self
            {
                return $this;
            }

            public function sendToGroup(string $groupJid, string $message, array $mentionedJids = [], bool $mentionEveryone = false): array
            {
                $this->sent[] = $message;

                return ['success' => true];
            }
        };
        $this->app->instance(EvolutionApiService::class, $fake);
        $this->app->forgetInstance(HuaweiAwarePsbOrchestratorService::class);
        $this->app->forgetInstance(PsbOrchestratorService::class);

        return $fake;
    }

    private function huaweiOlt(): Olt
    {
        $router = Router::query()->find(511) ?? (function (): Router {
            $router = new Router(['name' => 'SKYNET-GADANG', 'ip_address' => '198.18.2.16', 'username' => 't', 'password' => Canary::of('t'), 'is_active' => true]);
            $router->id = 511;
            $router->save();

            return $router;
        })();
        $olt = Olt::query()->find(11);
        if (! $olt) {
            $olt = new Olt([
                'name' => 'OLT GADANG 3', 'code' => 'OLT-GADANG-3', 'vendor' => 'huawei_ma5608t',
                'management_ip' => '198.18.4.11', 'router_id' => $router->id, 'area_id' => null,
            ]);
            $olt->id = 11;
            $olt->forceFill(['username' => 'u', 'password' => 'p'])->save();
        }

        return $olt->fresh();
    }

    /** @return array{0: Olt, 1: Package, 2: Area} */
    private function gadangFixtures(): array
    {
        $olt = $this->huaweiOlt();
        $area = Area::create(['name' => 'Gadang', 'code' => 'GADANG']);
        $package = Package::create([
            'router_id' => 511, 'code' => 'GADANG-200M', 'name' => 'Gadang 200M', 'mikrotik_profile' => 'GADANG-200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);
        Setting::set('psb_writes_enabled', '1');

        return [$olt, $package, $area];
    }

    /** @param array<string, mixed> $attributes */
    private function gadangDraft(Package $package, Area $area, array $attributes): PsbDraft
    {
        return PsbDraft::create($attributes + [
            'group_jid' => 'ops@g.us',
            'full_name' => 'HUAWEI BUSY',
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'router_id' => 511,
            'package_id' => $package->id,
            'area_id' => $area->id,
            'sn' => 'FHTT0000A03A',
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
            'meta' => ['area_manual' => $area->id],
        ]);
    }

    /**
     * Harness around executeCommandPhase with a scripted connect().
     *
     * @param  int|null  $busyOnConnectNumber  1-based connect() call that throws busy; null = the REAL connect()
     *                                         (so a held session lock produces the real busy exception)
     * @param  bool  $fakeSessions  when true every connect() hands out a mocked SSH2 and never busy
     */
    private function phaseHarness(?int $busyOnConnectNumber, bool $fakeSessions = false): object
    {
        $sessions = [$this->createMock(SSH2::class), $this->createMock(SSH2::class), $this->createMock(SSH2::class)];

        return new class(new MikrotikService, $sessions, $busyOnConnectNumber, $fakeSessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<string> */
            public array $commands = [];

            private int $connects = 0;

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, private array $sessions, private ?int $busyOn, private bool $fake)
            {
                parent::__construct($mikrotik);
            }

            protected function connect(Olt $olt): SSH2
            {
                $this->connects++;
                if ($this->busyOn !== null && $this->connects === $this->busyOn) {
                    throw new HuaweiOltSessionBusyException;
                }
                if ($this->busyOn === null && ! $this->fake) {
                    return parent::connect($olt); // real lock gate, busy throws before any SSH2
                }

                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                return $command."\nMA5608T#";
            }

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
    }

    private function hold(string $key): object
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->held[] = $lock;

        return $lock;
    }
}
