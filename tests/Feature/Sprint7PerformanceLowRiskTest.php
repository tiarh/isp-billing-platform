<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint7PerformanceLowRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_index_uses_invoice_aggregates_without_loading_invoice_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $area = Area::create(['code' => 'S7', 'name' => 'Sprint 7']);
        $package = Package::create(['code' => 'S7P', 'name' => 'Sprint 7 Paket', 'price' => 175000]);
        $customer = Customer::create([
            'code' => 'S7-001',
            'name' => 'Pelanggan Sprint 7',
            'phone' => '081234567890',
            'address' => 'RW 4 Randuagung',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'sprint7-001',
            'status' => 'active',
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 175000,
            'due_date' => now()->subDays(5)->format('Y-m-d'),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
        Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
            'amount' => 175000,
            'due_date' => now()->addDays(10)->format('Y-m-d'),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('customers.index'));

        $response->assertOk();
        $firstCustomer = $response->viewData('page')['props']['customers']['data'][0];

        $this->assertSame(2, $firstCustomer['unpaid_periods_count']);
        $this->assertSame(now()->subDays(5)->format('Y-m-d'), substr((string) $firstCustomer['next_unpaid_due_date'], 0, 10));
        $this->assertArrayNotHasKey('invoices', $firstCustomer, 'List pelanggan tidak boleh membawa row invoice penuh.');
        $this->assertArrayHasKey('package', $firstCustomer);
        $this->assertArrayHasKey('area', $firstCustomer);
    }
}
