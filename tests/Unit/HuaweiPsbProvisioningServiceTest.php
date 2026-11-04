<?php

namespace Tests\Unit;

use App\Models\Olt;
use App\Models\PsbDraft;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\PsbSiteRegistry;
use Mockery;
use Tests\TestCase;

class HuaweiPsbProvisioningServiceTest extends TestCase
{
    public function test_gadang_huawei_registry_targets_huawei_olt_and_router_ids_without_replacing_hioso(): void
    {
        $sites = collect((new PsbSiteRegistry)->builtinSites());
        $legacy = $sites->firstWhere('key', 'gadang');
        $site = $sites->firstWhere('key', 'gadang_huawei');

        $this->assertSame(PsbSiteRegistry::MODE_PPPOE_VLAN, $legacy['mode']);
        $this->assertSame('hioso', $legacy['vendor']);
        $this->assertSame(511, $site['router_id']);
        $this->assertSame(11, $site['olt_id']);
        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $site['mode']);
        $this->assertSame('huawei_ma5608t', $site['vendor']);
    }

    public function test_preflight_maps_autofind_port_and_first_free_ont_id(): void
    {
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('collectAutofind')->once()->andReturn([
            'command' => 'display ont autofind all',
            'matches' => [[
                'pon_port' => '0/0/7',
                'serial_number' => 'FHTT0000A03A',
                'vendor_id' => 'FHTT',
            ]],
        ]);
        $huawei->shouldReceive('collectSnapshot')->once()->andReturn(['onus' => []]);
        $huawei->shouldReceive('collectPortOnus')->once()->withArgs(function (Olt $olt, string $pon): bool {
            return $pon === '0/0/7';
        })->andReturn([
            'command' => 'display ont info 0 0 7 all',
            'onus' => array_map(fn (int $id): array => [
                'pon_port' => '0/0/7',
                'onu_id' => $id,
            ], range(0, 26)),
        ]);
        $huawei->shouldReceive('nextOnuId')->once()->andReturn(27);
        $huawei->shouldReceive('profileFor')->once()->with('0/0/7', 'FHTT0000A03A', 'FHTT')->andReturn([
            'vlan' => 108,
            'line_profile' => 26,
            'tr069_vlan' => 69,
        ]);
        $huawei->shouldReceive('buildPlan')->once()->with(
            'FHTT0000A03A',
            'TEST',
            '0/0/7',
            27,
            'FHTT',
            108,
            69,
        )->andReturn([
            'commands' => [
                'config',
                'interface gpon 0/0',
                'ont add 7 27 sn-auth "FHTT0000A03A" omci ont-lineprofile-id 26 ont-srvprofile-id 10 desc "TEST"',
                'display ont info by-sn FHTT0000A03A',
                'service-port vlan 108 gpon 0/0/7 ont 27 gemport 1',
                'save',
            ],
            'command_hash' => 'plan-hash',
        ]);

        $result = (new HuaweiPsbProvisioningService($huawei))->preflight(
            new PsbDraft(['sn' => 'FHTT0000A03A', 'pppoe_user' => 'TEST']),
            new Olt(['id' => 11, 'name' => 'OLT GADANG 3', 'vendor' => 'huawei_ma5608t']),
        );

        $this->assertTrue($result['eligible'], json_encode($result));
        $this->assertSame('0/0/7', $result['placement']['pon_port']);
        $this->assertSame(27, $result['placement']['onu_id']);
        $this->assertSame(108, $result['profile']['vlan']);
        $this->assertSame(26, $result['profile']['line_profile']);
        $this->assertStringContainsString('ont add 7 27', implode('\n', $result['plan']['commands']));
    }

    public function test_preflight_blocks_duplicate_serial_without_device_write_plan(): void
    {
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('collectAutofind')->once()->andReturn([
            'matches' => [[
                'pon_port' => '0/0/7',
                'serial_number' => 'FHTT0000A03A',
            ]],
        ]);
        $huawei->shouldReceive('collectSnapshot')->once()->andReturn([
            'onus' => [[
                'pon_port' => '0/0/4',
                'onu_ref' => '0/0/4:12',
                'serial_number' => 'FHTT0000A03A',
            ]],
        ]);

        $result = (new HuaweiPsbProvisioningService($huawei))->preflight(
            new PsbDraft(['sn' => 'FHTT0000A03A']),
            new Olt(['id' => 11, 'name' => 'OLT GADANG 3', 'vendor' => 'huawei']),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame('sn_already_registered', $result['reason']);
        $this->assertArrayNotHasKey('plan', $result);
    }

    public function test_preflight_rejects_non_huawei_target_before_ssh(): void
    {
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldNotReceive('collectAutofind');

        $result = (new HuaweiPsbProvisioningService($huawei))->preflight(
            new PsbDraft(['sn' => 'FHTT0000A03A']),
            new Olt(['id' => 1, 'name' => 'OLT RA C300', 'vendor' => 'zte']),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame('target_olt_is_not_huawei', $result['reason']);
    }

    public function test_preflight_resumes_matching_registered_ont_without_repeating_ont_add(): void
    {
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('collectAutofind')->once()->andReturn(['matches' => []]);
        $huawei->shouldReceive('collectSnapshot')->once()->andReturn([
            'onus' => [[
                'pon_port' => '0/0/7',
                'onu_ref' => '0/0/7:27',
                'onu_id' => 27,
                'serial_number' => 'FHTT0000A03A',
                'vendor_id' => 'FHTT',
            ]],
        ]);
        $huawei->shouldReceive('profileFor')->once()->andReturn(['vlan' => 108, 'line_profile' => 26, 'tr069_vlan' => 69]);
        $huawei->shouldReceive('buildPlan')->once()->withArgs(function (...$args): bool {
            return $args[0] === 'FHTT0000A03A' && $args[2] === '0/0/7' && $args[3] === 27 && $args[7] === true;
        })->andReturn(['commands' => ['service-port vlan 108 ...']]);

        $draft = new PsbDraft([
            'sn' => 'FHTT0000A03A',
            'pppoe_user' => 'TEST',
            'meta' => ['huawei_psb_preflight' => ['pon_port' => '0/0/7', 'onu_id' => 27]],
        ]);
        $result = (new HuaweiPsbProvisioningService($huawei))->preflight(
            $draft,
            new Olt(['name' => 'OLT GADANG 3', 'vendor' => 'huawei']),
        );

        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['resume']);
        $this->assertSame('registered_olt_resume', $result['placement']['source']);
    }
}
