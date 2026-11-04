<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentReversal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class FinancePerAreaReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_effective_payment_and_excludes_void_invoices(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('FIN-A', 'Finance Area A');
        $paid = $this->invoice($customer, '2026-09-01', 100000, 'paid');
        $transaction = Transaction::create([
            'invoice_id' => $paid->id,
            'reference' => 'FIN-TRX-001',
            'idempotency_key' => 'fin-trx-001',
            'channel' => 'manual',
            'amount' => 100000,
            'status' => 'paid',
            'method' => 'cash',
            'paid_at' => '2026-09-05 10:00:00',
        ]);
        PaymentReversal::create([
            'transaction_id' => $transaction->id,
            'invoice_id' => $paid->id,
            'amount' => 20000,
            'reversal_reference' => 'FIN-REV-001',
            'idempotency_key' => 'fin-rev-001',
            'reason' => 'Test correction',
            'reversed_by' => $user->id,
            'reversed_at' => '2026-09-06 10:00:00',
        ]);
        $unpaidCustomer = $this->customer('FIN-UNPAID', 'Finance Unpaid');
        $this->invoice($unpaidCustomer, '2026-09-01', 100000, 'unpaid', 'FIN-UNPAID-INV');
        $voidCustomer = $this->customer('FIN-VOID', 'Finance Void');
        $this->invoice($voidCustomer, '2026-09-01', 50000, 'void', 'FIN-VOID-INV');
        Area::create(['code' => 'FIN-EMPTY', 'name' => 'Finance Empty']);

        $response = $this->actingAs($user)->getJson(route('api.finance.per-area.summary', [
            'period' => '2026-09',
            'as_of' => '2026-09-30',
        ]));

        $response->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('formula_version', 'finance-per-area-v3')
            ->assertJsonPath('totals.invoice_count', 2)
            ->assertJsonPath('totals.billable_amount', '200000.00')
            ->assertJsonPath('totals.cash_paid_amount', '80000.00')
            ->assertJsonPath('totals.legacy_settled_amount', '0.00')
            ->assertJsonPath('totals.recorded_paid_amount', '80000.00')
            ->assertJsonPath('totals.outstanding_amount', '120000.00')
            ->assertJsonPath('totals.collection_rate', 40);
        $emptyArea = collect($response->json('areas'))->firstWhere('area_code', 'FIN-EMPTY');
        $this->assertNotNull($emptyArea);
        $this->assertNull($emptyArea['collection_rate']);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_reversals', ['transaction_id' => $transaction->id]);
    }

    public function test_legacy_paid_invoice_is_visible_separately_from_verified_cash(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('FIN-LEGACY', 'Finance Legacy');
        $invoice = $this->invoice($customer, '2026-08-01', 1650000, 'paid', 'FIN-LEGACY-INV');

        $response = $this->actingAs($user)->getJson(route('api.finance.per-area.summary', [
            'period' => '2026-08',
            'as_of' => '2026-08-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('formula_version', 'finance-per-area-v3')
            ->assertJsonPath('totals.cash_paid_amount', '0.00')
            ->assertJsonPath('totals.legacy_settled_amount', '1650000.00')
            ->assertJsonPath('totals.recorded_paid_amount', '1650000.00')
            ->assertJsonPath('data_quality.paid_without_transaction', 1);

        $area = collect($response->json('areas'))->firstWhere('area_code', 'FIN-LEGACY');
        $this->assertSame(1, $area['legacy_settled_count'] ?? null);
        $this->assertSame('1650000.00', $area['legacy_settled_amount'] ?? null);
        $this->assertSame('1650000.00', $area['recorded_paid_amount'] ?? null);
        $this->assertSame(1, $area['paid_count'] ?? null);
        $this->assertSame(0, $area['unpaid_count'] ?? null);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_detail_is_paginated_and_does_not_expose_internal_or_pppoe_fields(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('FIN-B', 'Finance Area B');
        $invoice = $this->invoice($customer, '2026-09-01', 125000, 'unpaid');

        $response = $this->actingAs($user)->getJson(route('api.finance.per-area.detail', [
            'period' => '2026-09',
            'search' => $invoice->code,
            'per_page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('stale', false)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.invoice_code', $invoice->code)
            ->assertJsonPath('data.0.outstanding', '125000.00');
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('pppoe_user', $row);
        $this->assertSame([], array_filter(array_keys($row), static fn (string $key): bool => str_starts_with($key, '_')));
    }

    public function test_verified_transaction_counts_even_when_invoice_status_is_unpaid(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('FIN-VERIFIED', 'Finance Verified');
        $invoice = $this->invoice($customer, '2026-09-01', 100000, 'unpaid');
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'FIN-TRX-VERIFIED',
            'idempotency_key' => 'fin-trx-verified',
            'channel' => 'manual',
            'amount' => 100000,
            'status' => 'verified',
            'method' => 'transfer',
            'paid_at' => '2026-09-10 10:00:00',
        ]);

        $response = $this->actingAs($user)->getJson(route('api.finance.per-area.detail', [
            'period' => '2026-09',
            'search' => 'FIN-VERIFIED',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.invoice_status', 'unpaid')
            ->assertJsonPath('data.0.effective_paid', '100000.00')
            ->assertJsonPath('data.0.outstanding', '0.00');
    }

    public function test_scoped_admin_cannot_read_another_area(): void
    {
        $areaA = Area::create(['code' => 'FIN-A', 'name' => 'Finance A']);
        $areaB = Area::create(['code' => 'FIN-B', 'name' => 'Finance B']);
        $customerA = $this->customer('FIN-C', 'Scoped A', $areaA);
        $customerB = $this->customer('FIN-D', 'Scoped B', $areaB);
        $this->invoice($customerA, '2026-09-01', 100000);
        $this->invoice($customerB, '2026-09-01', 200000, 'unpaid', 'FIN-SCOPED-B');
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($areaA);

        $this->actingAs($admin)->getJson(route('api.finance.per-area.detail', ['period' => '2026-09']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.customer_code', 'FIN-C');
        $this->actingAs($admin)->getJson(route('api.finance.per-area.summary', [
            'period' => '2026-09',
            'area' => $areaB->id,
        ]))->assertForbidden();
    }

    public function test_invalid_period_and_non_admin_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->getJson(route('api.finance.per-area.summary'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->getJson(route('api.finance.per-area.summary', ['period' => "2026-09' OR 1=1"]))
            ->assertUnprocessable();
    }

    private function customer(string $code, string $name, ?Area $area = null): Customer
    {
        $area ??= Area::create(['code' => $code, 'name' => $name]);
        $package = Package::create([
            'code' => $code.'-PKG',
            'name' => $name.' Package',
            'price' => 100000,
        ]);

        return Customer::create([
            'code' => $code,
            'name' => $name,
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code).'.test',
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $customer, string $period, int $amount, string $status = 'unpaid', ?string $code = null): Invoice
    {
        return Invoice::create([
            'code' => $code ?? 'INV-'.$customer->code,
            'customer_id' => $customer->id,
            'period' => $period,
            'amount' => $amount,
            'status' => $status,
            'due_date' => '2026-09-20',
            'generated_at' => '2026-09-01 00:00:00',
        ]);
    }
}
