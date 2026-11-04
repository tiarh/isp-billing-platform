<?php

namespace Tests\Feature;

use Tests\TestCase;

class TicketsShowFrontendContractTest extends TestCase
{
    public function test_ticket_delete_uses_in_app_destructive_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Show.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString("router.delete(route('tickets.destroy', ticket.id)", $source);
        $this->assertStringNotContainsString('confirm(', $source);
        $this->assertStringContainsString('formatWibDateTime(', $source);
    }
}
