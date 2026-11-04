<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbWaPanelFrontendContractTest extends TestCase
{
    public function test_psb_wa_status_and_rx_tones_do_not_use_violet(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/PsbWa/PsbWaPanel.tsx'));

        $this->assertStringNotContainsString('violet-', $source);
        // PR-6b: raw sky hue replaced by the info status token.
        $this->assertStringContainsString("applied: 'bg-status-info-bg", $source);
        $this->assertStringContainsString('RX {d.rx_dbm.toFixed(1)} dBm', $source);
        $this->assertStringContainsString('<Badge variant="info" className="text-[10px]">', $source);
    }

    public function test_perlu_area_badge_and_set_area_action_are_wired(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/PsbWa/PsbWaPanel.tsx'));

        $this->assertStringContainsString('{d.needs_area && (', $source);
        $this->assertStringContainsString('Perlu area', $source);
        $this->assertStringContainsString('`/psb-wa/${id}/set-area`', $source);
        $this->assertStringContainsString('{ area_id: areaId }', $source);
        $this->assertStringContainsString('Set area &amp; Retry', $source);
    }

    public function test_initial_and_group_loading_use_layout_skeletons(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/PsbWa/PsbWaPanel.tsx'));
        $initialLoading = strstr($source, 'if (loading && !data)');
        $initialLoading = $initialLoading !== false ? strstr($initialLoading, 'if (error && !data)', true) : false;

        $this->assertStringContainsString("import { Skeleton } from '@/Components/ui/skeleton';", $source);
        $this->assertNotFalse($initialLoading);
        $this->assertStringNotContainsString('animate-spin', $initialLoading ?: '');
        $this->assertStringContainsString('Skeleton', $initialLoading ?: '');
        $this->assertStringContainsString('Skeleton', substr($source, strpos($source, '{groupsLoading && groups.length === 0 ?') ?: 0, 500));
    }

    public function test_site_selector_keeps_ra_and_gadang_huawei_settings_independent(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/PsbWa/PsbWaPanel.tsx'));

        $this->assertStringContainsString('site_settings', $source);
        $this->assertStringContainsString('site_settings?.[payload.filters?.site]', $source);
        $this->assertStringContainsString('[settingsSiteKey]: {', $source);
        $this->assertStringContainsString('Pilihan Gadang tidak mengganti RA/C300.', $source);
        $this->assertStringContainsString('psb_site_settings: nextSiteSettings', $source);
    }
}
