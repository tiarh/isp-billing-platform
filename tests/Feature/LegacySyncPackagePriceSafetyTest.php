<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Services\LegacySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class LegacySyncPackagePriceSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_scraper_price_does_not_reset_existing_package_price(): void
    {
        config(['services.legacy_scraper.url' => 'http://legacy.test']);
        Http::fake([
            'http://legacy.test/api/v1/packages' => Http::response([
                ['name' => 'Paket Aman'],
            ]),
        ]);

        $package = Package::create([
            'name' => 'Paket Aman',
            'code' => 'PKG-AMAN',
            'price' => 150000,
        ]);

        $this->assertSame(1, app(LegacySyncService::class)->syncPackages());
        $this->assertSame('150000.00', $package->fresh()->price);
    }

    public function test_zero_scraper_price_does_not_reset_existing_package_price(): void
    {
        config(['services.legacy_scraper.url' => 'http://legacy.test']);
        Http::fake([
            'http://legacy.test/api/v1/packages' => Http::response([
                ['name' => 'Paket Aman', 'price' => 0],
            ]),
        ]);

        $package = Package::create([
            'name' => 'Paket Aman',
            'code' => 'PKG-AMAN',
            'price' => 150000,
        ]);

        $this->assertSame(1, app(LegacySyncService::class)->syncPackages());
        $this->assertSame('150000.00', $package->fresh()->price);
    }

    public function test_customer_keeps_existing_package_when_scraper_name_only_differs_by_case_and_whitespace(): void
    {
        config(['services.legacy_scraper.url' => 'http://legacy.test']);
        Http::fake([
            'http://legacy.test/api/v1/customers' => Http::response([
                [
                    'id' => 'LEG-001',
                    'code' => 'CUST-001',
                    'name' => 'Existing Package Customer',
                    'address' => 'Randuagung',
                    'phone' => '081234567890',
                    'geo_lat' => null,
                    'geo_long' => null,
                    'pppoe_user' => 'cust-001',
                    'ktp_photo_url' => null,
                    'package' => ['name' => '  paket   aman  '],
                    'area_name' => 'Randuagung',
                    'status' => 'active',
                    'due_day' => 20,
                    'is_online' => false,
                ],
            ]),
        ]);

        $package = Package::create([
            'name' => 'Paket Aman',
            'code' => 'PKG-AMAN',
            'price' => 150000,
        ]);
        $customer = Customer::create([
            'code' => 'CUST-001',
            'name' => 'Existing Package Customer',
            'address' => 'Randuagung',
            'pppoe_user' => 'cust-001',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        app(LegacySyncService::class)->syncCustomers();

        $this->assertSame($package->id, $customer->fresh()->package_id);
        $this->assertSame('150000.00', $package->fresh()->price);
    }

    public function test_customer_keeps_existing_package_when_scraper_package_is_unresolved(): void
    {
        config(['services.legacy_scraper.url' => 'http://legacy.test']);
        Http::fake([
            'http://legacy.test/api/v1/customers' => Http::response([
                [
                    'id' => 'LEG-002',
                    'code' => 'CUST-002',
                    'name' => 'Unresolved Package Customer',
                    'address' => 'Randuagung',
                    'phone' => '081234567891',
                    'geo_lat' => null,
                    'geo_long' => null,
                    'pppoe_user' => 'cust-002',
                    'ktp_photo_url' => null,
                    'package' => ['name' => 'Paket Tidak Terdaftar'],
                    'area_name' => 'Randuagung',
                    'status' => 'active',
                    'due_day' => 20,
                    'is_online' => false,
                ],
            ]),
        ]);

        $package = Package::create([
            'name' => 'Paket Aman',
            'code' => 'PKG-AMAN',
            'price' => 150000,
        ]);
        $customer = Customer::create([
            'code' => 'CUST-002',
            'name' => 'Unresolved Package Customer',
            'address' => 'Randuagung',
            'pppoe_user' => 'cust-002',
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ]);

        app(LegacySyncService::class)->syncCustomers();

        $this->assertSame($package->id, $customer->fresh()->package_id);
        $this->assertDatabaseMissing('packages', ['name' => 'Legacy/Unknown Package']);
    }
}
