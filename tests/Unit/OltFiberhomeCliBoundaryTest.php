<?php

namespace Tests\Unit;

use App\Http\Controllers\OltC300Controller;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class OltFiberhomeCliBoundaryTest extends TestCase
{
    public function test_safe_fiberhome_values_pass_the_cli_boundary(): void
    {
        $response = $this->validate([
            'ABCDEF1234567890',
            '1/1/1',
            'RDG276',
            'ALL',
            'UP100M',
            'DW100M',
            100,
            69,
        ]);

        $this->assertNull($response);
    }

    public function test_shell_tokens_are_rejected_before_olt_write(): void
    {
        $response = $this->validate([
            'ABCDEF1234567890',
            '1/1/1',
            'RDG$(whoami)',
            'ALL',
            'UP100M',
            'DW100M',
            100,
            69,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('olt_cli_value_invalid', $response->getData(true)['error_code']);
        $this->assertSame('name', $response->getData(true)['field']);
    }

    public function test_control_bytes_and_oversized_values_are_rejected_before_olt_write(): void
    {
        $controlByteResponse = $this->validate([
            "ABCDEF1234567890\x1b",
            '1/1/1',
            'RDG276',
            'ALL',
            'UP100M',
            'DW100M',
            100,
            69,
        ]);
        $oversizedResponse = $this->validate([
            'ABCDEF1234567890',
            '1/1/1',
            str_repeat('A', 257),
            'ALL',
            'UP100M',
            'DW100M',
            100,
            69,
        ]);

        $this->assertSame(422, $controlByteResponse->getStatusCode());
        $this->assertSame(422, $oversizedResponse->getStatusCode());
    }

    private function validate(array $values)
    {
        $controller = app(OltC300Controller::class);
        $method = new ReflectionMethod($controller, 'validateFiberhomeCliValues');
        $method->setAccessible(true);

        return $method->invoke($controller, ...$values);
    }
}
