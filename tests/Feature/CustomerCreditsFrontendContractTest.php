<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Source-level contract for the "Kelebihan bayar" admin page (Indonesian UI,
 * all three admin decisions, apply with expected amount, no refund promise).
 */
#[Group('blocking-core')]
class CustomerCreditsFrontendContractTest extends TestCase
{
    public function test_page_exposes_list_filters_and_all_admin_actions(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/CustomerCredits/Index.tsx'));

        $this->assertStringContainsString('title="Kelebihan Bayar"', $page);
        foreach (['Saldo tersedia', 'Perlu cek admin', 'Sudah diterapkan', 'Sudah direfund manual', 'Diabaikan'] as $label) {
            $this->assertStringContainsString($label, $page);
        }
        foreach (['Jadikan saldo', 'Terapkan ke tagihan', 'Sudah direfund manual', 'Abaikan', 'Alasan (wajib)'] as $label) {
            $this->assertStringContainsString($label, $page);
        }
        $this->assertStringContainsString("route('customer-credits.index')", $page);
        $this->assertStringContainsString("'customer-credits.apply'", $page);
        $this->assertStringContainsString("'customer-credits.resolve'", $page);
        // The double-click / stale-view guard is sent with every apply.
        $this->assertStringContainsString('expected_amount: row.amount', $page);
        $this->assertStringContainsString('data-testid={`credit-make-balance-${row.id}`}', $page);
        $this->assertStringContainsString('data-testid={`credit-apply-${row.id}`}', $page);
        $this->assertStringContainsString('data-testid={`credit-reason-${row.id}`}', $page);
        // Money formatting goes through the shared helper; reason required before saving.
        $this->assertStringContainsString("from '@/lib/format'", $page);
        $this->assertStringContainsString('reason.trim().length < 3', $page);
        $this->assertStringNotContainsString('toLocaleString(', $page);
        $this->assertStringNotContainsString('Intl.NumberFormat', $page);
    }

    public function test_page_states_no_refund_no_wa_policy_and_has_no_emoji(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/CustomerCredits/Index.tsx'));

        $this->assertStringContainsString('tanpa refund', $page);
        $this->assertStringContainsString('Pelanggan tidak menerima WA', $page);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $page));
    }

    public function test_sidebar_entry_matches_the_controller_permission_boundary(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/AppSidebar.tsx'));
        $this->assertStringContainsString(
            "{ name: 'Kelebihan Bayar', route: 'customer-credits.index', icon: Wallet, section: 'billing', access: 'global_admin_only' }",
            $sidebar,
        );
    }

    public function test_transactions_page_labels_credited_balance_and_no_longer_tells_ops_to_refund(): void
    {
        $page = (string) file_get_contents(base_path('resources/js/Pages/PaymentGateway/Transactions.tsx'));

        $this->assertStringContainsString("credited_balance: 'Jadi saldo pelanggan'", $page);
        $this->assertStringNotContainsString('lalu refund atau catat manual', $page);
        $this->assertStringContainsString('menu Kelebihan Bayar', $page);
    }
}
