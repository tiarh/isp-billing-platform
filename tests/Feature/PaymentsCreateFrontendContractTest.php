<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentsCreateFrontendContractTest extends TestCase
{
    public function test_payment_create_uses_shared_date_and_currency_formatting(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Payments/Create.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatDate(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringContainsString('type="submit"', $source);
        $this->assertStringNotContainsString('Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('toLocaleTimeString(', $source);
    }
}
