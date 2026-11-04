<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCoordinateGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_customer_coordinates_are_stored_as_null(): void
    {
        $package = Package::create([
            'code' => 'COORD-GUARD',
            'name' => 'Coord Guard',
            'price' => 100000,
        ]);

        $customer = Customer::create([
            'code' => 'COORD-001',
            'name' => 'Coord Customer',
            'address' => 'Coord Address',
            'pppoe_user' => 'coord.customer',
            'package_id' => $package->id,
            'status' => 'pending_installation',
            'geo_lat' => '-6.372358',
            'geo_long' => '112607408',
        ]);

        $this->assertSame('-6.37235800', $customer->refresh()->geo_lat);
        $this->assertNull($customer->geo_long);
    }
}
