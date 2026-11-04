<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicesCreateFrontendContractTest extends TestCase
{
    public function test_invoice_create_uses_shared_currency_format_and_plain_cards(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Create.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('type="submit"', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_customer_combobox_trigger_cannot_submit_the_invoice_form(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Create.tsx'));

        $this->assertMatchesRegularExpression(
            '/<PopoverTrigger asChild>\s*<Button\s+type="button"/s',
            $source,
            'The customer combobox trigger must remain a non-submit button inside the invoice form.'
        );
    }
}
