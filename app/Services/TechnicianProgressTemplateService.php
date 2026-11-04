<?php

namespace App\Services;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TechnicianDailyTask;
use App\Models\TechnicianTeam;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TechnicianProgressTemplateService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?string $date = null, ?string $manualText = null, ?string $notes = null, bool $includeLegacy = true): array
    {
        $workDate = $date ? Carbon::parse($date, 'Asia/Jakarta') : now('Asia/Jakarta');
        $manual = $manualText ?? (string) Setting::get('ticket_progress_manual_text', $this->defaultManualText());
        $dailyNotes = $notes ?? (string) Setting::get('ticket_progress_notes', $this->defaultNotes());
        $ticketJobs = $this->ticketJobs($workDate);
        $psbJobs = $this->psbJobs($workDate);
        $manualWaJobs = $this->manualWaJobs($workDate);
        $legacySnapshot = $includeLegacy && (bool) Setting::get('ticket_progress_legacy_scraper_enabled', true)
            ? app(LegacyProgressTaskService::class)->fetchActiveTasks($workDate)
            : ['jobs' => [], 'errors' => [], 'endpoints' => []];
        $legacyJobs = $legacySnapshot['jobs'] ?? [];
        $plannedTeams = (bool) Setting::get('ticket_progress_auto_area_enabled', true)
            ? app(TechnicianTeamPlannerService::class)->plan($this->plannerJobs($ticketJobs, $psbJobs, $legacyJobs, $manualWaJobs), $workDate->toDateString())
            : [];
        $plannedTeams = $this->applyTeamRosterOverrides($plannedTeams, $workDate);

        return [
            'date' => $workDate->toDateString(),
            'share_group_jid' => (string) Setting::get('wa_ticket_progress_share_group_jid', ''),
            'input_group_jid' => (string) Setting::get('wa_ticket_progress_input_group_jid', ''),
            'share_group_label' => (string) Setting::get('wa_ticket_progress_share_group_label', 'SKYNET REPORT'),
            'input_group_label' => (string) Setting::get('wa_ticket_progress_input_group_label', 'KERJAAN'),
            'preview_time' => (string) Setting::get('ticket_progress_preview_time', '21:00'),
            'morning_send_time' => (string) Setting::get('ticket_progress_morning_send_time', '08:00'),
            'summary_times' => (string) Setting::get('ticket_progress_summary_times', '12:00,15:00,17:00'),
            'auto_send_enabled' => (bool) Setting::get('ticket_progress_auto_send_enabled', false),
            'summary_enabled' => (bool) Setting::get('ticket_progress_summary_enabled', false),
            'dry_run_enabled' => (bool) Setting::get('ticket_progress_dry_run_enabled', true),
            'reply_enabled' => (bool) Setting::get('ticket_progress_reply_enabled', false),
            'reply_channel' => (string) Setting::get('ticket_progress_reply_channel', 'ticket'),
            'photo_archive_enabled' => (bool) Setting::get('ticket_progress_photo_archive_enabled', true),
            'photo_attach_minutes' => (int) Setting::get('ticket_progress_photo_attach_minutes', 90),
            'auto_area_enabled' => (bool) Setting::get('ticket_progress_auto_area_enabled', true),
            'legacy_scraper_enabled' => (bool) Setting::get('ticket_progress_legacy_scraper_enabled', true),
            'manual_text' => $manual,
            'notes' => $dailyNotes,
            'manual_wa_jobs' => $manualWaJobs,
            'ticket_jobs' => $ticketJobs,
            'psb_jobs' => $psbJobs,
            'legacy_jobs' => $legacyJobs,
            'legacy_errors' => $legacySnapshot['errors'] ?? [],
            'legacy_endpoints' => $legacySnapshot['endpoints'] ?? [],
            'planned_teams' => $plannedTeams,
            'message' => $this->buildMessage($workDate, $manual, $dailyNotes, $ticketJobs, $psbJobs, $legacyJobs, $plannedTeams, $manualWaJobs),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ticketJobs
     * @param  list<array<string, mixed>>  $psbJobs
     * @param  list<array<string, mixed>>  $legacyJobs
     * @param  list<array<string, mixed>>  $plannedTeams
     * @param  list<array<string, mixed>>  $manualWaJobs
     */
    public function buildMessage(
        Carbon $date,
        string $manualText,
        string $notes,
        array $ticketJobs = [],
        array $psbJobs = [],
        array $legacyJobs = [],
        array $plannedTeams = [],
        array $manualWaJobs = []
    ): string {
        $message = "*JADWAL TEAM TEKNISI SKYNET MALANG*\n";
        $message .= $this->indoDay($date).', '.$date->format('j-n-Y')."\n\n";

        $message .= "*PEMBAGIAN TEAM TEKNISI*\n";
        $message .= $plannedTeams === []
            ? "- Belum ada pembagian team dari ticket/PSB/scraper\n"
            : collect($plannedTeams)->map(function ($team) {
                $label = (string) ($team['team_label'] ?? (($team['team_no'] ?? null) ? 'Team '.$team['team_no'] : 'Team'));
                $warning = (string) data_get($team, 'meta.roster_warning', '');

                return sprintf(
                    "%s\n%s%s\nSite : %s\nTask : %s\nProgress : %s",
                    $label,
                    $this->teamTechnicianText($team),
                    $warning !== '' ? "\nKekurangan team : ".$warning : '',
                    $team['site'] ?? '-',
                    $team['task'] ?? '-',
                    $team['progress'] ?? '-'
                );
            })->implode("\n\n")."\n";

        $message .= "\n*NOTE DAILY*\n";
        $message .= trim($notes) !== '' ? trim($notes) : trim($this->defaultNotes());

        return $message;
    }

    /**
     * Return the only snapshot text allowed to be shared to the report group.
     *
     * Legacy scraper diagnostics remain available in the snapshot for the
     * dashboard and internal troubleshooting, but must never be copied into a
     * WhatsApp report message.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function messageForShare(array $snapshot): string
    {
        $message = trim((string) ($snapshot['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        $errors = [];
        foreach ((array) ($snapshot['legacy_errors'] ?? []) as $error) {
            if (is_scalar($error)) {
                $error = trim((string) $error);
                if ($error !== '') {
                    $errors[] = $error;
                }
            }
        }

        if ($errors === []) {
            return $message;
        }

        $lines = preg_split('/\R/u', $message) ?: [];
        $safeLines = array_filter($lines, static function (string $line) use ($errors): bool {
            foreach ($errors as $error) {
                if (str_contains($line, $error)) {
                    return false;
                }
            }

            return true;
        });

        return trim(implode("\n", $safeLines));
    }

    public function defaultManualText(): string
    {
        return <<<'TXT'
Team 1
WIDI & FIKRI
Site : SUKO
Task : STANDBY PSB RW 06 & 04
Progress : STANDBY PSB

Team 2
REHAN, VALNO
Site : SUKO
Task : STANDBY PSB RW 06 & 04
Progress : STANDBY PSB

Team 3
ROBI & DIMAS
Site : SUKO
Task : STANDBY PSB RW 06 & 04
Progress : STANDBY PSB

Team 4
RAFLY & FATKAH
Site : KALIREJO
Task : DEPLOY ODP 0/8
Progress : DEPLOY

Team 5
TYO & UPIN
Site : SUKO
Task : DEPLOY ODP 0/1
Progress : DEPLOY

Team 6
DAPPA & ROY
Site : CAPANG
Task : PSB CSR 0/4
Progress : CSR

Team 7
JAZULI, BAIM & IPIN
Site : OPEN TICKET AREA
Task : MAINTENANCE & PSB
Progress : CLOSED TICKET

Team 8
STANDBY
Site : -
Task : -
Progress : STANDBY
TXT;
    }

    public function defaultNotes(): string
    {
        return <<<'TXT'
- PRIORITASKAN TASK MASING-MASING
- DOKUMENTASI & REPORT WAJIB
- UNTUK TEAM DEPLOY JIKA TASK TEAM SELESAI BISA SALING BACKUP
TXT;
    }

    /**
     * @return list<array{code: string, assignee: string, site: string, task: string, progress: string}>
     */
    protected function ticketJobs(Carbon $date): array
    {
        return SupportTicket::query()
            ->with(['assignee:id,name', 'customer.area:id,name'])
            ->whereIn('status', ['open', 'assigned', 'in_progress', 'pending'])
            ->where(function ($q) use ($date) {
                [$from, $to] = OpsTime::dayBounds($date);
                $q->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('updated_at', [$from, $to])
                    ->orWhereBetween('due_at', [$from, $to]);
            })
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (SupportTicket $ticket) => [
                'code' => (string) $ticket->code,
                'assignee' => (string) ($ticket->assignee?->name ?: 'BELUM ASSIGN'),
                'customer_name' => (string) ($ticket->customer?->name ?: $ticket->customer_name ?: ''),
                'site' => (string) ($ticket->customer?->area?->name ?: $ticket->customer_name ?: '-'),
                'task' => Str::upper(Str::limit((string) ($ticket->gangguan ?: $ticket->subject ?: 'MAINTENANCE'), 70, '')),
                'progress' => Str::upper(str_replace('_', ' ', (string) $ticket->status)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{code: string, assignee: string, site: string, task_type: string, task: string, progress: string}>
     */
    protected function manualWaJobs(Carbon $date): array
    {
        return TechnicianDailyTask::query()
            ->whereDate('work_date', $date->toDateString())
            ->where('source', 'manual_wa')
            ->whereNotIn('status', ['close', 'closed', 'cancelled', 'canceled'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (TechnicianDailyTask $task) => [
                'code' => 'WA-'.$task->id,
                'team_no' => $task->team_no ? (int) $task->team_no : null,
                'assignee' => (string) ($task->team_label ?: (implode(',', $task->technicians ?? []) ?: 'BELUM BAGI TEAM')),
                'technicians' => is_array($task->technicians) ? $task->technicians : [],
                'site' => Str::upper((string) ($task->site ?: '-')),
                'task_type' => (string) ($task->task_type ?: 'manual'),
                'task' => Str::upper(Str::limit((string) $task->task, 90, '')),
                'progress' => Str::upper((string) ($task->progress ?: str_replace('_', ' ', $task->status ?: 'DRAFT'))),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $plannedTeams
     * @return list<array<string, mixed>>
     */
    protected function applyTeamRosterOverrides(array $plannedTeams, Carbon $date): array
    {
        $dbTeams = TechnicianTeam::query()
            ->with('members')
            ->whereDate('work_date', $date->toDateString())
            ->get();

        $overrides = $dbTeams->mapWithKeys(fn (TechnicianTeam $team) => [
            (int) $team->team_no => $team->members
                ->pluck('name')
                ->map(fn ($name) => Str::upper((string) $name))
                ->filter()
                ->values()
                ->all(),
        ])
            ->filter(fn (array $members) => $members !== [])
            ->all();

        $defaultTeams = [
            1 => ['technicians' => ['WIDI', 'FIKRI'], 'site' => 'SUKO', 'task' => 'STANDBY PSB SUKO TARGET 20/HARI'],
            2 => ['technicians' => ['REHAN', 'VALNO'], 'site' => 'SUKO', 'task' => 'STANDBY PSB SUKO TARGET 20/HARI'],
            3 => ['technicians' => ['ROBI', 'DIMAS'], 'site' => 'SUKO', 'task' => 'STANDBY PSB SUKO TARGET 20/HARI'],
            4 => ['technicians' => ['DAPPA', 'FAHRI'], 'site' => 'KALIREJO', 'task' => 'DEPLOY ODP KALIREJO'],
            5 => ['technicians' => ['TYO', 'UPIN'], 'site' => 'SUKO', 'task' => 'DEPLOY ODP SUKO'],
            6 => ['technicians' => ['RIDWAN', 'ROY'], 'site' => 'CAPANG', 'task' => 'PELURUSAN ODC CAPANG'],
        ];

        $existingTeamNos = collect($plannedTeams)->pluck('team_no')->map(fn ($n) => (int) $n)->all();

        // 1. Pastikan Team 1-6 default selalu hadir jika belum terisi oleh task planner
        foreach ($defaultTeams as $tNo => $def) {
            if (! in_array($tNo, $existingTeamNos, true)) {
                $members = $overrides[$tNo] ?? $def['technicians'];
                $plannedTeams[] = [
                    'team_no' => $tNo,
                    'team_label' => 'Team '.$tNo,
                    'technicians' => $members,
                    'site' => $def['site'],
                    'task_type' => 'standby',
                    'task' => $def['task'],
                    'progress' => 'STANDBY',
                    'priority' => 'normal',
                    'source' => 'default_planner',
                    'source_ref' => 'default:team:'.$tNo,
                    'meta' => [],
                ];
                $existingTeamNos[] = $tNo;
            }
        }

        // 2. Pastikan team dari DB (Team 7, Team 8+) juga masuk ke plannedTeams
        foreach ($dbTeams as $dbTeam) {
            $tNo = (int) $dbTeam->team_no;
            if ($tNo > 0 && ! in_array($tNo, $existingTeamNos, true)) {
                $members = $overrides[$tNo] ?? [];
                $plannedTeams[] = [
                    'team_no' => $tNo,
                    'team_label' => 'Team '.$tNo,
                    'technicians' => $members,
                    'site' => $dbTeam->site ?: 'OPEN TICKET AREA',
                    'task_type' => 'manual',
                    'task' => 'Daftar team dari WA KERJAAN',
                    'progress' => 'DRAFT',
                    'priority' => 'normal',
                    'source' => 'manual_wa',
                    'source_ref' => 'db:team:'.$tNo,
                    'meta' => [],
                ];
                $existingTeamNos[] = $tNo;
            }
        }

        // Sort per team_no
        usort($plannedTeams, fn ($a, $b) => ($a['team_no'] ?? 99) <=> ($b['team_no'] ?? 99));

        $used = [];
        foreach ($plannedTeams as &$team) {
            $teamNo = (int) ($team['team_no'] ?? 0);
            $members = array_values(array_unique($overrides[$teamNo] ?? ($team['technicians'] ?? [])));
            $members = array_values(array_filter($members, function ($name) use (&$used) {
                $name = Str::upper((string) $name);
                if ($name === '' || in_array($name, $used, true)) {
                    return false;
                }

                $used[] = $name;

                return true;
            }));

            $team['technicians'] = $members;
            if ($teamNo > 0 && count($members) < 2) {
                $team['meta']['roster_warning'] = $members === []
                    ? 'Team '.$teamNo.' belum ada teknisi. Balas: team '.$teamNo.' NAMA1 NAMA2'
                    : 'Team '.$teamNo.' baru 1 orang ('.$members[0].'). Tambah: team '.$teamNo.' nambah NAMA';
            } else {
                unset($team['meta']['roster_warning']);
            }
        }
        unset($team);

        return $plannedTeams;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    protected function teamTechnicianText(array $team): string
    {
        $members = is_array($team['technicians'] ?? null)
            ? array_values(array_filter(array_map(fn ($name) => trim((string) $name), $team['technicians'])))
            : [];

        return $members === [] ? 'BELUM ADA TEKNISI' : implode(' & ', $members);
    }

    /**
     * @return list<array{id: int, name: string, site: string, task: string, progress: string}>
     */
    protected function psbJobs(Carbon $date): array
    {
        return PsbDraft::query()
            ->with(['area:id,name'])
            ->where('status', '!=', PsbDraft::STATUS_CANCELLED)
            ->where(function ($q) use ($date) {
                [$from, $to] = OpsTime::dayBounds($date);
                $q->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('applied_at', [$from, $to])
                    ->orWhereBetween('secret_at', [$from, $to])
                    ->orWhereBetween('olt_at', [$from, $to]);
            })
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (PsbDraft $draft) => [
                'id' => (int) $draft->id,
                'name' => Str::upper(Str::limit((string) ($draft->full_name ?: 'PSB'), 40, '')),
                'site' => Str::upper((string) ($draft->area?->name ?: $draft->daerah ?: $draft->site_profile_key ?: '-')),
                'task' => Str::upper('PSB '.($draft->pppoe_user ?: $draft->sn ?: 'BARU')),
                'progress' => Str::upper(str_replace('_', ' ', (string) $draft->status)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $ticketJobs
     * @param  list<array<string, mixed>>  $psbJobs
     * @param  list<array<string, mixed>>  $legacyJobs
     * @param  list<array<string, mixed>>  $manualWaJobs
     * @return list<array<string, mixed>>
     */
    protected function plannerJobs(array $ticketJobs, array $psbJobs, array $legacyJobs, array $manualWaJobs = []): array
    {
        $jobs = [];
        foreach ($manualWaJobs as $job) {
            $jobs[] = [
                'source' => 'manual_wa',
                'source_ref' => 'manual:'.($job['code'] ?? md5(json_encode($job) ?: 'manual')),
                'task_type' => $job['task_type'] ?? 'manual',
                'team_no' => $job['team_no'] ?? null,
                'technicians' => $job['technicians'] ?? [],
                'priority' => 'normal',
                'customer_name' => '',
                'site' => $job['site'] ?? '',
                'task' => $job['task'] ?? '',
                'progress' => $job['progress'] ?? '',
            ];
        }
        foreach ($ticketJobs as $job) {
            $jobs[] = [
                'source' => 'local_ticket',
                'source_ref' => 'ticket:'.($job['code'] ?? md5(json_encode($job) ?: 'ticket')),
                'task_type' => 'maintenance',
                'priority' => 'normal',
                'customer_name' => $job['customer_name'] ?? '',
                'site' => $job['site'] ?? '',
                'task' => $job['task'] ?? '',
                'progress' => $job['progress'] ?? '',
            ];
        }
        foreach ($psbJobs as $job) {
            $jobs[] = [
                'source' => 'local_psb',
                'source_ref' => 'psb:'.($job['id'] ?? md5(json_encode($job) ?: 'psb')),
                'task_type' => 'psb',
                'priority' => 'normal',
                'customer_name' => $job['name'] ?? '',
                'site' => $job['site'] ?? '',
                'task' => $job['task'] ?? '',
                'progress' => $job['progress'] ?? '',
            ];
        }

        return array_values(array_merge($jobs, $legacyJobs));
    }

    protected function indoDay(Carbon $date): string
    {
        return [
            0 => 'MINGGU',
            1 => 'SENIN',
            2 => 'SELASA',
            3 => 'RABU',
            4 => 'KAMIS',
            5 => 'JUMAT',
            6 => 'SABTU',
        ][(int) $date->dayOfWeek] ?? strtoupper($date->format('l'));
    }
}
