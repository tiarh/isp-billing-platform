<?php

namespace Tests\Feature;

use Tests\TestCase;

class AttendanceFrontendContractTest extends TestCase
{
    public function test_attendance_page_uses_shared_states_and_formatting(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Attendances/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatDateTime(', $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString('<EmptyState', $source);
        $this->assertStringNotContainsString("from 'date-fns'", $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertStringNotContainsString('<Button variant="outline" onClick=', $source);
        $this->assertStringNotContainsString('<Button onClick={handleSave}', $source);
        // Read-only since the Grup WhatsApp page is the single source of truth: a link, no save handler.
        $this->assertStringNotContainsString('handleSave', $source);
        $this->assertStringContainsString("route('settings.wa-groups')", $source);
    }
}
