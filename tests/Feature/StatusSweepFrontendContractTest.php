<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Redesign A PR-6: ops/NOC screens use status tokens, not raw Tailwind hues.
 */
class StatusSweepFrontendContractTest extends TestCase
{
    private const RAW_HUE = '/\b(?:[a-z-]+:)*(?:bg|text|border|ring|from|to|via|fill|stroke|divide|outline|decoration|accent|border-l|border-t|border-r|border-b)-(?:red|green|emerald|amber|yellow|orange|blue|sky|violet|purple|rose|slate|gray|indigo|teal|cyan|lime|pink|zinc|neutral|stone)-\d{2,3}/';

    /** PR-6a file list (relative to resources/js). */
    private const SWEPT_6A = [
        'Pages/Olts/Show.tsx',
        'Pages/Olts/Index.tsx',
        'Pages/Olts/Create.tsx',
        'Pages/Olts/Edit.tsx',
        'Pages/OltC300/Index.tsx',
        'Components/Aktivasi/MigrationLinkPanel.tsx',
        'Pages/Routers/Show.tsx',
        'Pages/Routers/Create.tsx',
        'Pages/Routers/Edit.tsx',
        'Pages/Routers/Index.tsx',
        'Pages/Routers/Partials/RouterCustomersTable.tsx',
        'Pages/GenieAcs/Index.tsx',
        'Pages/Monitoring/Index.tsx',
        'Pages/Monitoring/IncidentInbox.tsx',
        'Pages/Monitoring/CustomerTrafficPanel.tsx',
        'Components/StaleDataBadge.tsx',
        'Components/RouterStatusBadge.tsx',
        'Components/noc/NocConnectivityAlertSettings.tsx',
        'Pages/Peta/Index.tsx',
        'Pages/Peta/PetaSidebar.tsx',
        'Pages/Isolated/Index.tsx',
        'Pages/Tickets/Show.tsx',
    ];

    /** PR-6b file list (PSB/Migrasi, Settings, Broadcast, Saleskit, Paket, shared enterprise bits). */
    private const SWEPT_6B = [
        'Pages/PsbDrafts/Dashboard.tsx',
        'Components/PsbWa/PsbWaPanel.tsx',
        'Pages/PsbImports/Index.tsx',
        'Pages/CustomerMigrations/Index.tsx',
        'Pages/Settings/Index.tsx',
        'Pages/Settings/WaPair.tsx',
        'Pages/Broadcasts/Index.tsx',
        'Pages/Broadcasts/Show.tsx',
        'Pages/Saleskit/Index.tsx',
        'Pages/Packages/Show.tsx',
        'Pages/ProductionControlPlane/Index.tsx',
        'Pages/Faq/Index.tsx',
        'Pages/Profile/Partials/UpdateProfileInformationForm.tsx',
        'Pages/Profile/Partials/UpdatePasswordForm.tsx',
        'Components/TableActions.tsx',
        'Components/enterprise/EnterpriseMetricCard.tsx',
    ];

    /**
     * Always-black terminal tokens (same as ThemeContrast ALLOWLIST), exempt
     * ONLY inside the ConsoleLog function body of OltC300/Index.tsx.
     */
    private const TERMINAL_ALLOWED = ['text-blue-300', 'text-green-400', 'text-emerald-400', 'text-red-400', 'text-amber-400', 'text-slate-500', 'text-slate-400'];

    /**
     * Peta legend/filter swatches deliberately keep one distinct hue per map
     * layer so they match the Leaflet marker colours (hex in PetaMap.tsx).
     */
    private const MAP_SWATCHES = [
        'Pages/Peta/Index.tsx' => ['bg-red-500', 'bg-purple-500', 'bg-emerald-600', 'bg-green-500', 'bg-blue-500'],
        // Filter chips carry white 10px text: backgrounds darkened for >= 4.5:1.
        'Pages/Peta/PetaSidebar.tsx' => ['bg-red-600', 'bg-violet-600', 'bg-green-700', 'bg-sky-700', 'bg-blue-600'],
    ];

    private function source(string $relative): string
    {
        return (string) file_get_contents(base_path('resources/js/'.$relative));
    }

    public function test_swept_ops_files_have_no_raw_hue_classes(): void
    {
        $violations = [];
        foreach (array_merge(self::SWEPT_6A, self::SWEPT_6B) as $relative) {
            $allowed = self::MAP_SWATCHES[$relative] ?? [];
            $inTerminal = false;
            foreach (preg_split('/\R/', $this->source($relative)) as $i => $line) {
                if ($relative === 'Pages/OltC300/Index.tsx') {
                    if (str_starts_with($line, 'function ConsoleLog(')) {
                        $inTerminal = true;
                    }
                }
                $lineAllowed = $inTerminal ? array_merge($allowed, self::TERMINAL_ALLOWED) : $allowed;
                if ($inTerminal && $line === '}') {
                    $inTerminal = false;
                }
                if (preg_match('/^\s*(\/\/|\*)/', $line)) {
                    continue;
                }
                if (preg_match_all(self::RAW_HUE, $line, $m)) {
                    foreach ($m[0] as $token) {
                        if (! in_array($token, $lineAllowed, true)) {
                            $violations[] = $relative.':'.($i + 1).' '.$token;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Raw hue classes in swept files:\n".implode("\n", $violations));
    }

    /**
     * Files/dirs (relative to resources/js) exempt from the global scan because
     * they are covered by a dedicated test above. Peta swatches are allowed per
     * file through MAP_SWATCHES instead.
     */
    private const GLOBAL_PENDING = [
        'Pages/OltC300/Index.tsx', // terminal tokens, covered by the swept test above
    ];

    public function test_no_raw_hue_classes_anywhere_in_resources_js_except_pending_files(): void
    {
        $root = base_path('resources/js');
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! preg_match('/\.tsx?$/', $file->getFilename())) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (self::GLOBAL_PENDING as $pending) {
                if ($relative === $pending || (str_ends_with($pending, '/') && str_starts_with($relative, $pending))) {
                    continue 2;
                }
            }
            foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) as $i => $line) {
                if (preg_match('/^\s*(\/\/|\*)/', $line)) {
                    continue;
                }
                if (preg_match_all(self::RAW_HUE, $line, $m)) {
                    foreach ($m[0] as $token) {
                        if (in_array($token, self::MAP_SWATCHES[$relative] ?? [], true)) {
                            continue;
                        }
                        $violations[] = $relative.':'.($i + 1).' '.$token;
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Raw hue classes outside the pending list:\n".implode("\n", $violations));
    }

    public function test_genieacs_status_cell_does_not_list_tags(): void
    {
        $source = $this->source('Pages/GenieAcs/Index.tsx');
        $this->assertStringNotContainsString('device.tags', $source);
        $this->assertStringContainsString('selected.tags.map', $source);
        $this->assertStringContainsString('SSID 5G', $source);
    }

    public function test_genieacs_status_dot_class_string_keeps_its_separator(): void
    {
        $source = $this->source('Pages/GenieAcs/Index.tsx');
        $this->assertStringContainsString("'bg-muted-foreground/50'} \${compact ?", $source);
    }

    public function test_metric_card_has_no_tone_aliases_and_uses_primary_soft_tile(): void
    {
        $source = $this->source('Components/enterprise/EnterpriseMetricCard.tsx');
        $this->assertStringNotContainsString('type Tone', $source);
        $this->assertStringNotContainsString('toneMap', $source);
        $this->assertStringNotContainsString('tone?:', $source);
        $this->assertStringContainsString('bg-primary-soft text-primary-soft-foreground', $source);
    }

    public function test_psb_dashboard_pct_color_uses_pastel_tokens_not_white_text(): void
    {
        $source = $this->source('Pages/PsbDrafts/Dashboard.tsx');
        $this->assertStringContainsString("'bg-status-up-bg text-status-up'", $source);
        $this->assertStringNotContainsString('text-white', $source);
    }

    public function test_router_customers_table_uses_status_badge(): void
    {
        $source = $this->source('Pages/Routers/Partials/RouterCustomersTable.tsx');

        $this->assertStringContainsString('<StatusBadge', $source);
        // Customer status colours come from the shared helper, not a local switch.
        $this->assertStringContainsString('customerStatusTone(customer.status)', $source);
        $this->assertStringNotContainsString('getStatusTone', $source);
        $this->assertStringNotContainsString('@/Components/ui/badge', $source);
        $this->assertDoesNotMatchRegularExpression(self::RAW_HUE, $source);
    }

    public function test_isolated_portal_icon_tone_uses_tokens(): void
    {
        $source = $this->source('Pages/Isolated/Index.tsx');
        $this->assertStringContainsString('bg-status-down-bg', $source);
        $this->assertStringContainsString('bg-status-up-bg', $source);
    }
}
