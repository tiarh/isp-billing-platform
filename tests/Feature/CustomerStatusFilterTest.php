<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_index_filters_inactive_customers_and_exposes_ui_option(): void
    {
        $package = Package::create([
            'code' => 'STATUS-FILTER',
            'name' => 'Status filter package',
            'price' => 125000,
        ]);

        Customer::create([
            'code' => 'INACTIVE-001',
            'name' => 'Inactive customer',
            'phone' => '081200000001',
            'address' => 'Inactive address',
            'package_id' => $package->id,
            'pppoe_user' => 'inactive-001',
            'status' => 'inactive',
        ]);
        Customer::create([
            'code' => 'ACTIVE-001',
            'name' => 'Active customer',
            'phone' => '081200000002',
            'address' => 'Active address',
            'package_id' => $package->id,
            'pppoe_user' => 'active-001',
            'status' => 'active',
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('customers.index', ['status' => 'inactive']));

        $response->assertOk();
        $rows = $response->viewData('page')['props']['customers']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('inactive', $rows[0]['status']);

        $source = file_get_contents(resource_path('js/Pages/Customers/Index.tsx'));
        // Status 2.2: filter options come from the shared 5 official statuses; legacy 'inactive'
        // stays filterable via the URL (backend) but is no longer an option in the dropdown.
        $this->assertStringContainsString('...CUSTOMER_STATUS_OPTIONS', $source);
        $labels = file_get_contents(resource_path('js/lib/customerLabels.ts'));
        $this->assertStringContainsString("inactive: 'Berhenti (lama)'", $labels);
    }

    public function test_customers_index_rows_do_not_carry_project_classification(): void
    {
        $package = Package::create([
            'code' => 'NO-PROJECT',
            'name' => 'No project package',
            'price' => 125000,
        ]);
        Customer::create([
            'code' => 'NOPROJ-001',
            'name' => 'No project customer',
            'phone' => '081200000003',
            'address' => 'Address',
            'package_id' => $package->id,
            'pppoe_user' => 'noproj-001',
            'status' => 'active',
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('customers.index'));

        $response->assertOk();
        $rows = $response->viewData('page')['props']['customers']['data'];

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('project', $row);
            $this->assertArrayNotHasKey('project_label', $row);
            $this->assertArrayNotHasKey('service_origin', $row);
        }
    }
}
