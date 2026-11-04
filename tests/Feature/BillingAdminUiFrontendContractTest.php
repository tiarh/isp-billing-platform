<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Redesign A PR-7a: admin billing screens use status tokens, not raw Tailwind hues,
 * and the page-size select always offers the current size. UI only.
 */
class BillingAdminUiFrontendContractTest extends TestCase
{
    private const RAW_HUE = '/\b(?:[a-z-]+:)*(?:bg|text|border|ring|from|to|via|fill|stroke|divide|outline|decoration|accent|border-l|border-t|border-r|border-b)-(?:red|green|emerald|amber|yellow|orange|blue|sky|violet|purple|rose|slate|gray|indigo|teal|cyan|lime|pink|zinc|neutral|stone)-\d{2,3}/';

    private const FILES = [
        'Pages/Invoices/Index.tsx',
        'Pages/Invoices/Show.tsx',
        'Pages/Invoices/Create.tsx',
        'Pages/Invoices/Edit.tsx',
        'Pages/Payments/Create.tsx',
        'Pages/PaymentGateway/Transactions.tsx',
        'Pages/Settings/PaymentGateway.tsx',
    ];

    public function test_admin_billing_pages_have_no_raw_tailwind_hues(): void
    {
        foreach (self::FILES as $file) {
            $source = (string) file_get_contents(resource_path('js/'.$file));
            $this->assertDoesNotMatchRegularExpression(self::RAW_HUE, $source, $file.' still uses a raw Tailwind hue.');
        }
    }

    public function test_invoice_show_keeps_billing_behaviour_pins(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Invoices/Show.tsx'));

        $this->assertStringContainsString("'Tagihan dibatalkan'", $source);
        $this->assertStringContainsString('<Ban className="w-6 h-6" />', $source);
        $this->assertStringContainsString('inputMode="numeric"', $source);
        $this->assertStringContainsString('className="gap-2 text-status-degraded border-status-degraded/40 hover:bg-status-degraded-bg hover:text-status-degraded"', $source);
        $this->assertStringContainsString('className="gap-2 text-destructive border-destructive/40 hover:bg-destructive/10 hover:text-destructive"', $source);
        $this->assertStringContainsString('className="bg-status-degraded text-status-degraded-bg hover:bg-status-degraded/90"', $source);
        $this->assertStringContainsString('<DialogTitle className="text-destructive">Delete Invoice</DialogTitle>', $source);
        // Record Payment is the default (primary) Button, not a custom green one.
        $this->assertStringContainsString('<Button className="gap-2">', $source);
        $this->assertStringNotContainsString('text-white', $source);
        // shadow-card is not a generated Tailwind utility in this theme.
        $this->assertStringContainsString('shadow-[var(--shadow-card)]', $source);
        $this->assertStringNotContainsString(' shadow-card', $source);
    }

    public function test_overdue_invoices_are_down_toned_in_list_and_detail(): void
    {
        $index = (string) file_get_contents(resource_path('js/Pages/Invoices/Index.tsx'));
        $show = (string) file_get_contents(resource_path('js/Pages/Invoices/Show.tsx'));

        $this->assertStringContainsString("(isOverdue(invoice) ? 'down' : 'degraded')", $index);
        $this->assertStringContainsString('getStatusBadge(invoice)', $index);
        // Raw status 'overdue' (seeded/legacy rows) counts as overdue too.
        $this->assertStringContainsString("invoice.status === 'overdue'", $index);
        $this->assertStringContainsString("invoice.status === 'overdue'", $show);
        // Void badge must not use the neutral question-mark icon.
        $this->assertStringContainsString("showIcon={invoice.status !== 'void'}", $index);
        $this->assertStringContainsString("showIcon={invoice.status !== 'void'}", $show);
        $this->assertStringNotContainsString('getStatusBadge(invoice.status)', $index);

        $this->assertMatchesRegularExpression("/isOverdue\s*\?\s*'down'\s*:\s*'degraded'/", $show);
        $this->assertMatchesRegularExpression("/isOverdue\s*\?\s*'bg-status-down-bg text-status-down'\s*:\s*'bg-status-degraded-bg text-status-degraded'/", $show);
    }

    public function test_invoice_show_action_rows_wrap_on_mobile(): void
    {
        $show = (string) file_get_contents(resource_path('js/Pages/Invoices/Show.tsx'));

        $this->assertStringContainsString('<div className="flex flex-wrap items-center gap-2">', $show);
        $this->assertStringContainsString('<div className="flex flex-wrap items-center gap-3">', $show);
    }

    public function test_page_size_select_always_includes_current_size(): void
    {
        $pagination = (string) file_get_contents(resource_path('js/Components/DataTablePagination.tsx'));
        $table = (string) file_get_contents(resource_path('js/Components/DataTable.tsx'));

        $this->assertStringContainsString('pageSizeOptions?: number[]', $pagination);
        $this->assertStringContainsString('data.per_page', $pagination);
        $this->assertStringContainsString('sizeOptions.map', $pagination);
        $this->assertStringContainsString('Baris per halaman', $pagination);
        $this->assertStringContainsString('pageSizeOptions?: number[]', $table);
        $this->assertStringContainsString('pageSizeOptions={[20, 50, 100]}', (string) file_get_contents(resource_path('js/Pages/Invoices/Index.tsx')));
        $this->assertStringContainsString('pageSizeOptions={[20, 50, 100]}', (string) file_get_contents(resource_path('js/Pages/Customers/Index.tsx')));
    }
}
