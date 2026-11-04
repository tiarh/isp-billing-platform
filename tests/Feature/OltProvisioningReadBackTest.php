<?php

namespace Tests\Feature;

use App\Exceptions\Olt\ProvisionReadBackException;
use App\Models\Olt;
use App\Services\Olt\ReadBack\OnuReadBackVerifier;
use App\Services\Olt\ReadBack\ZteC300ProvisioningReadBackVerifier;
use App\Services\OltZteC300Service;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class OltProvisioningReadBackTest extends TestCase
{
    private function makeVerifier(array $baseinfo, array $state, string $runningConfig = ''): OnuReadBackVerifier
    {
        $service = Mockery::mock(OltZteC300Service::class);
        $service->shouldReceive('onuDetail')->andReturn([
            'raw' => [
                'baseinfo' => $baseinfo['raw'] ?? '',
                'state' => $state['raw'] ?? '',
                'running_config' => $runningConfig,
            ],
            'commands' => [],
            'onu' => [],
        ]);
        $olt = new Olt;

        return new ZteC300ProvisioningReadBackVerifier($service);
    }

    public function test_happy_path_serial_state_and_phase_all_match(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-TEST0001\n"],
            state: ['raw' => "state: working\nphase_state: O5\n"]
        );

        $this->assertTrue($verifier->verify(
            new Olt,
            '1/3/11:5',
            [
                'serial' => 'ZTEG-TEST0001',
                'state' => 'working',
                'phase_state' => 'O5',
            ]
        ));
    }

    public function test_sad_path_serial_mismatch_throws(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-WRONG\n"],
            state: ['raw' => '']
        );

        $this->expectException(ProvisionReadBackException::class);
        $verifier->verify(new Olt, '1/3/11:5', ['serial' => 'ZTEG-EXPECTED']);
    }

    public function test_sad_path_state_mismatch_throws(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-TEST0001\n"],
            state: ['raw' => "state: dying-gasp\nphase_state: O5\n"]
        );

        $this->expectException(ProvisionReadBackException::class);
        $verifier->verify(new Olt, '1/3/11:5', [
            'serial' => 'ZTEG-TEST0001',
            'state' => 'working',
        ]);
    }

    public function test_sad_path_phase_state_mismatch_throws(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-TEST0001\n"],
            state: ['raw' => "state: working\nphase_state: O1\n"]
        );

        $this->expectException(ProvisionReadBackException::class);
        $verifier->verify(new Olt, '1/3/11:5', [
            'serial' => 'ZTEG-TEST0001',
            'phase_state' => 'O5',
        ]);
    }

    public function test_sad_path_service_profile_missing_throws(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-TEST0001\n"],
            state: ['raw' => ''],
            runningConfig: "interface gpon-onu_1/3/11:5\n  description foo\n"
        );

        $this->expectException(ProvisionReadBackException::class);
        $verifier->verify(new Olt, '1/3/11:5', [
            'serial' => 'ZTEG-TEST0001',
            'service_profile' => 'skynet-1g',
        ]);
    }

    public function test_happy_path_legacy_serial_with_space_recognized(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "Serial Number: ZTEG-LEGACY001\n"],
            state: ['raw' => '']
        );

        $this->assertTrue($verifier->verify(new Olt, '1/3/11:5', [
            'serial' => 'ZTEG-LEGACY001',
        ]));
    }

    public function test_happy_path_no_expected_keys_returns_true(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => ''],
            state: ['raw' => '']
        );

        // Empty expected should still return true (no key to verify against).
        $this->assertTrue($verifier->verify(new Olt, '1/3/11:5', []));
    }

    public function test_happy_path_distance_key_skipped_gracefully(): void
    {
        $verifier = $this->makeVerifier(
            baseinfo: ['raw' => "SerialNumber: ZTEG-TEST0001\n"],
            state: ['raw' => "state: working\nphase_state: O5\n"]
        );

        // distance_m recognition convention is verifier-internal; the
        // interface advertises 'distance_m' as a recognized expected key.
        // This test pins that unknown keys silently skip rather than fail.
        $this->assertTrue($verifier->verify(new Olt, '1/3/11:5', [
            'serial' => 'ZTEG-TEST0001',
            'state' => 'working',
            'phase_state' => 'O5',
            'distance_m' => 1240,
        ]));
    }
}
