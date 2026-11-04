<?php

namespace Tests\Unit;

use App\Services\GenieAcsService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[Group('blocking-psb')]
class GenieAcsFiberHomeMappingTest extends TestCase
{
    public function test_it_maps_fiberhome_virtual_parameters_to_device_fields(): void
    {
        $device = [
            '_id' => '000AC2-HG6045F3-FHTT0000A035',
            '_deviceId' => ['_SerialNumber' => 'FHTT0000A035', '_OUI' => '000AC2', '_ProductClass' => 'HG6045F3'],
            'VirtualParameters' => [
                'ontSerial' => ['_value' => 'FHTT0000A035'],
                'ponMode' => ['_value' => 'GPON'],
                'ontRxPower' => ['_value' => -18.96],
                'ontTemp' => ['_value' => 41.3],
                'ontUptime' => ['_value' => '25d 16:24:08'],
                'pppUptime' => ['_value' => '6d 18:42:14'],
                'ipTr069' => ['_value' => '198.18.40.239'],
                'pppIp' => ['_value' => '198.18.31.11'],
                'pppMac' => ['_value' => '64:9C:F3:67:AC:83'],
                'pppUsername' => ['_value' => 'SKYNET_1'],
                'wifiSsid' => ['_value' => 'Lantai 2'],
                'wifiSsid5g' => ['_value' => 'lantai2_5G'],
                'wifiClients' => ['_value' => '8'],
            ],
        ];

        $fields = $this->extract($device);

        $this->assertSame('FHTT0000A035', $fields['serial_number']);
        $this->assertSame('GPON', $fields['pon_mode']);
        $this->assertSame('SKYNET_1', $fields['pppoe_user']);
        $this->assertSame('Lantai 2', $fields['ssid']);
        $this->assertSame('lantai2_5G', $fields['ssid_5g']);
        $this->assertSame('198.18.31.11', $fields['ip_address']);
        $this->assertSame('198.18.40.239', $fields['ip_tr069']);
        $this->assertSame('64:9C:F3:67:AC:83', $fields['mac_address']);
        $this->assertSame('25d 16:24:08', $fields['uptime_text']);
        $this->assertSame('6d 18:42:14', $fields['pppoe_uptime']);
        $this->assertSame(8, $fields['wifi_clients']);
        $this->assertSame('Up', $fields['wan_status']);
    }

    public function test_it_prefers_the_ssid5g_vp_over_the_default_wlan_index(): void
    {
        $device = [
            '_id' => 'dev',
            'VirtualParameters' => ['wifiSsid5g' => ['_value' => 'fh_real_5G']],
            'InternetGatewayDevice' => [
                'LANDevice' => [
                    '1' => [
                        'WLANConfiguration' => [
                            '5' => ['SSID' => ['_value' => 'fh_ssid4']],
                            '9' => ['SSID' => ['_value' => 'fh_real_5G']],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame('fh_real_5G', $this->extract($device)['ssid_5g']);
    }

    /** @param array<string, mixed> $device @return array<string, mixed> */
    private function extract(array $device): array
    {
        $service = new GenieAcsService;

        return (new ReflectionMethod($service, 'extractDeviceFields'))->invoke($service, $device);
    }
}
