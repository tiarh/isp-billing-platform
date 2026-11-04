<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FreezesMidMonthClock;
use Tests\TestCase;

#[Group('blocking-core')]
class ScopedAdminAccessTest extends TestCase
{
    use FreezesMidMonthClock;
    use RefreshDatabase;

    public function test_scoped_admin_only_sees_customers_in_assigned_areas(): void
    {
        [$areaA, , $customerA, $customerB] = $this->seedAreaCustomers();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $response = $this->actingAs($admin)->get('/customers');

        $response->assertOk();
        $customers = $response->viewData('page')['props']['customers']['data'];

        $this->assertCount(1, $customers);
        $this->assertSame($customerA->id, $customers[0]['id']);
        $this->assertFalse(collect($customers)->pluck('id')->contains($customerB->id));
        $this->assertArrayNotHasKey('nik', $customers[0]);
    }

    public function test_scoped_admin_can_operate_inside_assigned_area(): void
    {
        [$areaA, , $customerA] = $this->seedAreaCustomers();
        [$invoiceA] = $this->seedInvoices($customerA);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)->get(route('customers.edit', $customerA))->assertOk();
        $this->actingAs($admin)->post(route('customers.isolate', $customerA))->assertRedirect();
        $this->actingAs($admin)->get(route('invoices.pay', $invoiceA))->assertOk();
        $this->actingAs($admin)->post(route('payments.store', $invoiceA), [
            'invoice_confirmation' => $invoiceA->code,
            'customer_confirmation' => $invoiceA->customer->code,
            'amount' => 50000,
            'method' => 'cash',
            'paid_at' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'invoice_id' => $invoiceA->id,
            'amount' => 50000,
        ]);
    }

    public function test_scoped_admin_cannot_open_or_mutate_records_outside_assigned_area(): void
    {
        [$areaA, , , $customerB] = $this->seedAreaCustomers();
        [$invoiceB] = $this->seedInvoices($customerB);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)->get(route('customers.show', $customerB))->assertForbidden();
        $this->actingAs($admin)->get(route('customers.edit', $customerB))->assertForbidden();
        $this->actingAs($admin)->post(route('customers.isolate', $customerB))->assertForbidden();
        $this->actingAs($admin)->get(route('invoices.show', $invoiceB))->assertForbidden();
        $this->actingAs($admin)->post(route('invoices.void', $invoiceB))->assertForbidden();
        $this->actingAs($admin)->post(route('payments.store', $invoiceB), [
            'invoice_confirmation' => $invoiceB->code,
            'customer_confirmation' => $invoiceB->customer->code,
            'amount' => 50000,
            'method' => 'cash',
        ])->assertForbidden();
    }

    public function test_scoped_admin_only_sees_invoices_and_payments_in_assigned_areas(): void
    {
        [$areaA, , $customerA, $customerB] = $this->seedAreaCustomers();
        [$invoiceA, $invoiceB] = $this->seedInvoices($customerA, $customerB);
        Transaction::create([
            'reference' => 'TRX-A001',
            'invoice_id' => $invoiceA->id,
            'amount' => 50000,
            'channel' => 'manual',
            'method' => 'transfer',
            'status' => 'verified',
            'proof_url' => 'proofs/private.jpg',
            'paid_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $response = $this->actingAs($admin)->get('/invoices');

        $response->assertOk();
        $invoices = $response->viewData('page')['props']['invoices']['data'];

        $this->assertCount(1, $invoices);
        $this->assertSame($invoiceA->id, $invoices[0]['id']);
        $this->assertFalse(collect($invoices)->pluck('id')->contains($invoiceB->id));

        $showResponse = $this->actingAs($admin)->get(route('invoices.show', $invoiceA));
        $showResponse->assertOk();
        $invoice = $showResponse->viewData('page')['props']['invoice'];
        $this->assertArrayNotHasKey('nik', $invoice['customer']);
        $this->assertArrayHasKey('proof_url', $invoice['transactions'][0]);
    }

    public function test_scoped_admin_dashboard_and_analytics_are_area_scoped(): void
    {
        [$areaA, , $customerA, $customerB] = $this->seedAreaCustomers();
        $this->seedInvoices($customerA, $customerB);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $dashboard = $this->actingAs($admin)->get('/dashboard');
        $dashboard->assertOk();
        $stats = $dashboard->viewData('page')['props']['stats'];
        $this->assertSame(1, $stats['active_customers']);
        $this->assertSame(1, $stats['unpaid_invoices']);
        $this->assertSame(100000, (int) $stats['projected_revenue']);
        $this->assertSame('Area A', $dashboard->viewData('page')['props']['area_label']);
        $this->assertSame(['summary', 'last_sync', 'stale', 'drilldowns'], array_keys($dashboard->viewData('page')['props']['operational_area']));

        $analytics = $this->actingAs($admin)->getJson(route('api.analytics.revenue-by-area', ['refresh' => true]));
        $analytics->assertOk();
        $this->assertSame(['Area A'], collect($analytics->json())->pluck('area_name')->all());
    }

    public function test_technician_dashboard_does_not_receive_billing_props(): void
    {
        [$areaA, , $customerA, $customerB] = $this->seedAreaCustomers();
        $this->seedInvoices($customerA, $customerB);
        $technician = User::factory()->create(['role' => 'teknisi']);

        $response = $this->actingAs($technician)->get('/dashboard');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertFalse($props['can_view_billing']);
        $this->assertArrayNotHasKey('stats', $props);
        $this->assertSame([], $props['recent_payments']);
        $this->assertSame([], $props['by_location']);
        $this->assertArrayNotHasKey('rows', $props['operational_area']);
        $this->assertArrayNotHasKey('options', $props['operational_area']);
    }

    public function test_global_admin_and_superadmin_see_all_customer_data(): void
    {
        $this->seedAreaCustomers();

        foreach (['admin', 'superadmin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get('/customers');

            $response->assertOk();
            $customers = $response->viewData('page')['props']['customers']['data'];

            $this->assertCount(2, $customers);
            $this->assertArrayNotHasKey('nik', $customers[0]);
        }
    }

    public function test_scoped_admin_cannot_access_global_management_or_user_management(): void
    {
        [$areaA] = $this->seedAreaCustomers();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)->get(route('areas.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('routers.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('packages.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('users.index'))->assertForbidden();
    }

    private function seedAreaCustomers(): array
    {
        $areaA = Area::create(['code' => 'A', 'name' => 'Area A']);
        $areaB = Area::create(['code' => 'B', 'name' => 'Area B']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        $customerA = Customer::create([
            'code' => 'A001',
            'name' => 'Customer A',
            'phone' => '0811111111',
            'address' => 'Area A Address',
            'area_id' => $areaA->id,
            'package_id' => $package->id,
            'pppoe_user' => 'a001',
            'status' => 'active',
            'nik' => '1234567890',
        ]);

        $customerB = Customer::create([
            'code' => 'B001',
            'name' => 'Customer B',
            'phone' => '0810000014',
            'address' => 'Area B Address',
            'area_id' => $areaB->id,
            'package_id' => $package->id,
            'pppoe_user' => 'b001',
            'status' => 'active',
            'nik' => '9876543210',
        ]);

        return [$areaA, $areaB, $customerA, $customerB];
    }

    private function seedInvoices(Customer $customerA, ?Customer $customerB = null): array
    {
        $invoices = [
            Invoice::create([
                'customer_id' => $customerA->id,
                'period' => now()->startOfMonth()->format('Y-m-d'),
                'amount' => 100000,
                'due_date' => now()->addDays(7),
                'status' => 'unpaid',
                'generated_at' => now(),
            ]),
        ];

        if ($customerB) {
            $invoices[] = Invoice::create([
                'customer_id' => $customerB->id,
                'period' => now()->startOfMonth()->format('Y-m-d'),
                'amount' => 200000,
                'due_date' => now()->addDays(7),
                'status' => 'unpaid',
                'generated_at' => now(),
            ]);
        }

        return $invoices;
    }
}
