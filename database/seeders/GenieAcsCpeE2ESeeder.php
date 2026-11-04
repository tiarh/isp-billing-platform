<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Sandbox-only fixture for the GenieACS WiFi browser spec (outage RCA-3): one device with fresh WLAN data
 * (5 GHz off) and one with only stale data (columns NULL, so the page must show "data lama").
 * Fake serials / names only. No customer link, no GenieACS call.
 */
class GenieAcsCpeE2ESeeder extends Seeder
{
    public const FRESH = 'E2E-CPE-FRESH';

    public const STALE = 'E2E-CPE-STALE';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('GenieAcsCpeE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }
        if (! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
            throw new RuntimeException('Migrasi RCA-3 belum jalan.');
        }

        $now = now();
        $base = [
            'product_class' => 'HG6045F3',
            'model' => 'HG6045F3',
            'manufacturer' => 'FiberHome',
            'snapshot_status' => 'active',
            'last_inform_at' => $now->copy()->subMinutes(3),
            'last_seen_sync_at' => $now->copy()->subMinutes(3),
            'uptime_seconds' => 90000,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('genieacs_devices')->whereIn('device_id', [self::FRESH, self::STALE])->delete();
        // two separate inserts: the rows have different column sets
        DB::table('genieacs_devices')->insert(
            $base + [
                'device_id' => self::FRESH,
                'serial_number' => 'FHTTE2E00001',
                'pelanggan' => 'Uji WiFi Segar',
                'ssid' => 'WIFI-UJI-SEGAR',
                'last_boot_at' => $now->copy()->subHours(30),
                'wlan24_status' => 'Up',
                'wlan24_enabled' => 1,
                'wlan24_assoc' => 3,
                'wlan5_status' => 'Disabled',
                'wlan5_enabled' => 0,
                'wlan5_assoc' => 0,
                'wlan_values_at' => $now->copy()->subMinutes(10),
            ]
        );
        DB::table('genieacs_devices')->insert(
            $base + [
                'device_id' => self::STALE,
                'serial_number' => 'FHTTE2E00002',
                'pelanggan' => 'Uji WiFi Lama',
                'ssid' => 'WIFI-UJI-LAMA',
                // what the sync keeps for a value older than the limit: NULL columns + raw value with its age
                'parameters' => json_encode(['cpe_raw' => ['wlan24' => ['status' => 'Up', 'assoc' => 4, 'age_min' => 36000]]]),
            ]
        );
    }
}
