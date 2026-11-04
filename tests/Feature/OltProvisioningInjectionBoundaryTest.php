<?php

namespace Tests\Feature;

use App\Services\Olt\Encoders\VendorCommandEncoder;
use App\Services\Olt\Encoders\VendorRuleViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OltProvisioningInjectionBoundaryTest extends TestCase
{
    private const ATTACK_SHAPES = [
        'quote_break_newline' => "'\nonu set desc --malicious",
        'leading_dash_option' => '--state=on',
        'crlf_reboot' => "\r\nreboot",
        'tar_archive_inject' => '-T/etc/passwd',
        'sudo_backtick_inject' => '`sudo cat /etc/shadow`',
    ];

    private const CALL_SITES = [
        1975 => 'authorize',
        2185 => 'setOnuAdminState',
        2471 => 'renameOnu',
        2676 => 'rebootOnu',
    ];

    private const VENDORS = [
        'zte_c300',
        'fiberhome',
        'hioso',
    ];

    #[DataProvider('injectionMatrix')]
    public function test_encoder_rejects_injection_before_dispatch(
        string $vendorKey,
        int $callSiteLine,
        string $callSiteName,
        string $shapeKey,
        string $payload
    ): void {
        $encoder = app()->make(VendorCommandEncoder::class, [
            'vendor' => self::vendorName($vendorKey),
        ]);

        $encoded = false;
        $caughtSafely = false;
        try {
            $encoder->encodeArg($payload);
            $encoded = true;
        } catch (VendorRuleViolationException) {
            $caughtSafely = true;
        }

        $this->assertFalse(
            $encoded,
            "Vendor {$vendorKey} must NOT encode dangerous shape '{$shapeKey}' at call site {$callSiteName} (line {$callSiteLine}). Payload: ".json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        $this->assertTrue(
            $caughtSafely,
            "Vendor {$vendorKey} must throw a typed exception for shape '{$shapeKey}' at call site {$callSiteName} (line {$callSiteLine})"
        );
    }

    public static function injectionMatrix(): array
    {
        $cases = [];
        foreach (self::ATTACK_SHAPES as $shapeKey => $payload) {
            foreach (self::CALL_SITES as $line => $callSiteName) {
                foreach (self::VENDORS as $vendorKey) {
                    $cases["{$vendorKey}/{$callSiteName}/{$shapeKey}"] = [
                        $vendorKey,
                        $line,
                        $callSiteName,
                        $shapeKey,
                        $payload,
                    ];
                }
            }
        }

        return $cases;
    }

    private static function vendorName(string $vendorKey): string
    {
        return match ($vendorKey) {
            'zte_c300' => 'zte-c300',
            'fiberhome' => 'fiberhome-an5506',
            'hioso' => 'hioso',
            default => throw new \InvalidArgumentException("Unknown vendor key: {$vendorKey}"),
        };
    }
}
