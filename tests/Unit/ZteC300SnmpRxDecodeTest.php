<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ZteC300SnmpRxDecodeTest extends TestCase
{
    /**
     * @dataProvider cepatKilatDecodeProvider
     */
    public function test_cepatt_kilat_rx_decode_formula(int $raw, float $expected): void
    {
        $rx = round($raw * 0.002 - 30.0, 2);
        $this->assertSame($expected, $rx);
    }

    public static function cepatKilatDecodeProvider(): array
    {
        return [
            'haryanto_4129m' => [1610, -26.78],
            'mita_4184m' => [395, -29.21],
        ];
    }
}
