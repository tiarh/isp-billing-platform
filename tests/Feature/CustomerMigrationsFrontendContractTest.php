<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerMigrationsFrontendContractTest extends TestCase
{
    public function test_migration_page_uses_shared_status_primitive_and_safe_correction_merge(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/CustomerMigrations/Index.tsx'));

        $this->assertStringContainsString('StatusBadge as SharedStatusBadge', $source);
        $this->assertStringContainsString('function MigrationStatusBadge', $source);
        $this->assertStringContainsString('...(current[row.id] ||', $source);
        $this->assertStringNotContainsString('function StatusBadge', $source);
        $this->assertStringNotContainsString('indigo-', $source);
        $this->assertStringNotContainsString('orange-', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_execute_requires_read_only_diff_preview_and_explicit_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/CustomerMigrations/Index.tsx'));

        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $source);
        $this->assertStringContainsString('pendingExecution', $source);
        $this->assertStringContainsString('Preview perubahan', $source);
        $this->assertStringContainsString('onConfirm={confirmMigrationExecution}', $source);
        $this->assertStringContainsString('old_sn', $source);
        $this->assertStringContainsString('new_sn', $source);
    }

    public function test_manual_complete_dialog_requires_reason_and_uses_read_only_evidence(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/CustomerMigrations/Index.tsx'));

        $this->assertStringContainsString('Tandai selesai', $source);
        $this->assertStringContainsString("route('customer-migrations.manual-complete.preview'", $source);
        $this->assertStringContainsString("route('customer-migrations.manual-complete'", $source);
        $this->assertStringContainsString('manual-complete-evidence', $source);
        $this->assertStringContainsString('manualReason.trim().length < (manualPreview?.reason_min ?? 10)', $source);
        $this->assertStringContainsString('canManualComplete', $source);
        $this->assertStringContainsString("synced: 'Selesai'", $source);
    }

    public function test_import_dry_run_button_declares_submit_type(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/CustomerMigrations/Index.tsx'));

        $this->assertMatchesRegularExpression(
            '/<form\s+className="grid gap-2 md:grid-cols-\[220px_1fr_160px_auto\]"[\s\S]*?<Button\s+type="submit"[^>]*>[^<]*<FileSpreadsheet/s',
            $source,
            'The staging import action must explicitly submit its own form.'
        );
    }
}
