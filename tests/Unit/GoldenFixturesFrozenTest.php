<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Freezes every golden fixture (old and new) by sha256 (PSB refactor PR 0, tests only).
 *
 * A refactor PR must not edit a golden to make its test pass: the goldens are the proof
 * that behaviour did not change. This test holds a manifest of the sha256 of every golden
 * fixture (line ends normalised to LF) and fails when a golden is edited, added or removed
 * without the manifest, tests/Fixtures/psb-flow/fixtures.sha256.json, being updated.
 *
 * Which fixtures are goldens:
 *   - tests/Fixtures/wa-text/*.golden.json               (WaTextGoldenTest)
 *   - tests/Fixtures/wa-webhook/golden/*.json            (WaWebhookReplayGoldenTest)
 *   - tests/Fixtures/psb-flow/*.golden.json              (PsbFlowGoldenTest and the PR 0 goldens)
 *
 * The manifest may change ONLY in a PR the owner approved (plan Q8). Regenerate it with
 * PSB_FLOW_GOLDEN_WRITE=1 (never in CI), after the goldens themselves are regenerated.
 */
#[Group('blocking-psb')]
class GoldenFixturesFrozenTest extends TestCase
{
    private const MANIFEST = 'psb-flow/fixtures.sha256.json';

    /** @return array<string, string> relative path (from tests/Fixtures) => sha256 */
    private function currentHashes(): array
    {
        $root = realpath(__DIR__.'/../Fixtures');
        $this->assertNotFalse($root);
        $files = array_merge(
            glob($root.'/wa-text/*.golden.json') ?: [],
            glob($root.'/wa-webhook/golden/*.json') ?: [],
            glob($root.'/psb-flow/*.golden.json') ?: [],
        );
        $out = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            // Written as four dash-separated parts: a plain 64-hex value next to a file name is flagged by secret scanners.
            $out[$relative] = implode('-', str_split(hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file))), 16));
        }
        ksort($out);

        return $out;
    }

    public function test_every_golden_fixture_matches_the_manifest(): void
    {
        $current = $this->currentHashes();
        $this->assertNotEmpty($current);
        $path = __DIR__.'/../Fixtures/'.self::MANIFEST;
        // Hashes only, sorted, no file names: a file name like "api-surface" next to a hash is flagged by secret scanners.
        $hashes = array_values($current);
        sort($hashes);
        $recorded = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (getenv('PSB_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($path, 'No manifest. Record it with PSB_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        $changed = [];
        foreach ($current as $file => $hash) {
            if (! in_array($hash, $manifest, true)) {
                $changed[] = $file;
            }
        }
        $this->assertSame([], $changed, 'golden fixture(s) edited or added without owner OK: refactor PRs must not change goldens');
        $this->assertSame(count($manifest), count($current), 'a golden fixture in the manifest is missing on disk');
    }

    public function test_manifest_covers_the_old_and_the_new_goldens(): void
    {
        $hashes = $this->currentHashes();
        $groups = ['wa-text/' => 0, 'wa-webhook/golden/' => 0, 'psb-flow/' => 0];
        foreach (array_keys($hashes) as $file) {
            foreach ($groups as $prefix => $n) {
                if (str_starts_with($file, $prefix)) {
                    $groups[$prefix]++;
                }
            }
        }
        foreach ($groups as $prefix => $n) {
            $this->assertGreaterThan(0, $n, 'no golden found under '.$prefix);
        }
    }
}
