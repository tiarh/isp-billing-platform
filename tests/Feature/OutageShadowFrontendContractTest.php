<?php

namespace Tests\Feature;

use App\Services\Outage\OutageShadowEpisodeReadModel;
use Tests\TestCase;

/**
 * Contract for the "Deteksi Gangguan" Monitoring tab. Picked up by the *FrontendContractTest glob.
 */
class OutageShadowFrontendContractTest extends TestCase
{
    private function panel(): string
    {
        $path = base_path('resources/js/Pages/Monitoring/OutageShadowPanel.tsx');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The small components of the tab (rows, cards, detail panel): resources/js/Components/monitoring/deteksi/*. */
    private function parts(): string
    {
        $files = glob(base_path('resources/js/Components/monitoring/deteksi/*')) ?: [];
        $this->assertNotSame([], $files);

        return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $files));
    }

    private function drawer(): string
    {
        return (string) file_get_contents(base_path('resources/js/Components/monitoring/deteksi/EpisodeDrawer.tsx'));
    }

    private function index(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/Monitoring/Index.tsx'));
    }

    public function test_tab_sits_right_after_gangguan_and_renders_the_panel(): void
    {
        $index = $this->index();

        $this->assertMatchesRegularExpression("/\{ id: 'gangguan', label: 'Gangguan' \},\s*\{ id: 'deteksi', label: 'Deteksi Gangguan' \}/", $index);
        $this->assertStringContainsString("activeTab === 'deteksi' && <OutageShadowPanel />", $index);
        $this->assertStringContainsString("activeTabRef.current === 'deteksi'", $index);
        $this->assertStringNotContainsString('bayangan', $index);
    }

    public function test_panel_uses_the_design_system_routes_and_shadow_badge(): void
    {
        $src = $this->panel();
        $all = $src."\n".$this->parts();

        $this->assertStringContainsString('<DataTable', $src);
        $this->assertStringContainsString('density="compact"', $src);
        $this->assertStringContainsString('<StatusBadge', $src);
        $this->assertStringContainsString("from '@/lib/format'", $src);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow'", $src);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow.customers'", $all);
        $this->assertStringContainsString("route('customers.show'", $all);
        $this->assertStringContainsString('Mode uji — tiket hanya dibuat manual', $src);
        $this->assertStringContainsString("tab: 'deteksi'", $src);
        $this->assertStringNotContainsString('bayangan', $src);
    }

    public function test_type_chips_come_from_the_server_with_plain_words_for_every_filter_value(): void
    {
        $src = $this->panel();

        // chips (value + label + count) are rendered from the response, not from a client list
        $this->assertStringContainsString('data?.facets', $src);
        $this->assertStringContainsString('f.label', $src);
        $this->assertStringNotContainsString('TYPE_OPTIONS', $src);
        $this->assertStringNotContainsString('Filter jenis', $src);
        // every filter value the API accepts has a short chip name and a page label
        foreach (OutageShadowEpisodeReadModel::TYPE_FILTERS as $value) {
            $this->assertArrayHasKey($value, OutageShadowEpisodeReadModel::CHIP_LABELS);
        }
        $this->assertSame('Banyak putus bersamaan', OutageShadowEpisodeReadModel::CHIP_LABELS['session_burst']);
        $this->assertSame('Dugaan listrik padam', OutageShadowEpisodeReadModel::CHIP_LABELS['area_power']);
    }

    public function test_search_tabs_and_info_send_the_server_params_with_a_debounce(): void
    {
        $src = $this->panel();

        // params the controller validates
        $this->assertStringContainsString('{ view: f.view }', $src);
        $this->assertStringContainsString('out.q = f.q', $src);
        $this->assertStringContainsString("out.info = '1'", $src);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow', toQuery(", $src);
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OutageShadowController.php'));
        foreach (["'q' =>", "'view' =>", "'info' =>"] as $rule) {
            $this->assertStringContainsString($rule, $controller);
        }
        $this->assertStringContainsString('OutageShadowEpisodeReadModel::VIEW_FILTERS', $controller);
        // typing waits 300 ms and 2 characters; the request is a state change, not one fetch per key
        $this->assertStringContainsString('SEARCH_DEBOUNCE_MS = 300', $src);
        $this->assertStringContainsString('SEARCH_MIN = 2', $src);
        $this->assertStringContainsString('window.setTimeout(() => applyRef.current(', $src);
        $this->assertStringContainsString('window.clearTimeout(timer)', $src);
        $this->assertMatchesRegularExpression('/setQInput\(e\.target\.value\)/', $src);
        $this->assertDoesNotMatchRegularExpression('/onChange=\{\(e\) => apply\(\{ q:/', $src);
        // a new search / tab / chip goes back to page 1 (apply resets it) and aborts the request in flight
        $this->assertMatchesRegularExpression('/function apply\(next: Partial<Filters>\) \{.*?setPage\(1\);/s', $src);
        $this->assertStringContainsString('return () => controller.abort();', $src);
        // tab values and the info types are the server's constants
        $model = (string) file_get_contents(base_path('app/Services/Outage/OutageShadowEpisodeReadModel.php'));
        foreach (['perlu', 'sedang', 'selesai', 'semua'] as $view) {
            $this->assertContains($view, OutageShadowEpisodeReadModel::VIEW_FILTERS);
            $this->assertStringContainsString("{ value: '$view', label:", $src);
        }
        $this->assertSame(OutageShadowEpisodeReadModel::INFO_TYPES, ['weak_rx', 'data_blind']);
        $this->assertStringContainsString("const INFO_TYPES = ['weak_rx', 'data_blind'];", $src);
        $this->assertStringContainsString('VIEW_FILTERS = [', $model);
        // plain words from the plan
        foreach (['Perlu dicek', 'Sedang dicek', 'Selesai', 'Semua', 'Tampilkan info', 'Cari pelanggan: nama, PPPoE, ID, no HP, #kejadian', 'Ketik minimal 2 huruf'] as $text) {
            $this->assertStringContainsString($text, $src);
        }
        foreach (['24 jam', '7 hari', '31 hari', 'Pilih tanggal…'] as $text) {
            $this->assertStringContainsString("'$text'", $src);
        }
        foreach (['Tidak ada gangguan yang perlu dicek.', 'tidak ditemukan. Coba nama lain, PPPoE atau no HP.', 'Ada lebih dari 50 pelanggan cocok. Ketik lebih lengkap.', 'Cari 31 hari', 'info disembunyikan.'] as $text) {
            $this->assertStringContainsString($text, $src);
        }
    }

    public function test_counts_chips_and_filters_come_from_the_server_not_the_browser(): void
    {
        $src = $this->panel();

        // no client-side filtering / sorting / counting of the rows the server returned
        $this->assertDoesNotMatchRegularExpression('/data\??\.data\??\.(filter|sort|toSorted|reduce)\(/', $src);
        $this->assertDoesNotMatchRegularExpression('/(rows|episodes)\.(filter|sort|reduce)\(/', $src);
        foreach (['counts?: Record<View, number>', 'facets?:', 'info_count?:', 'pinned_total?:', 'search?:'] as $field) {
            $this->assertStringContainsString($field, $src);
        }
        $this->assertStringContainsString('counts ? counts[t.value]', $src);
        // the page size picked in the footer is passed to the API (lesson #551: per-page stuck at 20)
        $this->assertStringContainsString('out.per_page = String(perPage)', $src);
        $this->assertStringContainsString('setPerPage(n)', $src);
        $this->assertStringContainsString('toQuery(filters, page, perPage)', $src);
        // the search result shows the matched customer and never asks for a phone
        $this->assertStringContainsString('matched_customers', $src."\n".$this->parts());
        $this->assertDoesNotMatchRegularExpression('/phone/i', $src);
    }

    public function test_filters_use_inertia_client_replace_not_a_visit_and_loads_are_abortable(): void
    {
        $src = $this->panel();

        // review-643: the URL is kept with Inertia's own client-side replace (no request, no history entry), not with a
        // scroll listener that puts the filters back over Inertia's saved URL
        $this->assertStringContainsString('router.replace({ url: `${window.location.pathname}?${params.toString()}`, preserveState: true, preserveScroll: true })', $src);
        $this->assertStringNotContainsString("addEventListener('scroll'", $src);
        // a late deferred-props answer (it carries the URL of the first visit) is put right with the same replace
        $this->assertStringContainsString("router.on('navigate'", $src);
        $this->assertStringContainsString("router.on('finish'", $src);
        $this->assertDoesNotMatchRegularExpression('/router\.(get|visit|reload)\(/', $src);
        $parts = $this->parts();
        $this->assertDoesNotMatchRegularExpression('/router\.(get|visit|reload)\(/', $parts);
        $this->assertSame(1, substr_count($src, 'new AbortController()'), 'the list load is abortable');
        $this->assertSame(1, substr_count($this->drawer(), 'new AbortController()'), 'the detail panel loads (customers, history) through one abortable helper');
        $this->assertStringContainsString('<dt', $parts);
        $this->assertStringContainsString('<dd', $parts);
    }

    public function test_the_24_hour_preset_sends_hours_and_means_the_last_24_hours(): void
    {
        $src = $this->panel();
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OutageShadowController.php'));

        $this->assertStringContainsString("out.hours = '24'", $src);
        $this->assertStringContainsString('hours: value === \'24h\'', $src);
        $this->assertStringContainsString("'hours' =>", $controller);
        $this->assertSame(24, OutageShadowEpisodeReadModel::LAST_HOURS);
    }

    public function test_rows_are_compact_with_a_detail_panel_and_phone_cards(): void
    {
        $src = $this->panel();
        $drawer = $this->drawer();
        $parts = $this->parts();

        // list-first: the list sits above the (collapsed) precision card and the held summary
        $this->assertLessThan(strpos($src, '<DiagnosisPrecisionCard />'), strpos($src, '<DataTable<Episode>'));
        $this->assertStringContainsString('<details', $src);
        // four columns, and the test-mode column moved into the detail panel
        foreach (["header: 'Kejadian'", "header: 'Pelanggan'", "header: 'Status'", "header: 'Aksi'"] as $header) {
            $this->assertStringContainsString($header, $src);
        }
        $this->assertStringNotContainsString("header: 'Seandainya aktif'", $src);
        $this->assertStringNotContainsString("header: 'Waktu (WIB)'", $src);
        $this->assertStringContainsString('Seandainya aktif:', $drawer);
        // phone cards through DataTable.mobileCard, one copy of each row in the page
        $this->assertStringContainsString('mobileCard=', $src);
        $this->assertStringContainsString('<EpisodeCard', $src);
        $this->assertStringContainsString('(min-width: 768px)', $src);
        // pinned band (count from the server) + divider
        $this->assertStringContainsString("'Belum selesai > 24 jam'", $src);
        $this->assertStringContainsString('data?.pinned_total', $src);
        $this->assertStringContainsString('Terbaru', $src);
        // the detail panel is shared by `open=` and reads ONLY the two existing endpoints
        $this->assertStringContainsString("params.set('open'", $src);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow.customers'", $drawer);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow.history'", $drawer);
        $this->assertSame(2, substr_count($parts, "route('api.monitoring."), 'the detail panel adds no endpoint');
        $this->assertStringContainsString('Dugaan sistem:', $drawer);
        $this->assertStringContainsString('diagnosed_label', $drawer);
        // a #id search / unknown episode is answered in plain words, and its tabs stay hidden
        $this->assertStringContainsString('tidak ditemukan.', $src);
        $this->assertStringContainsString('data?.filters.episode != null', $src);
    }

    public function test_pagination_prev_next_use_on_page_change_before_touching_links(): void
    {
        $src = (string) file_get_contents(base_path('resources/js/Components/DataTablePagination.tsx'));

        $this->assertStringNotContainsString('data.links[', $src);
        $this->assertStringContainsString('data.links?.[0]', $src);
        $this->assertStringContainsString('data.links?.[data.links.length - 1]', $src);
        $this->assertLessThan(strpos($src, 'data.links?.[0]'), strpos($src, 'handlePageChange(data.current_page - 1)'));
        $this->assertLessThan(strpos($src, 'data.links?.[data.links'), strpos($src, 'handlePageChange(data.current_page + 1)'));
    }

    public function test_pinned_age_label_reads_the_api_field_in_plain_words(): void
    {
        $src = $this->panel();
        $model = (string) file_get_contents(base_path('app/Services/Outage/OutageShadowEpisodeReadModel.php'));

        $this->assertStringContainsString('pinned_age_days', $src);
        $this->assertStringContainsString('Terbuka {row.pinned_age_days} hari', $src);
        $this->assertStringContainsString('pinnedAgeDays($s,', $model);
        $this->assertStringContainsString('PIN_EXCLUDED_TYPES', $model);
        // pinning happens in the query, not by sorting one page in the browser
        $this->assertStringContainsString('orderByRaw(', $model);
        $this->assertDoesNotMatchRegularExpression('/\.sort\(|\.toSorted\(/', $src);
    }

    public function test_panel_is_read_only_and_leaks_no_pii_fields(): void
    {
        $src = $this->panel();

        $this->assertDoesNotMatchRegularExpression("/method:\s*'(POST|PUT|PATCH|DELETE)'/i", $src);
        $this->assertDoesNotMatchRegularExpression('/router\.(post|put|patch|delete)\(/', $src);
        $this->assertDoesNotMatchRegularExpression('/phone|nik|address/i', $src);
        // status tokens only, no raw colour utilities
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|green|yellow|amber|blue|orange|rose|emerald)-\d{2,3}\b/', $src);
    }
}
