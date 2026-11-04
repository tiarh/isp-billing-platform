<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TechnicianMobileKitFrontendContractTest extends TestCase
{
    private const RAW_HUES = '/\b(?:bg|text|border|ring)-(?:red|amber|orange|yellow|green|emerald|blue|sky|indigo|violet)-\d{2,3}\b/';

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_mobile_kit_components_exist_with_contract_markers(): void
    {
        $bar = $this->source('resources/js/Components/mobile/BottomActionBar.tsx');
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $bar);
        $this->assertStringContainsString('md:hidden', $bar);
        $this->assertStringContainsString('role="region"', $bar);
        $this->assertStringContainsString('useSoftKeyboardOpen', $bar);
        $this->assertStringNotContainsString('backdrop-blur', $bar);

        $chips = $this->source('resources/js/Components/mobile/FilterChips.tsx');
        $this->assertStringContainsString('aria-pressed', $chips);
        $this->assertStringContainsString('role="group"', $chips);

        $card = $this->source('resources/js/Components/mobile/MobileCard.tsx');
        $this->assertStringContainsString('type="button"', $card);
        $this->assertStringContainsString('after:absolute', $card);
        $this->assertStringContainsString('shadow-card', $card);
        $this->assertStringContainsString('focus-visible:after:ring-2', $card);
        $this->assertStringContainsString('focus-visible:ring-2', $card);

        $hook = $this->source('resources/js/hooks/useSoftKeyboardOpen.ts');
        $this->assertStringContainsString('visualViewport', $hook);
        $this->assertStringContainsString('focusin', $hook);

        $this->assertStringContainsString('.safe-area-pb', $this->source('resources/css/app.css'));

        foreach ([$bar, $chips, $card, $hook] as $src) {
            $this->assertDoesNotMatchRegularExpression(self::RAW_HUES, $src);
        }
    }

    public function test_tickets_index_uses_mobile_kit_and_tokens(): void
    {
        $source = $this->source('resources/js/Pages/Tickets/Index.tsx');

        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString('<FilterChips', $source);
        $this->assertStringContainsString('<BottomActionBar', $source);
        $this->assertStringContainsString('<MobileCard', $source);
        $this->assertStringContainsString('hideOnMobile', $source);
        $this->assertStringContainsString('preserveState: false', $source);
        $this->assertStringNotContainsString('preserveState: true', $source);
        $this->assertStringContainsString('Closed', $source);
        $this->assertStringContainsString('Buka kandidat gangguan monitoring', $this->source('resources/js/Components/tickets/MonitoringCandidatesDrawer.tsx'));
        $this->assertDoesNotMatchRegularExpression(self::RAW_HUES, $source);
        $this->assertStringNotContainsString('<Card className="cursor-pointer', $source);
        $this->assertStringContainsString("'open_assigned'", $source);
        // Desktop row keeps both view and edit actions (table must not push Edit out of view).
        $this->assertStringContainsString('aria-label={`Buka tiket ${t.code}`}', $source);
        $this->assertStringContainsString('aria-label={`Edit tiket ${t.code}`}', $source);
        $this->assertStringContainsString('statusBadge(t.status, false)', $source);
        $this->assertStringContainsString('Cancelled', $source);
    }

    public function test_attendances_index_uses_mobile_card_and_tokens(): void
    {
        $source = $this->source('resources/js/Pages/Attendances/Index.tsx');

        $this->assertStringContainsString('<MobileCard', $source);
        $this->assertStringContainsString('line-clamp-2', $source);
        $this->assertStringNotContainsString('<BottomActionBar', $source);
        $this->assertDoesNotMatchRegularExpression(self::RAW_HUES, $source);
    }
}
