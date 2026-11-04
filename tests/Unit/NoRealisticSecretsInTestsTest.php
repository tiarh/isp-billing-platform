<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guard against secret-looking test data (GitGuardian flags it on every PR).
 *
 * A password/token/secret/api key assignment inside tests/ whose literal value is
 * 6+ characters and contains a digit looks like a real secret. Use 'canary-pw' for
 * passwords and 'canary-token' for tokens (anything starting with "canary" passes).
 * Values containing SYNTHETIC, FAKE or test-only also pass. Values already in the repo
 * before this guard existed are listed in LEGACY_VALUES; do not add new ones, change
 * the test input instead.
 */
#[Group('blocking-core')]
class NoRealisticSecretsInTestsTest extends TestCase
{
    /** Reported for a credential key whose value is a short literal glued to another literal. Never allowed. */
    private const CONCATENATION = 'short-literal concatenation, use the shared secret helper';

    /**
     * @var list<string>
     */
    private const LEGACY_VALUES = [
        '0x00000000000000000000',
        'hunter2',
        'hunter2secret',
        'hunter2-secret',
        's3cr3t-tk',
        'rahasia-123',
        'health-123',
        'token-value-123',
        'redis-password-hunter2',
    ];

    public function test_tests_dir_has_no_realistic_secret_values(): void
    {
        $violations = [];
        $root = dirname(__DIR__);
        $self = realpath(__FILE__);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || realpath($path) === $self || ! preg_match('/\.(php|json|txt|env|ya?ml|sh|py)$/i', $path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                foreach ($this->suspiciousValues($line) as $value) {
                    if (! $this->isAllowed($value)) {
                        $violations[] = substr($path, strlen($root) + 1).':'.($i + 1).' -> '.$value;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Secret-looking values in tests/. Use 'canary-pw' / 'canary-token' instead:\n".implode("\n", $violations),
        );
    }

    public function test_detector_flags_realistic_and_allows_canary_values(): void
    {
        // Sample values are built from pieces so this file itself holds no secret-looking literal.
        $word = 'Rahasia'.'123';
        $hex = 'abcdef'.'1234567890';
        $key = 'Xk9f'.'Q2mLp';
        $bearer = 'abcdefghijklmnop'.'1234';
        $this->assertSame([$word], $this->suspiciousValues('login pass'.'word='.$word.' user'));
        $this->assertSame([$hex], $this->suspiciousValues('log tok'.'en: '.$hex));
        $this->assertSame([$key], $this->suspiciousValues("'api_"."key' => '".$key."'"));
        $this->assertSame([$bearer], $this->suspiciousValues('Authorization: Bea'.'rer '.$bearer));
        $this->assertSame([], $this->suspiciousValues('password=canary-pw token: canary-token'));
        $this->assertSame([], $this->suspiciousValues("'password' => 'canary-pw'"));
        $this->assertSame([], $this->suspiciousValues('$job->token === $token'));
        $this->assertSame([], $this->suspiciousValues("'secret' => 'secret'"));
        // an empty string glued to a literal (any literal, canary or not) is flagged; the helper call is not
        $empty = "'"."'";
        $this->assertSame([self::CONCATENATION], $this->suspiciousValues("'pass"."word' => ".$empty.".'canary-pw'"));
        $this->assertSame([self::CONCATENATION], $this->suspiciousValues("'to"."ken' => ".$empty.' . '."'x'"));
        $this->assertSame([self::CONCATENATION], $this->suspiciousValues("'pass"."word' => 'ab'.'rest'"));
        $this->assertSame([self::CONCATENATION], $this->suspiciousValues("'to"."ken' => \"x\" . \"y\""));
        $this->assertSame([], $this->suspiciousValues("'title' => ".$empty.".'x'"));
        $this->assertSame([], $this->suspiciousValues("'pass"."word' => 'ab'.\$rest"));
        $this->assertSame([], $this->suspiciousValues("'pass"."word' => Canary::of('pw'),"));
        $this->assertFalse($this->isAllowed(self::CONCATENATION));
        $this->assertTrue($this->isAllowed('canary-pw'));
        $this->assertFalse($this->isAllowed($word));
    }

    /**
     * @return list<string>
     */
    private function suspiciousValues(string $line): array
    {
        $found = [];
        if (preg_match_all('/(?:password|passwd|pwd|token|secret|api[_-]?key)[\'"]?\s*(?:=>|[:=])\s*[\'"]?([^\s\'",;()|@\]}\\\\$]{6,})/i', $line, $m)) {
            foreach ($m[1] as $value) {
                if (preg_match('/\d/', $value)) {
                    $found[] = $value;
                }
            }
        }
        // a short literal (0 to 12 characters, also empty) glued to another literal hides it from the pattern above
        if (preg_match_all('/(?:password|passwd|pwd|token|secret|api[_-]?key|community)[\'"]?\s*(?:=>|[:=])\s*[\'"][^\'"]{0,12}[\'"]\s*\.\s*[\'"]/i', $line, $m)) {
            foreach ($m[0] as $unused) {
                $found[] = self::CONCATENATION;
            }
        }
        if (preg_match_all('/Bearer\s+([A-Za-z0-9._~+\/-]{16,})/', $line, $m)) {
            foreach ($m[1] as $value) {
                $found[] = $value;
            }
        }

        return $found;
    }

    private function isAllowed(string $value): bool
    {
        return str_starts_with(strtolower($value), 'canary')
            || str_contains(strtoupper($value), 'SYNTHETIC')
            || str_contains(strtoupper($value), 'FAKE')
            || str_contains(strtolower($value), 'test-only')
            || in_array($value, self::LEGACY_VALUES, true);
    }
}
