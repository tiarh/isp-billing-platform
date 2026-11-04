<?php

/**
 * RCA-3: per product_class mapping of the CPE parameter tree (GenieACS). Built from the real NBI samples of
 * 2026-10-17: the whole fleet is FiberHome TR-098 (InternetGatewayDevice). Paths are relative to `root`.
 *
 * `band_verified` is a code constant on purpose (not a setting): it is true only after the rollout check R3
 * (PossibleChannels of the primary 5 GHz instance contains 36+ on >= 95 % of devices). R3 passed on prod on
 * 2026-10-18 for the three FiberHome models below (5 GHz 36+ and 2.4 GHz 1-13 on 100 % of reporting devices).
 * While false (unknown models), the WLAN rules may only report "sedang" confidence.
 *
 * An unknown product_class, or a TR-181 (`Device.`) root, resolves to `generic`: it only reads UpTime and the
 * `_lastBoot` meta field and never guesses WLAN paths. ZTE / Huawei CPEs are "not supported yet" until a real
 * sample exists.
 */
$fiberhomeF3 = [
    'family' => 'fiberhome_tr098',
    'supported' => true,
    'root' => 'InternetGatewayDevice',
    // Primary instances: 1..8 = 2.4 GHz (ra0..ra7), 9..16 = 5 GHz (ra8..).
    'wlan' => ['band24' => [1], 'band5' => [9]],
    'band_verified' => true, // R3 passed on prod 2026-10-18 (HG6045F3/HG6145F3 inst 9, HG6145D2 inst 5)
    'assoc_instances' => range(1, 16),
    // AssociatedDevice.{j} is discovered on the F3 models, so refreshObject on it is safe.
    'assoc_refresh' => true,
    'paths' => [
        'wlan' => 'LANDevice.1.WLANConfiguration.{i}',
        'wlan_status' => 'Status',
        'wlan_enable' => 'Enable',
        'wlan_radio' => 'RadioEnabled',
        'wlan_assoc' => 'TotalAssociations',
        'wlan_channel' => 'Channel',
        'wlan_possible_channels' => 'PossibleChannels',
        'assoc_device' => 'AssociatedDevice',
        'assoc_rssi' => 'AssociatedDeviceRSSI',
        'hosts' => 'LANDevice.1.Hosts.Host',
        'host_rssi' => 'RSSI',
        'host_interface' => 'InterfaceType',
        'host_active' => 'Active',
        'ppp' => 'WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1',
        'ppp_status' => 'ConnectionStatus',
        'ppp_error' => 'LastConnectionError',
        'ppp_uptime' => 'Uptime',
        'lan_port_status' => 'LANDevice.1.LANEthernetInterfaceConfig.{i}.Status',
        'model_name' => 'DeviceInfo.ModelName',
        'uptime' => 'DeviceInfo.UpTime',
    ],
    'lan_ports' => 4,
];

$fiberhomeD2 = array_replace_recursive($fiberhomeF3, [
    // 8 WLAN instances: 1 = 2.4 GHz, 5 = 5 GHz.
    'wlan' => ['band24' => [1], 'band5' => [5]],
    'assoc_refresh' => false, // AssociatedDevice is NOT discovered on the HG6145D2
]);
$fiberhomeD2['assoc_instances'] = range(1, 8);

return [
    'models' => [
        'HG6045F3' => $fiberhomeF3,
        'HG6145F3' => $fiberhomeF3,
        'HG6145D2' => $fiberhomeD2,
    ],

    'generic' => [
        'family' => 'generic',
        'supported' => false,
        'root' => 'InternetGatewayDevice',
        'wlan' => ['band24' => [], 'band5' => []],
        'band_verified' => false,
        'assoc_instances' => [],
        'assoc_refresh' => false,
        'paths' => [
            'uptime' => 'DeviceInfo.UpTime',
        ],
        'lan_ports' => 0,
    ],
];
