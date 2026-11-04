<?php

namespace Tests\Feature;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Area;
use App\Models\Olt;
use App\Models\User;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Support\CacheLockWait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Unit\Olt\HuaweiAutofindParserTest;
use Throwable;

/**
 * "Lihat ONT baru" list on the Huawei tab. The REAL service runs; only the SSH
 * layer (connect/run) is faked, but the fake connect still takes the real
 * session lock so busy/lock-release behaviour is the production one.
 */
#[Group('blocking-psb')]
class OltHuaweiUiAutofindRealServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_list(): void
    {
        $olt = $this->olt();
        $this->bindService('autofind-empty-1.txt');

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0, 'entries' => [], 'partial' => false, 'cached' => false]);
    }

    public function test_one_entry_fields_and_no_forbidden_keys(): void
    {
        $olt = $this->olt();
        $this->bindService('autofind-one.txt');

        $response = $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('entries.0.sn', 'HWTC0000A005')
            ->assertJsonPath('entries.0.sn_hex', '485754430000A005')
            ->assertJsonPath('entries.0.pon_port', '0/0/3')
            ->assertJsonPath('entries.0.vendor_id', 'HWTC')
            ->assertJsonPath('entries.0.model', 'EG8145V5')
            ->assertJsonPath('entries.0.seen_at', '2026-10-22 09:14:03+07:00');

        $body = (string) $response->getContent();
        foreach (['"password"', '"raw"', '"command"', '"output"', 'canary-pw', 'loid-canary', 'REDACTED'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_many_entries_are_all_returned(): void
    {
        $olt = $this->olt();
        $this->bindService('autofind-many.txt');

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertOk()
            ->assertJsonPath('count', 14)
            ->assertJsonPath('partial', false);
    }

    public function test_second_call_is_cached_and_refresh_has_a_ten_second_floor(): void
    {
        $olt = $this->olt();
        $service = $this->bindService('autofind-one.txt');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])->assertOk()->assertJson(['cached' => false]);
        $this->assertSame(1, $service->connects);

        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])->assertOk()->assertJson(['cached' => true]);
        $this->assertSame(1, $service->connects);

        // refresh inside the 10 s floor: still the cached list
        $this->travel(5)->seconds();
        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id, 'refresh' => true])
            ->assertOk()->assertJson(['cached' => true]);
        $this->assertSame(1, $service->connects);

        // refresh after the floor: new read
        $this->travel(6)->seconds();
        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id, 'refresh' => true])
            ->assertOk()->assertJson(['cached' => false]);
        $this->assertSame(2, $service->connects);

        // cache expires after 30 s even without refresh
        $this->travel(31)->seconds();
        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertOk()->assertJson(['cached' => false]);
        $this->assertSame(3, $service->connects);
    }

    public function test_busy_session_gives_423_without_any_command_and_nothing_cached(): void
    {
        $olt = $this->olt();
        $service = $this->bindService('autofind-one.txt');
        $held = Cache::lock('huawei-olt-ssh-session:'.$olt->id, 60);
        $this->assertTrue($held->get());

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertStatus(423)
            ->assertJson(['success' => false, 'busy' => true, 'error' => 'OLT sedang dipakai. Coba lagi 1 menit.']);

        $this->assertSame([], $service->commands);
        $this->assertNull(Cache::get('huawei-autofind-ui:'.$olt->id));
        $held->release();
    }

    public function test_timeout_gives_504_plain_text_releases_lock_and_caches_nothing(): void
    {
        $olt = $this->olt();
        $service = $this->bindService('autofind-one.txt');
        $service->failWith = new HuaweiOltCommandException('timeout', 'command', null, 'display ont autofind all', 'RuntimeException', 'Huawei SSH transport failure at command (timeout).');

        $response = $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertStatus(504)
            ->assertJson(['success' => false, 'error' => 'OLT tidak menjawab. Coba lagi.']);
        $this->assertStringNotContainsString('transport', (string) $response->getContent());

        $this->assertNull(Cache::get('huawei-autofind-ui:'.$olt->id));
        $lock = Cache::lock('huawei-olt-ssh-session:'.$olt->id, 5);
        $this->assertTrue($lock->get(), 'session lock must be released after a timeout');
        $lock->release();
        $fetch = Cache::lock('huawei-autofind-ui-fetch:'.$olt->id, 5);
        $this->assertTrue($fetch->get(), 'fetch lock must be released after a timeout');
        $fetch->release();
    }

    public function test_bad_olt_answer_gives_422_plain_text(): void
    {
        $olt = $this->olt();
        $this->bindService('autofind-bad.txt');

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'OLT menolak perintah daftar ONT.']);
    }

    public function test_session_limits_are_bounded_and_reset_afterwards(): void
    {
        $olt = $this->olt();
        $service = $this->bindService('autofind-one.txt');

        $this->actingAs($this->admin())->postJson('/api/olt-huawei/autofind', ['olt_id' => $olt->id])->assertOk();

        $this->assertSame(['connect' => 5, 'read' => 8, 'lock' => 60, 'wait' => 0], $service->limitsSeen);
        $this->assertNotNull($service->deadlineSeen);
        $this->assertSame([null, null], $service->currentLimits());
    }

    public function test_only_global_admins_may_call_it(): void
    {
        $olt = $this->olt();
        $this->bindService('autofind-one.txt');
        $payload = ['olt_id' => $olt->id];

        $this->postJson('/api/olt-huawei/autofind', $payload)->assertStatus(401);

        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($teknisi)->postJson('/api/olt-huawei/autofind', $payload)->assertForbidden();

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['code' => 'HWA', 'name' => 'Area Huawei Uji']));
        $this->actingAs($scoped)->postJson('/api/olt-huawei/autofind', $payload)->assertForbidden();

        $noc = User::factory()->create(['role' => 'noc']);
        $this->actingAs($noc)->postJson('/api/olt-huawei/autofind', $payload)->assertOk();

        Cache::forget('huawei-autofind-ui:'.$olt->id);
        $this->actingAs($this->admin())->postJson('/api/olt-huawei/autofind', $payload)->assertOk();
    }

    public function test_validation_and_non_huawei_olt(): void
    {
        $this->bindService('autofind-one.txt');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', [])->assertStatus(422);

        $zte = Olt::create(['name' => 'OLT RA', 'code' => 'OLT-RA', 'vendor' => 'zte_c300', 'management_ip' => '198.18.4.2']);
        $this->actingAs($admin)->postJson('/api/olt-huawei/autofind', ['olt_id' => $zte->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    private function bindService(string $autofindFixture): object
    {
        $raw = HuaweiAutofindParserTest::fixture($autofindFixture);
        $service = new class(Mockery::mock(MikrotikService::class), $raw) extends HuaweiOltOnlyProvisioningService
        {
            public int $connects = 0;

            /** @var list<string> */
            public array $commands = [];

            /** @var array<string, int>|null */
            public ?array $limitsSeen = null;

            public ?float $deadlineSeen = null;

            public ?Throwable $failWith = null;

            public function __construct(MikrotikService $mikrotik, private string $autofindOutput)
            {
                parent::__construct($mikrotik);
            }

            /** @return array{0: mixed, 1: mixed} */
            public function currentLimits(): array
            {
                return [$this->sessionLimits, $this->sessionDeadlineAt];
            }

            protected function connect(Olt $olt): SSH2
            {
                $this->limitsSeen = $this->sessionLimits;
                $this->deadlineSeen = $this->sessionDeadlineAt;
                $limits = $this->sessionLimits ?? [];
                $lock = Cache::lock('huawei-olt-ssh-session:'.(int) $olt->getKey(), (int) ($limits['lock'] ?? 1800));
                if (! CacheLockWait::acquire($lock, (int) ($limits['wait'] ?? 5))) {
                    throw new HuaweiOltSessionBusyException;
                }
                $this->connects++;
                $ssh = Mockery::mock(SSH2::class, ['disconnect' => null]);
                $this->sessionLocks[spl_object_id($ssh)] = $lock;

                return $ssh;
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                $this->commands[] = $command;
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }

                return $command === 'display ont autofind all' ? $this->autofindOutput : "Failure: Parameters error\n";
            }
        };

        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $service);

        return $service;
    }

    private function olt(): Olt
    {
        return Olt::create([
            'name' => 'OLT GADANG 3',
            'code' => 'OLT-GDG3',
            'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.4.1',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }
}
