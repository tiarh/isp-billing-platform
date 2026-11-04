<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * App code lowers max_execution_time with set_time_limit(); the base TestCase must give the process its
 * "no limit" back after each test, or one test can time out a later, unrelated one.
 */
#[Group('blocking-core')]
class TimeLimitResetTest extends TestCase
{
    public function test_a_test_can_lower_the_time_limit(): void
    {
        set_time_limit(5);

        $this->assertSame('5', (string) ini_get('max_execution_time'));
    }

    #[Depends('test_a_test_can_lower_the_time_limit')]
    public function test_the_limit_is_reset_after_the_previous_test(): void
    {
        $this->assertSame('0', (string) ini_get('max_execution_time'));
    }
}
