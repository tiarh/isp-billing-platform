<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditCustomerPhonesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_masks_canonical_phone_and_does_not_change_customer(): void
    {
        $package = Package::create([
            'code' => 'PHONE-AUDIT',
            'name' => 'Phone audit',
            'price' => 100000,
        ]);
        $customer = Customer::create([
            'code' => 'PHONE-AUDIT-001',
            'name' => 'Phone Audit',
            'phone' => '081234567890',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'phone.audit.001',
            'status' => 'active',
        ]);
        $path = storage_path('app/phone-audit-test.csv');
        @unlink($path);

        try {
            $this->artisan('billing:audit-phones', ['--output' => $path])
                ->assertSuccessful();

            $csv = (string) file_get_contents($path);
            $this->assertStringContainsString('canonical_local', $csv);
            $this->assertStringNotContainsString('081234567890', $csv);
            $this->assertMatchesRegularExpression('/08\*+90/', $csv);
            $this->assertSame('081234567890', $customer->refresh()->phone);
        } finally {
            @unlink($path);
        }
    }
}
