<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\User;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Unit\Olt\HuaweiAutofindParserTest;

/**
 * Regression: detect crashed with "Call to protected method normalizePonPort()"
 * after the SN was found in autofind. The sibling UI test mocks the whole
 * service, so visibility bugs were invisible. Here the REAL service runs and
 * only the SSH layer (connect/run) is faked.
 */
#[Group('blocking-psb')]
class OltHuaweiUiDetectRealServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AUTOFIND = <<<'CLI'
display ont autofind all
-----------------------------------------------------------------------------
Number              : 1
F/S/P               : 0/0/1
Ont SN              : 464854540000A052 (FHTT-0000A052)
Password            : 0x00000000000000000000(                    )
Loid                :
Checkcode           :
VendorID            : FHTT
Ont Version         : V3.0
-----------------------------------------------------------------------------
CLI;

    private const PORT_1_OCCUPIED = <<<'CLI'
ONT-ID  SN                 Control flag  Run state  Config state
0       FHTT0000A03C       active        online     normal
1       FHTT0000A03D       active        online     normal
CLI;

    public function test_detect_resolves_autofind_through_the_real_service(): void
    {
        $olt = $this->olt();
        $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTT0000A052'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'pon_port' => '0/0/1',
                'onu_id' => 2,
                'onu_ref' => '0/0/1:2',
            ]);
    }

    public function test_detect_pon_hint_goes_through_the_real_service(): void
    {
        $olt = $this->olt();
        $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', [
                'olt_id' => $olt->id,
                'sn' => 'FHTT0000A052',
                'pon_port' => 'gpon-olt_0/0/1',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'pon_port' => '0/0/1', 'onu_id' => 2]);
    }

    public function test_detect_already_registered_fallback_goes_through_the_real_service(): void
    {
        $olt = $this->olt();
        $this->bindRealService("display ont autofind all\nFailure: There is no ONT autofind\n", [
            'display ont info 0 0 3 all' => <<<'CLI'
F/S/P   ONT-ID  SN                 Control flag  Run state  Config state  Match state
0/0/3   5       464854540000A052      active        online     normal        match
CLI,
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTT0000A052']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'pon_port' => '0/0/3',
            'onu_id' => 5,
        ]);
    }

    public function test_detect_works_on_an_empty_pon(): void
    {
        $olt = $this->olt();
        $this->bindRealService(
            HuaweiAutofindParserTest::fixture('autofind-one.txt'),
            ['display ont info 0 0 3 all' => "display ont info 0 0 3 all\n  Failure: There is no ONT available\nMA5608T#\n"],
        );

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'HWTC0000A005'])
            ->assertOk()
            ->assertJson(['success' => true, 'pon_port' => '0/0/3', 'onu_id' => 0, 'onu_ref' => '0/0/3:0']);
    }

    public function test_detect_still_rejects_the_help_text_as_a_port_answer(): void
    {
        $olt = $this->olt();
        $this->bindRealService(
            HuaweiAutofindParserTest::fixture('autofind-one.txt'),
            ['display ont info 0 0 3 all' => HuaweiAutofindParserTest::fixture('port-help.txt')],
        );

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'HWTC0000A005'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** @return array<string, array{string, string}> */
    public static function serialInputs(): array
    {
        return [
            'fiberhome hex' => ['464854540000A052', 'FHTT0000A052'],
            'fiberhome dashed' => ['FHTT-0000A052', 'FHTT0000A052'],
            'fiberhome lowercase' => ['fhtt0000a052', 'FHTT0000A052'],
        ];
    }

    #[DataProvider('serialInputs')]
    public function test_detect_accepts_hex_dashed_and_lowercase_sn(string $input, string $canonical): void
    {
        $olt = $this->olt();
        $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => $input])
            ->assertOk()
            ->assertJson(['success' => true, 'sn' => $canonical, 'pon_port' => '0/0/1', 'onu_id' => 2]);
    }

    public function test_detect_huawei_hex_sn_becomes_hwtc(): void
    {
        $olt = $this->olt();
        $this->bindRealService(
            HuaweiAutofindParserTest::fixture('autofind-one.txt'),
            ['display ont info 0 0 3 all' => "display ont info 0 0 3 all\n  Failure: There is no ONT available\nMA5608T#\n"],
        );

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => '485754430000A005'])
            ->assertOk()
            ->assertJson(['success' => true, 'sn' => 'HWTC0000A005']);
    }

    public function test_detect_finds_an_sn_that_the_old_5000_char_cut_would_hide(): void
    {
        $olt = $this->olt();
        $raw = HuaweiAutofindParserTest::fixture('autofind-many.txt');
        $this->assertGreaterThan(5000, strlen($raw));
        $this->assertStringNotContainsString('HWTCA000000D', mb_substr($raw, 0, 5000));
        $this->bindRealService($raw, [
            'display ont info 0 0 12 all' => "display ont info 0 0 12 all\n  Failure: There is no ONT available\nMA5608T#\n",
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'HWTCA000000D'])
            ->assertOk()
            ->assertJson(['success' => true, 'pon_port' => '0/0/12', 'onu_id' => 0]);
    }

    public function test_detect_pon_hint_match_ok_and_mismatch_rejected(): void
    {
        $olt = $this->olt();
        $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTT0000A052', 'pon_port' => '0/0/1'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTT0000A052', 'pon_port' => '0/0/2'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'PON 0/0/2 tidak cocok dengan hasil autofind 0/0/1.']);
    }

    public function test_detect_uses_one_ssh_session(): void
    {
        $olt = $this->olt();
        $service = $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTT0000A052'])
            ->assertOk();

        $this->assertSame(1, $service->connects);
        $this->assertSame([null, null], $service->currentLimits());
    }

    public function test_provision_dry_run_builds_the_plan_for_the_canonical_sn(): void
    {
        $olt = $this->olt();
        $this->bindRealService(self::AUTOFIND, ['display ont info 0 0 1 all' => self::PORT_1_OCCUPIED]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/provision', [
                'olt_id' => $olt->id,
                'sn' => '464854540000A052',
                'name' => 'USER_RT1_RW1_1.1',
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'dry_run' => true, 'sn' => 'FHTT0000A052', 'pon_port' => '0/0/1', 'onu_id' => 2])
            ->assertJsonPath('plan.serial_number', 'FHTT0000A052');
    }

    /** @param array<string, string> $portOutputs */
    private function bindRealService(string $autofind, array $portOutputs): object
    {
        $service = new class(Mockery::mock(MikrotikService::class), $autofind, $portOutputs) extends HuaweiOltOnlyProvisioningService
        {
            public int $connects = 0;

            /** @param array<string, string> $outputs */
            public function __construct(MikrotikService $mikrotik, private string $autofindOutput, private array $outputs)
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
                $this->connects++;

                return Mockery::mock(SSH2::class)->shouldIgnoreMissing();
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                if ($command === 'display ont autofind all') {
                    return $this->autofindOutput;
                }

                return $this->outputs[$command] ?? "Failure: Parameters error\n";
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
