<?php

namespace Tests\Feature;

use Tests\TestCase;

class TicketsShowEmojiFrontendContractTest extends TestCase
{
    public function test_ticket_contact_actions_use_icon_components_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Show.tsx'));

        $this->assertStringContainsString('<Phone className="h-4 w-4', $source);
        $this->assertStringContainsString('<MessageCircle className="h-4 w-4', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
