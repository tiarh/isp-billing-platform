<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Saleskit page contract. A missing required prop (icon) on
 * EnterpriseIndexLayout rendered `<undefined />` -> React error #130 and the
 * page crashed in the browser.
 */
class SaleskitFrontendContractTest extends TestCase
{
    private function page(): string
    {
        return file_get_contents(base_path('resources/js/Pages/Saleskit/Index.tsx'));
    }

    public function test_saleskit_page_passes_the_required_layout_icon(): void
    {
        $page = $this->page();

        // EnterpriseIndexLayout requires `icon`; other pages pass icon={...}.
        $this->assertStringContainsString('<EnterpriseIndexLayout', $page);
        $this->assertMatchesRegularExpression('/<EnterpriseIndexLayout[\s\S]*?icon=\{/', $page);
    }

    public function test_saleskit_route_and_ziggy_allowlist(): void
    {
        $this->assertTrue(Route::has('saleskit.index'));
        $this->assertContains('saleskit.*', config('ziggy.only'));
    }
}
