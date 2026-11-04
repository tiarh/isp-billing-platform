<?php

namespace Tests\Feature;

use Tests\TestCase;

class BroadcastFrontendContractTest extends TestCase
{
    public function test_broadcast_form_uses_inertia_transform_without_provider_side_effects(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Broadcasts/Create.tsx'));

        $this->assertStringContainsString('form.transform((payload)', $source);
        $this->assertStringNotContainsString('transform: (payload)', $source);
        $this->assertStringContainsString("post(route('broadcasts.store')", $source);
        $this->assertStringContainsString('preserveScroll: true', $source);
    }

    public function test_broadcast_pages_use_shared_time_formatting_and_in_app_retry_confirmation(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/Broadcasts/Index.tsx'));
        $show = (string) file_get_contents(base_path('resources/js/Pages/Broadcasts/Show.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $index);
        $this->assertStringContainsString('formatDateTime(', $index);
        $this->assertStringNotContainsString('toLocaleDateString(', $index);
        $this->assertStringContainsString('aria-label="Lihat Detail"', $index);

        $this->assertStringContainsString("from '@/lib/format'", $show);
        $this->assertStringContainsString('formatTime(', $show);
        $this->assertStringNotContainsString('toLocaleTimeString(', $show);
        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $show);
        $this->assertStringContainsString('<ConfirmDestructive', $show);
        $this->assertStringContainsString("route('broadcasts.start'", $show);
        $this->assertStringNotContainsString('confirm(', $show);
    }
}
