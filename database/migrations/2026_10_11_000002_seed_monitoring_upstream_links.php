<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoring_upstream_links')) {
            return;
        }

        $rows = [
            ['THC', 'THC (IPT-MIX Lintas Nusantara)', 'transit', 'VID.1329.SKYNET.LINTAS.NUSANTARA.IPT-MIX.EDGE2 VLAN 1329', 2000, 10],
            ['TLN', 'TLN', 'ix', 'Content_2101--TLN', 10000, 20],
            ['JKTIX', 'JKTIX', 'ix', 'Content_596--JKTIX', 10000, 30],
            ['IIX', 'IIX', 'ix', 'Content_597--IIX', 10000, 40],
            ['RBN', 'RBN (backup)', 'transit', '2.IPTRX_642--RBN', null, 50],
            ['DATAUTAMA', 'Datautama', 'transit', '1.IPTRX_1675--DATAUTAMA', null, 60],
            ['N3K_TOTAL', 'Total N3K (Bonding1)', 'aggregate', 'Bonding1-[N3K-Po1]', null, 90],
        ];

        $now = now();
        foreach ($rows as [$key, $name, $kind, $iface, $cap, $sort]) {
            // insertOrIgnore keyed on the unique upstream_key: never overwrites edits.
            DB::table('monitoring_upstream_links')->insertOrIgnore([
                'upstream_key' => $key,
                'display_name' => $name,
                'kind' => $kind,
                'router_label' => 'Jakarta-Edge',
                'interface_name' => $iface,
                'capacity_mbps' => $cap,
                'sort' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: seeded rows may have been edited by admins.
    }
};
