<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketAreaScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_admin_only_sees_customers_in_assigned_areas_on_ticket_create(): void
    {
        [$areaA, , $customerA, $customerB] = $this->seedCustomers();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $response = $this->actingAs($admin)->get(route('tickets.create'));

        $response->assertOk();
        $customers = $response->viewData('page')['props']['customers'];

        $this->assertCount(1, $customers);
        $this->assertSame($customerA->id, $customers[0]['id']);
        $this->assertFalse(collect($customers)->pluck('id')->contains($customerB->id));
    }

    public function test_scoped_admin_cannot_create_ticket_for_customer_outside_area(): void
    {
        [$areaA, , , $customerB] = $this->seedCustomers();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)->post(route('tickets.store'), [
            'customer_id' => $customerB->id,
            'gangguan' => 'Internet mati total',
            'type' => 'incident',
            'priority' => 'high',
        ])->assertForbidden();
    }

    private function seedCustomers(): array
    {
        $areaA = Area::create(['code' => 'A', 'name' => 'Area A']);
        $areaB = Area::create(['code' => 'B', 'name' => 'Area B']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        $customerA = Customer::create([
            'code' => 'A001',
            'name' => 'Customer A',
            'phone' => '0811111111',
            'address' => 'Addr A',
            'area_id' => $areaA->id,
            'package_id' => $package->id,
            'pppoe_user' => 'a001',
            'status' => 'active',
        ]);

        $customerB = Customer::create([
            'code' => 'B001',
            'name' => 'Customer B',
            'phone' => '0810000014',
            'address' => 'Addr B',
            'area_id' => $areaB->id,
            'package_id' => $package->id,
            'pppoe_user' => 'b001',
            'status' => 'active',
        ]);

        return [$areaA, $areaB, $customerA, $customerB];
    }
}
