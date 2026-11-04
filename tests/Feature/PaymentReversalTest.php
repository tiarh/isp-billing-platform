<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentReversal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoicePackageSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_reversal_keeps_transaction_and_reopens_invoice(): void
    {
        Bus::fake();
        [$admin, $invoice, $transaction] = $this->paidInvoice();

        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();

        $this->assertDatabaseHas('payment_reversals', [
            'invoice_id' => $invoice->id,
            'transaction_id' => $transaction->id,
            'amount' => '100000.00',
            'reason' => 'Salah memilih invoice saat input pembayaran',
        ]);
        $this->assertSame('paid', $transaction->refresh()->status);
        $this->assertSame('100000.00', $transaction->amount);
        $this->assertSame('unpaid', $invoice->refresh()->status);
        $this->assertSame(0.0, $invoice->totalPaid());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'payment_reversed',
            'subject_id' => $invoice->id,
            'causer_id' => $admin->id,
        ]);
        Bus::assertNothingDispatched();
    }

    public function test_reversal_is_idempotent_for_same_key(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $payload = $this->reversalPayload($invoice);

        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $payload)->assertRedirect();

        $this->assertDatabaseCount('payment_reversals', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'payment_reversed')->count());
    }

    public function test_same_transaction_cannot_be_reversed_with_a_new_key(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();

        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), array_merge(
            $this->reversalPayload($invoice),
            ['idempotency_key' => 'reversal-test-002'],
        ))->assertSessionHasErrors('transaction');

        $this->assertDatabaseCount('payment_reversals', 1);
    }

    public function test_wrong_invoice_or_customer_confirmation_is_rejected(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();

        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), array_merge(
            $this->reversalPayload($invoice),
            ['invoice_confirmation' => 'WRONG'],
        ))->assertSessionHasErrors('invoice_confirmation');
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), array_merge(
            $this->reversalPayload($invoice),
            ['customer_confirmation' => 'WRONG'],
        ))->assertSessionHasErrors('customer_confirmation');

        $this->assertDatabaseCount('payment_reversals', 0);
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    public function test_scoped_admin_and_technician_cannot_reverse(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $area = $invoice->customer->area;
        $scopedAdmin = User::factory()->create(['role' => 'admin']);
        $scopedAdmin->areas()->attach($area);
        $payload = $this->reversalPayload($invoice);

        $this->actingAs($scopedAdmin)->post(route('payments.reverse', [$invoice, $transaction]), $payload)
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->post(route('payments.reverse', [$invoice, $transaction]), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('payment_reversals', 0);
    }

    public function test_invoice_with_transaction_remains_non_deletable_after_reversal(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();

        $this->actingAs($admin)->delete(route('invoices.destroy', $invoice))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('payment_reversals', ['transaction_id' => $transaction->id]);
    }

    public function test_payment_can_be_recorded_again_using_effective_balance_after_reversal(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'replacement-payment-after-reversal',
        ])->assertRedirect();

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(100000.0, $invoice->totalPaid());
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('payment_reversals', 1);
    }

    public function test_payment_reversal_model_is_append_only(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();
        $reversal = PaymentReversal::firstOrFail();

        $this->expectException(LogicException::class);
        $reversal->update(['reason' => 'tamper']);
    }

    public function test_payment_reversal_model_cannot_be_deleted(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();
        $reversal = PaymentReversal::firstOrFail();

        $this->expectException(LogicException::class);
        $reversal->delete();
    }

    public function test_package_sync_does_not_restore_reversed_payment(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();

        $result = app(InvoicePackageSyncService::class)->syncCustomer($invoice->customer);

        $this->assertSame(0, $result['reconciled_paid']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('unpaid', $invoice->refresh()->status);
    }

    public function test_invoice_pdf_uses_effective_payment_after_reversal(): void
    {
        [$admin, $invoice, $transaction] = $this->paidInvoice();
        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), $this->reversalPayload($invoice))
            ->assertRedirect();

        $invoice->refresh()->load(['customer.package', 'transactions.reversals', 'reversals']);
        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'company' => ['name' => 'Test Company'],
            'manual_accounts' => [],
            'payment_url' => 'https://example.test/pay',
        ])->render();

        $this->assertStringNotContainsString('Nominal Sudah Bayar', $html);
        $this->assertStringNotContainsString('Status: LUNAS', $html);
        $this->assertSame('unpaid', $invoice->status);
    }

    private function paidInvoice(): array
    {
        $area = Area::create(['code' => 'REV', 'name' => 'Reversal Test']);
        $package = Package::create(['code' => 'REV100', 'name' => 'Reversal Test', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'REV001',
            'name' => 'Reversal Test Customer',
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'reversal.test',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'code' => 'INV-REV-001',
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'paid',
            'generated_at' => now(),
        ]);
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'REV-TRX-001',
            'idempotency_key' => 'paid-transaction-reversal-fixture',
            'channel' => 'manual',
            'admin_id' => User::factory()->create(['role' => 'admin'])->id,
            'amount' => 100000,
            'status' => 'paid',
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        return [User::factory()->create(['role' => 'superadmin']), $invoice->load('customer'), $transaction];
    }

    private function reversalPayload(Invoice $invoice): array
    {
        return [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'reason' => 'Salah memilih invoice saat input pembayaran',
            'idempotency_key' => 'reversal-test-001',
        ];
    }
}
