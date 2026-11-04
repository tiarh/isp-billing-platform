<?php

return [
    [
        'name' => 'cross_router_migration',
        'message' => <<<'TEXT'
#migrasi
Nama: PELANGGAN UJI SATU
PPPoE: PELANGGAN_RANDUAGUNG_01
Router lama: Randuagung
Router baru: skynet-ra-olt
SN lama: FHTTOLD001
SN baru: FHTTNEW001
Keterangan: pindah router target
TEXT,
        'expected' => [
            'pppoe_user' => 'PELANGGAN_RANDUAGUNG_01',
            'old_router_field' => 'Randuagung',
            'new_router_field' => 'skynet-ra-olt',
            'router_field' => 'skynet-ra-olt',
            'old_sn' => 'FHTTOLD001',
            'new_sn' => 'FHTTNEW001',
        ],
    ],
    [
        'name' => 'fiberhome_replacement_same_target',
        'message' => <<<'TEXT'
#migrasi
Nama: PELANGGAN UJI DUA
PPPoE: PELANGGAN_RA_02
Router lama: randuagung
Router baru: SKYNET-RA-OLT
SN lama: FHTTOLD002
SN baru: FHTTNEW002
Keterangan: perangkat FiberHome rusak, ganti perangkat baru
TEXT,
        'expected' => [
            'pppoe_user' => 'PELANGGAN_RA_02',
            'old_router_field' => 'randuagung',
            'new_router_field' => 'SKYNET-RA-OLT',
            'router_field' => 'SKYNET-RA-OLT',
            'old_sn' => 'FHTTOLD002',
            'new_sn' => 'FHTTNEW002',
        ],
    ],
    [
        'name' => 'same_sn_retry',
        'message' => <<<'TEXT'
#migrasi
PPPoE: PELANGGAN_RA_03
Router baru: skynet-ra-olt
SN baru: FHTTNEW003
Keterangan: kirim ulang pesan yang sama
TEXT,
        'expected' => [
            'pppoe_user' => 'PELANGGAN_RA_03',
            'old_router_field' => null,
            'new_router_field' => 'skynet-ra-olt',
            'router_field' => 'skynet-ra-olt',
            'old_sn' => null,
            'new_sn' => 'FHTTNEW003',
        ],
    ],
];
