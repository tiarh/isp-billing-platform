<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guard against light-only / dark-only color classes that make text
 * unreadable in the other theme.
 *
 * Inside one class string (a single-line '...', "..." or `...` literal), a
 * hard-coded color that only works on one background must come with a
 * `dark:` counterpart for the same property, or be replaced by a semantic
 * token (text-foreground, text-muted-foreground, bg-card, bg-muted,
 * border-border, text-status-up/down/degraded/info, text-destructive, ...).
 *
 * Fix examples:
 *   bg-white                 -> bg-card (or bg-background)
 *   text-gray-900 / text-black -> text-foreground
 *   text-gray-500/600        -> text-muted-foreground
 *   text-emerald-600         -> text-status-up, or text-emerald-700 dark:text-emerald-400
 *   bg-red-50 text-red-700   -> add dark:bg-red-950/40 dark:text-red-400
 *   hover:bg-slate-50        -> hover:bg-muted
 */
class ThemeContrastFrontendContractTest extends TestCase
{
    private const HUES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    private const NEUTRALS = 'gray|slate|zinc|neutral|stone';

    /** Hues whose 300-500 shades are below 4.5:1 as text on white. */
    private const WEAK_ON_LIGHT = 'orange|amber|yellow|lime|green|emerald|teal|cyan|sky';

    /**
     * Intentional single-theme colors: file (relative to resources/js) =>
     * tokens allowed there without a dark: counterpart.
     */
    private const ALLOWLIST = [
        // OLT console: always-black terminal (<pre className="... bg-black">).
        'Pages/OltC300/Index.tsx' => ['text-blue-300', 'text-green-400', 'text-emerald-400', 'text-red-400', 'text-amber-400', 'text-slate-500'],
        // Midtrans Snap embed host: the Snap iframe UI is always light.
        'Pages/Public/Payment/Show.tsx' => ['bg-white'],
    ];

    public function test_color_classes_have_a_counterpart_for_the_other_theme(): void
    {
        $root = base_path('resources/js');
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.tsx?$/', $file->getFilename())) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $allowed = self::ALLOWLIST[$relative] ?? [];
            $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname()));

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*(\/\/|\*)/', $line)) {
                    continue;
                }

                foreach ($this->classScopes($line) as $scope) {
                    foreach ($this->findings($scope) as $token) {
                        if (! in_array($token, $allowed, true)) {
                            $violations[] = $relative.':'.($index + 1).' '.$token;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Theme-unsafe color classes (add a dark: counterpart or use a semantic token, see class docblock):\n".implode("\n", $violations));
    }

    public function test_guard_detects_known_bad_patterns(): void
    {
        $this->assertSame(['bg-white'], $this->findings("'rounded bg-white p-4'"));
        $this->assertSame(['text-gray-900'], $this->findings("'text-gray-900 font-semibold'"));
        $this->assertSame(['text-emerald-600'], $this->findings("'text-emerald-600'"));
        $this->assertSame(['bg-red-50'], $this->findings("'bg-red-50 text-red-700 dark:text-red-300'"));
        $this->assertSame(['hover:bg-slate-50'], $this->findings("'border hover:bg-slate-50'"));
        $this->assertSame(['text-amber-500'], $this->findings("'text-xs text-amber-500'"));

        $this->assertSame([], $this->findings("'bg-white dark:bg-zinc-950 text-gray-900 dark:text-gray-100'"));
        $this->assertSame([], $this->findings("'bg-card text-foreground border-border'"));
        $this->assertSame([], $this->findings("'text-emerald-700 dark:text-emerald-400'"));
        $this->assertSame([], $this->findings("'bg-white/10 text-white'"));
        // Decorative icons (sized h-N w-N) may use 500-level hues.
        $this->assertSame([], $this->findings("'h-4 w-4 text-amber-500'"));
    }

    /** @return list<string> */
    private function classScopes(string $line): array
    {
        $scopes = [];
        $masked = $line;
        foreach (['/`[^`]*`/', "/'[^'\\n]*'/", '/"[^"\\n]*"/'] as $pattern) {
            if (preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $scopes[] = $text;
                    $masked = substr_replace($masked, str_repeat(' ', strlen($text)), $offset, strlen($text));
                }
            }
        }

        return $scopes;
    }

    /** @return list<string> */
    private function findings(string $scope): array
    {
        $tokens = array_values(array_filter(preg_split('/[\s\'"`{}()?,$]+/', $scope) ?: [], 'strlen'));
        $hues = self::HUES;
        $neutrals = self::NEUTRALS;
        $weak = self::WEAK_ON_LIGHT;

        $rules = [
            'bg' => "/^(bg-white(\/([5-9]\d|100))?|bg-({$neutrals})-(50|100|200|300)(\/\d+)?|bg-({$hues})-(50|100|200)(\/\d+)?)$/",
            'text' => "/^(text-black|text-({$neutrals})-(500|600|700|800|900|950)|text-({$hues})-(600|700|800|900|950)|text-({$weak})-(300|400|500)|text-({$hues})-(300|400)|text-(red|rose|blue|indigo|violet|purple|fuchsia|pink)-500)$/",
            'border' => "/^border(-[trblxy])?-({$neutrals}|{$hues})-(100|200|300)$/",
            'hover' => "/^(hover|focus|active):(bg-white|bg-({$neutrals})-(50|100|200)|bg-({$hues})-(50|100))(\/\d+)?$/",
        ];
        $counterparts = [
            'bg' => '/^dark:bg-/',
            'text' => '/^dark:text-/',
            'border' => '/^dark:border/',
            'hover' => '/^dark:(hover|focus|active):bg-/',
        ];

        $hasHeight = (bool) preg_grep('/^h-(\d|\[)/', $tokens);
        $hasWidth = (bool) preg_grep('/^w-(\d|\[)/', $tokens);
        $iconLike = (bool) preg_grep('/^size-\d/', $tokens) || ($hasHeight && $hasWidth);

        $found = [];
        foreach ($rules as $property => $rule) {
            $bad = preg_grep($rule, $tokens);
            if ($bad === [] || preg_grep($counterparts[$property], $tokens)) {
                continue;
            }
            foreach ($bad as $token) {
                if ($property === 'text' && $iconLike && preg_match('/-(300|400|500)$/', $token)) {
                    continue;
                }
                $found[] = $token;
            }
        }

        return array_values(array_unique($found));
    }
}
