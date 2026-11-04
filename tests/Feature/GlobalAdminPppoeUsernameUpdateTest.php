<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalAdminPppoeUsernameUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_admin_can_update_customer_pppoe_username(): void
    {
        $package = Package::create([
            'code' => 'BASIC',
            'name' => 'Basic',
            'price' => 100000,
        ]);
        $customer = Customer::create([
            'code' => 'CUST001',
            'name' => 'Customer One',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'OLD_USER',
            'status' => 'active',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'address' => $customer->address,
            'package_id' => $package->id,
            'status' => $customer->status,
            'pppoe_user' => 'NEW_USER_RT01_RW01',
        ]);

        $response->assertRedirect(route('customers.index'));
        $this->assertSame('NEW_USER_RT01_RW01', $customer->fresh()->pppoe_user);
    }
}
