<?php

namespace Tests\Unit;

use App\Services\OltZteC300Service;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class OltZteC300DeleteOnuTest extends TestCase
{
    #[DataProvider('benignDeleteErrorsProvider')]
    public function test_is_benign_delete_error(string $output, bool $expected): void
    {
        $service = app(OltZteC300Service::class);
        $method = new ReflectionMethod(OltZteC300Service::class, 'isBenignDeleteError');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($service, $output));
    }

    public static function benignDeleteErrorsProvider(): array
    {
        return [
            ['%Error 20200: ONU does not exist', true],
            ['ONU not found on this port', true],
            ['successfully removed', false],
        ];
    }

    #[DataProvider('absentStateProvider')]
    public function test_is_onu_absent_from_state(string $output, string $onuRef, bool $expected): void
    {
        $service = app(OltZteC300Service::class);
        $method = new ReflectionMethod(OltZteC300Service::class, 'isOnuAbsentFromState');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($service, $output, $onuRef));
    }

    public static function absentStateProvider(): array
    {
        return [
            ['', '1/3/11:16', true],
            ['%Error: not found', '1/3/11:16', true],
            ['gpon-onu_1/3/11:16   online', '1/3/11:16', false],
        ];
    }
}
