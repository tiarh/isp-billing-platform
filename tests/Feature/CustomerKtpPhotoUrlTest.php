<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerKtpPhotoUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_ktp_photo_accessor_repairs_doubled_legacy_url(): void
    {
        $customer = Customer::create([
            'code' => 'KTP-001',
            'name' => 'KTP Customer',
            'phone' => '080000000001',
            'address' => 'KTP Address',
            'pppoe_user' => 'ktp.customer',
            'package_id' => $this->package()->id,
            'status' => 'active',
            'ktp_photo_url' => 'https://e.example.test:2096/img/ktp/https://e.example.test:2096/img/ktp/43701765.jpeg',
        ]);

        $this->assertSame(
            'https://e.example.test:2096/img/ktp/43701765.jpeg',
            $customer->ktp_photo_url
        );
    }

    public function test_customer_ktp_photo_accessor_hides_incomplete_legacy_directory_url(): void
    {
        $customer = Customer::create([
            'code' => 'KTP-002',
            'name' => 'KTP Directory',
            'phone' => '080000000002',
            'address' => 'KTP Address',
            'pppoe_user' => 'ktp.directory',
            'package_id' => $this->package()->id,
            'status' => 'active',
            'ktp_photo_url' => 'https://e.example.test:2096/img/ktp/https://e.example.test:2096/img/ktp/',
        ]);

        $this->assertNull($customer->ktp_photo_url);
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'KTP Package',
            'code' => 'KTP-PKG-'.uniqid(),
            'price' => 100000,
        ]);
    }
}
