<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\Report\ReportGroupRegistry;
use App\Services\Wa\WaGroupSettingsWriter;
use App\Support\WaGroupJid;
use Illuminate\Console\Command;

/**
 * Find / verify / register the "Skynet Report" WA group. Everything is read-only
 * (no message is ever sent) except `set` and `clear`, which write report_wa_group_ids
 * (and report_wa_channel) only through WaGroupSettingsWriter, like the settings page.
 */
class ReportWaGroupCommand extends Command
{
    protected $signature = 'report:wa-group
        {action : groups|check|show|set|clear}
        {jid? : group JID (check, set)}
        {--channel= : ops|ticket (groups/check/set); with set it is also saved as report_wa_channel}
        {--force : with set, accept a membership that could not be verified}';

    protected $description = 'Skynet Report WA group: list, check membership, show settings, set/clear (never sends a message)';

    public function handle(ReportGroupRegistry $registry, EvolutionApiService $evolution, WaGroupSettingsWriter $writer): int
    {
        return match ((string) $this->argument('action')) {
            'groups' => $this->groups($registry, $evolution),
            'check' => $this->check($registry, $evolution),
            'show' => $this->show($registry),
            'set' => $this->set($registry, $writer),
            'clear' => $this->clear($registry, $writer),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        $this->error('Usage: report:wa-group groups|check <jid>|show|set <jid>|clear [--channel=ops|ticket] [--force]');

        return self::FAILURE;
    }

    private function channel(ReportGroupRegistry $registry): ?string
    {
        $opt = strtolower(trim((string) $this->option('channel')));
        if ($opt === '') {
            return $registry->channel();
        }
        if (! in_array($opt, ReportGroupRegistry::CHANNELS, true)) {
            $this->error('--channel must be ops or ticket.');

            return null;
        }

        return $opt;
    }

    private function groups(ReportGroupRegistry $registry, EvolutionApiService $evolution): int
    {
        $channel = $this->channel($registry);
        if ($channel === null) {
            return self::FAILURE;
        }
        // fetchAllGroups often times out (~15 s) on big instances: list from chat/findChats first
        // (12 s cap, chat-source naming) and only then fall back to fetchAllGroups. Read-only either way.
        $api = $evolution->forChannel($channel);
        $list = $api->listGroupsViaChats();
        $source = 'chats';
        if (! $list['success'] || $list['groups'] === []) {
            $chatsError = $list['error'] ?? 'empty';
            $list = $api->listGroupsLight();
            $source = 'fetchAllGroups';
            if (! $list['success']) {
                $this->error('Could not list groups: '.($list['error'] ?? 'unknown').' (findChats: '.$chatsError.')');

                return self::FAILURE;
            }
        }

        $rows = [];
        foreach ($list['groups'] as $g) {
            $name = (string) ($g['name'] ?? '');
            $rows[] = [
                stripos($name, 'report') !== false ? '>>' : '',
                $name,
                (string) ($g['id'] ?? ''),
                (int) ($g['members'] ?? 0),
            ];
        }
        $this->line('Channel: '.$channel.($source === 'chats'
            ? ' (groups from chat list; may include groups this number already left, verify with `check <jid>`)'
            : ' (groups this WA number is a member of)'));
        $this->table(['', 'Name', 'JID', 'Members'], $rows);

        return self::SUCCESS;
    }

    private function check(ReportGroupRegistry $registry, EvolutionApiService $evolution): int
    {
        $jid = WaGroupJid::normalize((string) $this->argument('jid'));
        if ($jid === '') {
            $this->error('check needs a group JID.');

            return self::FAILURE;
        }
        $channel = $this->channel($registry);
        if ($channel === null) {
            return self::FAILURE;
        }

        $wa = $evolution->forChannel($channel);
        $membership = $wa->groupMembership($jid);
        $this->line('Channel: '.$channel);
        $this->line('Instance state: '.$wa->getConnectionState());
        $this->line('Member: '.($membership['member'] === null ? 'unknown' : ($membership['member'] ? 'yes' : 'no')));
        $this->line('Subject: '.($membership['subject'] ?? '-'));
        $this->line('Size: '.($membership['size'] ?? '-'));
        if ($membership['error'] !== null) {
            $this->line('Note: '.$membership['error']);
        }
        if ($registry->isPsbGroup($jid)) {
            $this->error('OVERLAP: '.$jid.' ada di psb_wa_group_ids — HAPUS dari salah satu');
        } else {
            $this->line('Overlap with psb_wa_group_ids: none');
        }

        return self::SUCCESS;
    }

    private function show(ReportGroupRegistry $registry): int
    {
        $this->line('report_wa_group_ids: '.($registry->groupJids() === [] ? '(empty = feature off)' : implode(', ', $registry->groupJids())));
        $this->line('report_wa_channel: '.$registry->channel());
        $this->line('report_daily_enabled: '.($registry->dailyEnabled() ? 'ON' : 'off'));
        $this->line('report_recap_cmd_enabled: '.(in_array(Setting::get('report_recap_cmd_enabled', 0), [1, '1', true, 'true', 'on', 'yes'], true) ? 'ON' : 'off'));
        $this->line('psb_recap_cmd_enabled (global kill switch): '.(in_array(Setting::get('psb_recap_cmd_enabled', 0), [1, '1', true, 'true', 'on', 'yes'], true) ? 'ON' : 'off'));
        $this->line('wa_ticket_progress_share_group_jid (candidate): '.(string) Setting::get('wa_ticket_progress_share_group_jid', '-'));
        $this->line('wa_report_group_jid (candidate): '.(string) Setting::get('wa_report_group_jid', '-'));
        $this->line('psb_wa_group_ids: '.($registry->psbGroupJids() === [] ? '(empty)' : implode(', ', $registry->psbGroupJids())));
        foreach ($registry->overlapWithPsb() as $jid) {
            $this->error('OVERLAP: '.$jid.' ada di psb_wa_group_ids — HAPUS dari salah satu');
        }

        return self::SUCCESS;
    }

    private function set(ReportGroupRegistry $registry, WaGroupSettingsWriter $writer): int
    {
        $jid = WaGroupJid::normalize((string) $this->argument('jid'));
        if ($jid === '' || ! str_ends_with($jid, '@g.us')) {
            $this->error('set needs a group JID (xxxx@g.us).');

            return self::FAILURE;
        }
        $channel = $this->channel($registry);
        if ($channel === null) {
            return self::FAILURE;
        }

        // Same guards as Pengaturan > Grup WhatsApp: PSB refusal, cross-role checks, membership (--force =
        // "simpan walau belum terverifikasi"), masked activity row (source cli), per-key cache forget.
        $result = $writer->applyAsSystem('report', ['group_jid' => $jid, 'channel' => $channel], (bool) $this->option('force'), 'cli');
        if (! $result['ok']) {
            foreach ($result['errors'] as $message) {
                $this->error('Refused: '.$message);
            }

            return self::FAILURE;
        }
        if ($this->option('force')) {
            $this->warn('--force: membership that could not be verified is accepted.');
        }

        $this->info($result['changed'] === []
            ? 'No change: report_wa_group_ids already = '.$jid.' (channel '.$registry->channel().').'
            : 'Saved report_wa_group_ids = '.$jid.' (channel '.$registry->channel().'). Flags are unchanged.');

        return self::SUCCESS;
    }

    private function clear(ReportGroupRegistry $registry, WaGroupSettingsWriter $writer): int
    {
        $result = $writer->applyAsSystem('report', ['group_jid' => '', 'channel' => $registry->channel()], false, 'cli');
        if (! $result['ok']) {
            foreach ($result['errors'] as $message) {
                $this->error('Refused: '.$message);
            }

            return self::FAILURE;
        }
        $this->info('report_wa_group_ids cleared (Report feature off).');

        return self::SUCCESS;
    }
}
