<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class InvoicePaymentCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_payment_is_idempotent_and_settles_invoice(): void
    {
        Bus::fake();
        [$admin, $invoice] = $this->fixture();
        $payload = [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'invoice-payment-test-1',
        ];

        $this->actingAs($admin)->post(route('payments.store', $invoice), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('payments.store', $invoice), $payload)->assertRedirect();

        $this->assertSame(1, Transaction::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    public function test_record_payment_rejects_invalid_method_and_overpayment(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100001,
            'method' => 'cash',
            'idempotency_key' => 'invoice-payment-overpayment',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100000,
            'method' => 'payment_gateway',
            'idempotency_key' => 'invoice-payment-invalid-method',
        ])->assertSessionHasErrors('method');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('unpaid', $invoice->refresh()->status);
    }

    public function test_csv_payment_import_is_idempotent(): void
    {
        Bus::fake();
        [$admin, $invoice] = $this->fixture();
        $csv = implode(PHP_EOL, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',100000,cash,csv-payment-test',
        ]).PHP_EOL;

        $this->actingAs($admin)->post(route('payments.bulk-import'), [
            'csv_file' => UploadedFile::fake()->createWithContent('payments.csv', $csv),
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($admin)->post(route('payments.bulk-import'), [
            'csv_file' => UploadedFile::fake()->createWithContent('payments.csv', $csv),
        ])->assertRedirect();

        $this->assertSame(1, Transaction::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    public function test_unpaid_invoice_can_be_updated_but_paid_invoice_cannot(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->put(route('invoices.update', $invoice), [
            'period' => now()->addMonth()->format('Y-m'),
            'amount' => 100000,
            'due_date' => now()->addMonth()->addDays(7)->format('Y-m-d'),
        ])->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'period' => now()->addMonth()->startOfMonth()->toDateString(),
        ]);

        $invoice->update(['status' => 'paid']);
        $this->actingAs($admin)->put(route('invoices.update', $invoice), [
            'period' => now()->addMonths(2)->format('Y-m'),
            'amount' => 100000,
            'due_date' => now()->addMonths(2)->addDays(7)->format('Y-m-d'),
        ])->assertRedirect()->assertSessionHas('error');
    }

    private function fixture(): array
    {
        $area = Area::create(['code' => 'PAY', 'name' => 'Payment Test']);
        $package = Package::create(['code' => 'PAY100', 'name' => 'Payment Test', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'PAY001',
            'name' => 'Payment Test Customer',
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'payment.test',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        return [User::factory()->create(['role' => 'superadmin']), $invoice];
    }
}
