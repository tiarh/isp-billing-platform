<?php

namespace Tests\Feature;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Models\Olt;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiOnuMaintenanceService;
use App\Services\MikrotikService;
use App\Services\OltHuaweiMa5608tService;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Fake SSH transcripts only: no device I/O. Covers the Huawei ONU delete
 * (service-ports first), rename (ont modify desc) and reboot sequences.
 */
#[Group('blocking-billing')]
class HuaweiOnuMaintenanceServiceTest extends TestCase
{
    private const SN = 'HWTC0000A005';

    private const SN_HEX = '485754430000A005';

    private const MISSING = "  Failure: The ONT does not exist\nMA5608T#";

    private const NO_SERVICE_PORT = "  Failure: No service virtual port can be operated\nMA5608T(config)#";

    /** The owner's real table: INDEX 2/3/4 for 0/0/0 ONT 1. */
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

    protected function setUp(): void
    {
        parent::setUp();
        config(['olt-provisioning.dry_run' => false, 'olt-provisioning.kill_switch' => false]);
    }

    private function olt(): Olt
    {
        $olt = new Olt(['name' => 'GADANG', 'vendor' => 'huawei_ma5608t']);
        $olt->id = 11;

        return $olt;
    }

    private static function info(string $state, string $sn, string $desc, int $port = 0, int $id = 1): string
    {
        return <<<CLI
  F/S/P                   : 0/0/{$port}
  ONT-ID                  : {$id}
  Control flag            : active
  Run state               : {$state}
  Config state            : normal
  Match state             : match
  SN                      : {$sn}
  Description             : {$desc}
MA5608T#
CLI;
    }

    /**
     * @param  array<string, list<string>|string>  $script  command => output(s); the last output repeats
     * @param  list<int>  $busyConnects  1-based connect() calls that are busy
     */
    private function harness(array $script, array $busyConnects = []): object
    {
        $sessions = array_fill(0, 8, $this->createMock(SSH2::class));

        return new class(new MikrotikService, $script, $busyConnects, $sessions) extends HuaweiOnuMaintenanceService
        {
            /** @var list<string> */
            public array $commands = [];

            /** @var list<array{int, string}> */
            public array $sessionCommands = [];

            public int $connects = 0;

            /** @var array<string, int> */
            private array $served = [];

            /** @param array<string, list<string>|string> $script */
            public function __construct(MikrotikService $mikrotik, private array $script, private array $busy, private array $sessions)
            {
                parent::__construct($mikrotik);
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
                $this->sessionCommands[] = [$this->connects, $command];
                $entry = $this->script[$command] ?? null;
                if ($entry === null) {
                    return $command."\nMA5608T#";
                }
                $list = is_array($entry) ? $entry : [$entry];
                $n = $this->served[$command] ?? 0;
                $this->served[$command] = $n + 1;

                return $list[min($n, count($list) - 1)];
            }

            public function writeCommands(): array
            {
                return array_values(array_filter(
                    $this->commands,
                    static fn (string $c): bool => preg_match('/^(undo |ont delete|ont modify|ont reset|save|interface gpon|config)/', $c) === 1,
                ));
            }
        };
    }

    private function deleteScript(array $override = []): array
    {
        return $override + [
            'display ont info 0 0 0 1' => [
                self::info('offline', self::SN, 'TEST_ONT'),
                self::MISSING, // session B read-back
            ],
            'display service-port port 0/0/0 ont 1' => [self::OWNER_TABLE, self::NO_SERVICE_PORT],
        ];
    }

    // ---------------------------------------------------------------- parser

    public function test_parse_owner_transcript_table_and_ignore_other_onts(): void
    {
        $svc = $this->harness([]);
        $rows = $svc->parseServicePortRows(self::OWNER_TABLE, 0, 1);

        self::assertSame([2, 3, 4], array_column($rows, 'index'));
        self::assertSame([101, 69, 201], array_column($rows, 'vlan'));
        self::assertSame([1, 2, 3], array_column($rows, 'gemport'));
        self::assertSame([], $svc->parseServicePortRows(self::OWNER_TABLE, 0, 2));
        self::assertSame([], $svc->parseServicePortRows(self::OWNER_TABLE, 1, 1));
    }

    public function test_parse_compact_layout_paging_residue_and_empty_variants(): void
    {
        $svc = $this->harness([]);
        $compact = "0  108 common gpon 0/0/7  27  1  vlan 108  7  7  up\n1  69  common gpon 0/0/7  27  2  vlan 69   -  -  down\n";
        $rows = $svc->parseServicePortRows($compact, 7, 27);
        self::assertSame([0, 1], array_column($rows, 'index'));
        self::assertSame(['up', 'down'], array_column($rows, 'state'));

        $paged = "  ---- More ( Press 'Q' to break ) ----\e[37D                                     \e[37D  235  101 common   gpon 0/0 /1  0    1     vlan  101        200M 200M up\n";
        self::assertSame([235], array_column($svc->parseServicePortRows($paged, 1, 0), 'index'));

        foreach (['', self::NO_SERVICE_PORT, "  Total : 0\n", "Failure: The ONT does not exist\n"] as $empty) {
            self::assertSame([], $svc->parseServicePortRows($empty, 0, 1));
        }
    }

    // ---------------------------------------------------------------- delete

    public function test_delete_happy_path_sends_exact_sequence_across_two_sessions(): void
    {
        $svc = $this->harness($this->deleteScript());
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['verified_absent']);
        self::assertFalse($result['save_pending']);
        self::assertFalse($result['already_absent']);
        self::assertSame([2, 3, 4], array_column($result['removed_service_ports'], 'index'));
        self::assertCount(3, $result['rebuild_hint']);
        self::assertSame([
            [1, 'display ont info 0 0 0 1'],
            [1, 'display service-port port 0/0/0 ont 1'],
            [1, 'config'],
            [1, 'undo service-port 2'],
            [1, 'undo service-port 3'],
            [1, 'undo service-port 4'],
            [1, 'display service-port port 0/0/0 ont 1'],
            [1, 'interface gpon 0/0'],
            [1, 'ont delete 0 1'],
            [2, 'save'],
            [2, 'display ont info 0 0 0 1'],
        ], $svc->sessionCommands);
        self::assertSame(2, $svc->connects);
    }

    public function test_owner_failure_string_maps_to_service_port_still_present_and_never_saves(): void
    {
        $svc = $this->harness($this->deleteScript([
            'display service-port port 0/0/0 ont 1' => [self::NO_SERVICE_PORT],
            'ont delete 0 1' => self::OWNER_FAILURE,
        ]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['success']);
        self::assertSame('service_port_still_present', $result['error_code']);
        self::assertNotContains('save', $svc->commands);
        self::assertSame(1, $svc->connects);
    }

    public function test_service_port_left_after_undo_aborts_before_ont_delete(): void
    {
        $svc = $this->harness($this->deleteScript([
            'display service-port port 0/0/0 ont 1' => [self::OWNER_TABLE, self::OWNER_TABLE],
        ]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['success']);
        self::assertSame('partial_service_ports_removed', $result['error_code']);
        self::assertNotContains('ont delete 0 1', $svc->commands);
        self::assertNotContains('save', $svc->commands);
        self::assertTrue($result['partial']);
        self::assertCount(3, $result['removed_service_ports']);
    }

    public function test_missing_ont_is_idempotent_success_with_zero_writes(): void
    {
        $svc = $this->harness(['display ont info 0 0 0 1' => self::MISSING]);
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['already_absent']);
        self::assertTrue($result['verified_absent']);
        self::assertFalse($result['device_write']);
        self::assertSame([], $svc->writeCommands());
        self::assertSame(1, $svc->connects);
    }

    public function test_serial_mismatch_writes_nothing_and_hex_form_matches(): void
    {
        $svc = $this->harness($this->deleteScript(['display ont info 0 0 0 1' => [self::info('offline', 'HWTC0000A007', 'OTHER')]]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);
        self::assertFalse($result['success']);
        self::assertSame('serial_mismatch', $result['error_code']);
        self::assertSame([], $svc->writeCommands());

        // Device shows the hex form, inventory has the ASCII form (and vice versa).
        $hexOnDevice = $this->harness($this->deleteScript(['display ont info 0 0 0 1' => [self::info('offline', self::SN_HEX, 'X'), self::MISSING]]));
        self::assertTrue($hexOnDevice->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN)['success']);
        $hexInInventory = $this->harness($this->deleteScript());
        self::assertTrue($hexInInventory->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN_HEX)['success']);
    }

    public function test_empty_inventory_serial_is_refused_before_any_ssh(): void
    {
        $svc = $this->harness($this->deleteScript());
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', '  ');

        self::assertSame('serial_unknown', $result['error_code']);
        self::assertSame(0, $svc->connects);
    }

    public function test_online_onu_is_refused_unless_allow_online(): void
    {
        $online = ['display ont info 0 0 0 1' => [self::info('online', self::SN, 'LIVE'), self::MISSING]];

        $refused = $this->harness($this->deleteScript($online));
        $result = $refused->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);
        self::assertSame('onu_online', $result['error_code']);
        self::assertSame([], $refused->writeCommands());

        $allowed = $this->harness($this->deleteScript($online));
        self::assertTrue($allowed->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN, true)['success']);
    }

    public function test_never_undoes_a_service_port_that_is_not_this_onts(): void
    {
        $foreign = str_replace('gpon 0/0 /0  1    2', 'gpon 0/0 /0  2    2', self::OWNER_TABLE);
        $svc = $this->harness($this->deleteScript(['display service-port port 0/0/0 ont 1' => [$foreign]]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['success']);
        self::assertSame('service_port_scope_mismatch', $result['error_code']);
        self::assertSame([], $svc->writeCommands());

        $otherPon = str_replace('gpon 0/0 /0  1    3', 'gpon 0/0 /5  1    3', self::OWNER_TABLE);
        $svc2 = $this->harness($this->deleteScript(['display service-port port 0/0/0 ont 1' => [$otherPon]]));
        self::assertSame('service_port_scope_mismatch', $svc2->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN)['error_code']);
        self::assertSame([], $svc2->writeCommands());
    }

    public function test_undo_of_an_already_gone_index_is_idempotent_other_failures_stop(): void
    {
        $gone = $this->harness($this->deleteScript(['undo service-port 3' => "  Failure: The service virtual port does not exist\nMA5608T(config)#"]));
        $ok = $gone->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);
        self::assertTrue($ok['success']);
        self::assertContains('undo service-port 4', $gone->commands);
        self::assertSame([2, 4], array_column($ok['removed_service_ports'], 'index'));
        self::assertSame([3], array_column($ok['already_absent_service_ports'], 'index'));

        $bad = $this->harness($this->deleteScript(['undo service-port 3' => "  Failure: System is busy\nMA5608T(config)#"]));
        $fail = $bad->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);
        self::assertFalse($fail['success']);
        self::assertSame('command_rejected', $fail['error_code']);
        self::assertTrue($fail['partial']);
        self::assertSame([2], array_column($fail['removed_service_ports'], 'index'));
        self::assertNotContains('undo service-port 4', $bad->commands);
        self::assertNotContains('ont delete 0 1', $bad->commands);
    }

    public function test_recheck_failure_after_removal_is_partial_with_rebuild_hint(): void
    {
        $table = str_replace('200M 200M up', '7    7    up', self::OWNER_TABLE);
        $svc = $this->harness($this->deleteScript(['display service-port port 0/0/0 ont 1' => [$table, $table]]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['success']);
        self::assertSame('partial_service_ports_removed', $result['error_code']);
        self::assertTrue($result['partial']);
        self::assertStringContainsString('3 service-port sudah dihapus, ONU belum terhapus', $result['message']);
        self::assertCount(3, $result['rebuild_hint']);
        self::assertStringContainsString('traffic-table index 7', $result['rebuild_hint'][0]);
        self::assertNotContains('ont delete 0 1', $svc->commands);
    }

    public function test_ont_delete_failure_after_removal_also_carries_the_hint(): void
    {
        $svc = $this->harness($this->deleteScript(['ont delete 0 1' => "  Failure: System is busy\nMA5608T(config-if-gpon-0/0)#"]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertSame('command_rejected', $result['error_code']);
        self::assertCount(3, $result['rebuild_hint']);
        self::assertStringContainsString('service-port sudah dihapus', $result['message']);
    }

    public function test_unparsable_service_port_rows_abort_before_the_first_undo(): void
    {
        $nonVlan = str_replace('vlan  69         -    -    up', 'eth   69         -    -    up', self::OWNER_TABLE);
        $wrapped = str_replace('vlan  201        -    -    up', "vlan  201        -\n    -    up", self::OWNER_TABLE);
        $badTotal = str_replace('Total : 3', 'Total : 4', self::OWNER_TABLE);

        foreach (['non-vlan' => $nonVlan, 'wrapped' => $wrapped, 'total' => $badTotal] as $label => $table) {
            $svc = $this->harness($this->deleteScript(['display service-port port 0/0/0 ont 1' => [$table]]));
            $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

            self::assertFalse($result['success'], $label);
            self::assertSame('service_port_table_unparsed', $result['error_code'], $label);
            self::assertSame([], $svc->writeCommands(), $label);
        }
    }

    public function test_rebuild_hint_carries_traffic_table_when_shown(): void
    {
        $table = str_replace('200M 200M up', '7    7    up', self::OWNER_TABLE);
        $svc = $this->harness($this->deleteScript(['display service-port port 0/0/0 ont 1' => [$table, self::NO_SERVICE_PORT]]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertStringContainsString('inbound traffic-table index 7 outbound traffic-table index 7', $result['rebuild_hint'][0]);
        self::assertStringNotContainsString('traffic-table', $result['rebuild_hint'][1], 'dash = no table');
    }

    public function test_ont_delete_does_not_exist_is_idempotent(): void
    {
        $svc = $this->harness($this->deleteScript(['ont delete 0 1' => "  Failure: The ONT does not exist\nMA5608T(config-if-gpon-0/0)#"]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['already_absent']);
    }

    public function test_session_b_busy_marks_save_pending_and_readback_pending(): void
    {
        $svc = $this->harness($this->deleteScript(), [2, 3, 4, 5, 6, 7]);
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['save_pending']);
        self::assertFalse($result['verified_absent']);
        self::assertTrue($result['readback_pending']);
        self::assertNotContains('save', $svc->commands);
    }

    public function test_readback_with_other_serial_counts_as_absent_for_ours(): void
    {
        $svc = $this->harness($this->deleteScript([
            'display ont info 0 0 0 1' => [self::info('offline', self::SN, 'A'), self::info('online', 'HWTC0000A003', 'NEW_PSB_ONT')],
        ]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['verified_absent']);
        self::assertTrue($result['id_reused_by_other_serial']);
    }

    public function test_readback_still_showing_our_ont_is_not_success(): void
    {
        $svc = $this->harness($this->deleteScript([
            'display ont info 0 0 0 1' => [self::info('offline', self::SN, 'A'), self::info('offline', self::SN, 'A')],
        ]));
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['success']);
        self::assertSame('still_present_after_delete', $result['error_code']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        config(['olt-provisioning.dry_run' => true]);
        $svc = $this->harness($this->deleteScript());
        $result = $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);

        self::assertFalse($result['device_write']);
        self::assertSame('dry_run', $result['error_code']);
        self::assertSame(0, $svc->connects);
    }

    public function test_kill_switch_blocks_all_writes(): void
    {
        config(['olt-provisioning.kill_switch' => true]);
        $svc = $this->harness($this->deleteScript());

        foreach ([
            fn () => $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN),
            fn () => $svc->renameOnuDescription($this->olt(), '0/0/0:1', self::SN, 'NAME'),
            fn () => $svc->rebootOnuChecked($this->olt(), '0/0/0:1', self::SN),
            fn () => $svc->saveConfig($this->olt()),
        ] as $call) {
            try {
                $call();
                self::fail('kill switch must throw');
            } catch (OltProvisioningBlockedException) {
                self::assertSame(0, $svc->connects);
            }
        }
    }

    public function test_first_connect_busy_is_rethrown_with_nothing_sent(): void
    {
        $svc = $this->harness($this->deleteScript(), [1]);
        $this->expectException(HuaweiOltSessionBusyException::class);
        try {
            $svc->deleteOnuWithServicePorts($this->olt(), '0/0/0:1', self::SN);
        } finally {
            self::assertSame([], $svc->commands);
        }
    }

    // ---------------------------------------------------------------- rename

    public function test_rename_happy_path_verifies_name_then_saves(): void
    {
        $svc = $this->harness([
            'display ont info 0 0 15 2' => [
                self::info('offline', self::SN, 'Huawei-15-2', 15, 2),
                self::info('offline', self::SN, 'USERMUZ2_RT01_RW01', 15, 2),
            ],
        ]);
        $result = $svc->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, 'USERMUZ2_RT01_RW01');

        self::assertTrue($result['success']);
        self::assertTrue($result['verified_name']);
        self::assertSame('Huawei-15-2', $result['old_name']);
        self::assertSame([
            [1, 'display ont info 0 0 15 2'],
            [1, 'config'],
            [1, 'interface gpon 0/0'],
            [1, 'ont modify 15 2 desc "USERMUZ2_RT01_RW01"'],
            [2, 'display ont info 0 0 15 2'],
            [2, 'save'],
        ], $svc->sessionCommands);
    }

    public function test_rename_readback_mismatch_is_not_success_and_never_saves(): void
    {
        $svc = $this->harness([
            'display ont info 0 0 15 2' => [
                self::info('offline', self::SN, 'OLD', 15, 2),
                self::info('offline', self::SN, 'OLD', 15, 2),
            ],
        ]);
        $result = $svc->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, 'NEW_NAME');

        self::assertFalse($result['success']);
        self::assertSame('rename_not_verified', $result['error_code']);
        self::assertNotContains('save', $svc->commands);
    }

    public function test_rename_same_name_is_unchanged_without_write(): void
    {
        $svc = $this->harness(['display ont info 0 0 15 2' => [self::info('offline', self::SN, 'SAME', 15, 2)]]);
        $result = $svc->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, 'SAME');

        self::assertTrue($result['success']);
        self::assertTrue($result['unchanged']);
        self::assertFalse($result['device_write']);
        self::assertSame([], $svc->writeCommands());
    }

    public function test_rename_serial_mismatch_and_modify_rejection(): void
    {
        $mismatch = $this->harness(['display ont info 0 0 15 2' => [self::info('offline', 'HWTC00000000', 'X', 15, 2)]]);
        self::assertSame('serial_mismatch', $mismatch->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, 'NEW')['error_code']);
        self::assertSame([], $mismatch->writeCommands());

        $rejected = $this->harness([
            'display ont info 0 0 15 2' => [self::info('offline', self::SN, 'X', 15, 2)],
            'ont modify 15 2 desc "NEW"' => "  Failure: Parameter error\nMA5608T(config-if-gpon-0/0)#",
        ]);
        $result = $rejected->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, 'NEW');
        self::assertSame('command_rejected', $result['error_code']);
        self::assertNotContains('save', $rejected->commands);
    }

    public function test_rename_invalid_names_are_rejected_before_ssh(): void
    {
        foreach (['', 'A"B', 'A?B', 'A B', str_repeat('A', 65), '-LEADING', "A\nB"] as $bad) {
            $svc = $this->harness([]);
            $result = $svc->renameOnuDescription($this->olt(), '0/0/15:2', self::SN, $bad);
            self::assertSame('invalid_name', $result['error_code'], 'name: '.json_encode($bad));
            self::assertSame(0, $svc->connects);
        }
    }

    // ---------------------------------------------------------------- reboot / save

    public function test_reboot_sends_ont_reset_and_checks_registration(): void
    {
        $svc = $this->harness(['display ont info 0 0 0 1' => [self::info('online', self::SN, 'X')]]);
        $result = $svc->rebootOnuChecked($this->olt(), '0/0/0:1', self::SN);

        self::assertTrue($result['success']);
        self::assertTrue($result['verified_registered']);
        self::assertSame(['config', 'interface gpon 0/0', 'ont reset 0 1'], $svc->writeCommands());
        self::assertNotContains('save', $svc->commands);
    }

    public function test_reboot_pins_serial_and_reports_rejection(): void
    {
        $mismatch = $this->harness(['display ont info 0 0 0 1' => [self::info('online', 'HWTC00000000', 'X')]]);
        self::assertSame('serial_mismatch', $mismatch->rebootOnuChecked($this->olt(), '0/0/0:1', self::SN)['error_code']);
        self::assertSame([], $mismatch->writeCommands());

        $rejected = $this->harness([
            'display ont info 0 0 0 1' => [self::info('offline', self::SN, 'X')],
            'ont reset 0 1' => "  Failure: The ONT is not online\nMA5608T(config-if-gpon-0/0)#",
        ]);
        self::assertSame('command_rejected', $rejected->rebootOnuChecked($this->olt(), '0/0/0:1', self::SN)['error_code']);
    }

    public function test_save_config_runs_only_save(): void
    {
        $svc = $this->harness([]);
        $result = $svc->saveConfig($this->olt());

        self::assertTrue($result['success']);
        self::assertSame(['save'], $svc->commands);
    }

    // ---------------------------------------------------------------- regressions

    public function test_existing_delete_plan_is_unchanged_and_base_service_stays_blocked(): void
    {
        $plan = (new HuaweiOltOnlyProvisioningService(new MikrotikService))->buildDeletePlan($this->olt(), '0/0/15:2', self::SN);
        self::assertSame([
            'config',
            'interface gpon 0/0',
            'ont delete 15 2',
            'quit',
            'save',
            'display ont info 0 0 15 2',
        ], $plan['commands']);

        $base = new OltHuaweiMa5608tService;
        foreach ([
            fn () => $base->deleteOnu($this->olt(), '0/0/0:1'),
            fn () => $base->renameOnu($this->olt(), '0/0/0:1', 'X'),
            fn () => $base->rebootOnu($this->olt(), '0/0/0:1'),
        ] as $call) {
            try {
                $call();
                self::fail('base Huawei write must stay blocked');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('blocked', $e->getMessage());
            }
        }
    }
}
