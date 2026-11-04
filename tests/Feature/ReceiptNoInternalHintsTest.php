<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentReversal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class ReceiptNoInternalHintsTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = [
        ['bank' => 'BCA', 'account_name' => 'PT SKYNET', 'account_number' => '1234567890'],
    ];

    private function invoice(string $status, ?array $trx = null): Invoice
    {
        $area = Area::create(['code' => 'RCP', 'name' => 'Receipt Test']);
        $package = Package::create(['code' => 'RCP100', 'name' => 'Receipt Test', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'RCP001', 'name' => 'Receipt Customer', 'phone' => '081234567890',
            'address' => 'Test', 'area_id' => $area->id, 'package_id' => $package->id,
            'pppoe_user' => 'receipt.test', 'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'code' => 'INV-RCP-001', 'customer_id' => $customer->id, 'period' => now()->startOfMonth(),
            'amount' => 100000, 'due_date' => now()->addDays(7), 'status' => $status, 'generated_at' => now(),
        ]);
        if ($trx !== null) {
            Transaction::create($trx + [
                'invoice_id' => $invoice->id, 'reference' => 'RCP-TRX-1', 'idempotency_key' => 'rcp-fixture',
                'channel' => 'manual', 'amount' => 100000, 'status' => 'paid', 'method' => 'transfer',
                'paid_at' => '2026-10-05 10:00:00',
            ]);
        }

        return $invoice->load(['customer.package', 'transactions.reversals', 'reversals']);
    }

    private function html(Invoice $invoice, array $accounts, ?string $url = 'https://example.test/pay/x'): string
    {
        return view('invoices.pdf', [
            'invoice' => $invoice,
            'company' => ['name' => 'Test Company'],
            'manual_accounts' => $accounts,
            'payment_url' => $url,
        ])->render();
    }

    public function test_unpaid_invoice_with_manual_accounts_is_unchanged(): void
    {
        $html = $this->html($this->invoice('unpaid'), self::ACCOUNTS);

        $this->assertStringContainsString('Info Pembayaran:', $html);
        $this->assertStringContainsString('1) Bayar online (QRIS / VA / e-wallet / kartu)', $html);
        $this->assertStringContainsString('2) Transfer bank manual', $html);
        $this->assertStringContainsString('BCA A/N PT SKYNET', $html);
        $this->assertStringContainsString('1234567890', $html);
        $this->assertStringNotContainsString('Ringkasan Pembayaran', $html);
        $this->assertStringNotContainsString('Status: LUNAS', $html);
    }

    public function test_unpaid_invoice_without_payment_url_shows_plain_manual_title(): void
    {
        $html = $this->html($this->invoice('unpaid'), self::ACCOUNTS, null);

        $this->assertStringContainsString('Transfer bank manual', $html);
        $this->assertStringNotContainsString('2) Transfer bank manual', $html);
        $this->assertStringContainsString('1234567890', $html);
    }

    public function test_no_internal_admin_hint_when_no_manual_accounts(): void
    {
        $html = $this->html($this->invoice('unpaid'), []);
        $this->assertStringNotContainsString('diatur di eBilling', $html);
        $this->assertStringNotContainsString('Settings', $html);
        $this->assertStringNotContainsString('payment channels', $html);
        $this->assertStringNotContainsString('Transfer bank manual', $html);
        $this->assertStringContainsString('Bayar online', $html);

        $this->assertStringNotContainsString('diatur di eBilling', $html);
    }

    public function test_unpaid_without_accounts_and_url_omits_payment_section(): void
    {
        $html = $this->html($this->invoice('unpaid'), [], null);

        $this->assertStringNotContainsString('Info Pembayaran', $html);
        $this->assertStringNotContainsString('diatur di eBilling', $html);
    }

    public function test_paid_invoice_hides_manual_section_and_shows_summary(): void
    {
        $html = $this->html($this->invoice('paid', []), self::ACCOUNTS);

        $this->assertStringNotContainsString('Transfer bank manual', $html);
        $this->assertStringNotContainsString('Info Pembayaran', $html);
        $this->assertStringNotContainsString('1234567890', $html);
        $this->assertStringNotContainsString('diatur di eBilling', $html);
        $this->assertStringNotContainsString('Nominal Sudah Bayar', $html);
        $this->assertStringContainsString('Ringkasan Pembayaran', $html);
        $this->assertStringContainsString('Dibayar: Rp 100.000 — 05 Oktober 2026 — Transfer bank', $html);
        $this->assertStringContainsString('Status: LUNAS', $html);
    }

    private function extraTrx(Invoice $invoice, string $key, array $attrs = []): Transaction
    {
        return Transaction::create($attrs + [
            'invoice_id' => $invoice->id, 'reference' => $key, 'idempotency_key' => $key,
            'channel' => 'manual', 'amount' => 100000, 'status' => 'paid', 'method' => 'transfer',
            'paid_at' => '2026-10-06 03:00:00',
        ]);
    }

    private function reverse(Transaction $trx, float $amount): void
    {
        PaymentReversal::create([
            'transaction_id' => $trx->id, 'invoice_id' => $trx->invoice_id, 'amount' => $amount,
            'reversal_reference' => 'REV-'.$trx->reference, 'idempotency_key' => 'rev-'.$trx->reference,
            'reason' => 'Test refund', 'reversed_by' => User::factory()->create(['role' => 'superadmin'])->id,
            'reversed_at' => now(),
        ]);
    }

    private function reload(Invoice $invoice): Invoice
    {
        return $invoice->refresh()->load(['customer.package', 'transactions.reversals', 'reversals']);
    }

    public function test_paid_date_is_rendered_in_wib(): void
    {
        $manual = $this->html($this->invoice('paid', ['paid_at' => '2026-10-04 17:00:00']), []);
        $this->assertStringContainsString('— 05 Oktober 2026 —', $manual);
        $this->assertStringNotContainsString('04 Oktober 2026', $manual);
    }

    public function test_gateway_paid_date_is_rendered_in_wib(): void
    {
        $html = $this->html($this->invoice('paid', [
            'channel' => 'gateway', 'method' => 'va', 'idempotency_key' => 'gateway:duitku:b',
            'paid_at' => '2026-10-04 18:30:00',
        ]), []);
        $this->assertStringContainsString('— 05 Oktober 2026 — Virtual Account via Duitku', $html);
    }

    public function test_fully_reversed_duplicate_payment_shows_one_line(): void
    {
        $invoice = $this->invoice('paid', []);
        $dup = $this->extraTrx($invoice, 'dup-1');
        $this->reverse($dup, 100000);

        $html = $this->html($this->reload($invoice), []);
        $this->assertSame(1, substr_count($html, 'Dibayar: Rp'));
        $this->assertStringContainsString('Dibayar: Rp 100.000', $html);
    }

    public function test_partial_reversal_shows_net_amount(): void
    {
        $invoice = $this->invoice('unpaid', ['amount' => 100000]);
        $trx = $invoice->transactions->first();
        $this->reverse($trx, 30000);

        $html = $this->html($this->reload($invoice), self::ACCOUNTS);
        $this->assertStringContainsString('Dibayar: Rp 70.000', $html);
        $this->assertStringNotContainsString('Dibayar: Rp 100.000', $html);
        $this->assertStringContainsString('Sisa tagihan: Rp 30.000', $html);
    }

    public function test_all_payments_reversed_hides_summary(): void
    {
        $invoice = $this->invoice('unpaid', []);
        $this->reverse($invoice->transactions->first(), 100000);

        $invoice = $this->reload($invoice);
        $this->assertSame(0.0, $invoice->totalPaid());
        $html = $this->html($invoice, self::ACCOUNTS);
        $this->assertStringNotContainsString('Ringkasan Pembayaran', $html);
        $this->assertStringNotContainsString('Sisa tagihan', $html);
        $this->assertStringContainsString('2) Transfer bank manual', $html);
    }

    public function test_multiple_payments_each_get_a_line_and_partial_unpaid_keeps_accounts(): void
    {
        $invoice = $this->invoice('unpaid', ['amount' => 40000]);
        $this->extraTrx($invoice, 'multi-2', ['amount' => 25000]);

        $html = $this->html($this->reload($invoice), self::ACCOUNTS);
        $this->assertStringContainsString('Dibayar: Rp 40.000', $html);
        $this->assertStringContainsString('Dibayar: Rp 25.000', $html);
        $this->assertStringContainsString('Sisa tagihan: Rp 35.000', $html);
        $this->assertStringContainsString('1234567890', $html);
        $this->assertStringContainsString('2) Transfer bank manual', $html);
    }

    public function test_paid_gateway_invoice_names_provider_in_summary(): void
    {
        $html = $this->html($this->invoice('paid', [
            'channel' => 'gateway', 'method' => 'va', 'idempotency_key' => 'gateway:duitku:abc',
        ]), []);

        $this->assertStringContainsString('Virtual Account via Duitku', $html);
        $this->assertStringNotContainsString('Nominal Sudah Bayar', $html);
    }

    public function test_credit_application_shows_saldo_line_without_internal_hints(): void
    {
        $html = $this->html($this->invoice('paid', [
            'channel' => 'manual', 'method' => 'other', 'status' => 'verified',
            'idempotency_key' => 'credit:7:9:100000.00', 'reference' => 'CREDIT-7-9-261016000000',
            'notes' => 'Saldo kelebihan bayar #7 (order INV0-INTERNAL)',
        ]), []);

        $this->assertStringContainsString('Saldo (kelebihan bayar): Rp 100.000 — 05 Oktober 2026', $html);
        $this->assertStringNotContainsString('Dibayar: Rp', $html);
        $this->assertStringNotContainsString('CREDIT-', $html);
        $this->assertStringNotContainsString('credit:', $html);
        $this->assertStringNotContainsString('INV0-INTERNAL', $html);
        $this->assertStringNotContainsString('Lainnya', $html);
        $this->assertStringContainsString('Status: LUNAS', $html);
    }

    public function test_credit_and_cash_payments_on_one_invoice_get_separate_labels(): void
    {
        $invoice = $this->invoice('unpaid', ['amount' => 60000, 'method' => 'cash']);
        $this->extraTrx($invoice, 'credit:3:4:40000.00', ['amount' => 40000, 'method' => 'other', 'status' => 'verified']);

        $html = $this->html($this->reload($invoice), []);
        $this->assertStringContainsString('Dibayar: Rp 60.000', $html);
        $this->assertStringContainsString('Saldo (kelebihan bayar): Rp 40.000', $html);
        $this->assertSame(1, substr_count($html, 'Saldo (kelebihan bayar)'));
        $this->assertSame(1, substr_count($html, 'Dibayar: Rp'));
    }

    public function test_no_customer_facing_source_contains_internal_hint(): void
    {
        foreach ([
            'resources/views/invoices/pdf.blade.php',
            'resources/js/Pages/Public/Payment/Show.tsx',
            'resources/js/Pages/Isolated/Index.tsx',
            'app/Services/BillingWaTemplateService.php',
        ] as $file) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString('diatur di eBilling', $src, $file);
            $this->assertStringNotContainsString('Rekening manual belum dikonfigurasi', $src, $file);
        }
    }

    public function test_csp_allows_duitku_image_host_without_loosening_other_directives(): void
    {
        $csp = (string) (new SecurityHeaders)->handle(Request::create('/pay/x', 'GET'), static fn () => response('ok'))
            ->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/img-src [^;]*https:\/\/images\.duitku\.com(?:[ ;]|$)/', $csp);
        $this->assertSame(1, substr_count($csp, 'duitku.com'));
        $this->assertStringNotContainsString('*.duitku.com', $csp);
        $this->assertDoesNotMatchRegularExpression('/(script-src|style-src|connect-src|default-src)[^;]*duitku/', $csp);
        $this->assertDoesNotMatchRegularExpression('/img-src[^;]*(?:\s\*|https:(?:\s|;|$))/', $csp);
    }

    public function test_method_icon_has_local_fallback_when_image_fails(): void
    {
        $panel = (string) file_get_contents(base_path('resources/js/Components/payment/OnlinePaymentPanel.tsx'));

        $this->assertStringContainsString('function MethodIcon', $panel);
        $this->assertStringContainsString('onError={() => setFailed(true)}', $panel);
        $this->assertStringContainsString('methodInitials(name)', $panel);
        $this->assertStringContainsString('<MethodIcon name={m.name} imageUrl={m.image_url ?? null} />', $panel);
    }
}
