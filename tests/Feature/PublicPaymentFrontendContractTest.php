<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPaymentFrontendContractTest extends TestCase
{
    public function test_public_payment_page_uses_shared_formatting_helpers(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatDate(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringNotContainsString('Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('toLocaleTimeString(', $source);
    }
}
