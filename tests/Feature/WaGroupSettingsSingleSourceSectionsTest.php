<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * Grup WhatsApp page = single source of truth: the ticket, connectivity, attendance and technician-progress
 * sections (plan wa-groups-single-source, PR-1). Fake JIDs only.
 */
#[Group('blocking-core')]
class WaGroupSettingsSingleSourceSectionsTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    private const G_TICKET = '120363000000000011@g.us';

    private const G_CONN = '120363000000000012@g.us';

    private const G_ATT = '120363000000000013@g.us';

    private const G_SHARE = '120363000000000014@g.us';

    private const G_INPUT = '120363000000000015@g.us';

    private const PERSONAL = '62800000099@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
    }

    private function st(string $key, mixed $default = null): mixed
    {
        Setting::flushRequestMemo();

        return Setting::get($key, $default);
    }

    // ---------- ticket ----------

    public function test_ticket_group_is_saved_probed_on_the_ticket_channel_and_cleared_by_deleting_the_row(): void
    {
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_TICKET])->assertSessionHasNoErrors();
        $this->assertSame(self::G_TICKET, $this->st('wa_ticket_group_jid'));
        $this->assertContains(['ticket', self::G_TICKET], $this->membershipCalls);

        $this->putSection($this->superadmin(), 'ticket', ['jid' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Setting::where('key', 'wa_ticket_group_jid')->first());
    }

    public function test_ticket_group_refuses_psb_ops_and_bad_format(): void
    {
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_OPS])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'ticket', ['jid' => 'bukan-jid'])->assertSessionHasErrors('jid');
        $this->assertNull(Setting::where('key', 'wa_ticket_group_jid')->first());
    }

    public function test_ticket_group_unverified_membership_needs_the_force_flag(): void
    {
        $this->membershipByJid[self::G_TICKET] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_TICKET])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_TICKET], null, ['ticket'])->assertSessionHasNoErrors();
        $this->assertSame(self::G_TICKET, $this->st('wa_ticket_group_jid'));

        $this->membershipByJid[self::G_NEW] = ['member' => false, 'subject' => 'x', 'size' => 1, 'error' => null];
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_NEW], null, ['ticket'])->assertSessionHasErrors('jid');
    }

    // ---------- connectivity ----------

    public function test_connectivity_group_saved_on_the_ops_channel_and_empty_is_stored_empty(): void
    {
        $this->putSection($this->superadmin(), 'connectivity', ['jid' => self::G_CONN])->assertSessionHasNoErrors();
        $this->assertSame(self::G_CONN, $this->st('connectivity_alerts_group_id'));
        $this->assertContains(['ops', self::G_CONN], $this->membershipCalls);

        $this->putSection($this->superadmin(), 'connectivity', ['jid' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', (string) $this->st('connectivity_alerts_group_id'));
    }

    public function test_connectivity_group_refuses_psb_new_rule(): void
    {
        $this->putSection($this->superadmin(), 'connectivity', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->assertNull(Setting::where('key', 'connectivity_alerts_group_id')->first());
    }

    // ---------- attendance ----------

    public function test_attendance_group_and_label_saved_and_label_cleared(): void
    {
        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_ATT, 'label' => 'Absensi Teknisi'])->assertSessionHasNoErrors();
        $this->assertSame(self::G_ATT, $this->st('attendance_group_id'));
        $this->assertSame('Absensi Teknisi', $this->st('attendance_group_label'));

        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_ATT, 'label' => null])->assertSessionHasNoErrors();
        $this->assertSame('', (string) $this->st('attendance_group_label'));

        $this->putSection($this->superadmin(), 'attendance', ['jid' => '', 'label' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', (string) $this->st('attendance_group_id'));
    }

    public function test_attendance_refuses_psb_and_long_label(): void
    {
        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_PSB, 'label' => 'x'])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_ATT, 'label' => str_repeat('a', 256)])->assertSessionHasErrors('label');
        $this->assertNull(Setting::where('key', 'attendance_group_id')->first());
    }

    public function test_attendance_pre_existing_psb_value_is_not_rejudged_when_unchanged(): void
    {
        Setting::set('attendance_group_id', self::G_PSB, 'text', 'attendance');
        $calls = count($this->membershipCalls);

        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_PSB, 'label' => 'Label baru'])->assertSessionHasNoErrors();
        $this->assertSame('Label baru', $this->st('attendance_group_label'));
        $this->assertSame(self::G_PSB, $this->st('attendance_group_id'));
        $this->assertCount($calls, $this->membershipCalls, 'unchanged JID is never re-probed');

        // changing to another PSB-conflicting value is still impossible; moving away is fine.
        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_ATT, 'label' => 'Label baru'])->assertSessionHasNoErrors();
        $this->assertSame(self::G_ATT, $this->st('attendance_group_id'));
    }

    // ---------- technician ----------

    public function test_technician_share_and_input_groups_with_labels(): void
    {
        $this->putSection($this->superadmin(), 'technician', [
            'share_jid' => self::G_SHARE, 'share_label' => 'REPORT X',
            'input_jid' => self::G_INPUT, 'input_label' => 'KERJA X',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::G_SHARE, $this->st('wa_ticket_progress_share_group_jid'));
        $this->assertSame('REPORT X', $this->st('wa_ticket_progress_share_group_label'));
        $this->assertSame(self::G_INPUT, $this->st('wa_ticket_progress_input_group_jid'));
        $this->assertSame('KERJA X', $this->st('wa_ticket_progress_input_group_label'));
        $this->assertContains(['ticket', self::G_SHARE], $this->membershipCalls);
        $this->assertContains(['ticket', self::G_INPUT], $this->membershipCalls);
    }

    public function test_technician_input_may_be_a_personal_chat_kept_verbatim_and_not_probed(): void
    {
        $this->putSection($this->superadmin(), 'technician', [
            'share_jid' => self::G_SHARE, 'share_label' => '', 'input_jid' => self::PERSONAL, 'input_label' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::PERSONAL, $this->st('wa_ticket_progress_input_group_jid'));
        // empty labels fall back to the defaults (no row needed: the readers default to the same text)
        $this->assertSame('SKYNET REPORT', $this->st('wa_ticket_progress_share_group_label', 'SKYNET REPORT'));
        $this->assertSame('KERJAAN', $this->st('wa_ticket_progress_input_group_label', 'KERJAAN'));
        $this->assertNotContains(['ticket', self::PERSONAL], $this->membershipCalls);

        // saving again with the personal JID unchanged keeps it (no @g.us rewrite) and is a no-op.
        $this->putSection($this->superadmin(), 'technician', [
            'share_jid' => self::G_SHARE, 'share_label' => '', 'input_jid' => self::PERSONAL, 'input_label' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame(self::PERSONAL, $this->st('wa_ticket_progress_input_group_jid'));
    }

    public function test_technician_share_must_be_a_group_and_neither_may_be_psb(): void
    {
        $base = ['share_jid' => self::G_SHARE, 'share_label' => '', 'input_jid' => self::G_INPUT, 'input_label' => ''];
        $this->putSection($this->superadmin(), 'technician', array_merge($base, ['share_jid' => self::PERSONAL]))->assertSessionHasErrors('share_jid');
        $this->putSection($this->superadmin(), 'technician', array_merge($base, ['share_jid' => self::G_PSB]))->assertSessionHasErrors('share_jid');
        $this->putSection($this->superadmin(), 'technician', array_merge($base, ['input_jid' => self::G_PSB]))->assertSessionHasErrors('input_jid');
        $this->putSection($this->superadmin(), 'technician', array_merge($base, ['input_jid' => 'bukan-jid']))->assertSessionHasErrors('input_jid');
        $this->putSection($this->superadmin(), 'technician', array_merge($base, ['share_label' => str_repeat('a', 121)]))->assertSessionHasErrors('share_label');
        $this->assertSame('120363000000000009@g.us', $this->st('wa_ticket_progress_share_group_jid'), 'seeded share group untouched by refusals');
        $this->assertNull(Setting::where('key', 'wa_ticket_progress_input_group_jid')->first());
    }

    public function test_stale_version_rejected_for_new_sections_and_new_values_change_the_version(): void
    {
        $v = $this->waVersion();
        $this->putSection($this->superadmin(), 'attendance', ['jid' => self::G_ATT, 'label' => ''])->assertSessionHasNoErrors();
        $this->assertNotSame($v, $this->waVersion());
        $this->putSection($this->superadmin(), 'connectivity', ['jid' => self::G_CONN], $v)->assertSessionHasErrors('version');
        $this->assertNull(Setting::where('key', 'connectivity_alerts_group_id')->first());
    }

    // ---------- access ----------

    public function test_noc_and_area_admin_cannot_write_new_sections_but_unscoped_admin_can(): void
    {
        foreach ([$this->noc(), $this->scopedAdmin(), $this->teknisi()] as $user) {
            foreach (['ticket' => ['jid' => self::G_TICKET], 'connectivity' => ['jid' => self::G_CONN], 'attendance' => ['jid' => self::G_ATT], 'technician' => ['share_jid' => self::G_SHARE]] as $section => $fields) {
                $this->putSection($user, $section, $fields)->assertForbidden();
            }
        }
        $this->assertNull(Setting::where('key', 'attendance_group_id')->first());

        $this->putSection($this->unscopedAdmin(), 'attendance', ['jid' => self::G_ATT, 'label' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::G_ATT, $this->st('attendance_group_id'));
    }

    // ---------- activity log ----------

    public function test_activity_rows_are_masked_per_key_and_labels_never_leak(): void
    {
        $this->putSection($this->superadmin(), 'technician', [
            'share_jid' => self::G_SHARE, 'share_label' => 'RAHASIA-LABEL', 'input_jid' => self::PERSONAL, 'input_label' => '',
        ])->assertSessionHasNoErrors();
        $this->putSection($this->superadmin(), 'ticket', ['jid' => self::G_TICKET])->assertSessionHasNoErrors();

        $rows = Activity::query()->where('log_name', 'wa-groups')->where('event', 'wa_group_setting_changed')->get();
        $keys = $rows->map(fn ($r) => $r->properties['key'])->all();
        $this->assertContains('wa_ticket_progress_share_group_jid', $keys);
        $this->assertContains('wa_ticket_progress_share_group_label', $keys);
        $this->assertContains('wa_ticket_progress_input_group_jid', $keys);
        $this->assertContains('wa_ticket_group_jid', $keys);

        $json = (string) $rows->toJson();
        $this->assertStringNotContainsString('120363', $json);
        $this->assertStringNotContainsString('RAHASIA-LABEL', $json);
    }

    // ---------- PSB non-interference ----------

    #[Group('blocking-psb')]
    public function test_new_sections_leave_every_psb_setting_byte_identical(): void
    {
        Setting::set('psb_router_id', '513', 'integer', 'psb');
        Setting::set('psb_recap_cmd_enabled', '1', 'text', 'psb');
        $snap = fn () => Setting::query()->where('key', 'like', 'psb\\_%')->orderBy('key')
            ->get()->mapWithKeys(fn ($s) => [$s->key => $s->getAttributes()])->all();
        $before = $snap();
        $this->assertNotEmpty($before);
        $admin = $this->superadmin();

        $this->putSection($admin, 'ticket', ['jid' => self::G_TICKET])->assertSessionHasNoErrors();
        $this->putSection($admin, 'connectivity', ['jid' => self::G_CONN])->assertSessionHasNoErrors();
        $this->putSection($admin, 'attendance', ['jid' => self::G_ATT, 'label' => 'A'])->assertSessionHasNoErrors();
        $this->putSection($admin, 'technician', ['share_jid' => self::G_SHARE, 'share_label' => '', 'input_jid' => self::PERSONAL, 'input_label' => ''])->assertSessionHasNoErrors();
        // refusals and psb payloads too
        $this->putSection($admin, 'ticket', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->putSection($admin, 'attendance', ['jid' => self::G_ATT, 'psb_wa_group_ids' => [self::G_NEW]])->assertSessionHasErrors('psb_wa_group_ids');

        $this->assertSame($before, $snap());
        $this->assertSame([self::G_PSB], $this->st('psb_wa_group_ids'));
    }
}
