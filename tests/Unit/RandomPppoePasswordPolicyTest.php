<?php

namespace Tests\Unit;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Models\Area;
use App\Models\PsbDraft;
use App\Services\RandomPppoePasswordPolicy;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class RandomPppoePasswordPolicyTest extends TestCase
{
    public function test_it_implements_the_policy_contract(): void
    {
        $this->assertInstanceOf(PsbPppoePasswordPolicy::class, new RandomPppoePasswordPolicy);
    }

    public function test_draft_and_area_passwords_are_long_and_alphanumeric(): void
    {
        $policy = new RandomPppoePasswordPolicy;

        foreach ([$policy->passwordForDraft(new PsbDraft), $policy->passwordForArea(new Area)] as $password) {
            $this->assertGreaterThanOrEqual(12, strlen($password));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $password);
        }
    }

    public function test_each_call_returns_a_different_password(): void
    {
        $policy = new RandomPppoePasswordPolicy;
        $seen = [];

        for ($i = 0; $i < 50; $i++) {
            $seen[$policy->passwordForDraft(new PsbDraft)] = true;
        }

        $this->assertCount(50, $seen);
    }
}
