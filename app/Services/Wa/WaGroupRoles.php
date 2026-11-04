<?php

namespace App\Services\Wa;

use App\Models\Setting;
use App\Services\ConnectivityAlertSettings;
use App\Services\Outage\OutageSettings;
use App\Services\Report\ReportGroupRegistry;
use App\Support\WaGroupJid;

/**
 * Read model of every WhatsApp group "role" shown on the Grup WhatsApp page, from the same
 * settings the senders read. Editable roles: report, ops_alert, shadow, undelivered.
 * Read-only roles: PSB/migration groups, technician share group, global #rekap PSB switch.
 */
class WaGroupRoles
{
    public const SECTIONS = ['report', 'ops_alert', 'shadow', 'undelivered', 'ticket', 'connectivity', 'attendance', 'technician'];

    public const TECH_SHARE_LABEL_DEFAULT = 'SKYNET REPORT';

    public const TECH_INPUT_LABEL_DEFAULT = 'KERJAAN';

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $registry = app(ReportGroupRegistry::class);
        $reportJids = $registry->groupJids();

        $opsRow = trim((string) Setting::get('wa_report_group_jid', ''));
        $opsEnv = trim((string) config('services.evolution_api.ops_group_id', ''));
        $ticketRow = WaGroupJid::normalize((string) Setting::where('key', 'wa_ticket_group_jid')->value('value'));
        $connRow = WaGroupJid::normalize(trim((string) Setting::get('connectivity_alerts_group_id', '')));

        return [
            'report' => [
                'group_jid' => $reportJids[0] ?? '',
                'extra_jids' => array_slice($reportJids, 1),
                'channel' => $registry->channel(),
                'daily_enabled' => $registry->dailyEnabled(),
                'recap_cmd_enabled' => $this->flag('report_recap_cmd_enabled'),
                'pppoe_cmd_enabled' => $registry->pppoeCommandEnabled(),
                'onu_daily_enabled' => $registry->onuDailyEnabled(),
                'psb_detail_daily_enabled' => $registry->psbDetailDailyEnabled(),
                'onu_recap_cmd_enabled' => $this->flag('recap_onu_cmd_enabled'),
            ],
            'ops_alert' => [
                'jid' => WaGroupJid::normalize($opsRow),
                'effective_jid' => WaGroupJid::normalize($opsRow !== '' ? $opsRow : $opsEnv),
                'uses_env_default' => $opsRow === '' && $opsEnv !== '',
            ],
            'shadow' => [
                'jid' => WaGroupJid::normalize(OutageSettings::shadowLogJid()),
                'allow_report_group' => OutageSettings::shadowAllowReportGroup(),
            ],
            'undelivered' => [
                'jid' => WaGroupJid::normalize((string) Setting::get('report_undelivered_alert_jid', '')),
            ],
            'ticket' => [
                'jid' => $ticketRow,
                'effective_jid' => $this->effectiveTicketJid(),
                'uses_env_default' => $ticketRow === '' && WaGroupJid::normalize((string) config('services.evolution_api.ticket_group_id', '')) !== '',
            ],
            'connectivity' => [
                'jid' => $connRow,
                'effective_jid' => WaGroupJid::normalize((string) (app(ConnectivityAlertSettings::class)->groupId())),
                'uses_env_default' => $connRow === '' && WaGroupJid::normalize((string) config('services.connectivity_alerts.group_id', '')) !== '',
            ],
            'attendance' => [
                'jid' => WaGroupJid::normalize(trim((string) Setting::get('attendance_group_id', ''))),
                'label' => trim((string) Setting::get('attendance_group_label', '')),
            ],
            'technician' => [
                'share_jid' => WaGroupJid::normalize($this->scalarSetting('wa_ticket_progress_share_group_jid')),
                'share_label' => trim((string) Setting::get('wa_ticket_progress_share_group_label', self::TECH_SHARE_LABEL_DEFAULT)),
                'input_jid' => WaGroupGuards::normalizeTarget($this->scalarSetting('wa_ticket_progress_input_group_jid')),
                'input_label' => trim((string) Setting::get('wa_ticket_progress_input_group_label', self::TECH_INPUT_LABEL_DEFAULT)),
                // Channel that sends the auto reply into the Input chat (membership is probed on it); display only.
                'reply_channel' => strtolower(trim((string) Setting::get('ticket_progress_reply_channel', 'ticket'))) === 'ops' ? 'ops' : 'ticket',
            ],
            'readonly' => [
                'psb_group_jids' => $registry->psbGroupJids(),
                'ticket_group_jid' => $this->effectiveTicketJid(),
                'technician_share_jid' => WaGroupJid::normalize((string) Setting::get('wa_ticket_progress_share_group_jid', '')),
                'psb_recap_cmd_enabled' => $this->flag('psb_recap_cmd_enabled'),
                'overlap_with_psb' => $registry->overlapWithPsb(),
            ],
        ];
    }

    /** Hash of every editable value: a stale form (or a concurrent CLI edit) is rejected on save. */
    public function version(?array $snapshot = null): string
    {
        $s = $snapshot ?? $this->snapshot();

        return sha1((string) json_encode([
            $s['report'], $s['ops_alert']['jid'], $s['shadow'], $s['undelivered'],
            $s['ticket']['jid'], $s['connectivity']['jid'], $s['attendance'], $s['technician'],
        ]));
    }

    private function scalarSetting(string $key): string
    {
        $v = Setting::get($key, '');
        if (is_array($v)) {
            $v = $v[0] ?? '';
        }

        return trim((string) $v);
    }

    /** Effective ops alert group (Setting first, then env default), as the senders resolve it. */
    public function effectiveOpsJid(): string
    {
        return WaGroupJid::normalize((string) Setting::get('wa_report_group_jid', config('services.evolution_api.ops_group_id', '')));
    }

    /** Effective ticket group exactly as sendTicketClosedReportToGroup resolves it (setting, else env default). */
    public function effectiveTicketJid(): string
    {
        $row = Setting::where('key', 'wa_ticket_group_jid')->value('value');

        return WaGroupJid::normalize((string) ($row ?: config('services.evolution_api.ticket_group_id', '')));
    }

    private function flag(string $key): bool
    {
        return in_array(Setting::get($key, 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }
}
