<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentGatewayEvent;
use App\Models\Router;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * PR-PAY1: Midtrans notification → invoice settlement path.
 */
#[Group('blocking-billing')]
class PaymentGatewayWebhookFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-pay1';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('midtrans_client_key', 'SB-Mid-client-pay1', 'text', 'payment', 'Midtrans Client Key');
        Setting::set('midtrans_server_key', self::SERVER_KEY, 'text', 'payment', 'Midtrans Server Key');
        Setting::set('midtrans_is_production', '0', 'boolean', 'payment', 'Midtrans production mode');
        Setting::set('control_plane.payment_writes_enabled', '1', 'boolean', 'control_plane', 'Payment write');
        Queue::fake();
    }

    public function test_settlement_marks_invoice_paid_and_dispatches_reconnect(): void
    {
        Http::fake();
        $customer = $this->customer(['status' => 'isolated', 'router_id' => $this->router()->id]);
        $invoice = $this->invoice($customer);

        $this->notify($this->payload($invoice, 'settlement', '200', 100000))
            ->assertOk()
            ->assertJsonPath('status', 'settlement')
            ->assertJsonPath('invoice_id', $invoice->id);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $transaction = $invoice->transactions()->first();
        $this->assertSame('PG-MIDTRANS-INV'.$invoice->id.'-TEST', $transaction->reference);
        $this->assertSame(100000.0, (float) $transaction->amount);
        Queue::assertPushed(ReconnectCustomerJob::class, fn (ReconnectCustomerJob $job) => $job->customer->is($customer));
    }

    public function test_pending_then_settlement_settles_invoice(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer());

        $this->notify($this->payload($invoice, 'pending', '201', 100000))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());

        $this->notify($this->payload($invoice, 'settlement', '200', 100000))
            ->assertOk()
            ->assertJsonPath('status', 'settlement');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(2, PaymentGatewayEvent::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_duplicate_settlement_is_idempotent(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer(), ['amount' => 50000]);
        $payload = $this->payload($invoice, 'settlement', '200', 50000);

        $first = $this->notify($payload)->assertOk()->json('event_id');
        $second = $this->notify($payload)->assertOk()->json('event_id');
        $this->assertSame($first, $second);

        // transaction_id is not covered by the signature: changing it must not
        // produce a second credit for the same order.
        $this->notify(array_merge($payload, ['transaction_id' => 'forged-new-id']))
            ->assertOk();

        // Card flow: capture(accept) then settlement for the same order is one payment.
        $this->notify($this->payload($invoice, 'capture', '200', 50000))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_DUPLICATE_CREDIT);

        $this->assertSame(1, Transaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(50000.0, $invoice->fresh()->totalPaid());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_unsigned_fields_cannot_settle_invoice(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer());

        // Signed pending payload + status override in the query string.
        $pending = $this->payload($invoice, 'pending', '201', 100000);
        $this->postJson('/webhooks/payment-gateway/midtrans?status=settlement&transaction_status=settlement&status_code=200', $pending)
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        // Body claims settlement but the signed status_code is still 201.
        $this->notify(array_merge($pending, ['transaction_status' => 'settlement', 'status' => 'settlement']))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_UNSIGNED);

        // Card capture without fraud_status=accept is not a settlement.
        $this->notify(array_merge($this->payload($invoice, 'capture', '200', 100000), ['fraud_status' => 'challenge']))
            ->assertOk()
            ->assertJsonPath('status', 'capture_challenge');

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_partial_payment_charges_and_accepts_only_remaining_balance(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'sandbox-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token',
            ], 200),
            '*' => Http::response([], 200),
        ]);
        $invoice = $this->invoice($this->customer());
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'MANUAL-PAY1-PARTIAL',
            'channel' => 'manual',
            'amount' => 60000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        // Snap charges only what is left.
        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/snap/v1/transactions')
            && (int) $request->data()['transaction_details']['gross_amount'] === 40000
            && (int) $request->data()['item_details'][0]['price'] === 40000);

        // A full-amount settlement after the manual payment would overpay.
        $this->notify($this->payload($invoice, 'settlement', '200', 100000, 'INV'.$invoice->id.'-FULL'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_AMOUNT);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(60000.0, $invoice->fresh()->totalPaid());

        $this->notify($this->payload($invoice, 'settlement', '200', 40000, 'INV'.$invoice->id.'-REST'))
            ->assertOk()
            ->assertJsonPath('status', 'settlement');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());

        // Already settled: any further gateway money is rejected, not credited.
        $this->notify($this->payload($invoice, 'settlement', '200', 40000, 'INV'.$invoice->id.'-LATE'))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_AMOUNT);
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
    }

    public function test_void_invoice_is_not_payable(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer(), ['status' => 'void']);

        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonMissingPath('token');
        Http::assertNothingSent();

        $this->notify($this->payload($invoice, 'settlement', '200', 100000))
            ->assertOk()
            ->assertJsonPath('status', PaymentGatewayService::STATUS_REJECTED_VOID);

        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_settled_invoice_does_not_create_snap(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer(), ['status' => 'paid']);

        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertOk()
            ->assertJsonPath('redirect_url', route('public.invoice.show', $invoice->uuid))
            ->assertJsonMissingPath('token');
        Http::assertNothingSent();
    }

    public function test_invalid_signature_is_rejected_without_side_effects(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->customer());
        $payload = $this->payload($invoice, 'settlement', '200', 100000);
        $payload['signature_key'] = hash('sha512', 'tampered');

        $this->notify($payload)->assertUnauthorized();

        // Signed for a different amount than the one posted.
        $tampered = $this->payload($invoice, 'settlement', '200', 1000);
        $tampered['gross_amount'] = '100000.00';
        $this->notify($tampered)->assertUnauthorized();

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_audit_command_reports_gateway_findings_without_writing(): void
    {
        $invoice = $this->invoice($this->customer());
        // Simulates data written by the pre-fix M1 bug: credited but still unpaid.
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PG-MIDTRANS-legacy-bug',
            'channel' => 'manual',
            'amount' => 100000,
            'status' => 'paid',
            'method' => 'qris',
            'paid_at' => now(),
        ]);
        PaymentGatewayEvent::create([
            'provider' => 'midtrans',
            'external_id' => 'INV'.$invoice->id.'-OLD:201:pending',
            'reference' => 'INV'.$invoice->id.'-OLD',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'status' => 'pending',
            'payload' => [],
            'processed_at' => now(),
        ]);
        PaymentGatewayEvent::create([
            'provider' => 'midtrans',
            'external_id' => 'INV'.$invoice->id.'-OVER:200:settlement',
            'reference' => 'INV'.$invoice->id.'-OVER',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'status' => PaymentGatewayService::STATUS_REJECTED_AMOUNT,
            'payload' => [],
            'processed_at' => now(),
        ]);

        $this->artisan('payments:audit-gateway', ['--fail-on-findings' => true])
            ->expectsOutputToContain('Total findings: 3')
            ->assertExitCode(1);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(2, PaymentGatewayEvent::query()->count());
    }

    private function notify(array $payload): TestResponse
    {
        return $this->postJson('/webhooks/payment-gateway/midtrans', $payload);
    }

    private function payload(Invoice $invoice, string $status, string $statusCode, int $amount, ?string $orderId = null): array
    {
        $orderId ??= 'INV'.$invoice->id.'-TEST';
        $gross = number_format($amount, 2, '.', '');

        return [
            'transaction_time' => now()->format('Y-m-d H:i:s'),
            'transaction_status' => $status,
            'transaction_id' => 'mt-'.md5($orderId),
            'status_message' => 'midtrans payment notification',
            'status_code' => $statusCode,
            'signature_key' => hash('sha512', $orderId.$statusCode.$gross.self::SERVER_KEY),
            'payment_type' => 'qris',
            'order_id' => $orderId,
            'merchant_id' => 'G000000000',
            'gross_amount' => $gross,
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];
    }

    private function router(): Router
    {
        return Router::create([
            'name' => 'PAY1 Router '.uniqid(),
            'ip_address' => '198.18.23.'.rand(1, 254),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-PAY1',
            'name' => 'Pay1',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'PAY1-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Gateway Customer',
            'phone' => '081234567890',
            'address' => 'Gateway Address',
            'pppoe_user' => 'pay1.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }
}
