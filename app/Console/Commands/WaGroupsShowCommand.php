<?php

namespace App\Console\Commands;

use App\Services\Wa\WaGroupGuards;
use App\Services\Wa\WaGroupRoles;
use App\Services\Wa\WaGroupSettingsWriter;
use App\Support\WaGroupJid;
use Illuminate\Console\Command;

/**
 * Read-only overview of every WhatsApp group role (same read model as Pengaturan > Grup WhatsApp):
 * masked JID (8-char fingerprint, never the raw JID), label, source and conflicts. Never writes, never sends.
 */
class WaGroupsShowCommand extends Command
{
    protected $signature = 'wa:groups {action=show : show}';

    protected $description = 'Read-only: every WhatsApp group role with masked JID, label and conflicts (PSB clash, same-group pairs)';

    public function handle(WaGroupRoles $roles, WaGroupSettingsWriter $writer): int
    {
        if ((string) $this->argument('action') !== 'show') {
            $this->error('Usage: wa:groups show');

            return self::FAILURE;
        }

        $s = $roles->snapshot();
        $report = $s['report']['group_jid'] !== '' ? array_merge([$s['report']['group_jid']], $s['report']['extra_jids']) : [];

        // role => [jid, label, source]
        $rows = [
            'report' => [(string) $s['report']['group_jid'], '', 'setting (channel '.$s['report']['channel'].')'],
            'ops_alert' => [(string) $s['ops_alert']['effective_jid'], '', $s['ops_alert']['uses_env_default'] ? 'env default' : 'setting'],
            'shadow' => [(string) $s['shadow']['jid'], '', 'setting'],
            'undelivered' => [(string) $s['undelivered']['jid'], '', 'setting'],
            'ticket' => [(string) $s['ticket']['effective_jid'], '', $s['ticket']['uses_env_default'] ? 'env default' : 'setting'],
            'connectivity' => [(string) $s['connectivity']['effective_jid'], '', $s['connectivity']['uses_env_default'] ? 'env default' : 'setting'],
            'attendance' => [(string) $s['attendance']['jid'], (string) $s['attendance']['label'], 'setting'],
            'technician_share' => [(string) $s['technician']['share_jid'], (string) $s['technician']['share_label'], 'setting'],
            'technician_input' => [(string) $s['technician']['input_jid'], (string) $s['technician']['input_label'], 'setting (reply channel '.$s['technician']['reply_channel'].')'],
        ];
        foreach ($s['readonly']['psb_group_jids'] as $i => $psb) {
            $rows['psb_'.($i + 1)] = [(string) $psb, '', 'psb_wa_group_ids (read-only)'];
        }
        foreach (array_slice($report, 1) as $i => $extra) {
            $rows['report_extra_'.($i + 1)] = [(string) $extra, '', 'legacy extra Report JID'];
        }

        $table = [];
        foreach ($rows as $role => [$jid, $label, $source]) {
            $table[] = [$role, $this->mask($jid), $label === '' ? '-' : $label, $source];
        }
        $this->table(['Role', 'JID (masked)', 'Label', 'Source'], $table);

        $conflicts = [];
        foreach ($writer->violations($s) as $v) {
            $conflicts[] = 'RULE '.$v;
        }
        // Same-group pairs (informational: the same group used by two roles, PSB included).
        $keys = array_keys($rows);
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $a = $rows[$keys[$i]][0];
                $b = $rows[$keys[$j]][0];
                if (WaGroupGuards::isGroupJid($a) && WaGroupGuards::isGroupJid($b) && WaGroupGuards::same($a, $b)) {
                    $conflicts[] = 'SAME GROUP '.$keys[$i].' = '.$keys[$j].' ('.WaGroupJid::mask($a).')';
                }
            }
        }

        if ($conflicts === []) {
            $this->info('Conflicts: none');
        } else {
            $this->warn('Conflicts: '.count($conflicts));
            foreach ($conflicts as $c) {
                $this->line('  '.$c);
            }
        }

        return self::SUCCESS;
    }

    private function mask(string $jid): string
    {
        if ($jid === '') {
            return 'kosong';
        }
        if (WaGroupGuards::isPersonalJid($jid)) {
            return 'pribadi:'.substr(md5(strtolower($jid)), 0, 8);
        }

        return WaGroupJid::mask($jid);
    }
}
