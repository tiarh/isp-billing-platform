<?php

namespace Tests\Feature;

use Tests\TestCase;

class SkeletonFrontendContractTest extends TestCase
{
    public function test_shared_skeleton_is_static_and_layout_shaped(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/ui/skeleton.tsx'));

        $this->assertStringContainsString('rounded-md bg-muted', $source);
        $this->assertStringNotContainsString('animate-pulse', $source);
    }
}
