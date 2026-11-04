<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4a: Customers/Show card for a guard-deferred termination
 * (texts T6/T7 owner approved) and the props the controller sends it.
 */
#[Group('blocking-core')]
class PendingTerminationFrontendContractTest extends TestCase
{
    public function test_show_page_declares_props_card_text_and_cancel_route(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Customers/Show.tsx'));

        foreach (['source_label', 'requested_at_label', 'last_checked_label', 'last_reason_label', 'can_cancel', 'hold_billing'] as $prop) {
            $this->assertStringContainsString($prop, $source, $prop);
        }
        $this->assertStringContainsString('pendingTermination?: PendingTermination | null', $source);
        $this->assertStringContainsString("route('customers.pending-termination.cancel', customer.id)", $source);
        $this->assertStringContainsString('pending_id: pendingTermination?.id', $source);
        // T6 card
        $this->assertStringContainsString('Terminate tertunda sejak', $source);
        $this->assertStringContainsString('Tagihan baru ditahan; pelanggan tidak diisolir. Sistem akan menterminate otomatis setelah pelanggan terbukti tidak memakai layanan (PPPoE mati, ONU mati, tidak ada trafik 2 hari).', $source);
        // Cancel button + dialog
        $this->assertStringContainsString('Batalkan (pelanggan masih berlangganan)', $source);
        $this->assertStringContainsString('Batalkan terminate tertunda?', $source);
        $this->assertStringContainsString('tunggakan lama bisa langsung memicu isolir. Alasan (minimal 10 karakter).', $source);
        $this->assertStringContainsString('reason.trim().length < 10', $source);
    }

    public function test_index_badge_and_case_banner_texts_are_pinned(): void
    {
        $index = (string) file_get_contents(resource_path('js/Pages/Customers/Index.tsx'));
        $this->assertStringContainsString('has_pending_termination', $index);
        $this->assertStringContainsString('label="Terminate tertunda"', $index);

        $controller = (string) file_get_contents(app_path('Http/Controllers/CustomerController.php'));
        $this->assertStringContainsString("withExists(['pendingTermination as has_pending_termination'])", $controller);

        // T10 case banner
        $detail = (string) file_get_contents(resource_path('js/Components/recovery/RecoveryDetail.tsx'));
        $this->assertStringContainsString('Pelanggan belum diterminate (ditunda):', $detail);
        $this->assertStringContainsString('Dicek ulang otomatis tiap jam; tagihan baru ditahan.', $detail);
        $types = (string) file_get_contents(resource_path('js/Components/recovery/types.ts'));
        $this->assertStringContainsString('pending_termination?:', $types);
        $recovery = (string) file_get_contents(app_path('Http/Controllers/DeviceRecoveryController.php'));
        $this->assertStringContainsString("'pending_termination' =>", $recovery);
    }

    public function test_controller_payload_keys_match_the_typescript_contract(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/CustomerController.php'));

        foreach (['source_label', 'requested_at_label', 'last_checked_label', 'last_reason_label', 'can_cancel', 'hold_billing', "'pendingTermination' =>"] as $key) {
            $this->assertStringContainsString($key, $controller, $key);
        }
    }
}
