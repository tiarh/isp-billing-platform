<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationFrontendContractTest extends TestCase
{
    public function test_faq_replaces_the_hidden_support_placeholder_in_navigation(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/AppSidebar.tsx'));

        $this->assertStringContainsString('hiddenFromNavigation?: boolean;', $sidebar);
        $this->assertStringContainsString('!item.hiddenFromNavigation', $sidebar);
        $this->assertStringContainsString("{ name: 'FAQ / Panduan', route: 'faq.index', icon: CircleHelp, section: 'growth', access: 'auth' }", $sidebar);
        $this->assertStringNotContainsString("route: 'modules.support'", $sidebar);

        $this->assertTrue(Route::has('faq.index'));
        $this->assertTrue(Route::has('modules.support'), 'Tautan lama /support-center harus tetap dialihkan ke /faq.');
    }

    public function test_mobile_navigation_control_targets_the_drawer_element(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString('aria-controls="mobile-nav"', $layout);
        $this->assertStringContainsString('id="mobile-nav"', $layout);
    }

    public function test_finance_sidebar_route_is_exposed_to_ziggy(): void
    {
        $this->assertTrue(Route::has('finance.per-area.index'));
        $this->assertContains('finance.per-area.*', config('ziggy.only'));
    }

    public function test_saleskit_route_is_exposed_to_ziggy(): void
    {
        // config/ziggy.php only exposes allow-listed routes to the browser.
        // Without saleskit.* the sidebar item is hidden and the page fails to
        // open (route() throws client-side).
        $this->assertTrue(Route::has('saleskit.index'));
        $this->assertContains('saleskit.*', config('ziggy.only'));
    }

    /**
     * Every sidebar route must be in the Ziggy allowlist, otherwise its menu
     * item is hidden and the page crashes client-side (route() unknown).
     *
     * @return list<string>
     */
    private function sidebarRoutes(): array
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/AppSidebar.tsx'));
        $start = strpos($sidebar, 'export const navItems');
        $block = substr($sidebar, $start === false ? 0 : $start);
        $block = substr($block, 0, strpos($block, '];') ?: strlen($block));

        preg_match_all("/route:\s*'([^']+)'/", $block, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function ziggyExposes(string $routeName, array $only): bool
    {
        if (in_array($routeName, $only, true) || in_array('*', $only, true)) {
            return true;
        }

        foreach ($only as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_every_sidebar_route_is_exposed_to_ziggy(): void
    {
        $only = (array) config('ziggy.only');
        $missing = [];

        foreach ($this->sidebarRoutes() as $routeName) {
            if (! Route::has($routeName)) {
                $missing[] = $routeName.' (route not registered)';

                continue;
            }
            if (! $this->ziggyExposes($routeName, $only)) {
                $missing[] = $routeName.' (not in config/ziggy.php only)';
            }
        }

        $this->assertSame([], $missing, "Sidebar routes not exposed to Ziggy:\n".implode("\n", $missing));
    }

    public function test_every_sidebar_route_is_covered_by_the_menu_audit_manifest(): void
    {
        // e2e/tests/menus-audit.spec.ts only visits pages listed in
        // e2e/menus.manifest.json. A new sidebar page that is not added there is
        // never smoke-tested (this is how the Saleskit React #130 crash shipped).
        $manifest = json_decode(
            (string) file_get_contents(base_path('e2e/menus.manifest.json')),
            true,
        );
        $manifestRoutes = array_column(is_array($manifest) ? $manifest : [], 'route');

        $missing = array_values(array_diff($this->sidebarRoutes(), $manifestRoutes));

        $this->assertSame(
            [],
            $missing,
            "Sidebar routes missing from e2e/menus.manifest.json:\n".implode("\n", $missing),
        );
    }
}
