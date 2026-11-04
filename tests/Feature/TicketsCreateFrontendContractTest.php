<?php

namespace Tests\Feature;

use Tests\TestCase;

class TicketsCreateFrontendContractTest extends TestCase
{
    public function test_ticket_create_uses_nullable_debounce_ref_without_changing_submit_route(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Create.tsx'));

        $this->assertStringContainsString('useRef<ReturnType<typeof setTimeout> | null>(null)', $source);
        $this->assertStringContainsString("post(route('tickets.store'))", $source);
        $this->assertStringContainsString('fetch(`/api/customers/search?q=', $source);
        $this->assertStringNotContainsString('useRef<ReturnType<typeof setTimeout>>()', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_customer_combobox_trigger_cannot_submit_the_ticket_form(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Create.tsx'));

        $this->assertMatchesRegularExpression(
            '/<PopoverTrigger asChild>\s*<Button\s+type="button"/s',
            $source,
            'The customer combobox trigger must remain a non-submit button inside the ticket form.'
        );
    }
}
