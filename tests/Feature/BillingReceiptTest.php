<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\BillingReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class BillingReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_receipt_is_public_pdf_without_writing_billing_state(): void
    {
        $invoice = $this->invoiceFixture();
        $url = URL::temporarySignedRoute(
            'public.invoices.receipt',
            now()->addHour(),
            ['invoice' => $invoice->id],
        );

        $response = $this->get($url);

        $response->assertOk();
        self::assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        self::assertSame(1, Invoice::query()->count());
        self::assertSame('paid', $invoice->fresh()->status);
    }

    public function test_receipt_rejects_expired_and_tampered_signatures(): void
    {
        $invoice = $this->invoiceFixture();
        $expired = URL::temporarySignedRoute(
            'public.invoices.receipt',
            now()->subMinute(),
            ['invoice' => $invoice->id],
        );
        $valid = URL::temporarySignedRoute(
            'public.invoices.receipt',
            now()->addHour(),
            ['invoice' => $invoice->id],
        );
        $tampered = preg_replace('/signature=[^&]+/', 'signature=invalid', $valid);

        $this->get($expired)->assertForbidden();
        $this->get((string) $tampered)->assertForbidden();
    }

    public function test_signed_invoice_is_public_pdf_before_payment(): void
    {
        $invoice = $this->invoiceFixture('unpaid');
        $url = app(BillingReceiptService::class)->url($invoice);

        $response = $this->get($url);

        $response->assertOk();
        self::assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        self::assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_admin_download_route_still_requires_login(): void
    {
        $invoice = $this->invoiceFixture('unpaid');

        $this->get(route('invoices.download', $invoice))
            ->assertRedirect(route('login'));
    }

    public function test_invoice_pdf_view_contains_customer_name(): void
    {
        $invoice = $this->invoiceFixture()->load(['customer.package', 'transactions']);

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'company' => ['name' => 'Skynet Network'],
            'manual_accounts' => [],
            'payment_url' => null,
        ])->render();

        self::assertStringContainsString('Nama Pelanggan:', $html);
        self::assertStringContainsString('Nama Kuitansi', $html);
    }

    private function invoiceFixture(string $status = 'paid'): Invoice
    {
        $area = Area::create(['code' => 'REC', 'name' => 'Receipt Area']);
        $package = Package::create(['code' => 'REC100', 'name' => 'Receipt Package', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'REC001',
            'name' => 'Nama Kuitansi',
            'phone' => '081234567890',
            'address' => 'Alamat Receipt',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'receipt.test',
            'status' => 'active',
        ]);

        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => 'INV-REC-001',
            'period' => now()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => $status,
            'generated_at' => now(),
        ]);
    }
}
