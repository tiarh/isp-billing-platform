<?php

namespace Tests\Feature;

use Tests\TestCase;

class RadiusSuiteFrontendContractTest extends TestCase
{
    public function test_radius_suite_only_shows_allowlisted_payment_webhook_urls(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Modules/RadiusSuite.tsx'));

        $this->assertStringNotContainsString('payment-gateway/manual', $page);
        $this->assertStringContainsString("['midtrans', 'duitku']", $page);
        $this->assertStringContainsString('/webhooks/payment-gateway/${provider}', $page);
    }

    public function test_radius_suite_declares_button_types_for_forms_and_actions(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Modules/RadiusSuite.tsx'));

        $this->assertSame(3, substr_count($page, 'type="submit"'));
        $this->assertGreaterThanOrEqual(4, substr_count($page, 'type="button"'));
        $this->assertStringNotContainsString('<Button className="h-9 w-full gap-2"', $page);
        $this->assertStringNotContainsString('<Button variant="outline" onClick=', $page);
    }
}
