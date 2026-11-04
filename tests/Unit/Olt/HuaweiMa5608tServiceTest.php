<?php

namespace Tests\Unit\Olt;

use App\Exceptions\HuaweiOltCommandException;
use App\Models\Olt;
use App\Services\OltHuaweiMa5608tService;
use App\Support\OltVendor;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('blocking-psb')]
class HuaweiMa5608tServiceTest extends TestCase
{
    public function test_it_parses_huawei_ont_inventory_fixture(): void
    {
        $output = <<<'CLI'
ONT-ID : 1
Run state : online
Config state : normal
SN : ALCL-FE1BBC27
Description : Nokia customer
ONT Rx optical power : -18.4 dBm
ONT distance : 1250 m

ONT ID : 2
Run state : offline
Match state : mismatch
SN : FHTT-C186C14
Name : FiberHome test
CLI;

        $rows = (new OltHuaweiMa5608tService)->parsePortOutput($output, 0);

        self::assertCount(2, $rows);
        self::assertSame('0/0/0:1', $rows[0]['onu_ref']);
        self::assertSame('online', $rows[0]['state']);
        self::assertSame('ALCL-FE1BBC27', $rows[0]['serial_number']);
        self::assertSame(-18.4, $rows[0]['rx_power_dbm']);
        self::assertSame(1250, $rows[0]['distance_m']);
        self::assertSame('0/0/0:2', $rows[1]['onu_ref']);
        self::assertSame('offline', $rows[1]['state']);
        self::assertSame('mismatch', $rows[1]['phase_state']);
    }

    public function test_it_parses_huawei_all_ont_table_and_decodes_vendor_prefix(): void
    {
        $output = <<<'CLI'
F/S/P   ONT         SN         Control     Run      Config   Match    Protect
ID                     flag        state    state    state    side
-----------------------------------------------------------------------------
0/ 0/0    2  464854540000A046  active      online   normal   match    no
0/ 0/0    3  414C434CFE1BBC27  active      offline  initial  initial  no
-----------------------------------------------------------------------------
F/S/P   ONT-ID   Description
-----------------------------------------------------------------------------
0/ 0/0       2   YUNITAFRUITS
0/ 0/0       3   Nokia customer
CLI;

        $rows = (new OltHuaweiMa5608tService)->parsePortOutput($output, 0);

        self::assertCount(2, $rows);
        self::assertSame('0/0/0:2', $rows[0]['onu_ref']);
        self::assertSame('FHTT0000A046', $rows[0]['serial_number']);
        self::assertSame('FHTT', $rows[0]['vendor_id']);
        self::assertSame('online', $rows[0]['state']);
        self::assertSame('YUNITAFRUITS', $rows[0]['onu_name']);
        self::assertSame('ALCLFE1BBC27', $rows[1]['serial_number']);
        self::assertSame('ALCL', $rows[1]['vendor_id']);
    }

    public function test_it_keeps_serial_when_the_ont_table_has_an_extra_column(): void
    {
        $output = <<<'CLI'
0/ 0/0    2  464854540000A046  active      online   normal   match    no    -
0/ 0/0       2   YUNITAFRUITS
CLI;

        $rows = (new OltHuaweiMa5608tService)->parsePortOutput($output, 0);

        self::assertSame('FHTT0000A046', $rows[0]['serial_number']);
        self::assertSame('online', $rows[0]['state']);
        self::assertSame('YUNITAFRUITS', $rows[0]['onu_name']);
    }

    public function test_it_keeps_the_row_printed_right_after_a_pagination_prompt(): void
    {
        // Real `display ont info 0 0 1 all` capture (Gadang MA5608T, serials
        // and descriptions masked). The CLI erases `---- More ... ----` with
        // ESC[37D in place, so ONT 21 (table) and ONT 17 (description) share
        // a physical line with the prompt residue.
        $output = file_get_contents(__DIR__.'/../../Fixtures/huawei/ma5608t_display_ont_info_all_paginated.txt');
        self::assertIsString($output);
        self::assertStringContainsString("----\e[37D", $output);

        $rows = (new OltHuaweiMa5608tService)->parsePortOutput($output, 1);
        $byRef = array_column($rows, null, 'onu_ref');

        $expectedIds = [0, 1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 19, 20, 21, 22, 23, 24, 25];
        self::assertCount(24, $rows);
        self::assertSame(
            array_map(static fn (int $id) => "0/0/1:{$id}", $expectedIds),
            array_keys($byRef),
        );

        self::assertSame('online', $byRef['0/0/1:21']['state']);
        self::assertSame('FHTT0000A013', $byRef['0/0/1:21']['serial_number']);
        self::assertSame('FHTT', $byRef['0/0/1:21']['vendor_id']);
        self::assertSame('CUSTOMER-21', $byRef['0/0/1:21']['onu_name']);
        self::assertSame('CUSTOMER-17', $byRef['0/0/1:17']['onu_name']);
        self::assertSame('FHTT0000A012', $byRef['0/0/1:20']['serial_number']);
        self::assertSame('CUSTOMER-20', $byRef['0/0/1:20']['onu_name']);
        foreach ($rows as $row) {
            self::assertSame('online', $row['state'], $row['onu_ref']);
            self::assertMatchesRegularExpression('/^FHTT0000A0[0-9A-F]{2}$/', (string) $row['serial_number']);
            self::assertMatchesRegularExpression('/^CUSTOMER-\d{2}$/', (string) $row['onu_name']);
        }
    }

    public function test_it_keeps_the_row_after_a_backspace_erased_pagination_prompt(): void
    {
        $prompt = "---- More ( Press 'Q' to break ) ----";
        $erase = str_repeat("\x08", strlen($prompt)).str_repeat(' ', strlen($prompt)).str_repeat("\x08", strlen($prompt));
        $output = "  0/ 0/2    4  464854540000A046  active      online   normal   match    no \r\n"
            .$prompt.$erase."  0/ 0/2    5  414C434CFE1BBC27  active      offline  initial  initial  no \r\n"
            ."  0/ 0/2       4   CUSTOMER-04\r\n"
            .$prompt.$erase."  0/ 0/2       5   CUSTOMER-05\r\n";

        $rows = (new OltHuaweiMa5608tService)->parsePortOutput($output, 2);
        $byRef = array_column($rows, null, 'onu_ref');

        self::assertSame(['0/0/2:4', '0/0/2:5'], array_keys($byRef));
        self::assertSame('offline', $byRef['0/0/2:5']['state']);
        self::assertSame('ALCLFE1BBC27', $byRef['0/0/2:5']['serial_number']);
        self::assertSame('CUSTOMER-05', $byRef['0/0/2:5']['onu_name']);
    }

    public function test_huawei_is_treated_as_an_enterprise_olt(): void
    {
        $olt = new Olt(['vendor' => 'huawei_ma5608t']);

        self::assertTrue(OltVendor::isEnterprise($olt));
    }

    public function test_huawei_commands_use_interactive_shell_instead_of_exec_channel(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('write')->with("display version\r");
        $ssh->expects(self::never())->method('exec');
        $ssh->expects(self::once())->method('read')->with('', SSH2::READ_NEXT)->willReturn("display version\nVERSION : MA5600V800R018C00\nMA5608T#");

        $service = new class extends OltHuaweiMa5608tService
        {
            public function runForTest(SSH2 $ssh, string $command): string
            {
                return $this->run($ssh, $command);
            }
        };

        self::assertSame("display version\nVERSION : MA5600V800R018C00\nMA5608T#", $service->runForTest($ssh, 'display version'));
    }

    public function test_huawei_read_command_accepts_optional_argument_prompt_before_cli_prompt(): void
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
                "display service-port port 0/0/7 ont 29\n{ <cr>|e2e<K>|gemport<K> }:",
                "\r235 108 common gpon 0/0 /7 29 1 vlan 108 7 7 up\nMA5608T#",
            );

        $service = new class extends OltHuaweiMa5608tService
        {
            public function runForTest(SSH2 $ssh, string $command): string
            {
                return $this->run($ssh, $command);
            }
        };

        self::assertStringContainsString('vlan 108', $service->runForTest($ssh, 'display service-port port 0/0/7 ont 29'));
        self::assertSame(
            ["display service-port port 0/0/7 ont 29\r", "\r"],
            $writes,
        );
    }

    public function test_huawei_shell_prompt_is_synchronized_before_read_commands(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('write')->with("\r");
        $ssh->expects(self::exactly(2))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                "MA5608T>\nWarning: Using the default user password is not recommended. Please change the password.\n",
                'MA5608T>',
            );

        $service = new class extends OltHuaweiMa5608tService
        {
            public function synchronizeForTest(SSH2 $ssh): void
            {
                $this->synchronizeShell($ssh);
            }
        };

        $service->synchronizeForTest($ssh);
        self::addToAssertionCount(1);
    }

    public function test_huawei_shell_does_not_stop_at_prompt_inside_login_banner(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('write')->with("display version\r");
        $ssh->expects(self::exactly(2))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                "MA5608T>\nWarning: Using the default user password is not recommended. Please change the password.\n",
                "display version\nVERSION : MA5600V800R018C00\nMA5608T#",
            );

        $service = new class extends OltHuaweiMa5608tService
        {
            public function runForTest(SSH2 $ssh, string $command): string
            {
                return $this->run($ssh, $command);
            }
        };

        self::assertSame(
            "MA5608T>\nWarning: Using the default user password is not recommended. Please change the password.\ndisplay version\nVERSION : MA5600V800R018C00\nMA5608T#",
            $service->runForTest($ssh, 'display version'),
        );
    }

    public function test_huawei_shell_advances_each_pagination_prompt_once(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $writes = [];
        $ssh->expects(self::exactly(3))->method('write')->willReturnCallback(
            static function (string $value) use (&$writes): void {
                $writes[] = $value;
            },
        );
        $ssh->expects(self::exactly(3))->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willReturnOnConsecutiveCalls(
                "display ont info 0 0 0 all\n---- More ( Press 'Q' to break ) ----",
                "continued output\n---- More ( Press 'Q' to break ) ----",
                "final output\nMA5608T#",
            );

        $service = new class extends OltHuaweiMa5608tService
        {
            public function runForTest(SSH2 $ssh, string $command): string
            {
                return $this->run($ssh, $command);
            }
        };

        self::assertStringContainsString('continued output', $service->runForTest($ssh, 'display ont info 0 0 0 all'));
        self::assertSame(["display ont info 0 0 0 all\r", ' ', ' '], $writes);
    }

    public function test_huawei_transport_failure_keeps_stage_and_safe_command_context(): void
    {
        $command = 'ont add 0 8 sn-auth "FHTT0000A045" omci desc "USERG"';
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('write')->with($command."\r");
        $ssh->expects(self::once())->method('read')
            ->with('', SSH2::READ_NEXT)
            ->willThrowException(new RuntimeException('connection closed by peer'));

        $service = new class extends OltHuaweiMa5608tService
        {
            public function runForTest(SSH2 $ssh, string $command): string
            {
                return $this->run($ssh, $command);
            }
        };

        try {
            $service->runForTest($ssh, $command);
            self::fail('Expected a contextual Huawei SSH exception.');
        } catch (HuaweiOltCommandException $exception) {
            self::assertSame('transport', $exception->failureType);
            self::assertSame('command', $exception->stage);
            self::assertSame('ont add 0 8 sn-auth "[redacted]" omci desc "USERG"', $exception->commandLabel);
            self::assertSame('RuntimeException', $exception->causeClass);
            self::assertStringContainsString('connection closed', strtolower($exception->getPrevious()?->getMessage() ?? ''));
            self::assertStringNotContainsString('FHTT0000A045', $exception->getMessage());
            self::assertStringNotContainsString('password', strtolower($exception->getMessage()));
        }
    }

    public function test_huawei_disconnect_failure_does_not_escape_cleanup(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('disconnect')->willThrowException(new RuntimeException('already closed'));

        $service = new class extends OltHuaweiMa5608tService
        {
            public function disconnectForTest(SSH2 $ssh): void
            {
                $this->disconnectSafely($ssh);
            }
        };

        $service->disconnectForTest($ssh);
        self::addToAssertionCount(1);
    }

    #[DataProvider('blockedOperations')]
    public function test_huawei_write_operations_are_blocked(string $operation): void
    {
        $service = new OltHuaweiMa5608tService;
        $olt = new Olt;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only read-only inventory is enabled');

        match ($operation) {
            'rename' => $service->renameOnu($olt, '0/0/0:1', 'test'),
            'reboot' => $service->rebootOnu($olt, '0/0/0:1'),
            'state' => $service->setOnuAdminState($olt, '0/0/0:1', true),
            'delete' => $service->deleteOnu($olt, '0/0/0:1'),
            'authorize' => $service->authorizeOnu($olt, []),
        };
    }

    public static function blockedOperations(): array
    {
        return [
            ['rename'],
            ['reboot'],
            ['state'],
            ['delete'],
            ['authorize'],
        ];
    }
}
