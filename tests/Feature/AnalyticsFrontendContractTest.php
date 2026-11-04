<?php

namespace Tests\Feature;

use Tests\TestCase;

class AnalyticsFrontendContractTest extends TestCase
{
    public function test_analytics_page_uses_shared_formatting_and_loading_state(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Analytics/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatNumber(', $source);
        $this->assertStringContainsString('<SkeletonTable', $source);
        $this->assertStringContainsString('text-status-up', $source);
        $this->assertStringContainsString('text-status-down', $source);
        $this->assertStringNotContainsString('new Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_analytics_page_exposes_retryable_error_state(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Analytics/Index.tsx'));

        $this->assertStringContainsString('setError(true)', $source);
        $this->assertStringContainsString('role="alert"', $source);
        $this->assertStringContainsString('type="button"', $source);
        $this->assertStringContainsString('Coba lagi', $source);
        $this->assertStringContainsString('if (!response.ok) throw new Error', $source);
    }
}
