<?php

namespace Tests\Unit;

use App\Services\IndonesianPhoneNormalizer;
use PHPUnit\Framework\TestCase;

class IndonesianPhoneNormalizerTest extends TestCase
{
    /** @dataProvider validPhones */
    public function test_indonesian_phone_formats_are_canonicalized(string $raw, string $local, string $evolution): void
    {
        $result = (new IndonesianPhoneNormalizer)->normalize($raw);

        self::assertTrue($result['is_valid']);
        self::assertSame($local, $result['canonical_local']);
        self::assertSame($evolution, $result['evolution_recipient']);
    }

    public static function validPhones(): array
    {
        return [
            ['0812 0000 0039', '081200000039', '6281200000039'],
            ['81200000039', '081200000039', '6281200000039'],
            ['6281200000039', '081200000039', '6281200000039'],
            ['+6281200000039', '081200000039', '6281200000039'],
        ];
    }

    /** @dataProvider invalidPhones */
    public function test_invalid_or_non_indonesian_numbers_are_not_guessed(string $raw): void
    {
        $result = (new IndonesianPhoneNormalizer)->normalize($raw);

        self::assertFalse($result['is_valid']);
        self::assertNull($result['canonical_local']);
        self::assertNull($result['evolution_recipient']);
    }

    public static function invalidPhones(): array
    {
        return [[''], ['12345'], ['911234567'], ['+441234567890']];
    }
}
