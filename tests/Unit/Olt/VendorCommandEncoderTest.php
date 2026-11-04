<?php

namespace Tests\Unit\Olt;

use App\Services\Olt\Encoders\FiberHomeCommandEncoder;
use App\Services\Olt\Encoders\HiosoCommandEncoder;
use App\Services\Olt\Encoders\VendorCommandEncoder;
use App\Services\Olt\Encoders\VendorRuleViolationException;
use App\Services\Olt\Encoders\ZteC300CommandEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VendorCommandEncoderTest extends TestCase
{
    #[DataProvider('encoderProvider')]
    public function test_normal_value_passes_through(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->assertSame('customer_abc-123', $encoder->encodeArg('customer_abc-123'));
        // This contains a dash but not leading. The reject rule is leading-dash only.
    }

    #[DataProvider('encoderProvider')]
    public function test_leading_dash_rejected(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->expectException(VendorRuleViolationException::class);

        $encoder->encodeArg('-rf');
    }

    #[DataProvider('encoderProvider')]
    public function test_control_char_rejected(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->expectException(VendorRuleViolationException::class);

        $encoder->encodeArg("normal\x1b[31mred");
    }

    #[DataProvider('encoderProvider')]
    public function test_double_quote_rejected(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->expectException(VendorRuleViolationException::class);

        $encoder->encodeArg('has"quote');
    }

    #[DataProvider('encoderProvider')]
    public function test_shell_expansion_rejected(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->expectException(VendorRuleViolationException::class);

        $encoder->encodeArg('`whoami`');
    }

    #[DataProvider('encoderProvider')]
    public function test_oversize_rejected(string $vendor, VendorCommandEncoder $encoder): void
    {
        $this->expectException(VendorRuleViolationException::class);

        $encoder->encodeArg(str_repeat('a', 257));
    }

    public static function encoderProvider(): array
    {
        return [
            'zte-c300' => ['zte-c300', new ZteC300CommandEncoder],
            'fiberhome-an5506' => ['fiberhome-an5506', new FiberHomeCommandEncoder],
            'hioso' => ['hioso', new HiosoCommandEncoder],
        ];
    }
}
