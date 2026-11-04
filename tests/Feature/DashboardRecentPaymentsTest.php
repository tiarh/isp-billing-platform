<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FreezesMidMonthClock;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class DashboardRecentPaymentsTest extends TestCase
{
    use FreezesMidMonthClock;
    use RefreshDatabase;

    public function test_recent_payments_are_limited_to_five(): void
    {
        $package = Package::create(['code' => 'DASH-LIMIT', 'name' => 'Dashboard Limit', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'DASHL01',
            'name' => 'Limit Customer',
            'phone' => '081234567891',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'dashboard.limit.test',
            'status' => 'active',
        ]);

        foreach (range(1, 6) as $i) {
            $invoice = Invoice::create([
                'customer_id' => $customer->id,
                'period' => now()->subMonths($i)->startOfMonth()->toDateString(),
                'amount' => 100000,
                'status' => 'paid',
                'due_date' => now()->toDateString(),
                'generated_at' => now(),
            ]);
            Transaction::create([
                'reference' => "DASH-LIMIT-TX-{$i}",
                'invoice_id' => $invoice->id,
                'amount' => 100000,
                'channel' => 'manual',
                'method' => 'cash',
                'status' => 'paid',
                'paid_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs(User::factory()->create(['role' => 'superadmin']))->get('/dashboard');

        $response->assertOk();
        $this->assertCount(5, $response->viewData('page')['props']['recent_payments']);
    }

    public function test_recent_payment_keeps_archived_customer_and_invoice_period_in_dashboard_props(): void
    {
        $package = Package::create([
            'code' => 'DASH-ARCHIVE',
            'name' => 'Dashboard Archive Test',
            'price' => 100000,
        ]);
        $customer = Customer::create([
            'code' => 'DASH001',
            'name' => 'Archived Dashboard Customer',
            'phone' => '081234567890',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'dashboard.archive.test',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-08-01',
            'amount' => 100000,
            'status' => 'paid',
            'due_date' => '2026-08-07',
            'generated_at' => now(),
        ]);
        Transaction::create([
            'reference' => 'DASH-ARCHIVE-TX',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $customer->delete();

        $response = $this->actingAs(User::factory()->create(['role' => 'superadmin']))->get('/dashboard');

        $response->assertOk();
        $payment = $response->viewData('page')['props']['recent_payments'][0];

        $this->assertSame('Archived Dashboard Customer', data_get($payment, 'invoice.customer.name'));
        $this->assertSame('DASH001', data_get($payment, 'invoice.customer.code'));
        $this->assertStringStartsWith('2026-08-01', data_get($payment, 'invoice.period'));
    }
}
