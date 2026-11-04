<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthFrontendContractTest extends TestCase
{
    public function test_active_auth_forms_keep_routes_processing_states_and_icon_labels(): void
    {
        $expectations = [
            'resources/js/Pages/Auth/ConfirmPassword.tsx' => ["post(route('password.confirm'))", "processing ? 'Confirming...' : 'Confirm'"],
            'resources/js/Pages/Auth/ForgotPassword.tsx' => ["post(route('password.email'))", "processing ? 'Sending...' : 'Email Password Reset Link'"],
            'resources/js/Pages/Auth/ResetPassword.tsx' => ["post(route('password.store'))", "processing ? 'Resetting...' : 'Reset Password'"],
            'resources/js/Pages/Auth/VerifyEmail.tsx' => ["post(route('verification.send'))", "processing ? 'Sending...' : 'Resend Verification Email'"],
        ];

        foreach ($expectations as $file => $needles) {
            $source = (string) file_get_contents(base_path($file));

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, $file);
            }

            // The decorative brand mark now lives in the shared AuthShell layout.
            $this->assertStringContainsString('@/Layouts/AuthShell', $source, $file);
            $this->assertStringNotContainsString('alert(', $source, $file);
        }

        $shell = (string) file_get_contents(base_path('resources/js/Layouts/AuthShell.tsx'));
        $this->assertStringContainsString('aria-hidden="true"', $shell);
    }

    public function test_forgot_password_email_field_has_an_explicit_label(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Auth/ForgotPassword.tsx'));

        $this->assertStringContainsString('<Label htmlFor="email">Email</Label>', $source);
    }
}
