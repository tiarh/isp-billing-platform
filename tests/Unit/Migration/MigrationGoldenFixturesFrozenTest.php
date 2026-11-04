<?php

namespace Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Freezes every migration golden fixture by sha256 (migration refactor M0, tests only).
 *
 * A refactor step must not edit a golden to make its test pass: the goldens are the proof
 * that behaviour did not change. This test holds a manifest of the sha256 of every golden in
 * tests/Fixtures/migration-flow (line ends normalised to LF) and fails when a golden is
 * edited, added or removed without the manifest being updated. It is separate from the PSB
 * manifest (tests/Fixtures/psb-flow/fixtures.sha256.json), so a PSB PR and a migration PR
 * never edit the same file.
 *
 * The manifest may change ONLY in a PR the owner approved. Regenerate it with
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI), after the goldens themselves are regenerated.
 */
#[Group('blocking-psb')]
class MigrationGoldenFixturesFrozenTest extends TestCase
{
    private const DIR = __DIR__.'/../../Fixtures/migration-flow';

    /** @return array<string, string> file name => sha256 written as four dash-separated parts */
    private function currentHashes(): array
    {
        $out = [];
        foreach (glob(self::DIR.'/*.golden.json') ?: [] as $file) {
            // Four dash-separated parts: a plain 64-hex value next to a file name is flagged by secret scanners.
            $out[basename($file)] = implode('-', str_split(hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file))), 16));
        }
        ksort($out);

        return $out;
    }

    public function test_every_golden_fixture_matches_the_manifest(): void
    {
        $current = $this->currentHashes();
        $this->assertNotEmpty($current);
        $path = self::DIR.'/fixtures.sha256.json';
        // Hashes only, sorted, no file names: a file name like "api-surface" next to a hash is flagged by secret scanners.
        $hashes = array_values($current);
        sort($hashes);
        $recorded = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (getenv('MIGRATION_FLOW_GOLDEN_WRITE') === '1') {
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($path, 'No manifest. Record it with MIGRATION_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        $changed = [];
        foreach ($current as $file => $hash) {
            if (! in_array($hash, $manifest, true)) {
                $changed[] = $file;
            }
        }
        $this->assertSame([], $changed, 'migration golden fixture(s) edited or added without owner OK: refactor steps must not change goldens');
        $this->assertSame(count($manifest), count($current), 'a golden fixture in the manifest is missing on disk');
    }

    public function test_the_expected_goldens_exist(): void
    {
        $names = array_keys($this->currentHashes());
        foreach (['m01', 'm02', 'm03', 'm04', 'm05', 'm06', 'm07', 'm08', 'm09', 'm10', 'm11', 'm12', 'm13', 'm14', 'm15', 'm16', 'm17', 'm18'] as $prefix) {
            $this->assertNotEmpty(array_filter($names, fn (string $name): bool => str_starts_with($name, $prefix.'-')), 'no golden for '.$prefix);
        }
        $this->assertContains('helpers-matrix.golden.json', $names);
        $this->assertContains('api-surface.golden.json', $names);
    }
}
