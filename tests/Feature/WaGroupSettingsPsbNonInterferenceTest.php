<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * The Grup WhatsApp page must never change PSB / migration group settings, the technician share group or the
 * global #rekap switch (PsbDraftController::saveSettings clears all PSB groups when the field is missing).
 */
#[Group('blocking-psb')]
class WaGroupSettingsPsbNonInterferenceTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
        Setting::set('psb_router_id', '513', 'integer', 'psb');
        Setting::set('psb_package_id', '50', 'integer', 'psb');
        Setting::set('psb_olt_id', '1', 'integer', 'psb');
        Setting::set('psb_recap_cmd_enabled', '1', 'text', 'psb');
        Setting::set('psb_recap_start_hour', '8', 'integer', 'psb');
    }

    /** @return array<string, array<string, mixed>> */
    private function psbSnapshot(): array
    {
        return Setting::query()
            ->where(fn ($q) => $q->where('key', 'like', 'psb\\_%')->orWhere('key', 'wa_ticket_progress_share_group_jid'))
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => $s->getAttributes()]) // raw DB strings, incl. timestamps
            ->all();
    }

    public function test_every_successful_save_on_the_page_leaves_psb_and_share_settings_byte_identical(): void
    {
        $before = $this->psbSnapshot();
        $this->assertNotEmpty($before);
        $admin = $this->superadmin();

        $this->putSection($admin, 'report', ['group_jid' => self::G_NEW, 'channel' => 'ticket', 'daily_enabled' => true, 'recap_cmd_enabled' => true])->assertSessionHasNoErrors();
        $this->putSection($admin, 'ops_alert', ['jid' => self::G_SHADOW])->assertSessionHasNoErrors();
        $this->putSection($admin, 'shadow', ['jid' => self::G_OPS, 'allow_report_group' => true])->assertSessionHasNoErrors();
        $this->putSection($admin, 'undelivered', ['jid' => self::G_UNDELIVERED])->assertSessionHasNoErrors();
        $this->putSection($admin, 'report', ['group_jid' => '', 'channel' => 'ops'])->assertSessionHasNoErrors();
        $this->putSection($admin, 'ops_alert', ['jid' => ''])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->psbSnapshot());
    }

    public function test_refused_saves_and_psb_payloads_also_leave_psb_untouched(): void
    {
        $before = $this->psbSnapshot();
        $admin = $this->superadmin();

        $this->putSection($admin, 'report', ['group_jid' => self::G_PSB, 'channel' => 'ops'])->assertSessionHasErrors('group_jid');
        $this->putSection($admin, 'ops_alert', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->putSection($admin, 'shadow', ['jid' => self::G_PSB, 'allow_report_group' => true])->assertSessionHasErrors('jid');
        $this->putSection($admin, 'undelivered', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->putSection($admin, 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops', 'psb_wa_group_ids' => [self::G_NEW]])->assertSessionHasErrors('psb_wa_group_ids');
        $this->putSection($admin, 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops', 'psb_recap_cmd_enabled' => 1])->assertSessionHasErrors('psb_recap_cmd_enabled');

        $this->assertSame($before, $this->psbSnapshot());
        $this->assertSame([self::G_REPORT], (function () {
            Setting::flushRequestMemo();

            return Setting::get('report_wa_group_ids');
        })());
    }

    public function test_psb_webhook_group_allowlist_is_unchanged_after_report_group_changes(): void
    {
        $psb = app(PsbOrchestratorService::class);
        $this->assertSame([self::G_PSB], $psb->allowedGroupJids());

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'])->assertSessionHasNoErrors();
        Setting::flushRequestMemo();

        $this->assertSame([self::G_PSB], $psb->allowedGroupJids());
        $this->assertTrue($psb->isAllowedGroup(self::G_PSB));
        $this->assertFalse($psb->isAllowedGroup(self::G_NEW), 'the Report group never becomes a PSB group');
    }

    public function test_psb_panel_save_still_refuses_the_report_group_and_still_works_for_psb_groups(): void
    {
        $admin = $this->superadmin();
        $this->putSection($admin, 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), ['psb_wa_group_ids' => [self::G_NEW]])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), ['psb_wa_group_ids' => [self::G_PSB]])->assertOk();

        Setting::flushRequestMemo();
        $this->assertSame([self::G_PSB], Setting::get('psb_wa_group_ids'));
        $this->assertSame([self::G_NEW], Setting::get('report_wa_group_ids'));
    }

    public function test_page_code_never_calls_the_psb_settings_endpoint_or_writes_psb_keys(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;
        $files = [
            'app/Http/Controllers/WaGroupSettingsController.php',
            'app/Services/Wa/WaGroupSettingsWriter.php',
            'app/Services/Wa/WaGroupRoles.php',
            'app/Services/Wa/WaGroupDirectory.php',
            'resources/js/Pages/Settings/WaGroups.tsx',
            'resources/js/Components/WaGroups/GroupPicker.tsx',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($root.$file);
            $this->assertStringNotContainsString('saveSettings', $src, $file);
            $this->assertStringNotContainsString('psb-drafts.settings', $src, $file);
            $this->assertStringNotContainsString('/psb-wa/settings', $src, $file);
            $this->assertDoesNotMatchRegularExpression('/Setting::set\(\s*[\'"]psb_/', $src, $file);
            $this->assertDoesNotMatchRegularExpression('/Setting::set\(\s*[\'"]wa_ticket_progress_share/', $src, $file);
        }
    }
}
