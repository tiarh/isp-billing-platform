<?php

namespace Tests\Feature;

use Tests\TestCase;

class IsolationPortalFrontendContractTest extends TestCase
{
    public function test_isolation_portal_copy_and_polling_contract_are_present(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Isolated/Index.tsx'));

        $this->assertStringContainsString('WifiOff', $source);
        $this->assertStringContainsString('Internet Diisolir', $source);
        $this->assertStringContainsString('Bayar Sekarang', $source);
        $this->assertStringContainsString('Pembayaran Diterima', $source);
        $this->assertStringContainsString('portal_type', $source);
        // STEP 1 footer copy (owner-approved): pay -> auto recovery under 5 minutes.
        $this->assertStringContainsString('Setelah bayar aktif otomatis', $source);
        $this->assertStringContainsString('5 menit', $source);
        $this->assertStringContainsString('Memeriksa status setiap 30 detik', $source);
        // STEP 1 additions: WhatsApp admin + cash-at-office box.
        $this->assertStringContainsString('WhatsApp Admin', $source);
        $this->assertStringContainsString('Bisa bayar tunai di kantor', $source);
        $this->assertStringContainsString('wa_admin', $source);
        $this->assertStringContainsString('office_address', $source);
        $this->assertStringContainsString('30_000', $source);
        $this->assertStringContainsString('5_001', $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringNotContainsString('href={status.payment_url ??', $source);
        $this->assertStringNotContainsString('MikrotikService', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
    }

    public function test_general_isolir_page_contract_has_no_payment_or_customer_data(): void
    {
        // The general page is a standalone Blade view (no Inertia/JS bundle) so it
        // renders inside captive-portal mini-browsers with JS disabled.
        $this->assertFileDoesNotExist(base_path('resources/js/Pages/Isolir/Index.tsx'));
        $source = (string) file_get_contents(base_path('resources/views/isolir/general.blade.php'));

        $this->assertStringContainsString('Internet Anda sedang diisolir', $source);
        $this->assertStringContainsString('Layanan internet Anda sedang diisolir karena', $source);
        $this->assertStringContainsString('Cara mengaktifkan kembali', $source);
        $this->assertStringContainsString('Chat CS via WhatsApp', $source);
        $this->assertStringContainsString('$officeAddress', $source);
        $this->assertStringContainsString('prefers-color-scheme: dark', $source);
        $this->assertStringContainsString('/images/skynet-mark-96.png', $source);
        $this->assertFileExists(public_path('images/skynet-mark-96.png'));
        $this->assertLessThan(8 * 1024, filesize(public_path('images/skynet-mark-96.png')));
        // No bundle, no web fonts, no raw (unescaped) output.
        $this->assertStringNotContainsString('@vite', $source);
        $this->assertStringNotContainsString('@inertia', $source);
        $this->assertStringNotContainsString('fonts.', $source);
        $this->assertStringNotContainsString('{!!', $source);
        // General page must not show invoices, payment links, or customer identity.
        $this->assertStringNotContainsString('payment_url', $source);
        $this->assertStringNotContainsString('Bayar Sekarang', $source);
        $this->assertStringNotContainsString('customer_code', $source);
        $this->assertStringNotContainsString('invoice', strtolower($source));
        $this->assertStringNotContainsString('MikrotikService', $source);
        // Owner request: no bank accounts on the isolation page (CS + office only).
        $this->assertStringNotContainsString('bankAccounts', $source);
        $this->assertStringNotContainsString('payment_channels', $source);
        $this->assertStringNotContainsString('account_number', $source);
        $this->assertStringNotContainsString('rekening', strtolower($source));
        $this->assertStringContainsString('$telLink', $source);
        $this->assertStringContainsString('$officeHours', $source);
        $this->assertStringContainsString('Kantor layanan', $source);
    }

    public function test_general_isolir_page_stays_plain_light_and_mini_browser_safe(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/isolir/general.blade.php'));
        $lower = strtolower($source);

        // Pure HTML + inline CSS: captive mini-browsers may run without JS.
        $this->assertStringNotContainsString('<script', $lower);
        $this->assertStringNotContainsString('onclick', $lower);
        $this->assertStringNotContainsString('@import', $lower);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $lower);
        $this->assertStringNotContainsString('http://', $lower);
        // Calm ISP style: no decorative gradients / glass effects.
        $this->assertStringNotContainsString('gradient(', $lower);
        $this->assertStringNotContainsString('backdrop-filter', $lower);
        // Tap targets >= 44px and both colour schemes.
        $this->assertStringContainsString('min-height: 48px', $source);
        $this->assertStringContainsString('color-scheme', $source);
        // Small payload (excluding the ~2 KB logo).
        $this->assertLessThan(30 * 1024, strlen($source));
        // Raw controller data stays escaped, and the page keeps noindex.
        $this->assertStringNotContainsString('{!!', $source);
        $this->assertStringContainsString('noindex', $source);
    }
}
