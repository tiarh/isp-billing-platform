<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class OltShowHuaweiWriteFrontendContractTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/Olts/Show.tsx'));
    }

    public function test_huawei_buttons_are_disabled_unless_flag_on_and_superadmin(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('huaweiUiWrite?: { enabled: boolean; superadmin: boolean; save_pending_since?: string | null }', $source);
        $this->assertStringContainsString(
            'const huaweiWriteLocked = isHuawei && !(huaweiUiWrite?.enabled === true && huaweiUiWrite?.superadmin === true);',
            $source,
        );
        $this->assertSame(3, substr_count($source, 'disabled={isLoading || huaweiWriteLocked}'), 'rename, reboot and delete icon buttons');
        $this->assertStringContainsString('Tulis Huawei dimatikan (saklar owner)', $source);
        foreach (['openDeleteDialog', 'openRenameDialog', 'openRebootDialog'] as $opener) {
            $this->assertMatchesRegularExpression('/const '.$opener.' = [^{]*\{\s+if \(huaweiWriteLocked\)/', $source, $opener);
        }
    }

    public function test_delete_dialog_warns_about_service_ports_for_huawei_only(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('data-testid="huawei-delete-warning"', $source);
        $this->assertStringContainsString('Service-port ONU ini akan dihapus dulu', $source);
        $this->assertStringContainsString('{!isHuawei && deleteParsed && (', $source, 'ZTE CLI preview stays ZTE only');
    }

    public function test_rename_dialog_shows_suggestion_and_non_blocking_warning(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('data-testid="huawei-rename-suggestion"', $source);
        $this->assertStringContainsString('data-testid="huawei-rename-warning"', $source);
        $this->assertStringContainsString('Tetap bisa disimpan', $source);
        $this->assertStringContainsString("renameConfirmation !== 'RENAME ONU'", $source);
    }

    public function test_save_pending_survives_reload_rename_prefills_current_name_and_timeouts_are_handled(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('useState(Boolean(huaweiUiWrite?.save_pending_since))', $source);
        $this->assertStringContainsString("setRenameName(onu.onu_name || '');", $source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, 'r.status === 504'), 'delete, rename, reboot and save handle gateway timeouts');
    }

    public function test_partial_delete_error_toast_is_sticky_and_shows_rebuild_hint(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('Array.isArray(data.rebuild_hint) && data.rebuild_hint.length > 0', $source);
        $this->assertStringContainsString('duration: Infinity', $source);
        $this->assertStringContainsString("(data.rebuild_hint as string[]).join('\\n')", $source);
    }

    public function test_save_pending_banner_and_typed_phrases(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('data-testid="huawei-save-pending"', $source);
        $this->assertStringContainsString("route('api.olts.save-config', olt.id)", $source);
        $this->assertStringContainsString("saveConfirmation !== 'SAVE OLT'", $source);
        $this->assertStringContainsString("deleteConfirmation !== 'DELETE ONU'", $source);
        $this->assertStringContainsString('typedConfirmation="REBOOT ONU"', $source);
    }
}
