<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The theme toggle (resources/js/lib/theme.ts) sets `.dark` on <html>. These
 * contracts keep every theme-aware surface keyed to that class instead of the
 * OS preference, which is what made text unreadable when the chosen theme and
 * the OS theme differed.
 */
class ThemeCoreFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_tailwind_dark_variant_follows_the_dark_class_not_the_os(): void
    {
        $css = $this->source('resources/css/app.css');

        // Tailwind v4 defaults `dark:` to prefers-color-scheme; without this
        // line all `dark:` utilities ignore the in-app toggle.
        $this->assertStringContainsString('@custom-variant dark (&:where(.dark, .dark *));', $css);
    }

    public function test_native_controls_follow_the_chosen_theme(): void
    {
        $css = $this->source('resources/css/app.css');

        $this->assertMatchesRegularExpression('/:root\s*\{[^}]*color-scheme:\s*light;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*color-scheme:\s*dark;/s', $css);
    }

    public function test_light_status_and_destructive_tokens_meet_aa_on_white(): void
    {
        $css = $this->source('resources/css/app.css');

        // Redesign A values (AA-checked in DesignTokenContrastContractTest).
        $this->assertStringContainsString('--status-up: #0f6b45;', $css);
        $this->assertStringContainsString('--status-degraded: #8a5300;', $css);
        $this->assertStringContainsString('--status-down: #b42318;', $css);
        $this->assertStringContainsString('--destructive: oklch(50% 0.182 30);', $css);
    }

    public function test_theme_is_applied_before_paint_and_survives_blocked_storage(): void
    {
        $blade = $this->source('resources/views/app.blade.php');
        $theme = $this->source('resources/js/lib/theme.ts');

        $this->assertStringContainsString("classList.toggle('dark', dark)", $blade);
        $this->assertStringContainsString("try { t = localStorage.getItem('theme'); } catch (e) {}", $blade);
        // The pre-paint script runs before @vite so the first paint is already themed.
        $this->assertLessThan(strpos($blade, '@vite('), strpos($blade, "classList.toggle('dark', dark)"));
        $this->assertStringContainsString('try {', $theme);
    }

    public function test_font_is_self_hosted_and_wired_to_tailwind_font_sans(): void
    {
        $css = $this->source('resources/css/app.css');
        $app = $this->source('resources/js/app.tsx');

        $this->assertStringContainsString('@fontsource-variable/instrument-sans/wght.css', $app);
        $this->assertStringContainsString('--font-sans: "Instrument Sans Variable"', $css);
        $this->assertStringNotContainsString('--font-family-sans', $css);
        $this->assertStringNotContainsString('fonts.googleapis.com', $css);
    }

    public function test_toasts_follow_the_app_theme(): void
    {
        $sonner = $this->source('resources/js/Components/ui/sonner.tsx');

        $this->assertStringNotContainsString('theme="system"', $sonner);
        $this->assertStringContainsString('useIsDark()', $sonner);
        $this->assertStringContainsString("attributeFilter: ['class']", $this->source('resources/js/hooks/useIsDark.ts'));
    }
}
