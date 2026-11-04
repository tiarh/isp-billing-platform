<?php

namespace Tests\Unit\Olt;

use App\Exceptions\HuaweiOltCommandException;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('blocking-psb')]
class HuaweiOltOnlyProvisioningServiceTest extends TestCase
{
    public function test_it_parses_huawei_autofind_rows(): void
    {
        $output = <<<'CLI'
F/S/P : 0/0/0
ONT SN : FHTT0000A045
Vendor ID : FHTT

F/S/P : 0/0/1
ONT SN : ALCL-FE1BBC27
Vendor ID : ALCL
CLI;

        $rows = $this->service()->parseAutofindOutput($output);

        self::assertSame([
            [
                'pon_port' => '0/0/0',
                'serial_number' => 'FHTT0000A045',
                'vendor_id' => 'FHTT',
            ],
            [
                'pon_port' => '0/0/1',
                'serial_number' => 'ALCL-FE1BBC27',
                'vendor_id' => 'ALCL',
            ],
        ], $rows);
    }

    public function test_it_decodes_huawei_autofind_hex_serial(): void
    {
        $output = <<<'CLI'
Number : 1
F/S/P : 0/0/7
Ont SN : 464854540000A04B (FHTT-0000A04B)
VendorID : FHTT
CLI;

        self::assertSame([
            [
                'pon_port' => '0/0/7',
                'serial_number' => 'FHTT0000A04B',
                'vendor_id' => 'FHTT',
            ],
        ], $this->service()->parseAutofindOutput($output));
    }

    public function test_it_selects_the_next_ont_id_without_reusing_an_existing_id(): void
    {
        $service = $this->service();

        self::assertSame(8, $service->nextOnuId(array_merge(
            array_map(
                static fn (int $onuId): array => ['pon_port' => '0/0/0', 'onu_id' => $onuId],
                range(0, 7),
            ),
            [['pon_port' => '0/0/1', 'onu_ref' => '0/0/1:99']],
        ), '0/0/0'));
        self::assertSame(0, $service->nextOnuId([], '0/0/0'));
    }

    public function test_it_selects_after_ids_from_targeted_readback_rows(): void
    {
        $service = $this->service();

        self::assertSame(28, $service->nextOnuId(array_map(
            static fn (int $onuId): array => ['pon_port' => '0/0/7', 'onu_id' => $onuId],
            range(0, 27),
        ), '0/0/7'));
    }

    public function test_it_fills_the_first_free_ont_id_gap_on_the_target_pon(): void
    {
        self::assertSame(1, $this->service()->nextOnuId([
            ['pon_port' => '0/0/7', 'onu_id' => 0],
            ['pon_port' => '0/0/7', 'onu_id' => 2],
            ['pon_port' => '0/0/7', 'onu_id' => 26],
        ], '0/0/7'));
    }

    public function test_it_parses_occupied_ids_from_huawei_port_output(): void
    {
        $output = <<<'CLI'
F/S/P                 ONT-ID  SN                 Run state  Config state  Match state
0/0/7                 1       FHTT0000A03D       online     normal         match
0/0/7                 26      FHTT0000A043       online     normal         match
ONT-ID : 27
Run state : online
Config state : normal
Match state : match
CLI;

        self::assertSame([1, 26, 27], $this->service()->parseOccupiedOnuIds($output, '0/0/7'));
    }

    public function test_it_parses_targeted_port_rows_without_fsp_and_selects_the_first_gap(): void
    {
        $output = <<<'CLI'
ONT-ID  SN                 Control flag  Run state  Config state
0       FHTT0000A03C       active        online     normal
1       FHTT0000A03D       active        online     normal
2       FHTT0000A03E       active        online     normal
3       FHTT0000A03F       active        online     normal
4       FHTT0000A040       active        online     normal
5       FHTT0000A041       active        online     normal
6       FHTT0000A042       active        online     normal
CLI;

        $service = $this->service();
        $ids = $service->parseOccupiedOnuIds($output, '0/0/7');

        self::assertSame(range(0, 6), $ids);
        self::assertSame(7, $service->nextOnuId(
            array_map(static fn (int $id): array => ['pon_port' => '0/0/7', 'onu_id' => $id], $ids),
            '0/0/7',
        ));
    }

    public function test_it_queries_the_complete_targeted_ont_table_on_ma5608t(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $service = new class($this->createMock(MikrotikService::class), $ssh) extends HuaweiOltOnlyProvisioningService
        {
            private SSH2 $session;

            /** @var list<string> */
            public array $commands = [];

            public function __construct(MikrotikService $mikrotik, SSH2 $session)
            {
                parent::__construct($mikrotik);
                $this->session = $session;
            }

            protected function connect(Olt $olt): SSH2
            {
                return $this->session;
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                $this->commands[] = $command;

                return <<<'CLI'
ONT-ID  SN                 Control flag  Run state  Config state
0       FHTT0000A03C       active        online     normal
1       FHTT0000A03D       active        online     normal
2       FHTT0000A03E       active        online     normal
CLI;
            }
        };

        $result = $service->collectPortOnus(new Olt(['id' => 11]), '0/0/7');

        self::assertSame(['display ont info 0 0 7 all'], $service->commands);
        self::assertSame('display ont info 0 0 7 all', $result['command']);
        self::assertSame([0, 1, 2], $result['occupied_ids']);
    }

    public function test_it_rejects_ma5608t_help_output_instead_of_allocating_ont_id_zero(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $service = new class($this->createMock(MikrotikService::class), $ssh) extends HuaweiOltOnlyProvisioningService
        {
            private SSH2 $session;

            public function __construct(MikrotikService $mikrotik, SSH2 $session)
            {
                parent::__construct($mikrotik);
                $this->session = $session;
            }

            protected function connect(Olt $olt): SSH2
            {
                return $this->session;
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                return "display ont info 0 0 7\n{ all<K> }\n";
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('display ont info 0 0 7 all');

        $service->collectPortOnus(new Olt(['id' => 11]), '0/0/7');
    }

    public function test_it_uses_autofind_port_and_next_free_id_instead_of_legacy_hint(): void
    {
        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function collectAutofind(Olt $olt): array
            {
                return [
                    'matches' => [[
                        'pon_port' => '0/0/7',
                        'serial_number' => 'FHTT0000A03A',
                        'vendor_id' => 'FHTT',
                    ]],
                    'raw' => 'synthetic autofind',
                    'command' => 'display ont autofind all',
                ];
            }

            public function collectPortOnus(Olt $olt, string $ponPort): array
            {
                $onus = array_map(
                    static fn (int $onuId): array => ['pon_port' => $ponPort, 'onu_id' => $onuId],
                    range(0, 26),
                );

                return [
                    'pon_port' => $ponPort,
                    'onus' => $onus,
                    'occupied_ids' => range(0, 26),
                    'raw' => 'synthetic port info',
                    'command' => 'display ont info 0 0 7 all',
                ];
            }

            /** @return array<string, mixed> */
            public function placementForTest(Olt $olt, CustomerMigration $migration): array
            {
                return $this->discoverPlacement($olt, $migration);
            }
        };
        $migration = new CustomerMigration([
            'new_sn' => 'FHTT0000A03A',
            'pppoe_user' => 'USERAI_RT9_RW3_6.8',
            'new_pon_port' => '0/0/7',
            'new_onu_id' => 26,
            'meta' => ['huawei_olt_only' => ['requested_onu_ref_hint' => '0/0/7:26']],
        ]);

        $result = $service->placementForTest(new Olt(['id' => 1]), $migration);

        self::assertSame('0/0/7', $result['placement']['pon_port']);
        self::assertSame(27, $result['placement']['onu_id']);
        self::assertStringContainsString('ont add 7 27', $result['plan']['commands'][2]);
        self::assertStringContainsString('sudah dipakai', (string) $result['warning']);
    }

    public function test_it_blocks_a_full_pon(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah penuh');

        $this->service()->nextOnuId(array_map(
            static fn (int $onuId): array => ['pon_port' => '0/0/0', 'onu_id' => $onuId],
            range(0, 127),
        ), '0/0/0');
    }

    public function test_it_builds_a_fiberhome_huawei_plan_for_pon_zero(): void
    {
        $plan = $this->service()->buildPlan(
            'FHTT0000A045',
            'USERG',
            '0/0/0',
            8,
            'FHTT',
        );

        self::assertSame(HuaweiOltOnlyProvisioningService::MODE, $plan['mode']);
        self::assertSame(101, $plan['internet_vlan']);
        self::assertSame(69, $plan['tr069_vlan']);
        self::assertSame(10, $plan['line_profile']);
        self::assertSame(8, $plan['onu_id']);
        self::assertCount(1, array_filter($plan['core_commands'], static fn (string $command): bool => $command === 'config'));
        self::assertCount(2, array_filter($plan['commands'], static fn (string $command): bool => $command === 'config'));
        self::assertNotContains('screen-length 0 temporary', $plan['commands']);
        self::assertContains('ont add 0 8 sn-auth "FHTT0000A045" omci ont-lineprofile-id 10 ont-srvprofile-id 10 desc "USERG"', $plan['commands']);
        self::assertContains('ont tr069-server-config 0 8 profile-id 1', $plan['commands']);
        self::assertTrue((bool) array_filter($plan['commands'], static fn (string $command): bool => str_contains($command, 'gemport 1') && str_contains($command, 'vlan 101')));
        self::assertTrue((bool) array_filter($plan['commands'], static fn (string $command): bool => str_contains($command, 'gemport 2') && str_contains($command, 'vlan 69')));
        $ontInfoIndex = array_key_first(array_filter($plan['core_commands'], static fn (string $command): bool => $command === 'display ont info 0 0 0 8'));
        $internetIndex = array_key_first(array_filter($plan['commands'], static fn (string $command): bool => str_contains($command, 'service-port vlan 101')));
        $tr069Index = array_key_first(array_filter($plan['commands'], static fn (string $command): bool => str_contains($command, 'ont tr069-server-config 0 8 profile-id 1')));
        self::assertIsInt($ontInfoIndex);
        self::assertIsInt($internetIndex);
        self::assertIsInt($tr069Index);
        self::assertLessThan($internetIndex, $ontInfoIndex);
        self::assertLessThan($tr069Index, $internetIndex);
        self::assertSame($plan['commands'], [...$plan['core_commands'], ...$plan['optional_commands']]);
        self::assertFalse((bool) array_filter($plan['commands'], static fn (string $command): bool => str_contains($command, 'password')));
    }

    public function test_it_adds_tr069_binding_when_the_ont_is_already_registered(): void
    {
        $plan = $this->service()->buildPlan(
            'FHTT0000A045',
            'USERG',
            '0/0/7',
            18,
            'FHTT',
            108,
            69,
            true,
        );

        self::assertNotContains('ont add 7 18', $plan['commands']);
        self::assertContains('ont tr069-server-config 7 18 profile-id 1', $plan['commands']);
        self::assertContains('service-port vlan 69 gpon 0/0/7 ont 18 gemport 2 multi-service user-vlan 69 tag-transform translate', $plan['commands']);
        self::assertSame('ont tr069-server-config 7 18 profile-id 1', $plan['optional_commands'][2]);
    }

    public function test_it_builds_the_documented_ma5608t_command_contract_for_all_pons(): void
    {
        $expectedProfiles = [
            0 => [101, 10], 1 => [102, 20], 2 => [103, 21], 3 => [104, 22],
            4 => [105, 23], 5 => [106, 24], 6 => [107, 25], 7 => [108, 26],
            8 => [109, 27], 9 => [110, 28], 10 => [111, 29], 11 => [112, 30],
            12 => [113, 31], 13 => [114, 32], 14 => [115, 33], 15 => [116, 34],
        ];

        foreach ($expectedProfiles as $port => [$vlan, $lineProfile]) {
            $plan = $this->service()->buildPlan(
                'FHTTC19BC'.str_pad((string) $port, 2, '0', STR_PAD_LEFT),
                'PPPOE_'.$port,
                "0/0/{$port}",
                0,
                'FHTT',
            );
            $commands = $plan['commands'];

            self::assertSame($vlan, $plan['internet_vlan']);
            self::assertSame($lineProfile, $plan['line_profile']);
            self::assertSame([
                'config',
                'interface gpon 0/0',
                sprintf('ont add %d 0 sn-auth "FHTTC19BC%s" omci ont-lineprofile-id %d ont-srvprofile-id 10 desc "PPPOE_%d"', $port, str_pad((string) $port, 2, '0', STR_PAD_LEFT), $lineProfile, $port),
                'quit',
                sprintf('display ont info by-sn FHTTC19BC%s', str_pad((string) $port, 2, '0', STR_PAD_LEFT)),
                sprintf('display ont info 0 0 %d 0', $port),
                sprintf('service-port vlan %d gpon 0/0/%d ont 0 gemport 1 multi-service user-vlan %d tag-transform translate inbound traffic-table name "200M" outbound traffic-table name "200M"', $vlan, $port, $vlan),
                sprintf('service-port vlan 69 gpon 0/0/%d ont 0 gemport 2 multi-service user-vlan 69 tag-transform translate', $port),
                'quit',
                sprintf('display service-port port 0/0/%d ont 0', $port),
                'save',
                'config',
                'interface gpon 0/0',
                sprintf('ont tr069-server-config %d 0 profile-id 1', $port),
                'quit',
                'quit',
                'save',
            ], $commands);
        }
    }

    public function test_it_retries_by_sn_readback_after_an_autofind_refresh(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $service = new class($this->createMock(MikrotikService::class), $ssh) extends HuaweiOltOnlyProvisioningService
        {
            private SSH2 $session;

            /** @var list<string> */
            public array $commands = [];

            private int $bySnAttempts = 0;

            public function __construct(MikrotikService $mikrotik, SSH2 $session)
            {
                parent::__construct($mikrotik);
                $this->session = $session;
            }

            protected function connect(Olt $olt): SSH2
            {
                return $this->session;
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                if ($command === 'display ont info by-sn FHTT0000A03A') {
                    $this->bySnAttempts++;

                    return $this->bySnAttempts === 1
                        ? "Failure: The ONT does not exist\nMA5608T#"
                        : "SN : FHTT0000A03A\nONT-ID : 27\nMA5608T#";
                }
                if ($command === 'display ont info 0 0 7 27') {
                    return "ONT-ID : 27\nRun state : online\nConfig state : normal\nMatch state : match\nMA5608T#";
                }

                return $command."\nMA5608T#";
            }

            protected function waitBeforeOntReadbackRetry(int $attempt): void {}

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'display ont info by-sn FHTT0000A03A',
            'display ont info 0 0 7 27',
            'service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate',
        ];
        $outputs = [];

        $service->executeForTest(new Olt(['id' => 11]), $commands, [1, 2, 3], $outputs);

        self::assertSame(2, count(array_filter($service->commands, static fn (string $command): bool => $command === 'display ont info by-sn FHTT0000A03A')));
        self::assertContains('display ont autofind all', $service->commands);
        self::assertContains($commands[2], $service->commands);
    }

    public function test_it_uses_the_special_nokia_profile_without_touching_c300(): void
    {
        $plan = $this->service()->buildPlan(
            'ALCL-FE1BBC27',
            'NOKIA_USER',
            '0/0/0',
            9,
            'ALCL',
        );

        self::assertSame(11, $plan['line_profile']);
        self::assertSame(101, $plan['internet_vlan']);
        self::assertSame(81, $plan['tr069_vlan']);
        self::assertContains('ont add 0 9 sn-auth "ALCL-FE1BBC27" omci ont-lineprofile-id 11 ont-srvprofile-id 10 desc "NOKIA_USER"', $plan['commands']);
    }

    public function test_it_rejects_a_vlan_that_does_not_match_the_huawei_port_profile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak cocok');

        $this->service()->buildPlan(
            'FHTT0000A045',
            'USERG',
            '0/0/0',
            8,
            'FHTT',
            102,
        );
    }

    public function test_huawei_incomplete_command_is_rejected(): void
    {
        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function commandFailed(string $output): bool
            {
                return $this->isCommandFailure($output);
            }
        };

        self::assertTrue($service->commandFailed("% Incomplete command\nMA5608T(config)#"));
    }

    public function test_it_requires_the_new_phase_metadata_in_a_live_plan(): void
    {
        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            /** @return array<string, mixed> */
            public function phasesForTest(array $plan, array $commands): array
            {
                return $this->executionPhases($plan, $commands);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ulangi dry-run');
        $service->phasesForTest(['commands' => ['config', 'ont tr069-server-config 0 0 profile-id 1']], ['config', 'ont tr069-server-config 0 0 profile-id 1']);
    }

    public function test_live_command_transport_failure_records_command_number(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('write')->with("save\r");
        $ssh->expects(self::once())->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willThrowException(new RuntimeException('connection closed by peer'));

        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function runForTest(SSH2 $ssh, string $command, int $commandIndex): string
            {
                return $this->runInteractive($ssh, $command, $commandIndex);
            }
        };

        try {
            $service->runForTest($ssh, 'save', 16);
            self::fail('Expected a contextual Huawei SSH exception.');
        } catch (HuaweiOltCommandException $exception) {
            self::assertSame('command', $exception->stage);
            self::assertSame(16, $exception->commandIndex);
            self::assertSame('save', $exception->commandLabel);
            self::assertSame('RuntimeException', $exception->causeClass);
        }
    }

    public function test_service_port_readback_requires_an_explicit_up_vlan_row(): void
    {
        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function servicePortReadyForTest(string $output, ?int $vlan = null): bool
            {
                return $this->isServicePortReadbackReady($output, $vlan);
            }
        };

        $up = <<<'CLI'
0  108 common gpon 0/0/7  27  1  vlan 108  7  7  up
1  69  common gpon 0/0/7  27  2  vlan 69   -  -  up
CLI;
        $internetUp = <<<'CLI'
0  108 common gpon 0/0/7  27  1  vlan 108  7  7  up
1  69  common gpon 0/0/7  27  2  vlan 69   -  -  down
CLI;
        $tr069OnlyUp = <<<'CLI'
0  108 common gpon 0/0/7  27  1  vlan 108  7  7  down
1  69  common gpon 0/0/7  27  2  vlan 69   -  -  up
CLI;
        self::assertTrue($service->servicePortReadyForTest($up));
        self::assertTrue($service->servicePortReadyForTest($internetUp, 108));
        self::assertFalse($service->servicePortReadyForTest($tr069OnlyUp, 108));
        self::assertFalse($service->servicePortReadyForTest(<<<'CLI'
0  108 common gpon 0/0/7  27  1  vlan 108  7  7  down
1  69  common gpon 0/0/7  27  2  vlan 69   -  -  down
CLI));
        self::assertFalse($service->servicePortReadyForTest('Total : 0 (Up/Down : 0/0)'));
    }

    public function test_service_port_down_stops_before_save(): void
    {
        $session = $this->createMock(SSH2::class);
        $service = new class($this->createMock(MikrotikService::class), $session) extends HuaweiOltOnlyProvisioningService
        {
            private SSH2 $session;

            /** @var list<string> */
            public array $commands = [];

            public function __construct(MikrotikService $mikrotik, SSH2 $session)
            {
                parent::__construct($mikrotik);
                $this->session = $session;
            }

            protected function connect(Olt $olt): SSH2
            {
                return $this->session;
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                return str_starts_with($command, 'display service-port')
                    ? $command."\n0  108 common gpon 0/0/7  27  1  vlan 108  7  7  down\nMA5608T#"
                    : $command."\nMA5608T#";
            }

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs, 108);
            }
        };
        $outputs = [];

        try {
            $service->executeForTest(
                new Olt(['id' => 11]),
                ['config', 'display service-port port 0/0/7 ont 27', 'save'],
                [1, 2, 3],
                $outputs,
            );
            self::fail('Expected a down service-port to stop before save.');
        } catch (HuaweiOltCommandException $exception) {
            self::assertSame('service_port_not_up', $exception->causeClass);
            self::assertSame(2, $exception->commandIndex);
        }

        self::assertSame(['config', 'display service-port port 0/0/7 ont 27'], $service->commands);
    }

    public function test_huawei_confirmation_uses_terminal_enter_without_lf(): void
    {
        $writes = [];
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::exactly(2))->method('write')->willReturnCallback(
            function (string $payload) use (&$writes): void {
                $writes[] = $payload;
            },
        );
        $ssh->expects(self::exactly(2))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                "save\rSave configuration? (y/n)",
                "y\rMA5608T#",
            );

        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function runForTest(SSH2 $ssh): string
            {
                return $this->runInteractive($ssh, 'save', 1);
            }
        };

        self::assertStringContainsString('MA5608T#', $service->runForTest($ssh));
        self::assertSame(["save\r", "y\r"], $writes);
    }

    public function test_huawei_accepts_default_optional_command_argument_before_returning_prompt(): void
    {
        $writes = [];
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::exactly(2))->method('write')->willReturnCallback(
            function (string $payload) use (&$writes): void {
                $writes[] = $payload;
            },
        );
        $ssh->expects(self::exactly(2))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                'ont add 7 29 sn-auth "FHTT-0000A03A" { <cr>|ont-type<K> }:',
                "\r  Number of ONTs that can be added: 1, success: 1\nMA5608T#",
            );

        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function runForTest(SSH2 $ssh): string
            {
                return $this->runInteractive($ssh, 'ont add 7 29 sn-auth "FHTT-0000A03A"', 1);
            }
        };

        self::assertStringContainsString('success: 1', $service->runForTest($ssh));
        $writes = array_map(static fn (string $payload): string => str_replace(["\r\n", "\n"], "\r", $payload), $writes);
        self::assertCount(2, $writes);
        self::assertStringEndsWith("\r", $writes[0]);
        self::assertSame("\r", $writes[1]);
    }

    public function test_huawei_does_not_treat_partial_optional_argument_prompt_as_cli_prompt(): void
    {
        $writes = [];
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::exactly(2))->method('write')->willReturnCallback(
            function (string $payload) use (&$writes): void {
                $writes[] = $payload;
            },
        );
        $ssh->expects(self::exactly(2))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                "display service-port port 0/0/7 ont 29\n{ <cr>",
                "\r 235 108 common gpon 0/0 /7 29 1 vlan 108 7 7 up\nMA5608T#",
            );

        $service = new class($this->createMock(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function runForTest(SSH2 $ssh): string
            {
                return $this->runInteractive($ssh, 'display service-port port 0/0/7 ont 29', 1);
            }
        };

        self::assertStringContainsString('vlan 108', $service->runForTest($ssh));
        self::assertSame(
            ["display service-port port 0/0/7 ont 29\r", "\r"],
            $writes,
        );
    }

    public function test_huawei_quit_is_a_local_boundary_and_service_port_reenters_config(): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            /** @var list<string> */
            public array $commands = [];

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                return str_starts_with($command, 'display ont info')
                    ? $command."\nONT-ID : 8\nRun state : online\nConfig state : normal\nMatch state : match\nMA5608T#"
                    : $command."\nMA5608T#";
            }

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 0 8 sn-auth "FHTT0000A045" omci desc "USERG"',
            'quit',
            'service-port vlan 101 gpon 0/0/0 ont 8 gemport 1 multi-service user-vlan 101 tag-transform translate',
            'quit',
            'display ont info 0 0 0 8',
        ];
        $outputs = [];

        $service->executeForTest(new Olt(['id' => 1]), $commands, range(1, count($commands)), $outputs);

        self::assertSame([
            'config',
            'interface gpon 0/0',
            'ont add 0 8 sn-auth "FHTT0000A045" omci desc "USERG"',
            'config',
            'service-port vlan 101 gpon 0/0/0 ont 8 gemport 1 multi-service user-vlan 101 tag-transform translate',
            'display ont info 0 0 0 8',
        ], $service->commands);
        self::assertNotContains('quit', $service->commands);
    }

    public function test_it_tolerates_the_ma5608t_service_port_existed_already_response(): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            /** @var list<string> */
            public array $commands = [];

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                if (str_starts_with($command, 'display service-port')) {
                    return $command."\n  INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI   FLOW  FLOW  RX   TX   STATE\n"
                        ."    235  108 common   gpon 0/0 /7  29   1     vlan  108   7    7    up\n"
                        ."  Total : 1  (Up/Down :    1/0)\nMA5608T#";
                }

                if (str_starts_with($command, 'display ont info')) {
                    return $command."\nONT-ID : 29\nRun state : online\nConfig state : normal\nMatch state : match\nMA5608T#";
                }

                // Exact MA5608T duplicate-service-port response.
                if (str_contains($command, 'service-port vlan 108')) {
                    return $command."\n  Failure: Service virtual port has existed already\n"
                        ."  Conflicted service virtual port index: 235\nMA5608T#";
                }

                return $command."\nMA5608T#";
            }

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 7 29 sn-auth "FHTT0000A03A" omci desc "USERAV_RT6_RW5_6.5"',
            'quit',
            'display ont info by-sn FHTT0000A03A',
            'display ont info 0 0 7 29',
            'service-port vlan 108 gpon 0/0/7 ont 29 gemport 1 multi-service user-vlan 108 tag-transform translate inbound traffic-table name "200M" outbound traffic-table name "200M"',
            'quit',
            'display service-port port 0/0/7 ont 29',
            'save',
        ];
        $outputs = [];

        // Must not throw: the duplicate service-port is an idempotent no-op.
        $service->executeForTest(new Olt(['id' => 11]), $commands, range(1, count($commands)), $outputs);

        self::assertContains('save', $service->commands);
        self::assertContains(
            'service-port vlan 108 gpon 0/0/7 ont 29 gemport 1 multi-service user-vlan 108 tag-transform translate inbound traffic-table name "200M" outbound traffic-table name "200M"',
            $service->commands,
        );
    }

    public function test_it_still_rejects_a_real_service_port_failure(): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            /** @var list<string> */
            public array $commands = [];

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                if (str_starts_with($command, 'display ont info')) {
                    return $command."\nONT-ID : 29\nRun state : online\nConfig state : normal\nMatch state : match\nMA5608T#";
                }

                if (str_contains($command, 'service-port vlan 108')) {
                    return $command."\n  Failure: The VLAN does not exist\nMA5608T#";
                }

                return $command."\nMA5608T#";
            }

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 7 29 sn-auth "FHTT0000A03A" omci desc "X"',
            'quit',
            'display ont info 0 0 7 29',
            'service-port vlan 108 gpon 0/0/7 ont 29 gemport 1 multi-service user-vlan 108 tag-transform translate',
            'save',
        ];
        $outputs = [];

        $this->expectException(HuaweiOltCommandException::class);

        $service->executeForTest(new Olt(['id' => 11]), $commands, range(1, count($commands)), $outputs);
    }

    public function test_it_stops_before_service_port_when_ont_readback_says_not_exist(): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            /** @var list<string> */
            public array $commands = [];

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                return str_starts_with($command, 'display ont info')
                    ? $command."\nFailure: The ONT does not exist\nMA5608T#"
                    : $command."\nMA5608T#";
            }

            protected function waitBeforeOntReadbackRetry(int $attempt): void {}

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 7 27 sn-auth "FHTT0000A044" omci desc "LISAINTAN"',
            'quit',
            'display ont info 0 0 7 27',
            'service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate',
        ];
        $outputs = [];

        try {
            $service->executeForTest(new Olt(['id' => 11]), $commands, range(1, count($commands)), $outputs);
            self::fail('Expected missing ONT read-back to stop the phase.');
        } catch (HuaweiOltCommandException $exception) {
            self::assertSame('command', $exception->failureType);
            self::assertSame(5, $exception->commandIndex);
            self::assertSame('display ont info 0 0 7 27', $exception->commandLabel);
            self::assertSame('onu_not_present', $exception->causeClass);
        }

        self::assertContains('display ont info 0 0 7 27', $service->commands);
        self::assertCount(10, array_filter($service->commands, static fn (string $command): bool => str_starts_with($command, 'display ont info')));
        self::assertNotContains('service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate', $service->commands);
    }

    public function test_it_polls_ont_readback_until_ready_before_sending_service_port(): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            /** @var list<string> */
            public array $commands = [];

            private int $readbackAttempt = 0;

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                if (str_starts_with($command, 'display ont info')) {
                    $this->readbackAttempt++;

                    return match ($this->readbackAttempt) {
                        1 => $command."\nFailure: The ONT does not exist\nMA5608T#",
                        2 => $command."\nONT-ID : 27\nRun state : offline\nConfig state : normal\nMatch state : match\nMA5608T#",
                        default => $command."\nONT-ID : 27\nRun state : online\nConfig state : normal\nMatch state : match\nMA5608T#",
                    };
                }

                return $command."\nMA5608T#";
            }

            protected function waitBeforeOntReadbackRetry(int $attempt): void {}

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 7 27 sn-auth "FHTT0000A044" omci desc "LISAINTAN"',
            'quit',
            'display ont info 0 0 7 27',
            'service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate',
        ];
        $outputs = [];

        $service->executeForTest(new Olt(['id' => 11]), $commands, range(1, count($commands)), $outputs);

        self::assertSame(3, count(array_filter($service->commands, static fn (string $command): bool => str_starts_with($command, 'display ont info'))));
        self::assertContains('service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate', $service->commands);
        self::assertSame(3, count(array_filter($outputs, static fn (array $output): bool => $output['command'] === 'display ont info 0 0 7 27')));
    }

    #[DataProvider('notReadyOntReadbacks')]
    public function test_it_stops_before_service_port_when_ont_readback_is_not_ready(string $stateLines): void
    {
        $sessions = [
            $this->createMock(SSH2::class),
            $this->createMock(SSH2::class),
        ];
        $service = new class($this->createMock(MikrotikService::class), $sessions, $stateLines) extends HuaweiOltOnlyProvisioningService
        {
            /** @var list<SSH2> */
            private array $sessions;

            private string $stateLines;

            /** @var list<string> */
            public array $commands = [];

            /** @param list<SSH2> $sessions */
            public function __construct(MikrotikService $mikrotik, array $sessions, string $stateLines)
            {
                parent::__construct($mikrotik);
                $this->sessions = $sessions;
                $this->stateLines = $stateLines;
            }

            protected function connect(Olt $olt): SSH2
            {
                return array_shift($this->sessions);
            }

            protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
            {
                $this->commands[] = $command;

                return str_starts_with($command, 'display ont info')
                    ? $command."\n{$this->stateLines}\nMA5608T#"
                    : $command."\nMA5608T#";
            }

            protected function waitBeforeOntReadbackRetry(int $attempt): void {}

            /** @param list<string> $commands @param list<int> $indexes @param list<array{command:string,output:string}> $outputs */
            public function executeForTest(Olt $olt, array $commands, array $indexes, array &$outputs): void
            {
                $this->executeCommandPhase($olt, $commands, $indexes, $outputs);
            }
        };
        $commands = [
            'config',
            'interface gpon 0/0',
            'ont add 7 27 sn-auth "FHTT0000A044" omci desc "LISAINTAN"',
            'quit',
            'display ont info 0 0 7 27',
            'service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate',
        ];
        $outputs = [];

        try {
            $service->executeForTest(new Olt(['id' => 11]), $commands, range(1, count($commands)), $outputs);
            self::fail('Expected an unready ONT read-back to stop the phase.');
        } catch (HuaweiOltCommandException $exception) {
            self::assertSame('command', $exception->failureType);
            self::assertSame(5, $exception->commandIndex);
            self::assertSame('display ont info 0 0 7 27', $exception->commandLabel);
            self::assertSame('onu_not_ready', $exception->causeClass);
        }

        self::assertCount(10, array_filter($service->commands, static fn (string $command): bool => str_starts_with($command, 'display ont info')));
        self::assertNotContains('service-port vlan 108 gpon 0/0/7 ont 27 gemport 1 multi-service user-vlan 108 tag-transform translate', $service->commands);
    }

    public static function notReadyOntReadbacks(): array
    {
        return [
            'offline' => ["ONT-ID : 27\nRun state : offline\nConfig state : normal\nMatch state : match"],
            'config failed' => ["ONT-ID : 27\nRun state : online\nConfig state : failed\nMatch state : mismatch"],
            'missing state evidence' => ['ONT-ID : 27'],
        ];
    }

    private function service(): HuaweiOltOnlyProvisioningService
    {
        return new HuaweiOltOnlyProvisioningService($this->createMock(MikrotikService::class));
    }
}
