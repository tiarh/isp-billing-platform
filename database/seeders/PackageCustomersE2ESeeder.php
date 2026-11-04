<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the package detail browser spec: one package with 25 fake customers
 * (22 Aktif, 3 Isolir) so page 2 and the status filter have something to show. Fake data only.
 */
class PackageCustomersE2ESeeder extends Seeder
{
    public const PACKAGE_CODE = 'E2E-PKG-LIST';

    public const CUSTOMER_COUNT = 25;

    /** Positions (1-based) that are isolated; position 24 lands on page 2. */
    public const ISOLATED_POSITIONS = [3, 12, 24];

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('PackageCustomersE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $package = Package::query()->updateOrCreate(
            ['code' => self::PACKAGE_CODE],
            ['name' => 'Paket Uji Daftar Pelanggan', 'price' => 150000],
        );

        for ($i = 1; $i <= self::CUSTOMER_COUNT; $i++) {
            $label = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $customer = Customer::withTrashed()->where('code', 'E2E-PKG-'.$label)->first() ?? new Customer;
            $customer->forceFill([
                'code' => 'E2E-PKG-'.$label,
                'name' => 'Pelanggan Paket '.$label,
                'phone' => '0800000000',
                'address' => 'Alamat uji',
                'pppoe_user' => 'e2e-pkg-'.$label,
                'package_id' => $package->id,
                'status' => in_array($i, self::ISOLATED_POSITIONS, true) ? 'isolated' : 'active',
                'deleted_at' => null,
            ])->save();
        }
    }
}
