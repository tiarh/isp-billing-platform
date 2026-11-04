<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * env() returns null once config is cached, so it may only be called inside
 * config/ files. app/ and routes/ must read values through config().
 *
 * Not scanned on purpose: config/ (the right place), bootstrap/app.php
 * (TRUSTED_PROXIES runs before config is loaded), database/seeders (dev/e2e).
 * The allow-list for app/ and routes/ is empty.
 */
#[Group('blocking-core')]
class NoEnvOutsideConfigTest extends TestCase
{
    public function test_no_env_call_in_app_or_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['app', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                foreach ($this->envCallLines((string) file_get_contents($file->getPathname())) as $line) {
                    $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':'.$line;
                }
            }
        }

        $this->assertSame([], $offenders, "env() outside config/. Use config() instead:\n".implode("\n", $offenders));
    }

    public function test_scanner_detects_env_calls_but_not_methods_or_comments(): void
    {
        $this->assertSame([2], $this->envCallLines("<?php\n\$a = env('X');"));
        $this->assertSame([], $this->envCallLines("<?php\n// env('X')\n\$a = \$this->env('X'); Foo::env('X'); \$o?->env('X'); function env() {}"));
    }

    /**
     * @return list<int>
     */
    private function envCallLines(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $lines = [];

        foreach ($tokens as $i => $t) {
            if (! is_array($t) || $t[0] !== T_STRING || strtolower($t[1]) !== 'env') {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $lines[] = $t[2];
        }

        return $lines;
    }
}
