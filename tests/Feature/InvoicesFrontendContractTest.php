<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicesFrontendContractTest extends TestCase
{
    public function test_invoice_index_uses_shared_formatting_and_safe_mutation_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatDate(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringContainsString("from '@/Components/StatusBadge'", $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString("route('payments.bulk')", $source);
        $this->assertStringContainsString('invoice_ids: selectedInvoices.map', $source);
        $this->assertStringContainsString('period: invoice.period.slice(0, 10)', $source);
        $this->assertStringContainsString('due_date: invoice.due_date ? invoice.due_date.slice(0, 10) : null', $source);
        $this->assertStringContainsString('invoice-bulk-${crypto.randomUUID()}', $source);
        $this->assertStringNotContainsString('selectedInvoices.map((invoice) => invoice.id).join', $source);
        $this->assertStringContainsString('onError: (errors)', $source);
        $this->assertStringContainsString("from 'sonner'", $source);
        $this->assertStringContainsString('max-h-72', $source);
        $this->assertStringContainsString('aria-label="Lihat detail invoice"', $source);
        $this->assertStringContainsString('aria-label="Unduh PDF invoice"', $source);
        $this->assertStringNotContainsString('confirm(', $source);
        $this->assertStringNotContainsString('Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('toLocaleTimeString(', $source);
        $this->assertStringNotContainsString('animate-pulse', $source);
    }
}
