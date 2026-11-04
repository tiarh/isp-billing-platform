<?php

namespace Tests\Feature;

use App\Jobs\ApplyCustomerCreditsJob;
use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\CustomerAutoCreditGate;
use App\Services\CustomerCreditService;
use App\Services\InvoiceGenerationService;
use App\Services\PaymentGatewayService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * Owner condition for the 120 s timeouts: a job killed half way (SIGKILL at the timeout drops the DB
 * connection, so the open transaction rolls back) and then retried must never deduct a balance twice,
 * never make a second invoice and never credit a gateway payment twice.
 *
 * "Killed" is simulated by throwing from a model event in the middle of the work (after the first
 * writes, before the last one). The retry is the very same job code run again.
 *
 * Keys that make a repeat harmless (all asserted below):
 *  - credit apply: Invoice + CustomerCredit rows lockForUpdate, transactions.idempotency_key
 *    UNIQUE = credit:<credit id>:<invoice id>:<amount>, status re-check (unpaid/overdue, not settled)
 *  - ensure invoice: customer + invoice rows lockForUpdate, invoices UNIQUE (customer_id, period)
 *  - release held: payment_gateway_events row lockForUpdate + status held re-check, transactions.reference
 *    UNIQUE = PG-<PROVIDER>-<credit_key> (also checked with exists() before insert)
 */
#[Group('blocking-billing')]
class BillingJobKillRetryTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-kill-fake';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', OpsTime::TZ));
        Queue::fake();
        Http::fake();
        Setting::set(CustomerAutoCreditGate::SETTING_KEY, '1', 'boolean', 'billing');
    }

    // ---- ApplyCustomerCreditsJob ------------------------------------------

    public function test_credit_apply_killed_after_the_transaction_row_rolls_back_and_retry_deducts_once(): void
    {
        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);
        $credit = $this->credit($customer, 100000);

        $armed = true;
        CustomerCredit::updating(function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('simulated kill after the Transaction row was written');
            }
        });

        try {
            $this->runApply($invoice);
            $this->fail('the simulated kill must abort the run');
        } catch (RuntimeException) {
            // killed
        }

        // Nothing half-applied: no payment row, credit still spendable, invoice untouched.
        $this->assertSame(0, Transaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);

        $armed = false;
        $this->runApply($invoice);   // retry
        $this->runApply($invoice);   // redelivery after a lost ack
        $this->runApply($invoice);

        $transaction = Transaction::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('credit:'.$credit->id.':'.$invoice->id.':100000.00', $transaction->idempotency_key);
        $this->assertSame('100000.00', (string) $transaction->amount);
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->fresh()->status);
        $this->assertSame(0, CustomerCredit::query()->where('customer_id', $customer->id)->where('status', CustomerCredit::STATUS_AVAILABLE)->count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_partial_credit_killed_while_splitting_never_spends_more_than_the_invoice(): void
    {
        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);          // 100000
        $credit = $this->credit($customer, 150000);           // more than the invoice: split

        $armed = true;
        CustomerCredit::creating(function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('simulated kill while creating the split row');
            }
        });

        try {
            $this->runApply($invoice);
            $this->fail('the simulated kill must abort the run');
        } catch (RuntimeException) {
            // killed
        }

        $this->assertSame(0, Transaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(150000.0, (float) $credit->fresh()->amount);
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);

        $armed = false;
        $this->runApply($invoice);
        $this->runApply($invoice);

        $this->assertSame(1, Transaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
        // The balance that was not needed stays available, exactly once.
        $this->assertSame(50000.0, (float) $credit->fresh()->amount);
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame(
            50000.0,
            (float) CustomerCredit::query()->where('customer_id', $customer->id)->where('status', CustomerCredit::STATUS_AVAILABLE)->sum('amount'),
        );
    }

    public function test_database_refuses_a_second_payment_row_with_the_same_idempotency_key(): void
    {
        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);
        $row = fn (string $reference): array => [
            'reference' => $reference,
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'credit:1:'.$invoice->id.':100000.00',
            'channel' => 'manual',
            'amount' => 100000,
            'method' => 'other',
            'status' => 'verified',
            'paid_at' => now(),
        ];
        Transaction::create($row('CREDIT-A'));

        $this->expectException(QueryException::class);
        Transaction::create($row('CREDIT-B'));
    }

    // ---- EnsureCustomerCurrentInvoiceJob ----------------------------------

    public function test_ensure_invoice_killed_after_insert_rolls_back_and_retry_makes_exactly_one(): void
    {
        $customer = $this->gatewayCustomer(['join_date' => '2026-07-01', 'due_day' => 20]);
        $period = OpsTime::now()->startOfMonth()->toDateString();

        $armed = true;
        Invoice::created(function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('simulated kill right after the invoice insert');
            }
        });

        try {
            $this->runEnsure($customer->id, $period);
            $this->fail('the simulated kill must abort the run');
        } catch (RuntimeException) {
            // killed
        }
        $this->assertSame(0, Invoice::query()->where('customer_id', $customer->id)->count());

        $armed = false;
        $this->runEnsure($customer->id, $period);   // retry
        $this->runEnsure($customer->id, $period);   // redelivery
        $this->runEnsure($customer->id, $period);

        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->count());
        $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame($period, $invoice->period->toDateString());
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_database_refuses_a_second_invoice_for_the_same_customer_and_period(): void
    {
        $customer = $this->gatewayCustomer();
        $this->gatewayInvoice($customer, ['period' => '2026-10-01']);

        $this->expectException(QueryException::class);
        $this->gatewayInvoice($customer, ['period' => '2026-10-01']);
    }

    // ---- ReleaseHeldGatewayPaymentsJob ------------------------------------

    public function test_release_held_killed_mid_batch_keeps_released_events_and_retry_credits_the_rest_once(): void
    {
        Setting::setSecret('midtrans_client_key', 'SB-Mid-client-kill-fake', 'payment');
        Setting::setSecret('midtrans_server_key', self::SERVER_KEY, 'payment');
        Setting::set('midtrans_is_production', '0', 'boolean', 'payment');
        $this->setPaymentWrites(false);

        $first = $this->gatewayInvoice($this->gatewayCustomer(), ['period' => '2026-09-01']);
        $second = $this->gatewayInvoice($this->gatewayCustomer(), ['period' => '2026-10-01']);
        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($first->id, 100000))->assertOk();
        $this->postJson('/webhooks/payment-gateway/midtrans', $this->midtransPayload($second->id, 100000))->assertOk();
        $this->assertSame(2, PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_HELD)->count());

        $this->setPaymentWrites(true);
        $service = app(PaymentGatewayService::class);

        // Kill during the second event: after its Transaction row, before its status update.
        $armed = true;
        PaymentGatewayEvent::updating(function (PaymentGatewayEvent $event) use (&$armed, $second): void {
            if ($armed && (int) $event->invoice_id === (int) $second->id) {
                throw new RuntimeException('simulated kill in the second event');
            }
        });

        try {
            $service->releaseHeld(200);
            $this->fail('the simulated kill must abort the run');
        } catch (RuntimeException) {
            // killed
        }

        // First event stays released (own transaction); the second one rolled back completely.
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_RELEASED)->count());
        $this->assertSame(1, PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_HELD)->count());
        $this->assertSame('unpaid', $second->fresh()->status);

        $armed = false;
        $this->assertSame(1, $service->releaseHeld(200));   // retry: only the unfinished one
        $this->assertSame(0, $service->releaseHeld(200));   // redelivery: nothing left
        $this->assertSame(0, $service->releaseHeld(200));

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(
            ['PG-MIDTRANS-INV'.$first->id.'-PG1', 'PG-MIDTRANS-INV'.$second->id.'-PG1'],
            Transaction::query()->orderBy('id')->pluck('reference')->all(),
        );
        $this->assertSame(100000.0, $first->fresh()->totalPaid());
        $this->assertSame(100000.0, $second->fresh()->totalPaid());
    }

    public function test_database_refuses_a_second_gateway_payment_row_with_the_same_reference(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $row = [
            'reference' => 'PG-MIDTRANS-INV'.$invoice->id.'-PG1',
            'invoice_id' => $invoice->id,
            'channel' => 'gateway',
            'amount' => 100000,
            'method' => 'qris',
            'status' => 'verified',
            'paid_at' => now(),
        ];
        Transaction::create($row);

        $this->expectException(QueryException::class);
        Transaction::create($row);
    }

    // ---- helpers ------------------------------------------------------------

    private function runApply(Invoice $invoice): void
    {
        (new ApplyCustomerCreditsJob($invoice->id))->handle(app(CustomerCreditService::class));
    }

    private function runEnsure(int $customerId, string $period): void
    {
        (new EnsureCustomerCurrentInvoiceJob($customerId, $period))->handle(app(InvoiceGenerationService::class));
    }

    private function credit(Customer $customer, int $amount): CustomerCredit
    {
        return CustomerCredit::create([
            'customer_id' => $customer->id,
            'provider' => 'duitku',
            'gateway_reference' => 'INV0-KILL'.uniqid(),
            'amount' => $amount,
            'reason_code' => CustomerCredit::REASON_DOUBLE_PAYMENT,
            'status' => CustomerCredit::STATUS_AVAILABLE,
        ]);
    }

    /** @return array<string, string> */
    private function midtransPayload(int $invoiceId, int $amount): array
    {
        $orderId = 'INV'.$invoiceId.'-PG1';
        $gross = number_format($amount, 2, '.', '');

        return [
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'signature_key' => hash('sha512', $orderId.'200'.$gross.self::SERVER_KEY),
            'payment_type' => 'qris',
            'order_id' => $orderId,
            'gross_amount' => $gross,
            'fraud_status' => 'accept',
        ];
    }
}
