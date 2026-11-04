<?php

namespace Tests\Feature;

use Tests\TestCase;

class GlobalSearchFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_layout_mounts_global_search(): void
    {
        $layout = $this->source('resources/js/Layouts/AuthenticatedLayout.tsx');

        $this->assertStringContainsString('<GlobalSearch', $layout);
        $this->assertStringNotContainsString('Ctrl K', $layout);
    }

    public function test_component_contract(): void
    {
        $c = $this->source('resources/js/Components/search/GlobalSearch.tsx');

        foreach ([
            'CommandDialog',
            'shouldFilter={false}',
            'metaKey',
            'ctrlKey',
            'isComposing',
            'aria-live="polite"',
            'router.visit(',
            'aria-label="Cari"',
            'Pencarian global',
            "e.key === '/'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $c, $needle);
        }
        $this->assertStringNotContainsString('localStorage', $c);
        $this->assertStringNotContainsString('sessionStorage', $c);
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|blue|green|amber|yellow|slate|gray|zinc)-\d{2,3}\b/', $c);
    }

    public function test_hook_contract(): void
    {
        $h = $this->source('resources/js/hooks/useGlobalSearch.ts');

        $this->assertStringContainsString('AbortController', $h);
        $this->assertStringContainsString("route('api.global-search')", $h);
        $this->assertStringContainsString('axios.post', $h);
        $this->assertStringContainsString('429', $h);
        $this->assertStringNotContainsString('localStorage', $h);
    }

    public function test_route_is_post_throttled_and_exposed_to_ziggy(): void
    {
        $route = app('router')->getRoutes()->getByName('api.global-search');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertContains('throttle:global-search', $route->gatherMiddleware());
        $this->assertContains('api.global-search', config('ziggy.only'));
    }
}
