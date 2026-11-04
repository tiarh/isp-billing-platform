<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TechnicianMobileFrontendContractTest extends TestCase
{
    private function recoverySources(): string
    {
        $combined = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Recovery.tsx'));
        $files = glob(base_path('resources/js/Components/recovery/*')) ?: [];
        $this->assertNotEmpty($files, 'Components/recovery/* must exist');

        foreach ($files as $file) {
            $combined .= "\n".(string) file_get_contents($file);
        }

        return $combined;
    }

    public function test_recovery_page_is_split_into_recovery_components_without_dead_search_box(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Recovery.tsx'));

        $this->assertStringContainsString("from '@/Components/recovery/", $page);
        $this->assertStringContainsString('hideToolbar', $page);
        $this->assertStringContainsString('syncUrl={false}', $page);
        $this->assertLessThan(260, count(explode("\n", $page)), 'Recovery.tsx must stay a thin page after the split.');
        // Pages/ is globbed by app.tsx; extracted parts must live in Components/.
        $this->assertDirectoryDoesNotExist(base_path('resources/js/Pages/Tickets/recovery'));
    }

    public function test_recovery_keeps_operation_keys_page_level(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Recovery.tsx'));
        $detail = (string) file_get_contents(base_path('resources/js/Components/recovery/RecoveryDetail.tsx'));

        $this->assertStringContainsString('const operationKeys = useRef<Record<string, string>>({})', $page);
        $this->assertStringContainsString('operationKeys={operationKeys}', $page);
        $this->assertStringContainsString('key={detail.id}', $page);
        $this->assertStringNotContainsString('useRef', $detail);
    }

    public function test_recovery_e2e_hooks_survive_the_split(): void
    {
        $source = $this->recoverySources();

        $hooks = [
            'id="recovery-capture-sn"',
            'id="recovery-photo-label-sn"',
            'id="recovery-photo-device"',
            'id="recovery-photo-receipt"',
            'id={`recovery-receive-sn-${item.id}`}',
            'id={`recovery-expected-sn-${item.id}`}',
            'data-testid="recovery-capture-submit"',
            'data-testid="recovery-report-open"',
            'data-testid="recovery-report-form"',
            'data-testid="recovery-report-submit"',
            'title="Pengambilan Perangkat"',
            'title="Daftar pengambilan"',
            'title="Belum ada recovery perangkat"',
            'Perangkat {item.device_type.toUpperCase()}',
            'aria-label="Pilih teknisi recovery"',
            'aria-label="Jadwal pengambilan"',
            'aria-label="Jenis perangkat tambahan"',
            'aria-label="SN expected perangkat tambahan"',
            'aria-label="Alasan pembatalan recovery"',
            'Simpan penugasan',
            'Tandai diterima gudang',
            'Edit data',
            'Simpan data',
            'Tambah perangkat',
            'Batalkan recovery',
            'Coba lagi',
        ];

        foreach ($hooks as $hook) {
            $this->assertStringContainsString($hook, $source, "Recovery hook missing: {$hook}");
        }
    }

    public function test_recovery_mobile_layout_uses_server_summary_and_mobile_kit(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Recovery.tsx'));

        $this->assertStringContainsString('summary.in_progress', $page);
        $this->assertStringContainsString('summary.needs_review', $page);
        $this->assertStringContainsString('aria-controls="recovery-filters"', $page);
        $this->assertStringContainsString('id="recovery-filters"', $page);
        $this->assertStringContainsString('<BottomActionBar', $page);
        $this->assertStringContainsString('<FilterChips', $page);
        $this->assertStringContainsString('<MobileCard', $page);
        $this->assertStringContainsString('Cari SN', $page);
        $this->assertStringNotContainsString('cases.data.filter(', $page, 'Summary tiles must use the server-side summary, not the current page.');
        $this->assertStringNotContainsString('getUserMedia', $page);
        $this->assertSame(1, substr_count($page, 'id="recovery-search"'), 'Search id must not be duplicated.');
        $this->assertSame(1, substr_count($page, 'id="recovery-status"'), 'Status select id must not be duplicated.');
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|green|amber|yellow|blue|violet|slate|gray|emerald|orange)-\d{2,3}\b/', $page);
    }

    public function test_recovery_form_buttons_keep_explicit_type(): void
    {
        $files = array_merge(
            [base_path('resources/js/Pages/Tickets/Recovery.tsx')],
            glob(base_path('resources/js/Components/recovery/*.tsx')) ?: [],
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/<(Button|button)\b(?![^>]*\btype=)[^>]*>/', $source, $matches);
            $this->assertSame([], $matches[0], basename($file).' has a button without explicit type');
        }
    }
}
