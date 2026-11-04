<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\WaGroupJid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/** Every change on the page writes a masked activity row; refusals and no-ops write none. */
#[Group('blocking-core')]
class WaGroupSettingsActivityLogTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
    }

    private function rows(): Collection
    {
        return Activity::query()->where('log_name', 'wa-groups')->where('event', 'wa_group_setting_changed')->get();
    }

    public function test_report_change_writes_one_masked_row_per_changed_key(): void
    {
        $admin = $this->unscopedAdmin();
        $this->putSection($admin, 'report', ['group_jid' => self::G_NEW, 'channel' => 'ticket', 'daily_enabled' => true])->assertSessionHasNoErrors();

        $rows = $this->rows()->keyBy(fn ($r) => $r->properties['key']);
        $this->assertEqualsCanonicalizing(['report_wa_group_ids', 'report_wa_channel', 'report_daily_enabled'], $rows->keys()->all());

        $ids = $rows['report_wa_group_ids'];
        $this->assertSame('report', $ids->properties['role']);
        $this->assertSame([WaGroupJid::mask(self::G_REPORT)], $ids->properties['old']);
        $this->assertSame([WaGroupJid::mask(self::G_NEW)], $ids->properties['new']);
        $this->assertSame('ya', $ids->properties['membership']);
        $this->assertSame('ticket', $ids->properties['channel']);
        $this->assertSame('admin', $ids->properties['actor_role']);
        $this->assertSame($admin->id, (int) $ids->causer_id);
        $this->assertTrue($rows['report_daily_enabled']->properties['new']);
        $this->assertFalse($rows['report_daily_enabled']->properties['old']);
    }

    public function test_psb_detail_flag_toggle_writes_one_row_and_is_off_by_default(): void
    {
        $admin = $this->unscopedAdmin();
        $this->assertNull(Setting::get('report_daily_psb_detail_enabled'));

        $this->putSection($admin, 'report', ['group_jid' => self::G_REPORT, 'psb_detail_daily_enabled' => true])->assertSessionHasNoErrors();

        $rows = $this->rows()->keyBy(fn ($r) => $r->properties['key']);
        $this->assertSame(['report_daily_psb_detail_enabled'], $rows->keys()->all());
        $this->assertTrue($rows['report_daily_psb_detail_enabled']->properties['new']);
        $this->assertFalse($rows['report_daily_psb_detail_enabled']->properties['old']);
        $this->assertSame('1', (string) Setting::get('report_daily_psb_detail_enabled'));
    }

    public function test_no_raw_jid_or_group_name_is_ever_stored_in_the_activity_rows(): void
    {
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'])->assertSessionHasNoErrors();
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_SHADOW])->assertSessionHasNoErrors();
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED])->assertSessionHasNoErrors();

        $json = (string) Activity::query()->where('log_name', 'wa-groups')->get()->toJson();
        $this->assertStringNotContainsString('120363', $json);
        $this->assertStringNotContainsString('Grup Baru', $json);
        $this->assertCount(3, $this->rows());
    }

    public function test_forced_unverified_save_is_recorded_as_dipaksa(): void
    {
        $this->membershipByJid[self::G_UNDELIVERED] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED], null, ['undelivered'])->assertSessionHasNoErrors();

        $this->assertSame('dipaksa', $this->rows()->first()->properties['membership']);
    }

    public function test_ops_alert_change_is_logged_and_clearing_logs_kosong(): void
    {
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => ''])->assertSessionHasNoErrors();

        $row = $this->rows()->first();
        $this->assertSame('wa_report_group_jid', $row->properties['key']);
        $this->assertSame(WaGroupJid::mask(self::G_OPS), $row->properties['old']);
        $this->assertSame('kosong', $row->properties['new']);
    }

    public function test_shadow_change_writes_the_page_row_and_the_outage_row(): void
    {
        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_SHADOW, 'allow_report_group' => false])->assertSessionHasNoErrors();

        $this->assertSame('outage_shadow_log_jid', $this->rows()->first()->properties['key']);
        $outage = Activity::query()->where('log_name', 'outage')->where('event', 'outage_setting_changed')->first();
        $this->assertNotNull($outage);
        $this->assertSame('set', $outage->properties['new'], 'the outage writer masks the JID as set/empty');
    }

    public function test_refused_or_unchanged_saves_write_no_rows(): void
    {
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ops']);

        $this->assertCount(0, $this->rows());
    }
}
