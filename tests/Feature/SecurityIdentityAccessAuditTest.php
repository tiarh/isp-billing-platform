<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityIdentityAccessAuditTest extends TestCase
{
    public function test_existing_login_lockout_and_role_boundaries_are_present(): void
    {
        $login = file_get_contents(base_path('app/Http/Requests/Auth/LoginRequest.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('RateLimiter::tooManyAttempts($this->throttleKey(), 5)', $login);
        $this->assertStringContainsString('RateLimiter::hit($this->throttleKey(), 900)', $login);
        $this->assertStringContainsString("Route::middleware('superadmin')->group", $routes);
        $this->assertStringContainsString("Route::middleware('global-admin')->group", $routes);
        $this->assertStringContainsString('abort(403)', file_get_contents(base_path('app/Http/Middleware/EnsureSuperAdmin.php')));
        $this->assertStringContainsString('abort(403)', file_get_contents(base_path('app/Http/Middleware/EnsureGlobalAdmin.php')));
    }
}
