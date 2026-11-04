<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sandbox-only fixture for the "Tandai selesai" browser spec: one FAILED migration that already has device traces
 * (MikroTik moved + OLT provisioned), whose ONU is online but whose PPPoE is not, so the dialog shows a warning.
 * Fake data only; no WA, no device, no queue.
 */
class MigrationManualCompleteE2ESeeder extends Seeder
{
    public const PPPOE = 'e2e.mc.1';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('MigrationManualCompleteE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $router = Router::firstOrCreate(['name' => 'E2E-MC-RTR'], ['ip_address' => '10.99.0.1', 'username' => 'admin', 'password' => 'canary-pw', 'is_active' => true]);
        $olt = Olt::firstOrCreate(['code' => 'E2E-MC-OLT'], ['name' => 'OLT E2E Selesai', 'vendor' => 'zte_c300', 'router_id' => $router->id]);
        $package = Package::firstOrCreate(['code' => 'E2E-MC-PKG'], ['name' => 'E2E Selesai', 'price' => 100000]);

        $customer = Customer::firstOrCreate(['code' => 'E2E-MC-C1'], [
            'name' => 'Pelanggan Selesai Uji',
            'phone' => '08000009001',
            'address' => 'Alamat contoh',
            'pppoe_user' => self::PPPOE,
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'is_online' => false,
        ]);

        OltOnu::updateOrCreate(['olt_id' => $olt->id, 'onu_ref' => '1/2/1:7'], [
            'pon_port' => '1/2/1',
            'serial_number' => 'FHTTE2E00007',
            'onu_name' => self::PPPOE,
            'state' => 'online',
            'state_collected_at' => now()->subMinutes(4),
            'inventory_collected_at' => now()->subMinutes(4),
        ]);

        $migration = CustomerMigration::firstOrCreate(['message_id' => 'e2e-mc-1'], [
            'status' => CustomerMigration::STATUS_FAILED,
            'source' => 'wa',
            'group_jid' => 'e2e-grup@g.us',
            'customer_id' => $customer->id,
            'pppoe_user' => self::PPPOE,
            'full_name' => $customer->name,
            'old_router_id' => $router->id,
            'router_id' => $router->id,
            'new_olt_id' => $olt->id,
            'new_pon_port' => '1/2/1',
            'new_onu_ref' => '1/2/1:7',
            'new_sn' => 'FHTTE2E00007',
            'last_error' => 'Timeout saat set TR-069 (contoh)',
            'olt_at' => now()->subHours(3),
            'meta' => [
                'input_identity' => ['pppoe_user' => self::PPPOE],
                'mikrotik_migration' => ['ok' => true],
                'olt_provisioned_at' => now()->subHours(3)->toIso8601String(),
            ],
        ]);
        DB::table('customer_migrations')->where('id', $migration->id)->update([
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(3),
        ]);
    }
}
