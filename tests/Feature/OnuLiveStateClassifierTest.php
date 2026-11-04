<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use App\Support\OnuLiveState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

/**
 * REAL OLT RA (ZTE C300) captured output, verbatim, through the real parsing
 * path (only the SSH transport is replaced).
 */
#[Group('blocking-core')]
class OnuLiveStateClassifierTest extends TestCase
{
    private const WORKING_CMD = 'show gpon onu detail-info gpon-onu_1/5/5:3';

    private const DYING_CMD = 'show gpon onu detail-info gpon-onu_1/3/6:2';

    private const PORT_CMD = 'show gpon onu state gpon-olt_1/3/6';

    /**
     * @param  array<string, string>  $outputs  command => output
     */
    private function service(array $outputs): OltZteC300Service
    {
        return new class($outputs) extends OltZteC300Service
        {
            /** @param array<string, string> $outputs */
            public function __construct(private array $outputs) {}

            protected function runPexpectCommandsOrFail(Olt $olt, array $commands, int $timeout): array
            {
                $out = [];
                foreach ($commands as $command) {
                    $out[$command] = $this->outputs[$command] ?? '';
                }

                return $out;
            }
        };
    }

    /**
     * @param  array<string, string>  $outputs
     * @return array{status: string, state: string, label: string, serial: ?string, description: ?string}
     */
    private function read(array $outputs, string $ref): array
    {
        $probe = $this->service($outputs)->liveOnuProbe(new Olt, $ref);

        return OnuLiveState::fromZteProbe($probe, $ref);
    }

    public function test_real_working_onu_is_online_with_physical_serial(): void
    {
        $r = $this->read([
            self::WORKING_CMD => ZteOnuState::sample(self::WORKING_CMD),
            'show gpon onu state gpon-olt_1/5/5' => '',
        ], '1/5/5:3');

        $this->assertSame(OnuLiveState::PRESENT, $r['status']);
        $this->assertSame(OnuLiveState::ONLINE, $r['state']);
        $this->assertSame('FHTTC0000A01', $r['serial']);
        $this->assertSame('working', $r['label']);
    }

    public function test_real_dyinggasp_onu_is_offline_and_cross_check_agrees(): void
    {
        $r = $this->read([
            self::DYING_CMD => ZteOnuState::sample(self::DYING_CMD),
            self::PORT_CMD => ZteOnuState::sample(self::PORT_CMD),
        ], '1/3/6:2');

        $this->assertSame(OnuLiveState::OFFLINE, $r['state']);
        $this->assertSame('FHTTC0000A01', $r['serial']);
    }

    public function test_port_cross_check_disagreement_is_unknown(): void
    {
        // Port table says 1/3/6:2 is working while detail-info says DyingGasp.
        $port = str_replace('1/3/6:2     enable       disable     DyingGasp', '1/3/6:2     enable       enable      working  ', ZteOnuState::sample(self::PORT_CMD));
        $this->assertNotSame(ZteOnuState::sample(self::PORT_CMD), $port);

        $r = $this->read([self::DYING_CMD => ZteOnuState::sample(self::DYING_CMD), self::PORT_CMD => $port], '1/3/6:2');

        $this->assertSame(OnuLiveState::UNKNOWN, $r['state']);
    }

    public function test_port_table_phase_is_the_fourth_column_not_the_last(): void
    {
        $table = OnuLiveState::parsePortTable(ZteOnuState::sample(self::PORT_CMD));

        $this->assertTrue($table['valid']);
        $this->assertSame('DyingGasp', $table['rows']['1/3/6:2']);
        $this->assertSame('working', $table['rows']['1/3/6:3']);
        $this->assertSame('OffLine', $table['rows']['1/3/6:5']);
        $this->assertSame('LOS', $table['rows']['1/3/6:8']);
        $this->assertCount(23, $table['rows']);
        $this->assertSame([], $table['duplicates']);
        $this->assertNotContains('1(GPON)', $table['rows']);
    }

    public function test_error_output_is_unknown(): void
    {
        $err = "show gpon onu detail-info gpon-onu_1/3/6:2\n%Error 20202: Invalid input detected at '^' marker.Invalid parameter\n";

        $r = $this->read([self::DYING_CMD => $err], '1/3/6:2');

        $this->assertSame(OnuLiveState::UNKNOWN, $r['status']);
        $this->assertSame(OnuLiveState::UNKNOWN, $r['state']);
        $this->assertNull($r['serial']);
    }

    private const DEL = 'zte_c300_olt_ra_deleted_onu.txt';

    private const DEL_DETAIL_27 = 'show gpon onu detail-info gpon-onu_1/3/3:27';

    private const DEL_DETAIL_22 = 'show gpon onu detail-info gpon-onu_1/3/3:22';

    private const DEL_PORT = 'show gpon onu state gpon-olt_1/3/3';

    /**
     * @return array<string, string>
     */
    private function deleted(?string $portOverride = null, ?string $detail27Override = null): array
    {
        return [
            self::DEL_DETAIL_27 => $detail27Override ?? ZteOnuState::sample(self::DEL_DETAIL_27, self::DEL),
            self::DEL_DETAIL_22 => ZteOnuState::sample(self::DEL_DETAIL_22, self::DEL),
            self::DEL_PORT => $portOverride ?? ZteOnuState::sample(self::DEL_PORT, self::DEL),
        ];
    }

    public function test_real_deleted_onu_is_absent_with_complete_table(): void
    {
        $table = OnuLiveState::parsePortTable(ZteOnuState::sample(self::DEL_PORT, self::DEL));
        $this->assertTrue($table['valid']);
        $this->assertCount(69, $table['rows']);
        $this->assertArrayNotHasKey('1/3/3:27', $table['rows']);
        $this->assertSame('OffLine', $table['rows']['1/3/3:22']);

        $r = $this->read($this->deleted(), '1/3/3:27');

        $this->assertSame(OnuLiveState::ABSENT, $r['status']);
    }

    public function test_truncated_table_never_proves_absence(): void
    {
        $full = ZteOnuState::sample(self::DEL_PORT, self::DEL);

        // (1) footer dropped.
        $noFooter = preg_replace('/^ONU Number:.*$/m', '', $full);
        $this->assertSame(OnuLiveState::UNKNOWN, $this->read($this->deleted($noFooter), '1/3/3:27')['status']);

        // (2) rows cut (count differs from 69) but footer kept; also an index beyond the cut.
        $lines = preg_split('/\R/', $full) ?: [];
        $cut = implode("\n", array_filter($lines, fn (string $l): bool => ! preg_match('#^1/3/3:(5\d|6\d|7\d)\s#', $l)));
        $this->assertFalse(OnuLiveState::parsePortTable($cut)['valid']);
        $this->assertSame(OnuLiveState::UNKNOWN, $this->read($this->deleted($cut), '1/3/3:27')['status']);
        $this->assertSame(OnuLiveState::UNKNOWN, $this->read($this->deleted($cut, str_replace(':27', ':60', ZteOnuState::sample(self::DEL_DETAIL_27, self::DEL))), '1/3/3:60')['status']);

        // (3) header-only.
        $headerOnly = implode("\n", array_slice($lines, 0, 4));
        $this->assertSame(OnuLiveState::UNKNOWN, $this->read($this->deleted($headerOnly), '1/3/3:27')['status']);
    }

    public function test_no_related_information_is_unknown(): void
    {
        $r = $this->read($this->deleted("show gpon onu state gpon-olt_1/3/3\nNo related information to show.\n"), '1/3/3:27');

        $this->assertSame(OnuLiveState::UNKNOWN, $r['status']);
    }

    public function test_exact_index_is_used_for_real_offline_onu(): void
    {
        $r = $this->read($this->deleted(), '1/3/3:22');

        $this->assertSame(OnuLiveState::PRESENT, $r['status']);
        $this->assertSame(OnuLiveState::OFFLINE, $r['state']);
        $this->assertSame('FHTTC0000B02', $r['serial']);

        // :2 must not be satisfied by the :22 detail / row.
        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromZteProbe([
            'detail' => ZteOnuState::sample(self::DEL_DETAIL_22, self::DEL),
            'port' => ZteOnuState::sample(self::DEL_PORT, self::DEL),
        ], '1/3/3:2')['status']);
    }

    public function test_empty_or_garbage_detail_is_unknown_not_absent(): void
    {
        $port = ZteOnuState::sample(self::DEL_PORT, self::DEL);

        foreach (['', "show gpon onu detail-info gpon-onu_1/3/3:27\n\n\nSKYNET\n", "garbage ... %Error 99999 something\n", "does not exist\n"] as $detail) {
            $this->assertSame(OnuLiveState::UNKNOWN, $this->read($this->deleted($port, $detail), '1/3/3:27')['status']);
        }
    }

    public function test_detail_shows_onu_but_complete_table_lacks_it_is_unknown(): void
    {
        $detail = str_replace('1/3/3:22', '1/3/3:27', ZteOnuState::sample(self::DEL_DETAIL_22, self::DEL));

        $r = OnuLiveState::fromZteProbe(['detail' => $detail, 'port' => ZteOnuState::sample(self::DEL_PORT, self::DEL)], '1/3/3:27');

        $this->assertSame(OnuLiveState::UNKNOWN, $r['state']);
    }

    #[DataProvider('phases')]
    public function test_phase_mapping(string $phase, string $expected): void
    {
        $r = OnuLiveState::fromZteProbe(ZteOnuState::probe('1/3/6:2', $phase, 'FHTTC0000A01'), '1/3/6:2');

        $this->assertSame($expected, $r['state']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phases(): array
    {
        return [
            'working' => ['working', OnuLiveState::ONLINE],
            'DyingGasp' => ['DyingGasp', OnuLiveState::OFFLINE],
            'LOS' => ['LOS', OnuLiveState::OFFLINE],
            'OffLine' => ['OffLine', OnuLiveState::OFFLINE],
            'syncMib' => ['syncMib', OnuLiveState::UNKNOWN],
            'authpass' => ['authpass', OnuLiveState::UNKNOWN],
            'logging' => ['logging', OnuLiveState::UNKNOWN],
            'authFail' => ['authFail', OnuLiveState::UNKNOWN],
            'O7' => ['O7', OnuLiveState::UNKNOWN],
        ];
    }

    public function test_empty_phase_or_serial_or_wrong_interface_is_unknown(): void
    {
        $detail = ZteOnuState::sample(self::WORKING_CMD);

        $noPhase = str_replace('Phase state:         working', 'Phase state:         ', $detail);
        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromZteProbe(['detail' => $noPhase, 'port' => ''], '1/5/5:3')['state']);

        $noSerial = str_replace('FHTTC0000A01', '', $detail);
        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromZteProbe(['detail' => $noSerial, 'port' => ''], '1/5/5:3')['status']);

        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromZteProbe(['detail' => $detail, 'port' => ''], '1/5/5:4')['status']);
    }

    public function test_serial_is_the_physical_number_not_the_description(): void
    {
        $detail = str_replace('Description:         FHTTC0000A01', 'Description:         SOMEONE ELSE', ZteOnuState::sample(self::WORKING_CMD));

        $r = OnuLiveState::fromZteProbe(['detail' => $detail, 'port' => ''], '1/5/5:3');

        $this->assertSame('FHTTC0000A01', $r['serial']);
        $this->assertSame('SOMEONE ELSE', $r['description']);
    }

    public function test_read_service_maps_transport_failure_to_not_ok(): void
    {
        ThrowingZteProbeService::bind($this->app);
        $olt = new Olt;
        $olt->vendor = 'zte_c300';
        $olt->id = 1;

        $read = app(OnuLiveReadService::class)->read($olt, '1/3/6:2');

        $this->assertFalse($read['ok']);
        $this->assertFalse($read['absent']);
    }

    public function test_huawei_is_conservative(): void
    {
        $this->assertSame(OnuLiveState::ONLINE, OnuLiveState::fromHuaweiDetail(['onu' => ['state' => 'online', 'serial_number' => 'FHTT1']])['state']);
        $this->assertSame(OnuLiveState::OFFLINE, OnuLiveState::fromHuaweiDetail(['onu' => ['state' => 'offline', 'serial_number' => 'FHTT1']])['state']);
        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromHuaweiDetail(['onu' => ['state' => null, 'serial_number' => 'FHTT1']])['state']);
        $this->assertSame(OnuLiveState::UNKNOWN, OnuLiveState::fromHuaweiDetail(['onu' => ['state' => 'ranging', 'serial_number' => 'FHTT1']])['state']);
    }
}

class ThrowingZteProbeService
{
    public static function bind($app): void
    {
        $app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                throw new \RuntimeException('ssh down');
            }
        });
    }
}
