<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class ClientAreaFrontendContractTest extends TestCase
{
    public function test_client_area_pages_keep_routes_and_copy(): void
    {
        $expectations = [
            'resources/js/Pages/ClientArea/Login.tsx' => [
                "post('/pelanggan/login')",
                "processing ? 'Memproses...' : 'Masuk'",
                '<Label htmlFor="code">Kode pelanggan</Label>',
                '<Label htmlFor="password">Kata sandi</Label>',
                'ClientAreaLayout',
            ],
            'resources/js/Pages/ClientArea/Dashboard.tsx' => [
                "router.post('/pelanggan/logout')",
                'invoice.pay_url',
                'invoice.receipt_url',
                'Bayar',
                'ClientAreaLayout',
            ],
            'resources/js/Pages/ClientArea/Terms.tsx' => ['Syarat &amp; Ketentuan'],
            'resources/js/Pages/ClientArea/Refund.tsx' => ['Kebijakan Refund'],
            'resources/js/Layouts/ClientAreaLayout.tsx' => ['MODE SANDBOX', 'href="/syarat-ketentuan"', 'href="/kebijakan-refund"', 'DRAFT - menunggu persetujuan'],
        ];

        foreach ($expectations as $file => $needles) {
            $source = (string) file_get_contents(base_path($file));
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, $file);
            }
            // config/ziggy.php is an allowlist without client-area.*; route() would throw in the browser.
            $this->assertDoesNotMatchRegularExpression('/\broute\(/', $source, $file);
            $this->assertStringNotContainsString('alert(', $source, $file);
            $this->assertStringNotContainsString('AuthenticatedLayout', $source, $file);
        }
    }

    public function test_legal_pages_state_no_refund_credit_next_invoice_and_duitku_methods(): void
    {
        $terms = (string) file_get_contents(base_path('resources/js/Pages/ClientArea/Terms.tsx'));
        $refund = (string) file_get_contents(base_path('resources/js/Pages/ClientArea/Refund.tsx'));

        $this->assertStringContainsString(
            'Pembayaran online dapat dilakukan melalui virtual account bank, QRIS, atau dompet digital (e-wallet), yang diproses oleh penyedia pembayaran Duitku.',
            $terms,
        );
        $this->assertStringNotContainsString('transfer bank', $terms);
        $this->assertStringNotContainsString('tetap tersedia', $terms);

        $this->assertStringContainsString('tidak dapat dikembalikan', $refund);
        $this->assertStringContainsString('dimasukkan sebagai pengurang pada tagihan bulan berikutnya', $refund);
        $this->assertStringContainsString('{company.phone}', $refund);
        $this->assertStringContainsString('{company.email}', $refund);
        foreach (['14 hari', '3 hari kerja', '7 sampai 14 hari kerja', 'dikembalikan ke rekening', 'Kondisi yang dapat diajukan refund'] as $gone) {
            $this->assertStringNotContainsString($gone, $refund);
        }
    }

    public function test_pay_page_hides_manual_transfer_only_for_online_payment(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));
        $panel = (string) file_get_contents(base_path('resources/js/Components/payment/OnlinePaymentPanel.tsx'));

        // Manual tab trigger and content are both gated by showManual (= !online_only, or the failure
        // fallback); the unavailable state keeps the original copy.
        $this->assertStringNotContainsString('{!online_only', $show);
        $this->assertSame(2, substr_count($show, '{showManual && ('));
        $this->assertStringContainsString('const showManual = !online_only || manualFallback;', $show);
        $this->assertStringContainsString('Transfer Bank', $show);
        $this->assertStringContainsString('Gunakan transfer bank atau hubungi dukungan.', $show);
        $this->assertStringContainsString('hubungi layanan pelanggan', $show);

        $this->assertStringNotContainsString('Gunakan transfer bank', $panel);
        $this->assertStringContainsString('Silakan hubungi layanan pelanggan.', $panel);
        $this->assertStringContainsString('Virtual Account (transfer bank)', $panel);
    }

    public function test_pay_page_manual_fallback_is_switch_on_only_and_pinned(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));
        $panel = (string) file_get_contents(base_path('resources/js/Components/payment/OnlinePaymentPanel.tsx'));

        // Fallback needs the switch ON, a reported failure and at least one real account.
        $this->assertStringContainsString('const manualFallback = online_only && onlineUnavailable && hasManual && !txnActive;', $show);
        $this->assertStringContainsString('const hasManual = manual_accounts.length > 0;', $show);
        // Switch OFF keeps today's DOM: tabs start on the instant tab, controlled.
        $this->assertStringContainsString("useState('instant')", $show);
        $this->assertStringContainsString('value={activeTab}', $show);
        // An active VA/QR (or Snap pending) clears the fallback and pins the instant tab; the panel stays mounted
        // (forceMount only when online_only && online, so switch OFF keeps the same markup).
        $this->assertStringContainsString("const activeTab = txnActive ? 'instant' : tab;", $show);
        $this->assertStringContainsString('online_only && onlineUnavailable && hasManual && !txnActive;', $show);
        $this->assertStringContainsString('forceMount={online_only && online ? true : undefined}', $show);
        $this->assertStringContainsString('onTxnActive={handleTxnActive}', $show);
        $this->assertStringContainsString('setOnlineUnavailable(false);', $show);
        // After a failed start the customer returning from the provider gets no fallback.
        $this->assertStringContainsString('if (!returning && isFallbackStartFailure(status, reason))', $panel);
        $this->assertStringNotContainsString('defaultValue="instant"', $show);
        $this->assertStringContainsString('data-testid="manual-fallback-alert"', $show);
        $this->assertStringContainsString('Pembayaran online sedang tidak tersedia', $show);
        $this->assertStringContainsString('Anda tetap bisa membayar melalui Transfer Bank ke rekening resmi kami. Jika saldo Anda sudah terpotong untuk pembayaran online, jangan transfer lagi; hubungi layanan pelanggan.', $show);
        $this->assertStringContainsString('Lihat rekening Transfer Bank', $show);
        $this->assertStringContainsString('Pembayaran online sedang gangguan; transfer bank diverifikasi manual oleh admin.', $show);
        $this->assertStringContainsString('manualFallback={manualFallback}', $show);
        // Load failures open the bank tab; a failed start stays on the online tab.
        $this->assertStringContainsString("if (reason !== 'start_failed' && hasManual) setTab('manual');", $show);
        // Midtrans: Snap onError and create/script failures use the same allowlist; onPending/onClose never do.
        $this->assertStringContainsString('isFallbackStartFailure(failStatus, failReason)', $show);
        // Exactly three triggers: the panel callback, Snap onError and the create/script catch.
        $this->assertSame(3, substr_count($show, 'setOnlineUnavailable(true)'));
        $this->assertStringNotContainsString('onPending: () => setOnlineUnavailable', $show);
        // Snap onPending also raises the txn-active signal. The online_only guard is defensive (the server always
        // sends online_only=true when Snap is offered); onPending never marks the fallback.
        $this->assertStringContainsString('if (online_only) setTxnActive(true);', $show);
        $this->assertSame(1, substr_count($show, 'setTxnActive(true)'));
        $this->assertSame(1, preg_match('/onPending: \(\) => \{\s*setProcessing\(false\);.*?setOnlineUnavailable\(false\);\s*if \(online_only\) setTxnActive\(true\);\s*\},\s*onError:/s', $show));

        // Start-failure allowlist: system failures only.
        preg_match('/FALLBACK_START_REASONS = \[([^\]]*)\];/', $panel, $m);
        $this->assertNotEmpty($m, 'allowlist constant missing');
        foreach (['unavailable', 'provider_error', 'amount_too_small'] as $reason) {
            $this->assertStringContainsString("'{$reason}'", $m[1]);
        }
        foreach (['void', 'not_payable', 'busy', 'invalid_method'] as $reason) {
            $this->assertStringNotContainsString($reason, $m[1]);
        }
        $this->assertStringContainsString('return status === 0 || status >= 500;', $panel);

        // Never report while a pending/created VA/QR is shown, nor when returning from the provider; once only.
        // (#PR1: hasActiveCode = shown code OR any still-active code hidden by "Ganti metode".)
        $this->assertStringContainsString('if (!loadOutcome || hasActiveCode || returning || reportedRef.current) return;', $panel);
        $this->assertStringContainsString('const hasActiveCode = txnActive || activeCodes.length > 0;', $panel);
        $this->assertStringContainsString('onTxnActiveRef.current?.(hasActiveCode);', $panel);
        $this->assertStringContainsString("const txnActive = !!txn && (txn.status === 'pending' || txn.status === 'created');", $panel);
        $this->assertStringContainsString('Silakan bayar melalui Transfer Bank.', $panel);
        $this->assertStringNotContainsString('Gunakan transfer bank', $panel);
    }

    public function test_pay_page_switch_method_confirm_and_active_codes_are_pinned(): void
    {
        $panel = (string) file_get_contents(base_path('resources/js/Components/payment/OnlinePaymentPanel.tsx'));
        $show = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));

        // "Ganti metode" is never a one-click hide: it opens a confirmation first.
        $this->assertStringContainsString('data-testid="switch-method-confirm"', $panel);
        $this->assertStringContainsString('onClick={() => setConfirmSwitch(true)}', $panel);
        $this->assertStringContainsString('Kode pembayaran sebelumnya masih bisa dibayar sampai', $panel);
        $this->assertStringContainsString('Jangan bayar dua kali.', $panel);
        $this->assertStringContainsString('Tetap pakai kode ini', $panel);
        $this->assertSame(1, substr_count($panel, 'setTxn(null)'), 'only the confirmed switch may hide the code');

        // Still-active codes stay listed with "Tampilkan lagi" (client only, no server call).
        $this->assertStringContainsString('data-testid="active-codes-banner"', $panel);
        $this->assertStringContainsString('Tampilkan lagi', $panel);
        $this->assertStringContainsString('onClick={() => isActiveCode(c) && setTxn(c)}', $panel);
        // A code whose amount no longer matches the remaining bill is listed (still counts as active) but
        // never offered again: "Tampilkan lagi" only renders for non-mismatched codes.
        $this->assertStringContainsString('const mismatched = c.amount_invoice !== online.remaining;', $panel);
        $this->assertStringContainsString('{!mismatched && (', $panel);
        $this->assertStringContainsString('Nominal kode ini tidak sesuai sisa tagihan — jangan dibayar.', $panel);
        $this->assertStringContainsString('const activeCodes = codes.filter(isActiveCode);', $panel);
        // Expiry re-check tick and focus handoff to the confirmation.
        $this->assertStringContainsString('setInterval(() => setTick((n) => n + 1), 30_000)', $panel);
        $this->assertStringContainsString('keepCodeRef.current?.focus()', $panel);
        $this->assertStringContainsString('aria-describedby="switch-method-desc"', $panel);
        $this->assertStringContainsString('other_open_transactions?: OnlineTransaction[];', $panel);

        // A refused 4th code is no fallback trigger; the page-level fallback rule is untouched.
        preg_match('/FALLBACK_START_REASONS = \[([^\]]*)\];/', $panel, $m);
        $this->assertStringNotContainsString('too_many_open', $m[1] ?? 'too_many_open');
        $this->assertStringContainsString('const manualFallback = online_only && onlineUnavailable && hasManual && !txnActive;', $show);
        $this->assertStringContainsString('forceMount={online_only && online ? true : undefined}', $show);
    }

    public function test_pay_page_shows_sandbox_banner_display_only(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));

        $this->assertStringContainsString('MODE SANDBOX', $source);
        $this->assertStringContainsString('sandbox?: boolean', $source);
    }
}
