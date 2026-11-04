<?php

namespace Tests\Feature;

use Tests\TestCase;

class NocConnectivityAlertSettingsFrontendContractTest extends TestCase
{
    public function test_group_loading_uses_a_layout_shaped_skeleton(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/noc/NocConnectivityAlertSettings.tsx'));

        // The dialog no longer loads a group list (the group is chosen on Pengaturan > Grup WhatsApp), so there
        // is no group-loading skeleton left; the old copy must not come back either.
        $this->assertStringNotContainsString('<Skeleton', $source);
        $this->assertStringNotContainsString('/api/wa-gateway/groups', $source);
        $this->assertStringNotContainsString('Mengambil daftar grup...', $source);
    }

    public function test_save_processing_keeps_the_inline_spinner(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/noc/NocConnectivityAlertSettings.tsx'));

        $this->assertStringContainsString('saving && <Loader2', $source);
        $this->assertStringContainsString('disabled={saving}', $source);
    }
}
