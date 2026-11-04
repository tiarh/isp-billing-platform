<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\Wa\WaGroupGuards;
use App\Services\Wa\WaGroupRoles;
use App\Services\Wa\WaGroupSettingsWriter;
use App\Support\WaGroupJid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * Follow-ups of the Grup WhatsApp page: ops != ticket group (parity with the sender), re-probe of the
 * undelivered group on a Report channel change, legacy extra Report JIDs, and the guarded old WA Gateway POST.
 */
#[Group('blocking-core')]
class WaGroupFollowupsTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    private const G_TICKET = '120363000000000007@g.us';

    private const G_EXTRA = '120363000000000008@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
        Setting::set('wa_ticket_group_jid', self::G_TICKET, 'text', 'wa');
        Setting::flushRequestMemo();
    }

    private function st(string $key, mixed $default = null): mixed
    {
        Setting::flushRequestMemo();

        return Setting::get($key, $default);
    }

    private function rows(): Collection
    {
        return Activity::query()->where('log_name', 'wa-groups')->where('event', 'wa_group_setting_changed')->get();
    }

    /** @return array<string, mixed> */
    private function psbSnapshot(): array
    {
        return [
            'psb' => DB::table('settings')->where('key', 'like', 'psb_%')->orderBy('key')->pluck('value', 'key')->all(),
            'share' => DB::table('settings')->where('key', 'wa_ticket_progress_share_group_jid')->value('value'),
        ];
    }

    // ---------- L2: ops != ticket ----------

    public function test_guard_refuses_ticket_group_for_ops_alert_exact_and_bare(): void
    {
        $this->assertSame('ticket_group', WaGroupGuards::opsAlertRefusal(self::G_TICKET, [self::G_PSB], self::G_TICKET));
        $this->assertSame('ticket_group', WaGroupGuards::opsAlertRefusal('120363000000000007', [self::G_PSB], self::G_TICKET));
        $this->assertSame('psb_group', WaGroupGuards::opsAlertRefusal(self::G_PSB, [self::G_PSB], self::G_PSB));
        $this->assertNull(WaGroupGuards::opsAlertRefusal(self::G_NEW, [self::G_PSB], self::G_TICKET));
        $this->assertNull(WaGroupGuards::opsAlertRefusal(self::G_TICKET, [self::G_PSB], ''), 'no ticket group configured: nothing to clash with');
    }

    public function test_page_refuses_ops_alert_equal_to_the_ticket_group_and_writes_nothing(): void
    {
        foreach ([self::G_TICKET, '120363000000000007'] as $jid) {
            $this->putSection($this->superadmin(), 'ops_alert', ['jid' => $jid])->assertSessionHasErrors('jid');
        }
        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));
        $this->assertCount(0, $this->rows());

        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_NEW])->assertSessionHasNoErrors();
        $this->assertSame(self::G_NEW, $this->st('wa_report_group_jid'));
    }

    public function test_page_ticket_rule_uses_the_env_default_when_no_ticket_setting_row_exists(): void
    {
        Setting::where('key', 'wa_ticket_group_jid')->delete();
        Cache::forget('setting.wa_ticket_group_jid');
        config(['services.evolution_api.ticket_group_id' => self::G_EXTRA]);

        $this->putSection($this->superadmin(), 'ops_alert', ['jid' => self::G_EXTRA])->assertSessionHasErrors('jid');
        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));
    }

    public function test_page_rule_and_ticket_closed_report_sender_agree_for_every_candidate(): void
    {
        config([
            'services.evolution_api.api_key' => 'test-key',
            'services.evolution_api.base_url' => 'http://evo.test',
        ]);
        $ticket = SupportTicket::create(['subject' => 'Paritas', 'status' => 'closed', 'priority' => 'normal']);
        $psb = app(WaGroupRoles::class)->snapshot()['readonly']['psb_group_jids'];

        foreach ([self::G_TICKET, '120363000000000007', self::G_NEW, self::G_UNDELIVERED] as $jid) {
            Http::fake(['*' => Http::response(['key' => ['id' => 'x']], 200)]);
            Setting::set('wa_report_group_jid', $jid, 'text', 'wa');
            Setting::flushRequestMemo();

            $result = (new EvolutionApiService)->sendTicketClosedReportToGroup($ticket);
            $senderRefuses = ($result['error'] ?? null) === 'Report group matches ticket group';
            $pageRefuses = WaGroupGuards::opsAlertRefusal($jid, $psb, app(WaGroupRoles::class)->effectiveTicketJid()) === 'ticket_group';

            $this->assertSame($senderRefuses, $pageRefuses, "sender/page drift for {$jid}");
        }
    }

    // ---------- L3: re-probe undelivered on channel change ----------

    public function test_channel_change_reprobes_the_undelivered_group_on_the_new_channel(): void
    {
        Setting::set('report_undelivered_alert_jid', self::G_UNDELIVERED, 'text', 'report');
        $this->membershipByJid[self::G_UNDELIVERED] = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ticket'], null, ['report'])
            ->assertSessionHasErrors('channel');

        $this->assertContains(['ticket', self::G_UNDELIVERED], $this->membershipCalls);
        $this->assertSame('ops', $this->st('report_wa_channel'), 'definite non-member cannot be forced');
        $this->assertCount(0, $this->rows());
    }

    public function test_channel_change_with_unknown_undelivered_membership_needs_the_override(): void
    {
        Setting::set('report_undelivered_alert_jid', self::G_UNDELIVERED, 'text', 'report');
        $this->membershipByJid[self::G_UNDELIVERED] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ticket'])
            ->assertSessionHasErrors('channel');
        $this->assertSame('ops', $this->st('report_wa_channel'));

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ticket'], null, ['report'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ticket', $this->st('report_wa_channel'));
        $this->assertSame(self::G_UNDELIVERED, $this->st('report_undelivered_alert_jid'), 'the undelivered JID itself is never modified');
    }

    public function test_channel_change_with_a_member_undelivered_group_passes_and_no_probe_without_a_channel_change(): void
    {
        Setting::set('report_undelivered_alert_jid', self::G_UNDELIVERED, 'text', 'report');

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ops', 'daily_enabled' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->membershipCalls, 'same channel: no re-probe');

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ticket'])->assertSessionHasNoErrors();
        $this->assertContains(['ticket', self::G_UNDELIVERED], $this->membershipCalls);
        $this->assertSame('ticket', $this->st('report_wa_channel'));
    }

    public function test_channel_change_without_an_undelivered_group_probes_nothing_extra(): void
    {
        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ticket'])->assertSessionHasNoErrors();
        $this->assertSame([['ticket', self::G_REPORT]], $this->membershipCalls, 'only the Report group itself (channel changed)');
    }

    // ---------- L5: legacy extra Report JIDs ----------

    public function test_legacy_extra_report_jids_count_for_the_cross_role_guards(): void
    {
        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_EXTRA], 'json', 'report');

        // The runtime treats every legacy Report JID as a Report group, so these are refused like the primary one.
        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_EXTRA, 'allow_report_group' => false])->assertSessionHasErrors('jid');
        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_EXTRA])->assertSessionHasErrors('jid');
        $this->assertNull(Setting::where('key', 'outage_shadow_log_jid')->first());
        $this->assertNull($this->st('report_undelivered_alert_jid'));

        $this->putSection($this->superadmin(), 'shadow', ['jid' => self::G_EXTRA, 'allow_report_group' => true])->assertSessionHasNoErrors();
    }

    public function test_legacy_extra_jids_are_surfaced_as_a_violation_and_a_psb_extra_is_flagged(): void
    {
        $writer = app(WaGroupSettingsWriter::class);
        $roles = app(WaGroupRoles::class);

        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_EXTRA], 'json', 'report');
        $this->assertContains('report:legacy_extra_jids', $writer->violations($roles->snapshot()));

        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_PSB], 'json', 'report');
        $v = $writer->violations($roles->snapshot());
        $this->assertContains('report:psb_group', $v);
        $this->assertContains('report:legacy_extra_jids', $v);

        Setting::set('report_wa_group_ids', [self::G_REPORT], 'json', 'report');
        $this->assertSame([], $writer->violations($roles->snapshot()));
    }

    public function test_report_save_normalises_legacy_extras_to_one_group_even_when_an_extra_is_psb(): void
    {
        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_PSB], 'json', 'report');
        $before = $this->psbSnapshot();

        $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_REPORT, 'channel' => 'ops'])->assertSessionHasNoErrors();

        $this->assertSame([self::G_REPORT], $this->st('report_wa_group_ids'));
        $row = $this->rows()->firstWhere(fn ($r) => $r->properties['key'] === 'report_wa_group_ids');
        $this->assertSame([WaGroupJid::mask(self::G_REPORT), WaGroupJid::mask(self::G_PSB)], $row->properties['old']);
        $this->assertSame([WaGroupJid::mask(self::G_REPORT)], $row->properties['new']);
        $this->assertSame($before, $this->psbSnapshot(), 'PSB settings untouched');
    }

    public function test_a_legacy_extra_report_jid_does_not_block_an_unrelated_save(): void
    {
        Setting::set('report_wa_group_ids', [self::G_REPORT, self::G_PSB], 'json', 'report');

        $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED])->assertSessionHasNoErrors();
        $this->assertSame(self::G_UNDELIVERED, $this->st('report_undelivered_alert_jid'));
    }

    // ---------- L7: old WA Gateway POST ----------

    private function legacyPost(array $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->superadmin())->postJson('/api/wa-gateway/groups', $body);
    }

    public function test_old_post_is_gone_410_and_writes_nothing(): void
    {
        $before = $this->psbSnapshot();

        foreach ([
            ['wa_report_group_jid' => self::G_NEW],
            ['wa_ticket_group_jid' => self::G_EXTRA, 'wa_report_group_jid' => self::G_NEW],
            ['wa_report_group_jid' => self::G_PSB],
            [],
        ] as $body) {
            $this->legacyPost($body)->assertStatus(410)->assertJsonPath('success', false);
        }

        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));
        $this->assertSame(self::G_TICKET, $this->st('wa_ticket_group_jid'));
        $this->assertCount(0, $this->rows());
        $this->assertSame($before, $this->psbSnapshot());
    }

    public function test_old_post_keeps_its_superadmin_only_access(): void
    {
        $this->actingAs($this->unscopedAdmin())->postJson('/api/wa-gateway/groups', ['wa_report_group_jid' => self::G_NEW])->assertForbidden();
        $this->assertSame(self::G_OPS, $this->st('wa_report_group_jid'));
    }
}
