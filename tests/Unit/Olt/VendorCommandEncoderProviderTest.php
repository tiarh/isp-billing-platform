<?php

namespace Tests\Unit\Olt;

use App\Services\Olt\Encoders\FiberHomeCommandEncoder;
use App\Services\Olt\Encoders\HiosoCommandEncoder;
use App\Services\Olt\Encoders\VendorCommandEncoder;
use App\Services\Olt\Encoders\VendorRuleMissingException;
use App\Services\Olt\Encoders\ZteC300CommandEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VendorCommandEncoderProviderTest extends TestCase
{
    #[DataProvider('registeredVendors')]
    public function test_registered_vendor_resolves_to_its_encoder(string $vendor, string $encoderClass): void
    {
        $encoder = app()->make(VendorCommandEncoder::class, ['vendor' => $vendor]);

        $this->assertInstanceOf($encoderClass, $encoder);
        $this->assertSame('customer_abc-123', $encoder->encodeArg('customer_abc-123'));
    }

    public function test_unknown_vendor_fails_closed(): void
    {
        $this->expectException(VendorRuleMissingException::class);

        app()->make(VendorCommandEncoder::class, ['vendor' => 'unknown-vendor']);
    }

    public static function registeredVendors(): array
    {
        return [
            'zte-c300' => ['zte-c300', ZteC300CommandEncoder::class],
            'fiberhome-an5506' => ['fiberhome-an5506', FiberHomeCommandEncoder::class],
            'hioso' => ['hioso', HiosoCommandEncoder::class],
        ];
    }
}
