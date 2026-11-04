<?php

namespace Tests\Feature;

use Tests\TestCase;

class GenieAcsFrontendContractTest extends TestCase
{
    public function test_genieacs_uses_shared_time_formatting(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatDateTime', $source);
        $this->assertStringContainsString('formatRelative', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
    }

    public function test_reboot_uses_shared_destructive_confirmation_instead_of_browser_prompt(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString('/reboot', $source);
        $this->assertStringNotContainsString('confirm(', $source);
    }

    /** RCA-3: WiFi values carry their age; a value older than the limit is never shown as a number. */
    public function test_wifi_state_shows_fresh_values_with_age_and_hides_stale_ones(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));

        $this->assertStringContainsString('cpe: {', $source);
        $this->assertStringContainsString('— (data lama)', $source);
        $this->assertStringContainsString('Klien WiFi', $source);
        $this->assertStringContainsString('WiFi 2.4 GHz', $source);
        $this->assertStringContainsString('WiFi 5 GHz', $source);
        $this->assertStringContainsString('Restart terakhir', $source);
        $this->assertStringContainsString('Nyala', $source);
        $this->assertStringContainsString('cpeAge(', $source);
        $this->assertStringContainsString('— (belum ada data)', $source);
        $this->assertStringContainsString('hari lalu', $source);
        // the stale ~25 day old VP sum is no longer rendered as live client count
        $this->assertStringNotContainsString('{device.wifi_clients} klien', $source);
    }

    public function test_genieacs_kpi_buttons_do_not_use_scale_motion(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));

        $this->assertStringContainsString('EnterpriseMetricCard', $source);
        $this->assertStringNotContainsString('hover:scale-[1.01]', $source);
    }

    public function test_panel_acs_links_to_the_public_genieacs_overview(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/GenieAcsController.php'));

        // the link comes from config/site.php through a controller prop, never a literal in the page
        $this->assertStringContainsString('href={genieacsPanelUrl}', $source);
        $this->assertStringContainsString("'genieacsPanelUrl' => (string) config('site.genieacs_panel_url')", $controller);
        $this->assertMatchesRegularExpression('~^https?://[^/\s]+/genieacs-ui/#!/overview$~', (string) config('site.genieacs_panel_url'));
        $this->assertDoesNotMatchRegularExpression('~https?://|\b\d{1,3}(?:\.\d{1,3}){3}:7557~', $source, 'the page must not hard-code a host or ACS address');
    }

    public function test_device_list_shows_fiberhome_ssid5g_and_separate_ips(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/GenieAcs/Index.tsx'));

        // 5 GHz SSID must come from the dedicated field, not the 2.4G/default one.
        $this->assertStringContainsString('ssid_5g', $source);
        $this->assertStringContainsString('SSID 5G', $source);
        // PPPoE IP and TR-069 IP are shown separately, no doubled "IP".
        $this->assertStringContainsString('ip_tr069', $source);
        $this->assertStringContainsString('TR069', $source);
        // Uptime uses the GenieACS-formatted value.
        $this->assertStringContainsString('fmtUptimeText', $source);
        $this->assertStringContainsString('uptime_text', $source);
    }
}
