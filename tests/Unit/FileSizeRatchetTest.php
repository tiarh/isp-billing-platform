<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File-size ratchet: big files may not grow, new files stay small.
 *
 * Scope: app/**.php, routes/*.php, resources/js/**.ts and .tsx (no .d.ts, vendor, node_modules).
 * Lines = number of "\n" after CRLF is turned into LF.
 * Baseline: tests/Fixtures/file-size-ratchet.json (path => max lines), only files over NEW_FILE_CAP.
 *
 * To lower a baseline after a file shrank: run this test with FILE_SIZE_RATCHET_WRITE=1.
 * Write mode only lowers or drops existing entries. It never adds a path or raises an entry
 * (a new path is added only when the baseline is empty, the first bootstrap). A new big file
 * must be split; raising a cap is a manual JSON edit that stays visible in review.
 */
#[Group('blocking-core')]
class FileSizeRatchetTest extends TestCase
{
    public const NEW_FILE_CAP = 1000;

    public const SHRINK_SLACK = 20;

    /** @var array<string, int>|null */
    private static ?array $sizes = null;

    public function test_write_baseline_when_asked(): void
    {
        if (getenv('FILE_SIZE_RATCHET_WRITE') !== '1') {
            $this->markTestSkipped('Set FILE_SIZE_RATCHET_WRITE=1 to rewrite the baseline.');
        }

        $new = self::nextBaseline(self::currentSizes(), $this->loadBaseline());
        $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents(self::baselinePath(), $json);
        $this->assertNotSame('', $json);
    }

    public function test_listed_files_do_not_grow(): void
    {
        $this->assertSame([], self::violations(self::currentSizes(), $this->loadBaseline())['grew'], 'A big file grew.');
    }

    public function test_unlisted_files_stay_under_the_cap(): void
    {
        $this->assertSame([], self::violations(self::currentSizes(), $this->loadBaseline())['unlisted'], 'A file is over '.self::NEW_FILE_CAP.' lines.');
    }

    public function test_shrunk_files_lower_their_baseline(): void
    {
        $this->assertSame([], self::violations(self::currentSizes(), $this->loadBaseline())['shrunk'], 'Baseline is too high.');
    }

    public function test_listed_files_are_still_over_the_cap(): void
    {
        $this->assertSame([], self::violations(self::currentSizes(), $this->loadBaseline())['small'], 'Baseline lists a small file.');
    }

    public function test_checker_flags_each_rule_on_fake_input(): void
    {
        $baseline = ['app/Big.php' => 2000, 'app/Shrunk.php' => 2000, 'app/Small.php' => 1500, 'app/Gone.php' => 1800];
        $sizes = [
            'app/Big.php' => 2001,
            'app/Shrunk.php' => 1979,
            'app/Small.php' => 900,
            'app/NewBig.php' => 1001,
            'app/NewOk.php' => 1000,
        ];
        $v = self::violations($sizes, $baseline);

        $this->assertCount(1, $v['grew']);
        $this->assertStringContainsString('`app/Big.php` grew from 2000 to 2001 lines', $v['grew'][0]);
        $this->assertStringContainsString('new class (≤ 1,000 lines)', $v['grew'][0]);
        $this->assertCount(1, $v['unlisted']);
        $this->assertStringContainsString('app/NewBig.php', $v['unlisted'][0]);
        $this->assertCount(2, $v['shrunk']);
        $this->assertStringContainsString('`app/Shrunk.php` shrank to 1979 (baseline 2000)', $v['shrunk'][0]);
        $this->assertStringContainsString('`app/Gone.php`', $v['shrunk'][1]);
        $this->assertStringContainsString('remove it from the baseline', $v['shrunk'][1]);
        $this->assertCount(1, $v['small']);
        $this->assertStringContainsString('`app/Small.php`', $v['small'][0]);

        // exactly 20 lines of slack is fine; a clean state has no violations
        $clean = self::violations(['app/A.php' => 1980, 'app/B.php' => 10], ['app/A.php' => 2000]);
        $this->assertSame(['grew' => [], 'unlisted' => [], 'shrunk' => [], 'small' => []], $clean);
    }

    public function test_write_mode_never_raises_or_adds_and_only_keeps_big_files(): void
    {
        $sizes = ['app/Grew.php' => 3000, 'app/Shrunk.php' => 1500, 'app/NewBig.php' => 1200, 'app/Small.php' => 100, 'app/Dropped.php' => 900];
        $existing = ['app/Grew.php' => 2500, 'app/Shrunk.php' => 2000, 'app/Dropped.php' => 1100];

        $this->assertSame(
            ['app/Grew.php' => 2500, 'app/Shrunk.php' => 1500],
            self::nextBaseline($sizes, $existing),
        );

        // first bootstrap (empty baseline) records every big file
        $this->assertSame(
            ['app/Grew.php' => 3000, 'app/NewBig.php' => 1200, 'app/Shrunk.php' => 1500],
            self::nextBaseline($sizes, []),
        );
    }

    /**
     * @param  array<string, int>  $sizes  path => lines
     * @param  array<string, int>  $baseline  path => max lines
     * @return array{grew: list<string>, unlisted: list<string>, shrunk: list<string>, small: list<string>}
     */
    public static function violations(array $sizes, array $baseline): array
    {
        $out = ['grew' => [], 'unlisted' => [], 'shrunk' => [], 'small' => []];
        $cap = self::NEW_FILE_CAP;

        foreach ($baseline as $path => $max) {
            if (! array_key_exists($path, $sizes)) {
                $out['shrunk'][] = "`$path` no longer exists: remove it from the baseline (FILE_SIZE_RATCHET_WRITE=1).";

                continue;
            }
            $lines = $sizes[$path];
            if ($lines <= $cap) {
                $out['small'][] = "`$path` is $lines lines, at or under $cap: drop it from the baseline (FILE_SIZE_RATCHET_WRITE=1).";
            } elseif ($lines > $max) {
                $out['grew'][] = "`$path` grew from $max to $lines lines. Do not add code to this file: put the new code in a new class (≤ 1,000 lines) and call it from here.";
            } elseif ($lines < $max - self::SHRINK_SLACK) {
                $out['shrunk'][] = "`$path` shrank to $lines (baseline $max): lower the baseline (FILE_SIZE_RATCHET_WRITE=1) so the gain is locked in.";
            }
        }

        foreach ($sizes as $path => $lines) {
            if (! array_key_exists($path, $baseline) && $lines > $cap) {
                $out['unlisted'][] = "`$path` is a new/unlisted file with $lines lines (cap $cap): split it into smaller classes.";
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $sizes
     * @param  array<string, int>  $existing
     * @return array<string, int>
     */
    public static function nextBaseline(array $sizes, array $existing): array
    {
        $new = [];
        foreach ($sizes as $path => $lines) {
            if ($lines <= self::NEW_FILE_CAP) {
                continue;
            }
            if (isset($existing[$path])) {
                $new[$path] = min($existing[$path], $lines);
            } elseif ($existing === []) {
                $new[$path] = $lines; // first bootstrap only
            }
        }
        ksort($new, SORT_STRING);

        return $new;
    }

    private static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private static function baselinePath(): string
    {
        return self::root().'/tests/Fixtures/file-size-ratchet.json';
    }

    /** @return array<string, int> */
    private function loadBaseline(): array
    {
        $path = self::baselinePath();
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    /** @return array<string, int> */
    private static function currentSizes(): array
    {
        if (self::$sizes !== null) {
            return self::$sizes;
        }
        $root = self::root();
        $sizes = [];
        foreach (['app' => '/\.php$/', 'resources/js' => '/\.tsx?$/'] as $dir => $pattern) {
            if (! is_dir("$root/$dir")) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! $file->isFile() || preg_match($pattern, $path) !== 1 || str_ends_with($path, '.d.ts')
                    || str_contains($path, '/node_modules/') || str_contains($path, '/vendor/') || str_contains($path, '/generated/')) {
                    continue;
                }
                $sizes[substr($path, strlen($root) + 1)] = self::countLines($path);
            }
        }
        foreach (glob("$root/routes/*.php") ?: [] as $path) {
            $sizes[substr(str_replace('\\', '/', $path), strlen($root) + 1)] = self::countLines($path);
        }
        ksort($sizes, SORT_STRING);

        return self::$sizes = $sizes;
    }

    private static function countLines(string $path): int
    {
        return substr_count(str_replace("\r\n", "\n", (string) file_get_contents($path)), "\n");
    }
}
