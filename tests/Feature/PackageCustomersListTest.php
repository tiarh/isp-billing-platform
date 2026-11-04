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
class PackageCustomersListTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $code = 'PKG-A'): Package
    {
        return Package::create(['name' => 'Paket '.$code, 'code' => $code, 'price' => 100000]);
    }

    private function customer(Package $package, string $code, string $name, string $status = 'active', ?string $pppoe = null, ?Area $area = null): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => $name,
            'phone' => '080000000000',
            'address' => 'Addr',
            'pppoe_user' => $pppoe ?? strtolower($code),
            'package_id' => $package->id,
            'area_id' => $area?->id,
            'status' => $status,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_list_is_paginated_with_default_20_and_page_two(): void
    {
        $package = $this->package();
        for ($i = 1; $i <= 25; $i++) {
            $this->customer($package, sprintf('PC-%03d', $i), sprintf('Nama %03d', $i));
        }

        $page1 = $this->actingAs($this->admin())->getJson(route('packages.customers', $package))->assertOk();
        $page1->assertJsonPath('total', 25)->assertJsonPath('per_page', 20)->assertJsonCount(20, 'data');

        $page2 = $this->actingAs($this->admin())->getJson(route('packages.customers', ['package' => $package, 'page' => 2]))->assertOk();
        $page2->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', 'Nama 021');

        $this->actingAs($this->admin())->getJson(route('packages.customers', ['package' => $package, 'limit' => 10]))
            ->assertJsonCount(10, 'data')->assertJsonPath('last_page', 3);
    }

    public function test_search_matches_name_code_and_pppoe(): void
    {
        $package = $this->package();
        $this->customer($package, 'PC-001', 'Budi Contoh', 'active', 'budi-net');
        $this->customer($package, 'PC-002', 'Siti', 'active', 'siti-net');
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'search' => 'Contoh']))->assertJsonPath('total', 1);
        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'search' => 'PC-002']))->assertJsonPath('total', 1);
        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'search' => 'siti-net']))->assertJsonPath('data.0.name', 'Siti');
        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'search' => 'tidak-ada']))->assertJsonPath('total', 0);
    }

    public function test_status_filter_supports_one_or_several_statuses_and_ignores_unknown(): void
    {
        $package = $this->package();
        $this->customer($package, 'PC-001', 'A', 'active');
        $this->customer($package, 'PC-002', 'B', 'isolated');
        $this->customer($package, 'PC-003', 'C', 'suspended');
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'status' => 'isolated']))
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'isolated');
        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'status' => 'isolated,suspended']))
            ->assertJsonPath('total', 2);
        $this->actingAs($admin)->getJson(route('packages.customers', ['package' => $package, 'status' => 'bogus']))
            ->assertJsonPath('total', 3);
    }

    public function test_only_this_package_and_no_soft_deleted_customers(): void
    {
        $package = $this->package('PKG-A');
        $other = $this->package('PKG-B');
        $this->customer($package, 'PC-001', 'Ada');
        $gone = $this->customer($package, 'PC-002', 'Terhapus');
        $this->customer($other, 'PC-003', 'Paket Lain');
        $gone->delete();

        $response = $this->actingAs($this->admin())->getJson(route('packages.customers', $package))->assertOk();

        $response->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Ada');
    }

    public function test_rows_carry_area_pppoe_status_and_online_flag(): void
    {
        $package = $this->package();
        $area = Area::create(['name' => 'Sukamaju', 'code' => 'SKM']);
        $this->customer($package, 'PC-001', 'Ada', 'isolated', 'ada-pppoe', $area);

        $this->actingAs($this->admin())->getJson(route('packages.customers', $package))
            ->assertJsonPath('data.0.area.name', 'Sukamaju')
            ->assertJsonPath('data.0.pppoe_user', 'ada-pppoe')
            ->assertJsonPath('data.0.status', 'isolated')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'pppoe_user', 'status', 'is_online', 'area']]]);
    }

    public function test_guests_and_non_global_roles_are_refused(): void
    {
        $package = $this->package();

        $this->getJson(route('packages.customers', $package))->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->getJson(route('packages.customers', $package))->assertForbidden();
    }

    public function test_array_search_and_status_params_do_not_error(): void
    {
        $package = $this->package();
        $this->customer($package, 'PC-001', 'Ada');
        $admin = $this->actingAs($this->admin());

        $admin->getJson(route('packages.customers', ['package' => $package, 'search' => ['x'], 'status' => ['active'], 'limit' => ['5'], 'page' => ['2']]))
            ->assertOk();
    }

    public function test_show_counts_use_the_table_population_and_billable_statuses(): void
    {
        $package = $this->package();
        $this->customer($package, 'PC-001', 'Aktif', 'active');
        $this->customer($package, 'PC-002', 'Isolir', 'isolated');
        $this->customer($package, 'PC-003', 'Cuti', 'suspended');
        $this->customer($package, 'PC-004', 'Berhenti', 'terminated');
        $this->customer($package, 'IMP-005', 'Impor', 'active');
        $this->customer($package, 'PC-006', 'Terhapus', 'active')->delete();

        $this->actingAs($this->admin())->get(route('packages.show', $package))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Packages/Show')
                ->where('package.customers_count', 4)
                ->where('package.billable_customers_count', 2));

        $this->actingAs($this->admin())->getJson(route('packages.customers', $package))->assertJsonPath('total', 4);
    }
}
