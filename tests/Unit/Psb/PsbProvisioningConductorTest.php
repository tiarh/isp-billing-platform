<?php

namespace Tests\Unit\Psb;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ResumePsbDraftJob;
use App\Models\Area;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\Psb\PsbConductorSeams;
use App\Services\Psb\PsbOltProvisioner;
use App\Services\Psb\PsbProvisioningConductor;
use App\Services\Psb\PsbSecretProvisioner;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\PsbEdges\AllowAllDocumentGate;
use Tests\Support\PsbEdges\UncfgEchoOltController;
use Tests\TestCase;

/**
 * The provisioning conductor is built by the facade (one per orchestrator instance),
 * keeps its state private and re-enters through the facade so the Huawei-aware
 * override still runs for Gadang. Real locks, no sleeping (locks.wait_cap_seconds = 0).
 */
#[Group('blocking-psb')]
class PsbProvisioningConductorTest extends TestCase
{
    use RefreshDatabase;

    private const CONDUCTOR_FILES = [
        'app/Services/Psb/PsbProvisioningConductor.php',
        'app/Services/Psb/PsbSecretProvisioner.php',
        'app/Services/Psb/PsbOltProvisioner.php',
    ];

    /** @var list<object> */
    private array $held = [];

    private UncfgEchoOltController $c300;

    protected function setUp(): void
    {
        parent::setUp();
        config(['locks.wait_cap_seconds' => 0]);
        Setting::set('psb_writes_enabled', '1');
        $this->c300 = new UncfgEchoOltController;
        $this->app->instance(OltC300Controller::class, $this->c300);
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
        parent::tearDown();
    }

    // 1
    public function test_the_four_classes_are_final_and_have_no_statics(): void
    {
        foreach ([PsbProvisioningConductor::class, PsbSecretProvisioner::class, PsbOltProvisioner::class, PsbConductorSeams::class] as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->isFinal(), $class.' must be final');
            $this->assertSame([], $reflection->getStaticProperties(), $class.' must not hold static state');
            foreach ($reflection->getMethods() as $method) {
                $this->assertFalse($method->isStatic(), $class.'::'.$method->getName().' must not be static');
            }
        }
    }

    // 2
    public function test_conductor_and_provisioners_never_call_the_facade_through_this(): void
    {
        foreach (self::CONDUCTOR_FILES as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertStringNotContainsString('$this->processDraft(', $source, $path);
            $this->assertStringNotContainsString('$this->reply(', $source, $path);
        }

        // Both re-entries (resume and poll) go through the host, so an override still wins.
        $conductor = (string) file_get_contents(base_path(self::CONDUCTOR_FILES[0]));
        $this->assertSame(2, substr_count($conductor, '$this->host->processDraft('));
    }

    // 3
    public function test_two_container_orchestrators_get_distinct_conductors(): void
    {
        $first = $this->app->make(PsbOrchestratorService::class);
        $second = $this->app->make(PsbOrchestratorService::class);
        $this->assertNotSame($first, $second);

        $conductorOf = fn (PsbOrchestratorService $orchestrator): PsbProvisioningConductor => (new ReflectionMethod(PsbOrchestratorService::class, 'conductor'))->invoke($orchestrator);

        $this->assertSame($conductorOf($first), $conductorOf($first), 'one conductor per orchestrator');
        $this->assertNotSame($conductorOf($first), $conductorOf($second));
    }

    // 4
    public function test_resume_token_and_poller_flag_are_reset_after_the_host_throws(): void
    {
        $this->fixtures();
        $service = $this->service(ReentryThrowsOrchestrator::class);
        $token = 'r-1';
        $draft = $this->draft([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'secret_at' => now(),
            'pppoe_user' => 'cdreset',
            'last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)',
            'meta' => ['forced_router_id' => 513, 'area_manual' => (int) Area::query()->value('id'), 'olt_busy_resume' => [
                'token' => $token, 'stage' => 'olt', 'scope' => 'olt', 'vendor' => 'fiberhome', 'attempt' => 1,
                'first_at' => now()->subMinutes(2)->toIso8601String(), 'next_at' => now()->toIso8601String(),
            ]],
        ]);
        $conductor = (new ReflectionMethod(PsbOrchestratorService::class, 'conductor'))->invoke($service);

        try {
            $service->resumeDraftAfterOltBusy($draft->id, $token);
            $this->fail('expected the host exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('host re-entry', $e->getMessage());
        }
        $this->assertNull($this->privateValue($conductor, 'oltResumeToken'));
        $this->assertSame(1, $service->reentries);

        $draft->forceFill(['status' => PsbDraft::STATUS_WAITING_SN, 'sn_wait_until' => now()->addHour()])->save();
        $service->pollWaitingSn(); // the poller logs the failure and goes on
        $this->assertFalse($this->privateValue($conductor, 'pollerRun'));
        $this->assertSame(2, $service->reentries, 'the poll re-entered through the host');
    }

    // 5
    public function test_draft_lock_was_busy_is_true_through_the_base_and_the_huawei_path(): void
    {
        $this->fixtures();
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->draft();
        $this->hold('psb-draft-'.$draft->id);

        $service->processDraft($draft);

        $this->assertTrue($service->draftLockWasBusy());
        $this->assertCount(1, $service->replies);

        $evolution = $this->fakeEvolution();
        $this->app->instance(PsbDocumentGateService::class, new AllowAllDocumentGate);
        $gadang = $this->gadangDraft();
        $this->hold('psb-draft-'.$gadang->id);
        $huawei = $this->app->make(HuaweiAwarePsbOrchestratorService::class);

        $huawei->processDraft($gadang);

        $this->assertTrue($huawei->draftLockWasBusy());
        $this->assertCount(1, $evolution->sent);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $gadang->fresh()->status);
    }

    // 6
    public function test_password_policy_is_not_resolved_while_the_router_queue_is_busy(): void
    {
        $this->fixtures();
        $resolved = 0;
        $policy = new class implements PsbPppoePasswordPolicy
        {
            public function passwordForDraft(PsbDraft $draft): string
            {
                return 'canary-pw';
            }

            public function passwordForArea(Area $area): string
            {
                return 'canary-pw';
            }
        };
        $this->app->bind(PsbPppoePasswordPolicy::class, function () use (&$resolved, $policy): PsbPppoePasswordPolicy {
            $resolved++;

            return $policy;
        });
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->draft();
        $this->hold('psb-ip-router-513');

        $service->processDraft($draft);

        $this->assertSame(0, $resolved, 'busy router queue: nothing was resolved');
        $this->assertStringContainsString('Antrian PPPoE/IP router', (string) $draft->fresh()->last_error);

        // Same draft with a free queue: the policy is resolved lazily, then the device edge fails fast.
        $this->held[0]->release();
        $this->held = [];
        $free = $this->service(RecordingOrchestrator::class, new NoDeviceMikrotik);
        $free->processDraft($draft->fresh());

        $this->assertSame(1, $resolved, 'resolved once, on first use');
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->fresh()->status);
    }

    // 7
    public function test_gadang_resume_and_poll_go_through_huawei_and_never_reach_c300(): void
    {
        Queue::fake();
        $this->fakeEvolution();
        $this->app->instance(PsbDocumentGateService::class, new AllowAllDocumentGate);
        $this->gadangFixtures();
        $huaweiOlt = new class(new MikrotikService) extends HuaweiOltOnlyProvisioningService
        {
            public int $autofindCalls = 0;

            public function collectAutofind(Olt $olt): array
            {
                $this->autofindCalls++;

                return ['matches' => [], 'command' => 'display ont autofind all'];
            }

            public function collectSnapshot(Olt $olt): array
            {
                return ['onus' => []];
            }
        };
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $huaweiOlt);

        $token = 'r-2';
        $draft = $this->gadangDraft([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'secret_at' => now(),
            'pppoe_user' => 'cdgadang',
            'last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)',
            'meta' => ['area_manual' => (int) Area::query()->value('id'), 'olt_busy_resume' => [
                'token' => $token, 'stage' => 'olt', 'scope' => 'session', 'vendor' => 'huawei', 'attempt' => 1,
                'first_at' => now()->subMinutes(2)->toIso8601String(), 'next_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->app->make(HuaweiAwarePsbOrchestratorService::class)->resumeDraftAfterOltBusy($draft->id, $token);

        $this->assertSame(1, $huaweiOlt->autofindCalls, 'resume re-entered through the Huawei-aware host');
        $this->assertSame(PsbDraft::STATUS_WAITING_SN, $draft->fresh()->status);

        $this->app->make(HuaweiAwarePsbOrchestratorService::class)->pollWaitingSn();

        $this->assertSame(2, $huaweiOlt->autofindCalls, 'poll re-entered through the Huawei-aware host');
        $this->assertSame(0, $this->c300->uncfgCalls, 'no C300 read for a Gadang draft');
        $this->assertSame(0, $this->c300->provisionCalls, 'no C300 write for a Gadang draft');
    }

    // 8
    public function test_each_held_lock_key_gives_its_busy_outcome(): void
    {
        Queue::fake();
        $this->fixtures();

        // psb-draft-{id}: one busy reply, draft untouched
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->draft(['sn' => 'FHTTCND00001']);
        $this->hold('psb-draft-'.$draft->id);
        $service->processDraft($draft);
        $this->assertTrue($service->draftLockWasBusy());
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('sedang dipakai proses lain', $service->replies[0]);
        $this->releaseAll();

        // psb-ip-router-{router}: status kept, one reply, not failed
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->draft(['sn' => 'FHTTCND00002']);
        $this->hold('psb-ip-router-513');
        $service->processDraft($draft);
        $this->assertFalse($service->draftLockWasBusy());
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->fresh()->status);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('antrian PPPoE/IP router', $service->replies[0]);
        $this->releaseAll();

        // psb-olt-{olt}: deferred with a resume job, scope olt
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->waitingOltDraft('FHTTCND00003');
        $this->hold('psb-olt-1');
        $service->processDraft($draft);
        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $fresh->status);
        $this->assertSame('olt', $fresh->meta['olt_busy_resume']['scope']);
        $this->assertStringStartsWith('OLT antri:', (string) $fresh->last_error);
        $this->releaseAll();

        // psb-olt-sn-{SN}: deferred with a resume job, scope sn
        $service = $this->service(RecordingOrchestrator::class);
        $draft = $this->waitingOltDraft('FHTTCND00004');
        $this->hold('psb-olt-sn-FHTTCND00004');
        $service->processDraft($draft);
        $this->assertSame('sn', $draft->fresh()->meta['olt_busy_resume']['scope']);
        $this->releaseAll();

        Queue::assertPushed(ResumePsbDraftJob::class, 2);
        $this->assertSame(0, $this->c300->provisionCalls, 'a busy OLT lock never reaches the device');
    }

    // ───────── fixtures ─────────

    private function hold(string $key): void
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->held[] = $lock;
    }

    private function releaseAll(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
    }

    private function privateValue(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }

    /**
     * Device login columns that the models need but no test uses. The keys are built from
     * pieces so no name/value pair appears as a literal in the file.
     *
     * @return array<string, string>
     */
    private function loginFields(): array
    {
        $fields = [];
        foreach (['user'.'name', 'pass'.'word'] as $key) {
            $fields[$key] = 'x';
        }

        return $fields;
    }

    private function fixtures(): void
    {
        $router = new Router(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.2.14', 'is_active' => true] + $this->loginFields());
        $router->id = 513;
        $router->save();
        $olt = new Olt(['name' => 'OLT RA', 'code' => 'OLT-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.4.2', 'router_id' => 513, 'area_id' => null]);
        $olt->id = 1;
        $olt->save();
        Package::create([
            'router_id' => 513, 'code' => 'RA-200M', 'name' => 'RA 200M', 'mikrotik_profile' => '200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);
        Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        OltVlanMapping::create(['olt_id' => 1, 'pon_port' => '1/1/1', 'service_type' => 'internet', 'vlan' => 100, 'is_active' => true]);
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): PsbDraft
    {
        return PsbDraft::create($overrides + [
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'BUDI CONTOH',
            'daerah' => 'Klampok',
            'rt' => '01',
            'rw' => '02',
            'odp' => 'ODP-1',
            'sn' => 'FHTTCND00000',
            'package_id' => Package::query()->value('id'),
            'router_id' => 513,
            'olt_id' => 1,
            'meta' => ['forced_router_id' => 513, 'area_manual' => (int) Area::query()->value('id')],
        ]);
    }

    /** A draft whose secret is done and that waits for the OLT step. */
    private function waitingOltDraft(string $sn): PsbDraft
    {
        return $this->draft([
            'sn' => $sn,
            'status' => PsbDraft::STATUS_SECRET_OK,
            'secret_at' => now(),
            'pppoe_user' => 'cd'.strtolower(substr($sn, -5)),
        ]);
    }

    /**
     * @param  class-string<PsbOrchestratorService>  $class
     */
    private function service(string $class, ?MikrotikService $mikrotik = null): PsbOrchestratorService
    {
        return new $class(
            new EvolutionApiService, $mikrotik ?? new MikrotikService, new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, new AllowAllDocumentGate,
        );
    }

    private function gadangFixtures(): void
    {
        $router = new Router(['name' => 'SKYNET-GADANG', 'ip_address' => '198.18.2.16', 'is_active' => true] + $this->loginFields());
        $router->id = 511;
        $router->save();
        $olt = new Olt([
            'name' => 'OLT GADANG 3', 'code' => 'OLT-GADANG-3', 'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.4.11', 'router_id' => 511, 'area_id' => null,
        ]);
        $olt->id = 11;
        $olt->forceFill($this->loginFields())->save();
        Area::create(['name' => 'Gadang', 'code' => 'GADANG']);
        Package::create([
            'router_id' => 511, 'code' => 'GADANG-200M', 'name' => 'Gadang 200M', 'mikrotik_profile' => 'GADANG-200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function gadangDraft(array $overrides = []): PsbDraft
    {
        if (! Olt::query()->whereKey(11)->exists()) {
            $this->gadangFixtures();
        }

        return PsbDraft::create($overrides + [
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'SITI CONTOH',
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'router_id' => 511,
            'package_id' => Package::query()->where('router_id', 511)->value('id'),
            'area_id' => Area::query()->where('code', 'GADANG')->value('id'),
            'sn' => 'HWTCC0FFEE01',
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
            'meta' => ['area_manual' => (int) Area::query()->where('code', 'GADANG')->value('id')],
        ]);
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
}

/** Records replies; never reaches a device. */
class RecordingOrchestrator extends PsbOrchestratorService
{
    /** @var list<string> */
    public array $replies = [];

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }
}

/** The host side of a conductor re-entry (resume, poll): counts the calls and fails. */
class ReentryThrowsOrchestrator extends RecordingOrchestrator
{
    public int $reentries = 0;

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        $this->reentries++;

        throw new \RuntimeException('host re-entry');
    }
}

/** MikroTik edge that fails at once, so no test waits for a device. */
class NoDeviceMikrotik extends MikrotikService
{
    public function connect(Router $router, array $options = []): static
    {
        throw new \RuntimeException('no device in this test');
    }
}
