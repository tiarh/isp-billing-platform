<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

class CleanupDelinquentCustomersTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    public function test_dry_run_reports_three_unpaid_period_customers_without_deleting(): void
    {
        $package = $this->package();
        $candidate = $this->customer($package, 'CUST-DRY');
        $this->unpaidInvoice($candidate, '2026-01-01');
        $this->unpaidInvoice($candidate, '2026-02-01');
        $this->unpaidInvoice($candidate, '2026-03-01');

        $this->artisan('customers:cleanup-delinquent')
            ->expectsOutput('Minimum unpaid periods: 3')
            ->expectsOutput('Eligible customers: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', [
            'id' => $candidate->id,
            'deleted_at' => null,
        ]);
    }

    public function test_apply_soft_deletes_only_customers_with_three_unpaid_periods(): void
    {
        $this->allowCustomerLifecycleGuard();
        $package = $this->package();
        $candidate = $this->customer($package, 'CUST-DELETE');
        $safeCustomer = $this->customer($package, 'CUST-SAFE');
        $terminatedCustomer = $this->customer($package, 'CUST-TERM', ['status' => 'terminated']);

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
            $this->unpaidInvoice($candidate, $period);
            $this->unpaidInvoice($terminatedCustomer, $period);
        }
        $this->unpaidInvoice($safeCustomer, '2026-01-01');
        $this->unpaidInvoice($safeCustomer, '2026-02-01');
        $this->paidInvoice($safeCustomer, '2026-03-01');

        $this->artisan('customers:cleanup-delinquent', ['--apply' => true])
            ->expectsOutput('Soft-deleted customers: 1')
            ->assertExitCode(0);

        $this->assertSoftDeleted('customers', ['id' => $candidate->id]);
        $this->assertDatabaseHas('customers', ['id' => $safeCustomer->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('customers', ['id' => $terminatedCustomer->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('invoices', ['customer_id' => $candidate->id, 'status' => 'unpaid']);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Customer::class,
            'subject_id' => $candidate->id,
            'description' => 'customer_soft_deleted_for_delinquency',
        ]);
    }

    public function test_customers_index_can_filter_cleanup_candidates(): void
    {
        $package = $this->package();
        $candidate = $this->customer($package, 'CUST-FILTER');
        $other = $this->customer($package, 'CUST-OTHER');

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
            $this->unpaidInvoice($candidate, $period);
        }
        $this->unpaidInvoice($other, '2026-01-01');

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get(route('customers.index', ['unpaid_periods' => '3plus']));

        $response->assertOk();
        $rows = $response->viewData('page')['props']['customers']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame($candidate->id, $rows[0]['id']);
        $this->assertSame(3, $rows[0]['unpaid_periods_count']);
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'Cleanup Package',
            'code' => 'PKG-CLEANUP-'.uniqid(),
            'price' => 100000,
        ]);
    }

    private function customer(Package $package, string $code, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000000',
            'address' => 'Cleanup Address',
            'pppoe_user' => strtolower($code),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    private function unpaidInvoice(Customer $customer, string $period): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'period' => $period,
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => Carbon::parse($period)->day(20)->toDateString(),
            'generated_at' => now(),
        ]);
    }

    private function paidInvoice(Customer $customer, string $period): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'period' => $period,
            'amount' => 100000,
            'status' => 'paid',
            'due_date' => Carbon::parse($period)->day(20)->toDateString(),
            'generated_at' => now(),
        ]);
    }
}
