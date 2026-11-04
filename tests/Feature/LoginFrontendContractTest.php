<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginFrontendContractTest extends TestCase
{
    public function test_login_keeps_auth_contract_and_avoids_visual_slop(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Auth/Login.tsx'));

        $this->assertStringContainsString("post(route('login'))", $source);
        $this->assertStringContainsString("processing ? 'Signing in...' : 'Sign In'", $source);
        $this->assertStringContainsString('aria-hidden="true"', $source);
        $this->assertStringNotContainsString('radial-gradient', $source);
        $this->assertStringNotContainsString('blur-3xl', $source);
        $this->assertStringNotContainsString('animate-spin', $source);
        $this->assertStringNotContainsString('violet-', $source);
    }

    public function test_login_user_visible_copy_has_no_mojibake(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Auth/Login.tsx'));

        $this->assertStringContainsString('placeholder="Enter password"', $source);
        $this->assertStringContainsString('Sistem internal Skynet - akses hanya untuk staf yang berwenang.', $source);
        $this->assertStringNotContainsString('â€¢', $source);
        $this->assertStringNotContainsString('â€”', $source);
    }
}
