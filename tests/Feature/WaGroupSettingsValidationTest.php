<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/** Full guard matrix of the Grup WhatsApp save path (positive + negative for every guard). */
#[Group('blocking-core')]
class WaGroupSettingsValidationTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

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

    // ---------- version / generic ----------

    public function test_stale_version_is_rejected_and_nothing_is_written(): void
    {
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED], str_repeat('a', 40))
            ->assertSessionHasErrors('version');

        $this->assertNull($this->st('report_undelivered_alert_jid'));
    }

    public function test_unknown_section_and_prohibited_psb_keys_are_rejected(): void
    {
        $this->putSection($this->superadmin(), 'psb', [])->assertSessionHasErrors('section');

        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED, 'psb_wa_group_ids' => [self::G_NEW]])
            ->assertSessionHasErrors('psb_wa_group_ids');
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED, 'psb_recap_cmd_enabled' => 1])
            ->assertSessionHasErrors('psb_recap_cmd_enabled');

        $this->assertNull($this->st('report_undelivered_alert_jid'));
        $this->assertSame([self::G_PSB], $this->st('psb_wa_group_ids'));
    }

    public function test_bad_jid_format_is_rejected(): void
    {
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => 'bukan-jid'])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'report', ['group_jid' => 'bukan-jid', 'channel' => 'ops'])->assertSessionHasErrors('group_jid');
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'telegram'])->assertSessionHasErrors('channel');
    }

    // ---------- report ----------

    public function test_report_group_saved_in_cli_format_with_channel_and_flags(): void
    {
        $this->putSection($this->superadmin(), 'report', [
            'group_jid' => '120363000000000006',
            'channel' => 'ticket',
            'daily_enabled' => true,
            'recap_cmd_enabled' => false,
            'pppoe_cmd_enabled' => true,
            'onu_daily_enabled' => true,
            'psb_detail_daily_enabled' => true,
            'onu_recap_cmd_enabled' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame([self::G_NEW], $this->st('report_wa_group_ids'));
        $this->assertSame('ticket', $this->st('report_wa_channel'));
        $this->assertSame('1', (string) $this->st('report_daily_enabled'));
        $this->assertSame('0', (string) $this->st('report_recap_cmd_enabled'));
        $this->assertSame('1', (string) $this->st('report_pppoe_cmd_enabled'));
        $this->assertSame('1', (string) $this->st('report_daily_onu_enabled'));
        $this->assertSame('1', (string) $this->st('report_daily_psb_detail_enabled'));
        $this->assertSame('0', (string) $this->st('recap_onu_cmd_enabled'));
        $this->assertContains(['ticket', self::G_NEW], $this->membershipCalls, 'membership is probed on the chosen channel');
    }

    public function test_report_group_equal_to_psb_group_is_refused_exact_and_bare(): void
    {
        foreach ([self::G_PSB, '120363000000000002'] as $jid) {
            $this->putSection($this->superadmin(), 'report', ['group_jid' => $jid, 'channel' => 'ops'])->assertSessionHasErrors('group_jid');
        }
        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));
    }

    public function test_report_membership_false_is_refused_and_unknown_needs_override(): void
    {
        $this->membershipByJid[self::G_NEW] = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'], null, ['report'])
            ->assertSessionHasErrors('group_jid');
        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'), 'definite non-member cannot be forced');

        Cache::flush();
        $this->membershipByJid[self::G_NEW] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'])
            ->assertSessionHasErrors('group_jid');
        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'], null, ['report'])
            ->assertSessionHasNoErrors();
        $this->assertSame([self::G_NEW], $this->st('report_wa_group_ids'));
    }

    public function test_report_clear_and_flag_only_change_do_not_probe_membership(): void
    {
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ops', 'daily_enabled' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->membershipCalls, 'unchanged JID + channel is not re-checked');

        $this->putSection($this->superadmin(), 'report', ['group_jid' => '', 'channel' => 'ops'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->st('report_wa_group_ids'));
    }

    public function test_report_with_several_legacy_groups_collapses_to_exactly_one(): void
    {
        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_NEW], 'json', 'report');
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ops'])->assertSessionHasNoErrors();

        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));
    }

    // ---------- ops alert ----------

    public function test_ops_alert_never_to_a_psb_group(): void
    {
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_PSB])->assertSessionHasErrors('jid');
        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));
    }

    public function test_ops_alert_saved_and_empty_falls_back_to_env_by_deleting_the_row(): void
    {
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_NEW])->assertSessionHasNoErrors();
        $this->assertSame(self::G_NEW, $this->st('wa_report_group_jid'));
        $this->assertContains(['ops', self::G_NEW], $this->membershipCalls);

        config(['services.evolution_api.ops_group_id' => self::G_UNDELIVERED]);
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Setting::where('key', 'wa_report_group_jid')->first());
        $this->assertSame(self::G_UNDELIVERED, $this->st('wa_report_group_jid', config('services.evolution_api.ops_group_id')));
    }

    // ---------- shadow ----------

    public function test_shadow_psb_never_even_with_allow_flag(): void
    {
        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_PSB, 'allow_report_group' => true])->assertSessionHasErrors('jid');
        $this->assertNull(Setting::where('key', 'outage_shadow_log_jid')->first());
        $this->assertNull(Setting::where('key', 'outage_shadow_allow_report_group')->first(), 'refusal is atomic: the allow flag is not written either');
    }

    public function test_shadow_to_report_or_ops_group_needs_the_allow_flag(): void
    {
        foreach ([self::G_REPORT, self::G_OPS] as $jid) {
            $this->putSection($this->superadmin(), 'shadow', ['jid' => $jid, 'allow_report_group' => false])->assertSessionHasErrors('jid');
        }
        $this->assertNull(Setting::where('key', 'outage_shadow_log_jid')->first());

        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_REPORT, 'allow_report_group' => true])->assertSessionHasNoErrors();
        $this->assertSame(self::G_REPORT, $this->st('outage_shadow_log_jid'));
        $this->assertSame('1', (string) $this->st('outage_shadow_allow_report_group'));
    }

    public function test_shadow_allow_off_while_pointing_at_report_group_is_refused_and_clearing_first_works(): void
    {
        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_REPORT, 'allow_report_group' => true])->assertSessionHasNoErrors();

        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_REPORT, 'allow_report_group' => false])->assertSessionHasErrors('jid');
        $this->assertSame('1', (string) $this->st('outage_shadow_allow_report_group'));

        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_SHADOW, 'allow_report_group' => false])->assertSessionHasNoErrors();
        $this->assertSame(self::G_SHADOW, $this->st('outage_shadow_log_jid'));
        $this->assertSame('0', (string) $this->st('outage_shadow_allow_report_group'));
    }

    // ---------- undelivered ----------

    public function test_undelivered_never_report_ops_or_psb(): void
    {
        foreach ([self::G_REPORT, self::G_OPS, self::G_PSB] as $jid) {
            $this->putSection($this->superadmin(), 'undelivered', ['jid' => $jid])->assertSessionHasErrors('jid');
        }
        $this->assertNull($this->st('report_undelivered_alert_jid'));
    }

    public function test_undelivered_saved_probed_on_the_report_channel_and_clearable(): void
    {
        Setting::set('report_wa_channel', 'ticket', 'text', 'report');
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED])->assertSessionHasNoErrors();
        $this->assertSame(self::G_UNDELIVERED, $this->st('report_undelivered_alert_jid'));
        $this->assertContains(['ticket', self::G_UNDELIVERED], $this->membershipCalls);

        $this->putSection($this->superadmin(), 'undelivered', ['jid' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', $this->st('report_undelivered_alert_jid'));
    }

    // ---------- cross-role ----------

    public function test_a_change_that_breaks_another_role_is_refused(): void
    {
        Setting::set('report_undelivered_alert_jid', self::G_UNDELIVERED, 'text', 'report');

        // Ops alert -> the undelivered group would make the undelivered alert target the Ops group.
        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_UNDELIVERED])->assertSessionHasErrors('jid');
        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));

        // Report -> the undelivered group likewise.
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_UNDELIVERED, 'channel' => 'ops'])->assertSessionHasErrors('group_jid');
        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));
    }

    public function test_cache_is_forgotten_again_after_the_transaction_commits(): void
    {
        // Simulate a concurrent reader re-caching the OLD value after the in-transaction forget:
        // the poison lands when the activity row is written, i.e. inside the transaction.
        Activity::created(function (): void {
            Cache::forever('setting.report_undelivered_alert_jid', 'STALE-OLD-VALUE');
        });

        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED])->assertSessionHasNoErrors();

        $this->assertSame(self::G_UNDELIVERED, $this->st('report_undelivered_alert_jid'));
    }

    public function test_a_pre_existing_problem_in_another_role_does_not_block_an_unrelated_save(): void
    {
        // Legacy state: undelivered already points at the Report group (the sender refuses it at runtime).
        Setting::set('report_undelivered_alert_jid', self::G_REPORT, 'text', 'report');

        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_NEW])->assertSessionHasNoErrors();
        $this->assertSame(self::G_NEW, $this->st('wa_report_group_jid'));
    }
}
