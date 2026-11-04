<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicesShowFrontendContractTest extends TestCase
{
    public function test_invoice_show_uses_shared_formatting_and_semantic_status(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Show.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatDate(', $source);
        $this->assertStringContainsString('formatDateTime(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringContainsString("from '@/Components/StatusBadge'", $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringNotContainsString('Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('toLocaleTimeString(', $source);
        $this->assertStringNotContainsString('confirm(', $source);
    }
}
