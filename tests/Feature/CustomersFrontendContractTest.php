<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomersFrontendContractTest extends TestCase
{
    public function test_customers_page_uses_shared_formatting_and_status_primitive(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Customers/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatDate(', $source);
        $this->assertStringContainsString('formatDateTime(', $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringNotContainsString('confirm(', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('toLocaleTimeString(', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_customers_page_has_no_project_column(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Customers/Index.tsx'));

        $this->assertStringNotContainsString('header: "Project"', $source);
        $this->assertStringNotContainsString("header: 'Project'", $source);
        $this->assertStringNotContainsString('project_label', $source);
        $this->assertStringNotContainsString('service_origin', $source);
    }

    public function test_customer_show_delete_dialog_cancel_closes_the_dialog(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Customers/Show.tsx'));

        $this->assertMatchesRegularExpression(
            '/const \[deleteDialogOpen, setDeleteDialogOpen\] = useState\(false\);/s',
            $source,
            'The customer delete dialog must have controlled open state.'
        );
        $this->assertMatchesRegularExpression(
            '/<Dialog\s+open=\{deleteDialogOpen\}\s+onOpenChange=\{setDeleteDialogOpen\}>/s',
            $source,
            'The customer delete dialog must be controlled so Cancel can close it.'
        );
        $this->assertMatchesRegularExpression(
            '/<Button\s+variant="outline"\s+onClick=\{\(\) => setDeleteDialogOpen\(false\)\}>Batal<\/Button>/s',
            $source,
            'The customer archive dialog "Batal" button must close the controlled dialog.'
        );
    }

    public function test_customer_show_ktp_photo_does_not_use_scale_motion(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Customers/Show.tsx'));

        $this->assertStringContainsString('className="w-full h-full object-cover"', $source);
        $this->assertStringNotContainsString('hover:scale-105', $source);
    }

    public function test_customers_index_follows_a3_table_pattern(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Customers/Index.tsx'));

        $this->assertStringContainsString('header: "Paket"', $source);
        $this->assertStringContainsString('header: "Gabung"', $source);
        $this->assertStringNotContainsString('header: "Package"', $source);
        $this->assertStringNotContainsString('Tanggal Gabung', $source);
        $this->assertStringContainsString('Tidak ada tagihan', $source);
        $this->assertStringNotContainsString('Tidak ada tagihan aktif', $source);
        $this->assertStringContainsString('TERLAMBAT', $source);
        $this->assertStringContainsString('text-status-down', $source);
        $this->assertStringContainsString('aria-pressed', $source);
        $this->assertStringContainsString('truncate', $source);
        $this->assertStringContainsString('title={', $source);
        $this->assertStringContainsString('mobileCard=', $source);
        $this->assertStringContainsString('summary=', $source);
        // Row actions: Edit + Invoice + overflow menu; archive is no longer a one-click icon.
        $this->assertStringContainsString('Detail lengkap', $source);
        $this->assertStringContainsString('Pindah ke Arsip', $source);
        $this->assertStringContainsString("route('customers.invoices', customer.id)", $source);
        // Export is admin/superadmin/noc only (route middleware) - hidden for teknisi.
        $this->assertStringContainsString('{canExport && (', $source);
        // Phone-less, non-archived mobile cards still need a focusable Detail control.
        $this->assertStringContainsString('onClick={() => setSelectedCustomer(customer)}', $source);
        $this->assertStringContainsString('{(tel || wa || !isArchive || (isArchive && isAdmin)) && (', $source);
    }

    public function test_customers_pages_use_semantic_tokens_only(): void
    {
        foreach (['Index', 'Show', 'Edit', 'Create'] as $page) {
            $source = (string) file_get_contents(base_path("resources/js/Pages/Customers/{$page}.tsx"));

            $this->assertDoesNotMatchRegularExpression(
                '/(bg|text|border)-(red|amber|orange|yellow|green|emerald|blue|indigo|violet)-\d+/',
                $source,
                "Customers/{$page}.tsx must use semantic status tokens, not raw hues."
            );
        }
    }
}
