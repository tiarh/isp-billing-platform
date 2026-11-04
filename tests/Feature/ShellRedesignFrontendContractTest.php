<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Redesign A PR-2: pins the app shell (sidebar, flat header, breadcrumb,
 * mobile drawer). Purely visual — the navigation items must not change.
 */
class ShellRedesignFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_nav_items_are_unchanged(): void
    {
        $sidebar = $this->source('resources/js/Components/AppSidebar.tsx');
        $start = strpos($sidebar, 'export const navItems');
        $block = substr($sidebar, $start === false ? 0 : $start);
        $block = substr($block, 0, strpos($block, '];') ?: strlen($block));

        preg_match_all("/name:\s*'([^']+)'/", $block, $matches);

        $this->assertSame([
            'Dashboard', 'Pelanggan', 'PSB WA', 'Saleskit', 'Migrasi', 'Absensi',
            'Invoice', 'Payment', 'Transaksi Online', 'Kelebihan Bayar', 'Keuangan', 'Paket',
            'WA Gateway', 'Broadcast WA', 'Ticketing', 'Monitoring', 'Mapping',
            'Inventaris', 'MikroTik', 'OLT', 'Area', 'Aktivasi',
            'Kemitraan', 'GenieACS', 'FAQ / Panduan',
            'Settings', 'Grup WhatsApp', 'Users', 'Control Plane',
        ], $matches[1]);
        $this->assertStringContainsString("{ name: 'Monitoring', route: 'monitoring.index', icon: Activity, section: 'network', access: 'global_admin' }", $sidebar);
    }

    public function test_nav_items_keep_their_role_access_pairs(): void
    {
        $sidebar = $this->source('resources/js/Components/AppSidebar.tsx');
        $start = strpos($sidebar, 'export const navItems');
        $block = substr($sidebar, $start === false ? 0 : $start);
        $block = substr($block, 0, strpos($block, '];') ?: strlen($block));

        preg_match_all("/name:\s*'([^']+)'.*?access:\s*'([^']+)'/", $block, $matches, PREG_SET_ORDER);
        $pairs = [];
        foreach ($matches as $match) {
            $pairs[$match[1]] = $match[2];
        }

        $this->assertSame([
            'Dashboard' => 'auth',
            'Pelanggan' => 'auth',
            'PSB WA' => 'global_admin',
            'Saleskit' => 'auth',
            'Migrasi' => 'admin',
            'Absensi' => 'auth',
            'Invoice' => 'auth',
            'Payment' => 'superadmin',
            'Transaksi Online' => 'admin_only',
            'Kelebihan Bayar' => 'global_admin_only',
            'Keuangan' => 'admin',
            'Paket' => 'global_admin',
            'WA Gateway' => 'superadmin',
            'Broadcast WA' => 'auth',
            'Ticketing' => 'auth',
            'Monitoring' => 'global_admin',
            'Mapping' => 'auth',
            'Inventaris' => 'auth',
            'MikroTik' => 'global_admin',
            'OLT' => 'global_admin',
            'Area' => 'global_admin',
            'Aktivasi' => 'global_admin',
            'Kemitraan' => 'admin',
            'GenieACS' => 'admin',
            'FAQ / Panduan' => 'auth',
            'Settings' => 'superadmin',
            'Grup WhatsApp' => 'global_admin_only',
            'Users' => 'superadmin',
            'Control Plane' => 'superadmin',
        ], $pairs);
    }

    public function test_mobile_drawer_traps_focus_via_radix_dialog(): void
    {
        $layout = $this->source('resources/js/Layouts/AuthenticatedLayout.tsx');

        $this->assertStringContainsString("from '@radix-ui/react-dialog'", $layout);
        $this->assertStringContainsString('<DialogPrimitive.Root open={mobileNavOpen} onOpenChange={setMobileNavOpen}>', $layout);
        $this->assertStringContainsString('<DialogPrimitive.Title', $layout);
        $this->assertStringNotContainsString('aria-modal="true"', $layout);
        // Drawer modal harus menutup saat viewport melewati md (rotasi), dan efek manual lama sudah dihapus.
        $this->assertStringContainsString("window.matchMedia('(min-width: 768px)')", $layout);
        $this->assertStringContainsString("mq.addEventListener('change', onChange)", $layout);
        $this->assertStringContainsString("mq.removeEventListener('change', onChange)", $layout);
        $this->assertStringNotContainsString('document.body.style.overflow', $layout);
        $this->assertStringContainsString("document.addEventListener('inertia:navigate', close)", $layout);
    }

    public function test_middle_breadcrumb_item_without_link_is_not_styled_as_current(): void
    {
        $breadcrumbs = $this->source('resources/js/Components/Common/Breadcrumbs.tsx');

        $this->assertStringContainsString('isLast ? currentClass : plainClass', $breadcrumbs);
    }

    public function test_sidebar_uses_redesign_tokens(): void
    {
        $sidebar = $this->source('resources/js/Components/AppSidebar.tsx');

        $this->assertStringContainsString('<aside className="', $sidebar);
        $this->assertStringContainsString('w-60', $sidebar);
        $this->assertStringContainsString('bg-sidebar', $sidebar);
        $this->assertStringContainsString('border-r border-border', $sidebar);
        $this->assertStringContainsString('Skynet Admin', $sidebar);
        $this->assertStringContainsString('/images/skynet-mark-96.png', $sidebar);
        $this->assertFileExists(public_path('images/skynet-mark-96.png'));
        $this->assertStringContainsString('bg-primary-soft text-primary-soft-foreground', $sidebar);
        $this->assertStringContainsString("aria-current={isActive ? 'page' : undefined}", $sidebar);
        $this->assertStringNotContainsString('border-l-2 border-primary', $sidebar);
        $this->assertStringNotContainsString("href = '#'", $sidebar);
    }

    public function test_layout_has_flat_header_and_shared_nav(): void
    {
        $layout = $this->source('resources/js/Layouts/AuthenticatedLayout.tsx');

        $this->assertStringContainsString('flex h-14 shrink-0 items-center gap-2 border-b border-border bg-card px-4 sm:px-6', $layout);
        $this->assertStringNotContainsString('rounded-2xl', $layout);
        $this->assertStringNotContainsString('md:w-[calc(100%-16rem)]', $layout);
        $this->assertStringContainsString('<SidebarNav', $layout);
        $this->assertStringNotContainsString('sectionLabels', $layout);
        $this->assertStringContainsString('id="mobile-nav"', $layout);
        $this->assertStringContainsString('aria-controls="mobile-nav"', $layout);
        $this->assertStringContainsString('aria-expanded={mobileNavOpen}', $layout);
        $this->assertStringContainsString('<ContextualHelpButton />', $layout);
        $this->assertStringContainsString('<ThemeToggle />', $layout);
        // Ctrl+K global search is now an owner-approved feature (kept in its own component).
        $this->assertStringContainsString('<GlobalSearch', $layout);
        $this->assertStringNotContainsString('Ctrl K', $layout);
        $this->assertStringNotContainsString('<Bell ', $layout);
    }

    public function test_breadcrumb_is_accessible(): void
    {
        $breadcrumbs = $this->source('resources/js/Components/Common/Breadcrumbs.tsx');

        $this->assertStringContainsString('aria-label="Breadcrumb"', $breadcrumbs);
        $this->assertStringContainsString('aria-label="Dashboard"', $breadcrumbs);
        $this->assertStringContainsString("aria-current={isLast ? 'page' : undefined}", $breadcrumbs);
        $this->assertStringContainsString('max-w-[24ch]', $breadcrumbs);
    }

    public function test_header_buttons_are_flat_and_keep_faq_contract(): void
    {
        $theme = $this->source('resources/js/Components/ThemeToggle.tsx');
        $this->assertStringContainsString('h-8 w-8 rounded-md', $theme);
        $this->assertStringNotContainsString('text-orange-500', $theme);
        $this->assertStringNotContainsString('text-blue-400', $theme);

        $help = $this->source('resources/js/Components/ContextualHelpButton.tsx');
        $this->assertStringContainsString('h-8 w-8 rounded-md', $help);
        $this->assertStringContainsString("route('faq.index', { kategori: categorySlug })", $help);
        $this->assertStringContainsString('data-faq-category', $help);
    }
}
