<?php

namespace Tests\Feature;

use Tests\TestCase;

class UsersFrontendContractTest extends TestCase
{
    public function test_user_deletion_uses_accessible_destructive_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Users/Index.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString("route('users.destroy', userPendingDeletion.id)", $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringNotContainsString('alert(', $source);
    }

    public function test_user_list_exposes_totp_state_with_a_semantic_status_badge(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Users/Index.tsx'));

        $this->assertStringContainsString("import { StatusBadge } from '@/Components/StatusBadge';", $source);
        $this->assertStringContainsString("header: 'TOTP'", $source);
        $this->assertStringContainsString("user.totp_enabled ? 'Aktif' : 'Belum aktif'", $source);
        $this->assertStringContainsString("tone={user.totp_enabled ? 'up' : 'degraded'}", $source);
    }
}
