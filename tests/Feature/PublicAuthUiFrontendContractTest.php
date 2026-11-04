<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicAuthUiFrontendContractTest extends TestCase
{
    private function src(string $path): string
    {
        return (string) file_get_contents(base_path('resources/js/'.$path));
    }

    public function test_public_and_auth_files_have_no_raw_hues(): void
    {
        foreach ([
            'Pages/Public/Payment/Show.tsx',
            'Components/payment/OnlinePaymentPanel.tsx',
            'Layouts/PublicLayout.tsx',
            'Layouts/AuthShell.tsx',
            'Pages/Auth/Login.tsx',
            'Pages/Auth/Totp.tsx',
            'Pages/Auth/TotpChallenge.tsx',
            'Pages/Auth/ForgotPassword.tsx',
            'Pages/Auth/ResetPassword.tsx',
            'Pages/Auth/ConfirmPassword.tsx',
            'Pages/Auth/VerifyEmail.tsx',
        ] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:bg|text|border|ring|fill|stroke)-(?:red|green|blue|amber|orange|yellow|emerald|slate|gray|zinc|indigo|violet)-\d{2,3}\b/',
                $this->src($file),
                $file
            );
        }
    }

    public function test_payment_logic_pins_stay_in_place(): void
    {
        $show = $this->src('Pages/Public/Payment/Show.tsx');
        $this->assertStringContainsString("route('public.invoice.pay', invoice.uuid)", $show);
        $this->assertStringContainsString("window.snap.embed(payload.token, { embedId: 'midtrans-snap-container' })", $show);
        $this->assertStringContainsString("script.dataset.midtransSnap = 'true'", $show);
        $this->assertStringContainsString("isPaid ? 'up' :", $show);
        $this->assertStringContainsString('bg-status-degraded-bg text-status-degraded', $show);
        $this->assertStringContainsString('w-fit self-start', $show);
        $this->assertStringContainsString('id="midtrans-snap-container"', $show);

        $panel = $this->src('Components/payment/OnlinePaymentPanel.tsx');
        $this->assertStringContainsString('window.setInterval(poll, 5000)', $panel);
        $this->assertStringContainsString("route('public.invoice.online.start', invoiceUuid)", $panel);
        $this->assertStringContainsString("route('public.invoice.online.status', invoiceUuid)", $panel);
        $this->assertStringContainsString('bg-white dark:bg-white', $panel);
        foreach (['online-paid', 'online-instructions', 'online-methods'] as $id) {
            $this->assertStringContainsString('data-testid="'.$id.'"', $panel);
        }
    }

    public function test_auth_pages_use_auth_shell_and_gradient_logo(): void
    {
        foreach (['TotpChallenge', 'ForgotPassword', 'ResetPassword', 'ConfirmPassword', 'VerifyEmail'] as $page) {
            $source = $this->src("Pages/Auth/{$page}.tsx");
            $this->assertStringContainsString('@/Layouts/AuthShell', $source, $page);
            $this->assertStringNotContainsString('GuestLayout', $source, $page);
        }

        $shell = $this->src('Layouts/AuthShell.tsx');
        $this->assertStringContainsString('shadow-[var(--shadow-card)]', $shell);
        $this->assertStringNotContainsString(' shadow-card', $shell);

        $login = $this->src('Pages/Auth/Login.tsx');
        $this->assertStringContainsString('/images/skynet-mark-96.png', $login);
        $this->assertStringContainsString('Skynet E-Billing', $login);
        $this->assertStringNotContainsString('<Command', $login);
        $this->assertStringContainsString('/images/skynet-mark-96.png', $this->src('Layouts/PublicLayout.tsx'));
        $this->assertStringNotContainsString('ApplicationLogo', $this->src('Layouts/PublicLayout.tsx'));
    }
}
