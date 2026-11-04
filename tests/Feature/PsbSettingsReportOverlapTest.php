<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PSB settings save: a Report group JID may never become a PSB/migration group.
 * With an empty report list the behaviour is identical to before.
 */
#[Group('blocking-psb')]
class PsbSettingsReportOverlapTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private function save(array $groups)
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->postJson('/psb-wa/settings', [
            'psb_wa_group_ids' => $groups,
            'psb_router_id' => 0,
            'psb_package_id' => 0,
            'psb_olt_id' => 0,
        ]);
    }

    public function test_saving_the_report_jid_as_a_psb_group_is_rejected_with_422(): void
    {
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('psb_wa_group_ids', [self::P]);

        $response = $this->save([self::P, self::R]);

        $response->assertStatus(422);
        $this->assertStringContainsString('grup Report', (string) $response->json('errors.psb_wa_group_ids.0'));
        Setting::flushRequestMemo();
        $this->assertSame([self::P], Setting::get('psb_wa_group_ids'));
    }

    public function test_bare_numeric_form_of_the_report_jid_is_also_rejected(): void
    {
        Setting::set('report_wa_group_ids', ['120363111111111111@g.us']);

        $this->save(['120363111111111111'])->assertStatus(422);
    }

    public function test_with_an_empty_report_list_saving_is_unchanged(): void
    {
        $response = $this->save([self::P, self::R]);

        $response->assertOk()->assertJson(['success' => true]);
        Setting::flushRequestMemo();
        $this->assertSame([self::P, self::R], Setting::get('psb_wa_group_ids'));
    }

    public function test_a_report_list_without_overlap_does_not_block_saving(): void
    {
        Setting::set('report_wa_group_ids', [self::R]);

        $this->save([self::P])->assertOk();
    }
}
