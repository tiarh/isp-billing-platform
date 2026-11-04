<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the "secret belum terhubung" browser spec: one fake router with 30 unlinked secrets
 * (so page 2 and search have something to show) and one package that matches their profile.
 * Row 02 is disabled, so the Tambah pelanggan form starts on "Menunggu Pasang"; the others start on "Aktif".
 * Fake data only.
 */
class RouterUnlinkedSecretsE2ESeeder extends Seeder
{
    public const ROUTER_NAME = 'Router Uji Secret';

    public const COUNT = 30;

    public const PROFILE = 'uji-10m';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('RouterUnlinkedSecretsE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $router = Router::query()->firstOrNew(['name' => self::ROUTER_NAME]);
        $router->forceFill([
            'ip_address' => '198.18.29.1',
            'port' => 8728,
            'is_active' => true,
            'last_scanned_at' => now()->subHour(),
        ]);
        // Kredensial router hanya supaya baris valid; router ini tidak pernah dihubungi.
        $router->forceFill(['username' => self::ROUTER_NAME]);
        $router->forceFill(['password' => 'canary-pw']);
        $router->save();

        Package::query()->updateOrCreate(
            ['code' => 'E2E-SECRET-10M'],
            ['name' => 'Paket Uji Secret 10M', 'price' => 150000, 'mikrotik_profile' => self::PROFILE, 'router_id' => $router->id, 'is_active' => true],
        );

        for ($i = 1; $i <= self::COUNT; $i++) {
            $label = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            RouterStagedCustomer::query()->updateOrCreate(
                ['router_id' => $router->id, 'pppoe_user' => 'USERUJI_RT01_RW01_ODP.'.$label],
                [
                    'profile' => self::PROFILE,
                    'disabled' => $i === 2,
                    'status' => 'unmatched',
                    'matched_customer_id' => null,
                    'first_seen_at' => now()->subDays(3),
                    'last_seen_at' => now()->subHour(),
                ],
            );
        }
    }
}
