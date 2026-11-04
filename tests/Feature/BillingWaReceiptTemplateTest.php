<?php

namespace Tests\Feature;

use App\Jobs\SendBillingWaJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingWaEventService;
use App\Services\BillingWaTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class BillingWaReceiptTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_template_contains_name_and_signed_receipt_once(): void
    {
        $customer = $this->customer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'code' => 'INV-WA-001',
            'period' => now()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'paid',
            'generated_at' => now(),
        ]);
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'TEST-WA-001',
            'idempotency_key' => 'test-wa-001',
            'amount' => 100000,
            'channel' => 'manual',
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        $link = 'https://ebilling.example.test/invoices/1/receipt?expires=1&signature=test';

        $message = app(BillingWaTemplateService::class)->render(
            app(BillingWaTemplateService::class)->defaultTemplate(BillingWaTemplateService::EVENT_PAYMENT),
            $customer,
            ['event' => BillingWaTemplateService::EVENT_PAYMENT, 'invoice' => $invoice, 'transaction' => $transaction, 'receipt_link' => $link],
        );

        self::assertStringContainsString('Kuitansi atas nama:', $message);
        self::assertSame(1, substr_count($message, $link));
        self::assertStringContainsString($customer->name, $message);
    }

    public function test_legacy_payment_template_gets_one_receipt_block(): void
    {
        $customer = $this->customer();
        $link = 'https://ebilling.example.test/invoices/1/receipt?expires=1&signature=test';
        $message = app(BillingWaTemplateService::class)->render(
            'Pembayaran {invoice_code} diterima.',
            $customer,
            ['event' => 'payment', 'receipt_link' => $link],
        );

        self::assertSame(1, substr_count($message, $link));
        self::assertStringContainsString($customer->name, $message);
    }

    public function test_paid_payment_dispatches_one_fake_job_with_normalized_phone_and_receipt(): void
    {
        Bus::fake();
        Setting::set('billing_wa_payment_enabled', '1', 'boolean', 'whatsapp');
        Setting::set('wa_billing_writes_enabled', '0', 'boolean', 'whatsapp');
        $customer = $this->customer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'code' => 'INV-WA-002', 'period' => now()->startOfMonth(),
            'amount' => 100000, 'due_date' => now()->addDays(7), 'status' => 'paid', 'generated_at' => now(),
        ]);
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id, 'reference' => 'TEST-WA-002', 'idempotency_key' => 'test-wa-002',
            'amount' => 100000, 'channel' => 'manual', 'status' => 'verified', 'method' => 'cash', 'paid_at' => now(),
        ]);

        app(BillingWaEventService::class)->notifyPayment($invoice->fresh(), $transaction);
        app(BillingWaEventService::class)->notifyPayment($invoice->fresh(), $transaction);

        self::assertDatabaseCount('billing_wa_logs', 1);
        self::assertDatabaseHas('billing_wa_logs', [
            'dedupe_key' => 'payment:invoice:'.$invoice->id.':settled:v2',
            'phone' => '08********90',
        ]);
        self::assertStringContainsString('[RECEIPT_LINK]', (string) DB::table('billing_wa_logs')->where('invoice_id', $invoice->id)->value('message'));
        Bus::assertDispatchedTimes(SendBillingWaJob::class, 1);
        Bus::assertDispatched(SendBillingWaJob::class, function (SendBillingWaJob $job) use ($invoice): bool {
            return $job->phone === '081234567890'
                && $job->queue === 'billing'
                && str_contains($job->message, '/invoices/'.$invoice->id.'/receipt');
        });
    }

    private function customer(): Customer
    {
        $area = Area::create(['code' => 'WAT', 'name' => 'WA Test']);
        $package = Package::create(['code' => 'WA100', 'name' => 'WA Test', 'price' => 100000]);

        return Customer::create([
            'code' => 'WA001', 'name' => 'Pelanggan WA', 'phone' => '81234567890',
            'address' => 'Alamat WA', 'area_id' => $area->id, 'package_id' => $package->id,
            'pppoe_user' => 'wa.test', 'status' => 'active',
        ]);
    }
}
