<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Redesign A: parses resources/css/app.css and asserts WCAG 2.x AA for the
 * main text/background pairs in both themes (text >= 4.5:1, form-control
 * outline >= 3:1). Pure file parsing; no application boot needed.
 */
#[Group('blocking-core')]
class DesignTokenContrastContractTest extends TestCase
{
    /** @return array<string,string> */
    private function tokens(string $block): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $this->assertSame(1, preg_match('/(?:^|\n)'.preg_quote($block, '/').'\s*\{(.*?)\r?\n\}/s', $css, $m), $block);
        preg_match_all('/(--[a-z0-9-]+):\s*([^;]+);/i', $m[1], $all, PREG_SET_ORDER);
        $out = [];
        foreach ($all as [, $name, $value]) {
            $out[$name] = trim($value);
        }

        return $out;
    }

    /** @return array{0:float,1:float,2:float} linear sRGB */
    private function rgb(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
            $c = array_map(fn ($h) => hexdec($h) / 255, str_split($m[1], 2));

            return array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        }
        if (preg_match('/^oklch\(\s*([\d.]+)%\s+([\d.]+)\s+([\d.]+)\s*\)$/', $value, $m)) {
            $L = (float) $m[1] / 100;
            $a = (float) $m[2] * cos(deg2rad((float) $m[3]));
            $b = (float) $m[2] * sin(deg2rad((float) $m[3]));
            $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
            $mm = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
            $s = ($L - 0.0810000015 * $a - 1.2914855480 * $b) ** 3;

            return array_map(fn ($x) => min(1.0, max(0.0, $x)), [
                4.0767416621 * $l - 3.3077115913 * $mm + 0.2309699292 * $s,
                -1.2684380046 * $l + 2.6097574011 * $mm - 0.3413193965 * $s,
                -0.0041960863 * $l - 0.7034186147 * $mm + 1.7076147010 * $s,
            ]);
        }
        $this->fail("Unparseable colour: {$value}");
    }

    private function ratio(string $fg, string $bg): float
    {
        $lum = fn (array $c) => 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        $a = $lum($this->rgb($fg));
        $b = $lum($this->rgb($bg));

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** @return array<string,array{string,string,string,float}> */
    public static function pairs(): array
    {
        $set = [];
        foreach (['light' => ':root', 'dark' => '.dark'] as $theme => $block) {
            foreach ([
                ['--fg', '--bg', 4.5], ['--fg', '--card', 4.5], ['--fg-2', '--card', 4.5],
                ['--card-fg', '--card', 4.5], ['--popover-fg', '--popover', 4.5],
                ['--muted-fg', '--bg', 4.5], ['--muted-fg', '--card', 4.5], ['--muted-fg', '--muted', 4.5],
                ['--muted-fg', '--primary-soft', 4.5],
                ['--primary-fg', '--primary', 4.5], ['--primary', '--card', 4.5], ['--primary', '--bg', 4.5],
                ['--primary-soft-fg', '--primary-soft', 4.5],
                ['--destructive-fg', '--destructive', 4.5], ['--destructive', '--card', 4.5],
                ['--secondary-fg', '--secondary', 4.5],
                ['--status-up', '--status-up-bg', 4.5], ['--status-down', '--status-down-bg', 4.5],
                ['--status-degraded', '--status-degraded-bg', 4.5], ['--status-info', '--status-info-bg', 4.5],
                ['--status-up', '--card', 4.5], ['--status-down', '--card', 4.5],
                ['--status-degraded', '--card', 4.5], ['--status-info', '--card', 4.5],
                ['--input', '--card', 3.0], // WCAG 1.4.11 form-control outline
            ] as [$fg, $bg, $min]) {
                $set["$theme $fg on $bg >= $min"] = [$block, $fg, $bg, $min];
            }
        }

        return $set;
    }

    #[DataProvider('pairs')]
    public function test_token_pair_meets_wcag_aa(string $block, string $fg, string $bg, float $min): void
    {
        $tokens = $this->tokens($block);
        if ($block === '.dark') {
            // Anything not overridden in .dark falls back to :root.
            $tokens += $this->tokens(':root');
        }
        $this->assertArrayHasKey($fg, $tokens);
        $this->assertArrayHasKey($bg, $tokens);
        $this->assertGreaterThanOrEqual($min, $this->ratio($tokens[$fg], $tokens[$bg]), "{$block} {$fg} on {$bg}");
    }

    public function test_plan_pinned_values_are_present(): void
    {
        $light = $this->tokens(':root');
        $dark = $this->tokens('.dark');

        $this->assertStringStartsWith('oklch(52.3% 0.223 287)', $light['--primary']);
        $this->assertStringStartsWith('oklch(70.6% 0.155 294)', $dark['--primary']);
        $this->assertStringStartsWith('oklch(63.8% 0.011 82)', $light['--input']);
        $this->assertStringStartsWith('oklch(52.8% 0.014 291)', $dark['--input']);
        $this->assertSame('0.875rem', $light['--radius']);
        $this->assertSame('0.625rem', $light['--radius-control']);
    }
}
