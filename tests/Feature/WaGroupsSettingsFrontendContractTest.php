<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Source-level contract of the Grup WhatsApp page (no browser): saves, guards, texts, menu and access wiring. */
#[Group('blocking-core')]
class WaGroupsSettingsFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path);
    }

    public function test_page_saves_with_inertia_router_put_never_fetch(): void
    {
        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');

        $this->assertStringContainsString('router.put(', $page);
        $this->assertStringContainsString("route('settings.wa-groups.update')", $page);
        $this->assertStringNotContainsString("method: 'PUT'", $page);
        $this->assertStringNotContainsString("method: 'POST'", $page);
        $this->assertStringContainsString("route('api.settings.wa-groups.groups')", $page);
    }

    /** #525 L-a: the Report card shows the force checkbox when the channel switch is refused as "unverified". */
    public function test_report_card_offers_the_force_checkbox_for_the_server_unverified_channel_error(): void
    {
        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');
        $writer = $this->source('app/Services/Wa/WaGroupSettingsWriter.php');

        $this->assertStringContainsString("UNVERIFIED_ERROR_MARKER = 'belum bisa diverifikasi'", $page);
        $this->assertStringContainsString('serverError={errors.channel}', $page);
        $this->assertStringContainsString('belum bisa diverifikasi. Centang "Simpan walau belum terverifikasi"', $writer, 'server text must keep the marker the page keys on');
    }

    public function test_page_uses_the_shared_guards_and_components(): void
    {
        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');

        foreach (['useUnsavedChangesGuard', 'ConfirmDialog', 'ConfirmDestructive', 'StatusBadge', 'EmptyState', 'GroupPicker', 'typedConfirmation="IZINKAN"'] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }

        $picker = $this->source('resources/js/Components/WaGroups/GroupPicker.tsx');
        $this->assertStringContainsString('@/Components/ui/command', $picker);
        $this->assertStringContainsString("route('api.settings.wa-groups.membership')", $picker);
        $this->assertStringContainsString('BLOCK_REASON_TEXT', $picker);
    }

    public function test_indonesian_texts_and_read_only_psb_section(): void
    {
        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');

        foreach ([
            'Halaman ini tidak mengubah grup PSB.',
            'Simpan walau belum terverifikasi',
            'Daftar grup tidak bisa dimuat (server WA lambat/timeout).',
            'Anda tetap bisa memasukkan JID manual.',
            'BENTROK:',
            'Muat ulang daftar grup',
            'Pengaturan grup WhatsApp disimpan.',
            'Skynet Report',
            'Alert Ops',
            'Log teknis',
            'Peringatan laporan harian gagal',
            'Progres teknisi',
            'Grup Tiket',
            'Alert Connectivity (NOC)',
            'Absensi',
            'Bagian "Draft PSB dan kendala" di laporan harian',
        ] as $text) {
            $this->assertStringContainsString($text, $this->source('resources/js/Pages/Settings/WaGroups.tsx').$this->source('resources/js/Components/WaGroups/GroupPicker.tsx'), $text);
        }
        $this->assertStringContainsString("route('oltc300.index')", $page, 'PSB section links to the PSB WA panel instead of editing');
    }

    public function test_page_never_touches_psb_endpoint_or_raw_evolution_fields_or_banned_styles(): void
    {
        $all = $this->source('resources/js/Pages/Settings/WaGroups.tsx')
            .$this->source('resources/js/Components/WaGroups/GroupPicker.tsx')
            .$this->source('resources/js/Components/WaGroups/types.ts');

        foreach (['/psb-wa/settings', 'psb-drafts.settings', 'participants', 'lastMessage', 'pushName', 'violet-', 'bg-gradient-', 'control-plane-totp'] as $banned) {
            $this->assertStringNotContainsString($banned, $all, $banned);
        }
    }

    public function test_sidebar_entry_under_sistem_and_nav_access_matches_the_server_rule(): void
    {
        $sidebar = $this->source('resources/js/Components/AppSidebar.tsx');
        $this->assertMatchesRegularExpression("/name: 'Grup WhatsApp', route: 'settings\\.wa-groups'.*section: 'system', access: 'global_admin_only'/", $sidebar);

        $nav = $this->source('resources/js/lib/navAccess.ts');
        $this->assertStringContainsString("'global_admin_only'", $nav);
        $this->assertStringContainsString("case 'global_admin_only':", $nav);
        $this->assertStringContainsString("user.role === 'admin' && user.scope === 'global_admin'", $nav);
    }

    public function test_route_is_not_behind_totp_in_the_route_file(): void
    {
        $routes = $this->source('routes/web.php');
        $start = strpos($routes, "'/settings/wa-groups', [WaGroupSettingsController::class, 'update']");
        $this->assertNotFalse($start);
        $block = substr($routes, $start, 200);
        $this->assertStringNotContainsString('control-plane-totp', $block);
    }

    public function test_saving_never_prompts_and_cross_role_errors_show_on_the_report_card(): void
    {
        $hook = $this->source('resources/js/hooks/useUnsavedChangesGuard.ts');
        $this->assertStringContainsString("method !== 'get' && !LOGOUT_PATH.test(url.pathname)", $hook, 'a PUT save must not trigger the leave-page prompt');

        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');
        $this->assertStringContainsString('errors.group_jid ?? errors.jid', $page);
    }

    public function test_group_lists_are_fetched_once_per_channel_at_page_level(): void
    {
        $page = $this->source('resources/js/Pages/Settings/WaGroups.tsx');

        // definition + exactly two calls (ops, ticket) inside the page component, none inside the cards.
        $this->assertSame(3, substr_count($page, 'useGroups('));
        $this->assertStringContainsString("useGroups('ops', true)", $page);
        $this->assertStringContainsString("useGroups('ticket', true)", $page);
        // every new card saves through the same router.put plumbing and picker, never its own fetch.
        foreach (['section="ticket"', 'section="connectivity"', '<AttendanceCard', '<TechnicianCard', 'allowPersonal', "useSectionSave('technician'"] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }
        $this->assertStringContainsString('Juga menerima laporan tiket selesai', $page);
    }

    public function test_group_label_shows_name_plus_jid_suffix_and_prefers_the_probed_subject(): void
    {
        $types = $this->source('resources/js/Components/WaGroups/types.ts');
        $picker = $this->source('resources/js/Components/WaGroups/GroupPicker.tsx');

        // format: "Nama Grup · …123456@g.us" (last 6 chars of the local part)
        $this->assertStringContainsString('export function formatGroupLabel(', $types);
        $this->assertStringContainsString('local.slice(-6)', $types);
        $this->assertStringContainsString('`${clean} · ${suffix}`', $types);
        $this->assertStringContainsString('…', $types);

        // picker: list items and trigger use it; probed subject (findGroupInfos) wins over the chat-list name.
        $this->assertStringContainsString('formatGroupLabel(g.name, g.jid)', $picker);
        $this->assertStringContainsString('probedSubject ?? selected.name', $picker);

        // whitelist stays: only jid/name/members reach the browser, never pushName/participants/lastMessage.
        foreach (['pushName', 'participants', 'lastMessage'] as $raw) {
            $this->assertStringNotContainsString($raw, $picker.$types);
        }
    }

    public function test_old_screens_are_read_only_and_link_to_the_groups_page(): void
    {
        $files = [
            'resources/js/Pages/Settings/Index.tsx',
            'resources/js/Components/noc/NocConnectivityAlertSettings.tsx',
            'resources/js/Pages/Attendances/Index.tsx',
        ];
        foreach ($files as $f) {
            $src = $this->source($f);
            $this->assertStringContainsString("route('settings.wa-groups')", $src, $f);
            $this->assertStringContainsString('Ubah di Pengaturan › Grup WhatsApp', $src, $f);
            $this->assertStringContainsString('formatGroupLabel', $src, $f);
            foreach (["fetch('/api/wa-gateway/groups', {", 'share_group_jid: techProgress', 'input_group_jid: techProgress', 'share_group_label: techProgress', 'input_group_label: techProgress', 'JSON.stringify(waGroups)', 'attendance_group_id:', "axios.post('/api/attendances/settings'", 'group_id: selectedGroup'] as $banned) {
                $this->assertStringNotContainsString($banned, $src, $f.' must not write groups: '.$banned);
            }
        }
        // Noc dialog no longer lists groups (that endpoint is superadmin-only); the switches still save.
        $noc = $this->source('resources/js/Components/noc/NocConnectivityAlertSettings.tsx');
        $this->assertStringNotContainsString('/api/wa-gateway/groups', $noc);
        $this->assertStringContainsString('can_manage_wa_groups', $noc);
        $this->assertStringContainsString('Minta admin mengubah di Pengaturan › Grup WhatsApp', $noc);
    }

    public function test_picker_never_offers_blocked_groups_as_selectable(): void
    {
        $picker = $this->source('resources/js/Components/WaGroups/GroupPicker.tsx');

        $this->assertStringContainsString('disabled={blocked}', $picker);
        $this->assertStringContainsString('if (blocked) return;', $picker);
    }
}
