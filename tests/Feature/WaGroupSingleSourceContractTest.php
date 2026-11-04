<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * Single source of truth (plan wa-groups-single-source, PR-2): the old screens/endpoints no longer write any
 * WhatsApp group key. Only WaGroupSettingsWriter (+ the PSB panel and the CLI tools) write them.
 */
#[Group('blocking-core')]
class WaGroupSingleSourceContractTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    private const G_SHARE = '120363000000000021@g.us';

    private const G_INPUT = '120363000000000022@g.us';

    private const G_CONN = '120363000000000023@g.us';

    private const G_ATT = '120363000000000024@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
        Setting::set('wa_ticket_progress_share_group_jid', self::G_SHARE, 'text', 'wa');
        Setting::set('wa_ticket_progress_share_group_label', 'SHARE LBL', 'text', 'wa');
        Setting::set('wa_ticket_progress_input_group_jid', self::G_INPUT, 'text', 'wa');
        Setting::set('wa_ticket_progress_input_group_label', 'INPUT LBL', 'text', 'wa');
        Setting::set('connectivity_alerts_group_id', self::G_CONN, 'text', 'monitoring');
        Setting::set('attendance_group_id', self::G_ATT, 'text', 'attendance');
        Setting::set('attendance_group_label', 'ATT LBL', 'text', 'attendance');
        Setting::flushRequestMemo();
    }

    private function st(string $key): mixed
    {
        Setting::flushRequestMemo();

        return Setting::get($key);
    }

    /** @return array<string, mixed> */
    private function groupKeys(): array
    {
        $out = [];
        foreach ([
            'wa_ticket_group_jid', 'wa_report_group_jid', 'psb_wa_group_ids',
            'wa_ticket_progress_share_group_jid', 'wa_ticket_progress_share_group_label',
            'wa_ticket_progress_input_group_jid', 'wa_ticket_progress_input_group_label',
            'connectivity_alerts_group_id', 'attendance_group_id', 'attendance_group_label',
        ] as $k) {
            $out[$k] = $this->st($k);
        }

        return $out;
    }

    public function test_old_wa_gateway_post_is_410_and_writes_nothing(): void
    {
        $before = $this->groupKeys();
        $this->actingAs($this->superadmin())
            ->postJson('/api/wa-gateway/groups', ['wa_ticket_group_jid' => self::G_ATT, 'wa_report_group_jid' => self::G_CONN])
            ->assertStatus(410);
        $this->assertSame($before, $this->groupKeys());
    }

    public function test_technician_progress_save_rejects_group_fields_and_a_template_save_keeps_the_groups(): void
    {
        $before = $this->groupKeys();
        $admin = $this->superadmin();

        foreach ([
            ['share_group_jid' => self::G_ATT],
            ['input_group_jid' => self::G_ATT],
            ['share_group_label' => 'x'],
            ['input_group_label' => 'x'],
        ] as $groupField) {
            $this->actingAs($admin)->postJson(route('api.technician-progress.save'), array_merge(['manual_text' => 'T'], $groupField))
                ->assertStatus(422);
        }
        $this->assertSame($before, $this->groupKeys());

        // F2 pin: a plain template/schedule save used to wipe share/input (?? ''). It must keep them now.
        $this->actingAs($admin)->postJson(route('api.technician-progress.save'), [
            'manual_text' => 'Template baru',
            'notes' => 'catatan',
            'preview_time' => '20:00',
        ])->assertOk();
        $this->assertSame('Template baru', $this->st('ticket_progress_manual_text'));
        $this->assertSame($before, $this->groupKeys());
    }

    public function test_connectivity_save_rejects_group_id_and_keeps_the_group_when_only_switches_are_saved(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->postJson(route('api.monitoring.connectivity.settings.save'), ['group_id' => self::G_ATT, 'enabled' => true])
            ->assertStatus(422);
        $this->assertSame(self::G_CONN, $this->st('connectivity_alerts_group_id'));

        $this->actingAs($admin)->postJson(route('api.monitoring.connectivity.settings.save'), ['enabled' => true, 'dry_run' => false])
            ->assertOk();
        $this->assertSame('1', (string) $this->st('connectivity_alerts_enabled'));
        $this->assertSame('0', (string) $this->st('connectivity_alerts_dry_run'));
        $this->assertSame(self::G_CONN, $this->st('connectivity_alerts_group_id'));
    }

    public function test_connectivity_payload_tells_the_ui_who_may_open_the_groups_page(): void
    {
        $this->actingAs($this->noc())->getJson(route('api.monitoring.connectivity.settings'))
            ->assertOk()->assertJsonPath('can_manage_wa_groups', false);
        $this->actingAs($this->superadmin())->getJson(route('api.monitoring.connectivity.settings'))
            ->assertOk()->assertJsonPath('can_manage_wa_groups', true);
    }

    public function test_attendance_settings_post_is_410_and_keeps_group_and_label(): void
    {
        $before = $this->groupKeys();
        $this->actingAs($this->superadmin())
            ->postJson(route('api.attendances.settings.save'), ['attendance_group_id' => self::G_CONN, 'attendance_group_label' => 'x'])
            ->assertStatus(410);
        $this->assertSame($before, $this->groupKeys());
    }

    public function test_only_the_allowed_writers_call_setting_set_for_the_group_keys(): void
    {
        $keys = [
            'wa_ticket_group_jid', 'wa_report_group_jid', 'report_wa_group_ids', 'report_wa_channel',
            'report_undelivered_alert_jid', 'outage_shadow_log_jid', 'outage_shadow_allow_report_group',
            'connectivity_alerts_group_id', 'attendance_group_id', 'attendance_group_label',
            'wa_ticket_progress_share_group_jid', 'wa_ticket_progress_share_group_label',
            'wa_ticket_progress_input_group_jid', 'wa_ticket_progress_input_group_label', 'psb_wa_group_ids',
        ];
        // The Grup WhatsApp writer, the PSB panel (psb_wa_group_ids) and the outage writer (also used by
        // outage:settings). report:wa-group writes through WaGroupSettingsWriter, so it is NOT allowlisted.
        $allowed = [
            'app/Services/Wa/WaGroupSettingsWriter.php',
            'app/Http/Controllers/PsbDraftController.php',
            'app/Services/Outage/OutageSettingsWriter.php',
            'app/Console/Commands/OutageSettingsCommand.php',
        ];
        $pattern = '/Setting::(?:set|updateOrCreate)\(\s*[\'"]('.implode('|', $keys).')[\'"]/';

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
                $offenders[] = $rel.' => '.$m[1];
            }
        }

        $this->assertSame([], $offenders, 'Only the allowlisted writers may write WhatsApp group keys.');
    }
}
