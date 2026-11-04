<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Kontrak FAQ / Panduan (resources/js/faq):
 * - setiap menu sidebar punya kategori FAQ,
 * - setiap route yang disebut FAQ benar-benar ada dan bisa dipakai di browser,
 * - konten bebas dari emoji, alamat IP, dan kredensial.
 *
 * Dijalankan CI lewat tests/Feature/*FrontendContractTest.php.
 */
class FaqFrontendContractTest extends TestCase
{
    public function test_every_sidebar_menu_is_covered_by_a_faq_category(): void
    {
        $covered = $this->faqRoutes();

        foreach ($this->visibleSidebarRoutes() as $route) {
            $this->assertContains(
                $route,
                $covered,
                "Menu sidebar '{$route}' belum punya kategori FAQ. Tambahkan ke `routes` salah satu file di resources/js/faq/categories/.",
            );
        }
    }

    public function test_every_route_named_in_faq_exists(): void
    {
        foreach (array_merge($this->faqRoutes(), $this->faqLinkRoutes()) as $route) {
            $this->assertTrue(Route::has($route), "FAQ menunjuk route '{$route}' yang tidak ada.");
        }
    }

    public function test_every_faq_link_route_is_exposed_to_ziggy(): void
    {
        $allowlist = config('ziggy.only');

        foreach (array_merge(['faq.index'], $this->faqLinkRoutes()) as $route) {
            $this->assertTrue(
                $this->matchesZiggy($route, $allowlist),
                "Route '{$route}' dipakai FAQ tetapi tidak ada di config/ziggy.php, tombolnya tidak akan tampil.",
            );
        }
    }

    public function test_faq_category_slugs_and_item_ids_are_unique(): void
    {
        $slugs = [];
        foreach ($this->categoryFiles() as $file) {
            $source = (string) file_get_contents($file);
            preg_match("/slug:\s*'([^']+)'/", $source, $slug);
            $this->assertNotEmpty($slug[1] ?? null, basename($file).' tidak punya slug.');
            $this->assertNotContains($slug[1], $slugs, "Slug FAQ '{$slug[1]}' dipakai dua kali.");
            $slugs[] = $slug[1];

            preg_match_all("/\bid:\s*'([^']+)'/", $source, $ids);
            $this->assertSame(
                count($ids[1]),
                count(array_unique($ids[1])),
                basename($file).' punya id pertanyaan ganda.',
            );
        }
    }

    public function test_faq_content_is_free_of_emoji_ip_addresses_and_credentials(): void
    {
        foreach ($this->categoryFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source), "{$name} mengandung emoji.");
            $this->assertSame(0, preg_match('/\b\d{1,3}(\.\d{1,3}){3}\b/', $source), "{$name} mengandung alamat IP.");
            $this->assertSame(0, preg_match('/password\s*[:=]\s*\S+/i', $source), "{$name} tampak memuat password.");
            $this->assertSame(0, preg_match('/\.sky\.net\.id|\b10\.\d+\.\d+\.\d+\b/', $source), "{$name} mengandung hostname/IP internal.");
        }
    }

    public function test_faq_page_and_contextual_help_are_wired(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AuthenticatedLayout.tsx'));
        $this->assertStringContainsString('<ContextualHelpButton />', $layout);

        $button = (string) file_get_contents(base_path('resources/js/Components/ContextualHelpButton.tsx'));
        $this->assertStringContainsString("route('faq.index', { kategori: categorySlug })", $button);

        $page = (string) file_get_contents(base_path('resources/js/Pages/Faq/Index.tsx'));
        $this->assertStringContainsString('type="search"', $page);
        $this->assertStringContainsString('aria-label="Cari pertanyaan"', $page);
        $this->assertStringContainsString('Panduan untuk bagian ini sedang disusun.', $page);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $page));
    }

    public function test_faq_page_uses_runbook_layout_without_horizontal_scroll(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Faq/Index.tsx'));

        // Tidak ada lagi deretan chip kategori yang digulir menyamping.
        $this->assertStringNotContainsString('overflow-x-auto', $page, 'Halaman FAQ tidak boleh punya scrollbar horizontal.');
        $this->assertStringNotContainsString('overflow-x-scroll', $page, 'Halaman FAQ tidak boleh punya scrollbar horizontal.');

        // Daftar isi: sidebar sticky di desktop, <select> di HP, sorotan bagian aktif.
        $this->assertStringContainsString('aria-label="Daftar isi"', $page);
        $this->assertStringContainsString('className="sticky top-0', $page);
        $this->assertStringContainsString('id="faq-toc"', $page);
        $this->assertStringContainsString('IntersectionObserver', $page);
        $this->assertStringContainsString("from '@/Components/AppSidebar'", $page, 'Kelompok daftar isi harus mengikuti kelompok menu sidebar.');

        // Anchor #slug dan #slug--id tetap dipakai tombol "?" dan tautan langsung.
        $this->assertStringContainsString('id={category.slug}', $page);
        $this->assertStringContainsString('faqItemAnchor(category, item)', $page);
        $this->assertStringContainsString('scroll-mt-', $page);
    }

    /** @return list<string> */
    private function categoryFiles(): array
    {
        $files = glob(base_path('resources/js/faq/categories/*.ts')) ?: [];
        $this->assertNotEmpty($files, 'Tidak ada file kategori FAQ.');

        return $files;
    }

    /** @return list<string> */
    private function faqRoutes(): array
    {
        $routes = [];
        foreach ($this->categoryFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/routes:\s*\[([^\]]*)\]/', $source, $block)) {
                preg_match_all("/'([^']+)'/", $block[1], $names);
                $routes = array_merge($routes, $names[1]);
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return list<string> */
    private function faqLinkRoutes(): array
    {
        $routes = [];
        foreach ($this->categoryFiles() as $file) {
            preg_match_all("/\broute:\s*'([^']+)'/", (string) file_get_contents($file), $names);
            $routes = array_merge($routes, $names[1]);
        }

        return array_values(array_unique($routes));
    }

    /** @return list<string> */
    private function visibleSidebarRoutes(): array
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/AppSidebar.tsx'));
        $start = strpos($sidebar, 'export const navItems');
        $block = substr($sidebar, $start === false ? 0 : $start);
        $block = substr($block, 0, strpos($block, '];') ?: strlen($block));

        $routes = [];
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            if (str_contains($line, 'hiddenFromNavigation: true')) {
                continue;
            }
            if (preg_match("/route:\s*'([^']+)'/", $line, $match)) {
                $routes[] = $match[1];
            }
        }

        return array_values(array_unique($routes));
    }

    /** @param list<string> $allowlist */
    private function matchesZiggy(string $route, array $allowlist): bool
    {
        foreach ($allowlist as $pattern) {
            if ($pattern === $route) {
                return true;
            }
            if (str_ends_with($pattern, '.*') && str_starts_with($route, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
