<?php

namespace App\Services\Report;

use App\Models\Setting;
use App\Services\Recap\RecapTopics;
use App\Support\WaGroupJid;

/**
 * The "Skynet Report" WA group(s): settings only, no I/O. An empty `report_wa_group_ids`
 * means the whole feature is off. A JID that is also in `psb_wa_group_ids` is an
 * overlap (misconfiguration): the PSB path wins as before and Report features skip it.
 */
class ReportGroupRegistry
{
    public const CHANNELS = ['ops', 'ticket'];

    /** @return list<string> normalised, unique */
    public function groupJids(): array
    {
        return $this->readJidList('report_wa_group_ids');
    }

    /** @return list<string> */
    public function psbGroupJids(): array
    {
        return $this->readJidList('psb_wa_group_ids');
    }

    public function isReportGroup(string $jid): bool
    {
        return $this->inList($jid, $this->groupJids());
    }

    public function isPsbGroup(string $jid): bool
    {
        return $this->inList($jid, $this->psbGroupJids());
    }

    /** Report JIDs that are also PSB/migration groups (must be fixed by the owner). @return list<string> */
    public function overlapWithPsb(): array
    {
        $out = [];
        foreach ($this->groupJids() as $jid) {
            if ($this->isPsbGroup($jid)) {
                $out[] = $jid;
            }
        }

        return $out;
    }

    /** Report groups that may receive the daily report / recap replies. @return list<string> */
    public function sendableGroupJids(): array
    {
        return array_values(array_diff($this->groupJids(), $this->overlapWithPsb()));
    }

    /** 'ops' or 'ticket' (anything else falls back to ops). */
    public function channel(): string
    {
        $channel = strtolower(trim((string) Setting::get('report_wa_channel', 'ops')));

        return in_array($channel, self::CHANNELS, true) ? $channel : 'ops';
    }

    public function dailyEnabled(): bool
    {
        return $this->flag('report_daily_enabled');
    }

    /** "#rekap psb" inside the Report group: needs its own flag AND the global recap kill switch. */
    public function commandsEnabled(): bool
    {
        return $this->flag('report_recap_cmd_enabled') && $this->flag('psb_recap_cmd_enabled');
    }

    /** "#rekap <topic>" (non-psb topics) inside the Report group: the Report command flag AND the topic's own flag. */
    public function topicCommandEnabled(string $topic): bool
    {
        $flag = RecapTopics::FLAGS[$topic] ?? null;

        return $flag !== null && $this->flag('report_recap_cmd_enabled') && $this->flag($flag);
    }

    /** "Kesehatan ONU" section of the daily report (default OFF). */
    public function onuDailyEnabled(): bool
    {
        return $this->flag('report_daily_onu_enabled');
    }

    /** "Draft PSB dan kendala" section of the daily report (default OFF). */
    public function psbDetailDailyEnabled(): bool
    {
        return $this->flag('report_daily_psb_detail_enabled');
    }

    /** A draft older than this many days counts as stuck (Setting report_psb_stuck_days, default 3, clamp 1..14). */
    public function psbStuckDays(): int
    {
        $days = (int) Setting::get('report_psb_stuck_days', PsbDailyDetailStats::DEFAULT_STUCK_DAYS);

        return max(1, min(14, $days > 0 ? $days : PsbDailyDetailStats::DEFAULT_STUCK_DAYS));
    }

    /**
     * Migration auto-reconcile details in "#rekap migrasi" and the daily "Migrasi" section
     * ("status DB a + otomatis b", ONU already online, perlu dicek admin). Default OFF.
     */
    public function migrationEvidenceEnabled(): bool
    {
        return $this->flag('recap_migration_olt_evidence_enabled');
    }

    /** "#pppoe cari" inside the Report group: its own flag only (default OFF). */
    public function pppoeCommandEnabled(): bool
    {
        return $this->flag('report_pppoe_cmd_enabled');
    }

    private function flag(string $key): bool
    {
        return in_array(Setting::get($key, 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * @return list<string>
     */
    private function readJidList(string $key): array
    {
        $raw = Setting::get($key, []);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $split = preg_split('/[\s,]+/', $raw);
                $raw = is_array($split) ? $split : [];
            }
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $jid) {
            $n = WaGroupJid::normalize((string) $jid);
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $list
     */
    private function inList(string $jid, array $list): bool
    {
        $jid = WaGroupJid::normalize($jid);
        if ($jid === '' || $list === []) {
            return false;
        }
        if (in_array($jid, $list, true)) {
            return true;
        }
        $bare = explode('@', $jid, 2)[0];
        foreach ($list as $candidate) {
            if (explode('@', $candidate, 2)[0] === $bare) {
                return true;
            }
        }

        return false;
    }
}
