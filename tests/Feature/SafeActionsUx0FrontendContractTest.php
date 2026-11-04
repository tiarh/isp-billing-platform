<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PR-UX0: destructive / money-moving actions must be honest and guarded.
 * Audit IDs: P1, I1, C1, C2, S1, S2 (AUDIT_EBILLING_UIUX_20261007).
 */
class SafeActionsUx0FrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertNotFalse($source, $path);

        return (string) $source;
    }

    /** P1: a void invoice never shows payment options on the public page. */
    public function test_public_payment_page_only_offers_payment_for_open_invoices(): void
    {
        $pay = $this->source('resources/js/Pages/Public/Payment/Show.tsx');

        $this->assertStringContainsString("const isVoid = invoice.status === 'void';", $pay);
        $this->assertStringContainsString("const isPayable = invoice.status === 'unpaid' || invoice.status === 'overdue';", $pay);
        $this->assertStringContainsString('{isPayable && (', $pay);
        $this->assertStringNotContainsString('{!isPaid && (', $pay);
        $this->assertStringContainsString('Tagihan ini dibatalkan', $pay);
        $this->assertStringContainsString('data-testid="public-invoice-void"', $pay);

        // Server guard already rejects void (kept in sync with the FE set).
        $gateway = $this->source('app/Http/Controllers/PaymentGatewayController.php');
        $this->assertStringContainsString("if (\$invoice->status === 'void')", $gateway);
        $this->assertStringContainsString("['unpaid', 'overdue']", $gateway);
    }

    /** I1: admin invoice detail has a neutral void branch, not "Payment Pending". */
    public function test_admin_invoice_show_has_void_branch(): void
    {
        $show = $this->source('resources/js/Pages/Invoices/Show.tsx');

        $this->assertStringContainsString("'Tagihan dibatalkan'", $show);
        $this->assertStringContainsString('<Ban className="w-6 h-6" />', $show);
        $this->assertStringNotContainsString("'Payment Pending'", $show);
    }

    /** C1: archive from Edit uses ConfirmDestructive with honest soft-delete copy. */
    public function test_customer_edit_archive_dialog_is_honest_and_cancellable(): void
    {
        $edit = $this->source('resources/js/Pages/Customers/Edit.tsx');

        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $edit);
        $this->assertStringContainsString('<ConfirmDestructive', $edit);
        $this->assertStringContainsString('title="Arsipkan pelanggan ini?"', $edit);
        $this->assertStringContainsString('bisa dipulihkan dari tab Arsip', $edit);
        $this->assertStringContainsString('isLoading={processing}', $edit);
        $this->assertStringNotContainsString('permanently delete', $edit);
        $this->assertStringNotContainsString('cannot be undone', $edit);
        $this->assertStringNotContainsString("getElementById('close-dialog')", $edit);
    }

    /** C2: reconnect only from isolated; busy state; Indonesian copy with impact. */
    public function test_customer_show_reconnect_only_for_isolated_customers(): void
    {
        $show = $this->source('resources/js/Pages/Customers/Show.tsx');

        $this->assertStringContainsString("const serviceIsActive = customer.status === 'active';", $show);
        $this->assertStringContainsString("(customer.status as string) === 'isolated'", $show);
        $this->assertStringContainsString('const canToggleService = serviceIsActive || serviceIsIsolated;', $show);
        $this->assertStringContainsString('disabled={!canEnforceOnMikrotik || !canToggleService || toggleBusy}', $show);
        $this->assertStringContainsString("if (confirmAction === 'unblock' && !serviceIsIsolated) return;", $show);
        $this->assertStringContainsString('isLoading={toggleBusy}', $show);
        $this->assertStringContainsString('Buka isolir hanya untuk pelanggan berstatus Isolir', $show);
        $this->assertStringContainsString("'Isolir layanan'", $show);
        $this->assertStringContainsString('sesi yang sedang aktif diputus (kick)', $show);
        $this->assertStringNotContainsString('Activate Service', $show);
        $this->assertStringNotContainsString('Suspend Customer Service', $show);
    }

    /** S1: bulk apply asks for confirmation that states the count. */
    public function test_psb_imports_bulk_apply_requires_confirmation_with_count(): void
    {
        $index = $this->source('resources/js/Pages/PsbImports/Index.tsx');

        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $index);
        $this->assertStringContainsString('onClick={() => setBulkApplyConfirmOpen(true)}', $index);
        $this->assertStringNotContainsString('onClick={bulkApply}', $index);
        $this->assertStringContainsString('title={`Apply ${safeSummary.ready} kandidat siap?`}', $index);
        $this->assertStringContainsString('onConfirm={bulkApply}', $index);
        $this->assertStringContainsString('isLoading={bulkApplying}', $index);
    }

    /** S2: per-row busy guard + confirmation for Apply and Apply Koreksi. */
    public function test_psb_wa_panel_row_actions_are_busy_guarded_and_confirmed(): void
    {
        $panel = $this->source('resources/js/Components/PsbWa/PsbWaPanel.tsx');

        $this->assertStringContainsString('const [busyId, setBusyId] = useState<Record<number, string>>({});', $panel);
        $this->assertStringContainsString('if (busyRef.current.has(id)) return;', $panel);
        $this->assertStringContainsString("onClick={() => setPendingConfirm({ kind: 'apply', draft: d })}", $panel);
        $this->assertStringContainsString("onClick={() => setPendingConfirm({ kind: 'correction', draft: d })}", $panel);
        $this->assertStringNotContainsString('onClick={() => void applyDraft(d.id)}', $panel);
        $this->assertStringNotContainsString('onClick={() => void applyCorrection(d.id)}', $panel);
        $this->assertSame(5, substr_count($panel, 'disabled={Boolean(busyId[d.id])}'));
        $this->assertStringContainsString('<ConfirmDestructive', $panel);
    }
}
