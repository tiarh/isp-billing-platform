<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Partial salvage of closed #366: void lock, '!Y-m' period parsing, single bulk reconnect.
 */
#[Group('blocking-billing')]
class InvoiceVoidLockAndPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_void_unpaid_invoice_without_payment_succeeds(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('invoices.void', $invoice), ['reason' => 'salah input'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('void', $invoice->refresh()->status);
    }

    public function test_void_refused_when_invoice_has_recorded_payment(): void
    {
        [$admin, $invoice] = $this->fixture();
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'VOID-LOCK-1',
            'idempotency_key' => 'void-lock-1',
            'channel' => 'manual',
            'amount' => 40000,
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('invoices.void', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg): bool => str_contains($msg, 'riwayat pembayaran'));

        $this->assertSame('unpaid', $invoice->refresh()->status);
    }

    public function test_void_refused_when_invoice_has_gateway_payment(): void
    {
        [$admin, $invoice] = $this->fixture();
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PG-MIDTRANS-1',
            'idempotency_key' => 'gateway:midtrans:1',
            'channel' => 'gateway',
            'amount' => 40000,
            'method' => 'other',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('invoices.void', $invoice))
            ->assertSessionHas('error');

        $this->assertSame('unpaid', $invoice->refresh()->status);
    }

    public function test_period_on_day_31_does_not_roll_into_next_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'Asia/Jakarta'));
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->put(route('invoices.update', $invoice), [
            'period' => '2026-02',
            'amount' => 100000,
            'due_date' => '2026-02-10',
        ])->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('2026-02-01', $invoice->refresh()->period->toDateString());
    }

    public function test_bulk_payment_dispatches_reconnect_once_per_customer(): void
    {
        Bus::fake();
        [$admin, $invoice] = $this->fixture();
        $invoice->customer->update(['status' => 'isolated']);

        $this->actingAs($admin)->post(route('payments.bulk'), [
            'invoice_ids' => [$invoice->id],
            'method' => 'cash',
            'idempotency_key' => 'bulk-reconnect-once',
            'confirmed' => true,
            'invoice_confirmations' => [[
                'id' => $invoice->id,
                'code' => $invoice->code,
                'customer_code' => $invoice->customer->code,
                'customer_name' => $invoice->customer->name,
                'area_id' => $invoice->customer->area_id,
                'period' => $invoice->period->toDateString(),
                'amount' => 100000,
                'due_date' => $invoice->due_date->toDateString(),
                'status' => 'unpaid',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->refresh()->status);
        Bus::assertDispatchedTimes(ReconnectCustomerJob::class, 1);
    }

    private function fixture(): array
    {
        $area = Area::create(['code' => 'VLK', 'name' => 'Void Lock Test']);
        $package = Package::create(['code' => 'VLK100', 'name' => 'Void Lock', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'VLK001',
            'name' => 'Void Lock Customer',
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'void.lock',
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
