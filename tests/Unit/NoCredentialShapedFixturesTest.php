<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Golden and fixture files must not hold credential-shaped values (GitGuardian flags
 * "Username ... Password: word" even when the value is fake). A password/secret/token
 * label may only be followed by a placeholder: <pw...>, canary-*, stars, or nothing.
 * Scans every text fixture under tests/Fixtures/**, every golden/ directory and every
 * *.golden.* / *.snap file under tests/. Known-safe lines are listed in ALLOWED.
 */
#[Group('blocking-core')]
class NoCredentialShapedFixturesTest extends TestCase
{
    private const LABEL = '(?:[a-z]+_(?:password|pass)|password(?:\s+pppoe)?|passwd|pass|secret|token|api[_-]?key)';

    /** @var list<array{0:string, 1:string}> [path fragment, value] known-safe pairs */
    private const ALLOWED = [
        ['/huawei/', '0x00000000'],
        ['/wa-webhook/', 'wrong-token'],
        // WA texts with a bold word right after a label-like word ("... pass: *Gagal*")
        ['/wa-text/', '*Gagal'],
        ['/wa-text/', '*PPPoE'],
        ['/wa-text/', '*Proses'],
        ['/wa-text/', '*Preview'],
        // OLT CLI samples: "Description:" is a CLI field name, not a value
        ['/zte_c300_olt_ra_', 'Description:'],
    ];

    public function test_fixtures_hold_only_placeholder_credentials(): void
    {
        $root = dirname(__DIR__);
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $inFixtures = (str_contains($path, '/tests/Fixtures/') || str_contains($path, '/golden/'))
                && preg_match('/\.(json|txt|ya?ml|csv|log|md|xml|html?)$/i', $path);
            $golden = preg_match('/\.(golden\.[a-z]+|snap)$/i', $path);
            if (! $file->isFile() || (! $inFixtures && ! $golden)) {
                continue;
            }
            $text = (string) file_get_contents($path);
            foreach ($this->suspiciousValues($text) as $value) {
                if ($this->isAllowed($path, $value)) {
                    continue;
                }
                $violations[] = substr($path, strlen($root) + 1).' -> '.$value;
            }
        }

        $this->assertSame([], $violations, "Credential-shaped values in fixtures. Mask them (<pw>, canary-pw):\n".implode("\n", array_slice($violations, 0, 40)));
    }

    public function test_detector_flags_values_and_allows_placeholders(): void
    {
        // Sample values are built from pieces so this file holds no password-shaped literal itself.
        $label = 'Pass'.'word';
        $word = 'zeta'.'blue';
        $this->assertSame([$word], $this->suspiciousValues('User: x\\n'.$label.': '.$word.'\\nProfile'));
        $this->assertSame([$word], $this->suspiciousValues('"pppoe_'.strtolower($label).'": "'.$word.'"'));
        $this->assertSame([$word], $this->suspiciousValues('"router_'.strtolower($label).'": "'.$word.'"'));
        $this->assertSame([$word], $this->suspiciousValues('pppoe_'.'pass'.': '.$word));
        $this->assertCount(1, $this->suspiciousValues($label.': `'.$word.'`'));
        $this->assertCount(1, $this->suspiciousValues($label.': *'.$word.'*'));
        $this->assertCount(1, $this->suspiciousValues($label.': _'.$word.'_'));
        $this->assertSame([], $this->suspiciousValues('Password: <pw>'));
        $this->assertSame([], $this->suspiciousValues('"password": "<pw len=7>"'));
        $this->assertSame([], $this->suspiciousValues('"password": "canary-pw"'));
        $this->assertSame([], $this->suspiciousValues('Password: ****'));
        $this->assertSame([], $this->suspiciousValues('"password_set": true'));
    }

    private function isAllowed(string $path, string $value): bool
    {
        foreach (self::ALLOWED as [$fragment, $allowedValue]) {
            if (str_contains($path, $fragment) && str_starts_with($value, $allowedValue)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function suspiciousValues(string $text): array
    {
        $found = [];
        // label (optionally quoted) then ":" or "=" then an optional quote and the value
        if (preg_match_all('/(?:(?<![A-Za-z_])|(?<=\\\\n))'.self::LABEL.'["\']?\s*[:=]\s*["\']?([^\s"\'\\\\,;}\]]+)/i', $text, $m)) {
            foreach ($m[1] as $value) {
                if ($this->isPlaceholder($value)) {
                    continue;
                }
                $found[] = $value;
            }
        }

        return $found;
    }

    private function isPlaceholder(string $value): bool
    {
        $raw = strtolower($value);
        if (str_starts_with($raw, '<') || str_starts_with($raw, '[redacted') || preg_match('/^[*•]+$/u', $raw) === 1 || preg_match('/^x{4,}$/', $raw) === 1) {
            return true;
        }
        // markdown wrappers (` * _) around a value do not make it a placeholder
        $v = trim($raw, '`*_');

        return $v === ''
            || str_starts_with($v, 'canary')
            || preg_match('/^[a-z0-9]/', $v) !== 1
            || in_array($v, ['true', 'false', 'null', 'tersimpan', 'password', 'secret', 'token'], true);
    }
}
