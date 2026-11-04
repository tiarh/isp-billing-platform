<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\TechnicianProgressService;
use App\Support\WaGroupJid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * Plan wa-groups-single-source PR-3: report:wa-group set/clear write through WaGroupSettingsWriter (same guards,
 * masked activity row with source cli), wa:groups show is read-only and masked, and the Progres teknisi Input
 * field never stores a bare phone number as a fake @g.us group. Fake JIDs only.
 */
#[Group('blocking-core')]
class WaGroupsCliWriterTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    private const G_INPUT = '120363000000000015@g.us';

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

    private function rows()
    {
        return Activity::query()->where('log_name', 'wa-groups')->where('event', 'wa_group_setting_changed')->get();
    }

    // ---------- report:wa-group set/clear via the writer ----------

    public function test_cli_set_writes_through_the_writer_with_masked_cli_activity_row(): void
    {
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_NEW]));

        $this->assertSame([self::G_NEW], $this->st('report_wa_group_ids'));
        $row = $this->rows()->firstWhere('properties.key', 'report_wa_group_ids');
        $this->assertNotNull($row);
        $this->assertSame('cli', $row->properties['source']);
        $this->assertSame('report', $row->properties['role']);
        $this->assertNull($row->causer_id);
        $this->assertSame([WaGroupJid::mask(self::G_REPORT)], $row->properties['old']);
        $this->assertSame([WaGroupJid::mask(self::G_NEW)], $row->properties['new']);
        $this->assertSame('ya', $row->properties['membership']);
        $this->assertStringNotContainsString('120363', (string) Activity::query()->where('log_name', 'wa-groups')->get()->toJson());
    }

    public function test_cli_set_refuses_psb_and_cross_role_clashes_like_the_page(): void
    {
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_PSB]));

        // Report must not become the "laporan gagal" alert group: only the writer knows this cross-role rule.
        Setting::set('report_undelivered_alert_jid', self::G_UNDELIVERED, 'text', 'report');
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_UNDELIVERED]));
        $this->assertStringContainsString('Refused', Artisan::output());

        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));
        $this->assertCount(0, $this->rows());
    }

    public function test_cli_set_membership_rules_and_force_are_recorded(): void
    {
        $this->membershipByJid[self::G_NEW] = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_NEW, '--force' => true]));

        $this->membershipByJid[self::G_NEW] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];
        Cache::flush();
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_NEW]));
        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));

        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_NEW, '--force' => true]));
        $this->assertSame([self::G_NEW], $this->st('report_wa_group_ids'));
        $this->assertSame('dipaksa', $this->rows()->firstWhere('properties.key', 'report_wa_group_ids')->properties['membership']);
    }

    public function test_cli_set_unchanged_is_a_noop_without_activity_and_clear_logs_kosong(): void
    {
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_REPORT]));
        $this->assertCount(0, $this->rows());

        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'clear']));
        $this->assertSame([], $this->st('report_wa_group_ids'));
        $row = $this->rows()->firstWhere('properties.key', 'report_wa_group_ids');
        $this->assertSame('kosong', $row->properties['new'][0] ?? 'kosong');
        $this->assertSame('cli', $row->properties['source']);
    }

    public function test_cli_set_with_channel_saves_channel_and_forgets_cached_values(): void
    {
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::G_NEW, '--channel' => 'ticket']));

        $this->assertSame('ticket', $this->st('report_wa_channel'));
        $this->assertContains(['ticket', self::G_NEW], $this->membershipCalls);
        // read through the cache layer: no stale value after the commit
        $this->assertSame([self::G_NEW], $this->st('report_wa_group_ids'));
    }

    // ---------- wa:groups show ----------

    public function test_wa_groups_show_is_read_only_masked_and_reports_conflicts(): void
    {
        Setting::set('attendance_group_id', self::G_PSB, 'text', 'attendance');
        Setting::set('wa_ticket_group_jid', self::G_OPS, 'text', 'wa');
        Setting::set('wa_ticket_progress_input_group_jid', '62800000099@s.whatsapp.net', 'text', 'wa');
        Setting::flushRequestMemo();
        $settingsBefore = Setting::query()->orderBy('key')->pluck('value', 'key')->all();
        $activityBefore = Activity::query()->count();
        $callsBefore = count($this->membershipCalls);

        $this->assertSame(0, Artisan::call('wa:groups', ['action' => 'show']));
        $out = Artisan::output();

        foreach (['report', 'ops_alert', 'shadow', 'undelivered', 'ticket', 'connectivity', 'attendance', 'technician_share', 'technician_input', 'psb_1'] as $role) {
            $this->assertStringContainsString($role, $out);
        }
        $this->assertStringContainsString(WaGroupJid::mask(self::G_REPORT), $out);
        $this->assertStringContainsString('SKYNET REPORT', $out);
        $this->assertStringNotContainsString('120363', $out);
        $this->assertStringNotContainsString('62800000099', $out);
        $this->assertStringContainsString('attendance:psb_group', $out);
        $this->assertStringContainsString('SAME GROUP ops_alert = ticket', $out);

        $this->assertSame($settingsBefore, Setting::query()->orderBy('key')->pluck('value', 'key')->all());
        $this->assertSame($activityBefore, Activity::query()->count());
        $this->assertSame($callsBefore, count($this->membershipCalls));
    }

    public function test_wa_groups_rejects_unknown_action(): void
    {
        $this->assertSame(1, Artisan::call('wa:groups', ['action' => 'set']));
    }

    // ---------- Progres teknisi Input JID fixes ----------

    private function putInput(string $input, array $extra = [])
    {
        $fields = ['share_jid' => '120363000000000009@g.us', 'input_jid' => $input];

        return $this->putSection($this->superadmin(), 'technician', array_merge($fields, $extra));
    }

    public function test_input_bare_phone_number_becomes_a_personal_jid_never_a_fake_group(): void
    {
        $this->putInput('62800000099')->assertSessionHasNoErrors();
        $this->assertSame('62800000099@s.whatsapp.net', $this->st('wa_ticket_progress_input_group_jid'));
        $this->assertNotContains(['ticket', '62800000099@g.us'], $this->membershipCalls);

        $this->putInput('+0800 0000 098')->assertSessionHasNoErrors();
        $this->assertSame('628000000098@s.whatsapp.net', $this->st('wa_ticket_progress_input_group_jid'));

        $this->assertStringNotContainsString('62800000099', (string) Activity::query()->where('log_name', 'wa-groups')->get()->toJson());
    }

    public function test_input_normalisation_equals_the_runtime_normaliser(): void
    {
        $runtime = new class extends TechnicianProgressService
        {
            public function __construct() {}

            public function norm(string $v): string
            {
                return $this->normalizeInputJid($v);
            }
        };

        foreach (['81234567890', '081234567890', '6281234567890', '+6281234567890'] as $input) {
            $this->putInput($input)->assertSessionHasNoErrors();
            $stored = $this->st('wa_ticket_progress_input_group_jid');
            $this->assertSame('6281234567890@s.whatsapp.net', $stored, "input {$input}");
            $this->assertSame($runtime->norm($input), $stored, "runtime drift for {$input}");
            // the stored value must match an incoming remoteJid through the runtime normaliser
            $this->assertSame($stored, $runtime->norm($stored));
        }
    }

    public function test_input_long_bare_group_id_stays_a_group_and_junk_is_refused(): void
    {
        $this->putInput('120363000000000015')->assertSessionHasNoErrors();
        $this->assertSame(self::G_INPUT, $this->st('wa_ticket_progress_input_group_jid'));

        $this->putInput('0800-0000-099')->assertSessionHasErrors('input_jid');
        $this->putInput('abc')->assertSessionHasErrors('input_jid');
        $this->assertSame(self::G_INPUT, $this->st('wa_ticket_progress_input_group_jid'));
    }

    public function test_input_short_digit_only_values_are_refused_but_ten_digits_is_a_phone(): void
    {
        $this->putInput('6281234567890')->assertSessionHasNoErrors();
        $before = $this->st('wa_ticket_progress_input_group_jid');

        foreach (['12345678', '123456789', '+080000003', '0812 3456'] as $typo) {
            $this->putInput($typo)->assertSessionHasErrors('input_jid');
            $this->assertSame($before, $this->st('wa_ticket_progress_input_group_jid'), "typo {$typo} must not be stored");
        }

        $this->putInput('0812345678')->assertSessionHasNoErrors();
        $this->assertSame('62812345678@s.whatsapp.net', $this->st('wa_ticket_progress_input_group_jid'));
    }

    public function test_stored_empty_label_is_not_rewritten_to_its_default(): void
    {
        Setting::set('wa_ticket_progress_input_group_label', '', 'text', 'wa');
        Setting::flushRequestMemo();

        $this->putInput(self::G_INPUT, ['input_label' => ''])->assertSessionHasNoErrors();

        $this->assertSame(self::G_INPUT, $this->st('wa_ticket_progress_input_group_jid'));
        $this->assertSame('', $this->st('wa_ticket_progress_input_group_label', 'KERJAAN'));
        $this->assertNull($this->rows()->firstWhere('properties.key', 'wa_ticket_progress_input_group_label'));
    }

    public function test_reply_channel_change_rechecks_the_input_group_membership(): void
    {
        Setting::set('wa_ticket_progress_input_group_jid', self::G_INPUT, 'text', 'wa');
        Setting::set('ticket_progress_reply_channel', 'ticket', 'text', 'ticket');
        Setting::flushRequestMemo();
        $this->membershipByJid[self::G_INPUT] = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];

        $this->actingAs($this->superadmin())->postJson(route('api.technician-progress.save'), ['reply_channel' => 'ops'])
            ->assertStatus(422)->assertJsonValidationErrors('reply_channel');
        $this->assertSame('ticket', $this->st('ticket_progress_reply_channel'));
        $this->assertContains(['ops', self::G_INPUT], $this->membershipCalls);

        $this->membershipByJid[self::G_INPUT] = ['member' => true, 'subject' => 'Grup Uji', 'size' => 5, 'error' => null];
        Cache::flush();
        $this->actingAs($this->superadmin())->postJson(route('api.technician-progress.save'), ['reply_channel' => 'ops'])->assertOk();
        $this->assertSame('ops', $this->st('ticket_progress_reply_channel'));
        $this->assertSame(self::G_INPUT, $this->st('wa_ticket_progress_input_group_jid'));
    }

    public function test_reply_channel_change_with_a_personal_input_is_not_probed(): void
    {
        Setting::set('wa_ticket_progress_input_group_jid', '62800000099@s.whatsapp.net', 'text', 'wa');
        Setting::flushRequestMemo();
        $calls = count($this->membershipCalls);

        $this->actingAs($this->superadmin())->postJson(route('api.technician-progress.save'), ['reply_channel' => 'ops'])->assertOk();
        $this->assertSame($calls, count($this->membershipCalls));
    }
}
