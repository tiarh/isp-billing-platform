<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Audit 2026-10-06 (docs/AUDIT_EBILLING_UX_PERF_SEC_20261006.md §1.4): pins the
 * UI consistency pass so the fake chrome, English shared-component copy and
 * the technician-page regressions do not creep back.
 */
class UiConsistencyFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_layout_has_no_dead_search_or_static_notification_bell(): void
    {
        $layout = $this->source('resources/js/Layouts/AuthenticatedLayout.tsx');

        $this->assertStringNotContainsString('No new notifications', $layout);
        $this->assertStringNotContainsString('placeholder="Search ..."', $layout);
        $this->assertStringNotContainsString('radial-gradient(circle at 2px 2px', $layout);
        $this->assertStringNotContainsString('<Bell ', $layout);
    }

    public function test_page_shell_has_no_decorative_enterprise_badge(): void
    {
        $shell = $this->source('resources/js/Components/enterprise/EnterprisePageShell.tsx');

        $this->assertStringNotContainsString('>Enterprise</Badge>', $shell);
    }

    public function test_shared_table_components_speak_indonesian(): void
    {
        $pagination = $this->source('resources/js/Components/DataTablePagination.tsx');
        $this->assertStringContainsString('Baris per halaman', $pagination);
        $this->assertStringContainsString('Menampilkan <span', $pagination);
        $this->assertStringContainsString('flex flex-wrap items-center justify-between', $pagination);
        $this->assertStringNotContainsString('Rows per page', $pagination);
        $this->assertStringNotContainsString('Go to next page', $pagination);

        $table = $this->source('resources/js/Components/DataTable.tsx');
        $this->assertStringContainsString('title="Tidak ada data yang cocok"', $table);
        $this->assertStringNotContainsString('No results found', $table);
        $this->assertStringContainsString('searchPlaceholder = "Cari..."', $table);

        $toolbar = $this->source('resources/js/Components/DataTableToolbar.tsx');
        $this->assertStringContainsString('type="search"', $toolbar);
        $this->assertStringContainsString('aria-label={searchPlaceholder}', $toolbar);
        $this->assertStringNotContainsString('<svg', $toolbar);

        $this->assertStringContainsString('confirmText = "Konfirmasi"', $this->source('resources/js/Components/ConfirmDialog.tsx'));
        $this->assertStringContainsString('title = "Hapus"', $this->source('resources/js/Components/TableActions.tsx'));
        $this->assertStringContainsString('Tidak ada hasil.', $this->source('resources/js/Components/DataTableFacetedFilter.tsx'));
    }

    public function test_technician_ticket_page_is_phone_first(): void
    {
        $show = $this->source('resources/js/Pages/Tickets/Show.tsx');

        $this->assertStringContainsString('href={`tel:${digits}`}', $show);
        $this->assertStringContainsString('order-first space-y-6 lg:order-none', $show);
        $this->assertStringContainsString('aria-label={`Hapus foto ${i + 1}`}', $show);
        $this->assertStringContainsString('<Label htmlFor="tech-kendala">', $show);
        $this->assertStringContainsString('<Label htmlFor="tech-status">', $show);
        $this->assertStringContainsString('aria-label="Komentar internal"', $show);
        $this->assertStringContainsString('target="_blank" rel="noreferrer" className="text-primary', $show);
        $this->assertStringNotContainsString('(current)', $show);
    }

    public function test_customer_archive_dialog_describes_a_restorable_archive(): void
    {
        $show = $this->source('resources/js/Pages/Customers/Show.tsx');

        $this->assertStringContainsString('Arsipkan pelanggan ini?', $show);
        $this->assertStringContainsString('dipulihkan dari tab Arsip', $show);
        $this->assertStringNotContainsString('permanently remove', $show);
        $this->assertStringNotContainsString('Confimed', $show);
        $this->assertStringContainsString('<TableHead className="text-right">Nominal</TableHead>', $show);
    }

    public function test_money_columns_are_right_aligned_and_tabular(): void
    {
        $invoices = $this->source('resources/js/Pages/Invoices/Index.tsx');
        $this->assertStringContainsString('className: "text-right"', $invoices);
        $this->assertStringContainsString('items-end gap-0.5 tabular-nums', $invoices);
    }

    public function test_mobile_keyboards_and_lang_attribute(): void
    {
        $this->assertStringContainsString('<html lang="id">', $this->source('resources/views/app.blade.php'));
        $this->assertStringContainsString('autoComplete="current-password"', $this->source('resources/js/Pages/Auth/Login.tsx'));
        $this->assertStringNotContainsString('System Administrator', $this->source('resources/js/Pages/Auth/Login.tsx'));

        foreach ([
            'resources/js/Pages/Customers/Create.tsx',
            'resources/js/Pages/Customers/Edit.tsx',
            'resources/js/Pages/Tickets/Create.tsx',
        ] as $file) {
            $this->assertStringContainsString('inputMode="tel"', $this->source($file), $file);
        }

        foreach ([
            'resources/js/Pages/Payments/Create.tsx',
            'resources/js/Pages/Invoices/Show.tsx',
        ] as $file) {
            $source = $this->source($file);
            $this->assertStringContainsString('inputMode="numeric"', $source, $file);
            $this->assertStringNotContainsString('step="0.01"', $source, $file);
        }
    }

    public function test_invoice_show_does_not_double_toast_controller_flashes(): void
    {
        $show = $this->source('resources/js/Pages/Invoices/Show.tsx');

        $this->assertStringNotContainsString("toast.success('Invoice has been voided.')", $show);
        $this->assertStringNotContainsString("toast.success('Payment recorded successfully')", $show);
        $this->assertStringNotContainsString("toast.success('Invoice deleted successfully.')", $show);
    }

    public function test_public_pages_use_configured_whatsapp_admin(): void
    {
        $pay = $this->source('resources/js/Pages/Public/Payment/Show.tsx');
        $this->assertStringContainsString('company.wa_admin', $pay);
        $this->assertStringContainsString('encodeURIComponent(`Saya sudah bayar tagihan ${invoice.code}`)', $pay);
        $this->assertStringNotContainsString('href="https://wa.me/6281200000048"', $pay);
        $this->assertStringContainsString("isOverdue ? 'destructive' : 'secondary'", $pay);

        $this->assertStringContainsString("'isolation_portal_wa_admin'", $this->source('app/Http/Controllers/PublicInvoiceController.php'));

        $isolated = $this->source('resources/js/Pages/Isolated/Index.tsx');
        // P2 (PR-UX1): WA link uses the 0->62 normalised number (server wa_digits, client fallback).
        $this->assertStringContainsString('const waNumber = waDigits(status);', $isolated);
        $this->assertStringContainsString("{waNumber !== '' && (", $isolated);
    }

    public function test_dark_mode_destructive_token_is_readable(): void
    {
        $css = $this->source('resources/css/app.css');

        $this->assertStringNotContainsString('--destructive: oklch(30.6% 0.132 29);', $css);
        $this->assertStringContainsString('--destructive: oklch(75.9% 0.144 26);', $css);
        $this->assertStringContainsString('--status-degraded: #8a5300;', $css);
    }
}
