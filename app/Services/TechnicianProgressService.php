<?php

namespace App\Services;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\TechnicianDailySchedule;
use App\Models\TechnicianDailyTask;
use App\Models\TechnicianMember;
use App\Models\TechnicianProgressPhoto;
use App\Models\TechnicianProgressReport;
use App\Models\TechnicianTeam;
use App\Models\TechnicianTeamMember;
use App\Models\TechnicianWaCommandLog;
use App\Services\Wa\WaGroupGuards;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TechnicianProgressService
{
    public function __construct(protected TechnicianProgressParser $parser) {}

    protected function getJidSetting(string $key, string $default = ''): string
    {
        $val = Setting::get($key, $default);
        if (is_array($val)) {
            $val = $val[0] ?? $default;
        }

        return (string) $val;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'share_group_jid' => $this->getJidSetting('wa_ticket_progress_share_group_jid', ''),
            'share_group_label' => (string) Setting::get('wa_ticket_progress_share_group_label', 'SKYNET REPORT'),
            'input_group_jid' => $this->getJidSetting('wa_ticket_progress_input_group_jid', ''),
            'input_group_label' => (string) Setting::get('wa_ticket_progress_input_group_label', 'KERJAAN'),
            'preview_time' => (string) Setting::get('ticket_progress_preview_time', '21:00'),
            'morning_send_time' => (string) Setting::get('ticket_progress_morning_send_time', '08:00'),
            'summary_times' => (string) Setting::get('ticket_progress_summary_times', '12:00,17:00,20:30'),
            'auto_send_enabled' => (bool) Setting::get('ticket_progress_auto_send_enabled', false),
            'summary_enabled' => (bool) Setting::get('ticket_progress_summary_enabled', false),
            'dry_run_enabled' => (bool) Setting::get('ticket_progress_dry_run_enabled', true),
            'reply_enabled' => (bool) Setting::get('ticket_progress_reply_enabled', false),
            'reply_channel' => (string) Setting::get('ticket_progress_reply_channel', 'ticket'),
            'photo_archive_enabled' => (bool) Setting::get('ticket_progress_photo_archive_enabled', true),
            'photo_attach_minutes' => (int) Setting::get('ticket_progress_photo_attach_minutes', 90),
            'auto_area_enabled' => (bool) Setting::get('ticket_progress_auto_area_enabled', true),
            'legacy_scraper_enabled' => (bool) Setting::get('ticket_progress_legacy_scraper_enabled', true),
        ];
    }

    public function isInputGroup(string $groupJid): bool
    {
        $configured = $this->normalizeInputJid($this->getJidSetting('wa_ticket_progress_input_group_jid', ''));
        $incoming = $this->normalizeInputJid($groupJid);

        return $configured !== '' && $incoming === $configured;
    }

    public function isShareGroup(string $groupJid): bool
    {
        $configured = $this->normalizeInputJid($this->getJidSetting('wa_ticket_progress_share_group_jid', ''));
        $incoming = $this->normalizeInputJid($groupJid);

        return $configured !== '' && $incoming === $configured;
    }

    public function isObservedGroup(string $groupJid): bool
    {
        return $this->isInputGroup($groupJid) || $this->isShareGroup($groupJid);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhookMessage(array $msg, string $pushName): array
    {
        $remoteJid = (string) ($msg['key']['remoteJid'] ?? '');
        $isInputGroup = $this->isInputGroup($remoteJid);
        $isShareGroup = $this->isShareGroup($remoteJid);
        if (! empty($msg['key']['fromMe']) && ! $isInputGroup) {
            return ['handled' => false, 'reason' => 'from_me'];
        }
        if (! $isInputGroup && ! $isShareGroup) {
            return ['handled' => false, 'reason' => 'wrong_group'];
        }

        $messageContent = $this->unwrapMessageContent($msg['message'] ?? []);
        $text = $this->extractText($messageContent);
        $hasImage = $this->hasImage($messageContent);
        $messageId = (string) ($msg['key']['id'] ?? '');
        $senderJid = (string) (
            $msg['key']['participantAlt']
            ?? $msg['key']['participantPn']
            ?? $msg['key']['participant']
            ?? $remoteJid
        );
        if ($senderJid !== '' && ! str_contains($senderJid, '@')) {
            $senderJid .= '@s.whatsapp.net';
        }

        $fallbackDate = isset($msg['messageTimestamp'])
            ? Carbon::createFromTimestamp((int) $msg['messageTimestamp'], 'Asia/Jakarta')
            : now('Asia/Jakarta');
        $dryRun = (bool) Setting::get('ticket_progress_dry_run_enabled', true);
        $textForParse = $this->applyLanguageMemory($text);
        $isTrigger = $this->parser->isTrigger($textForParse);
        $dedupeId = $messageId !== ''
            ? $messageId
            : 'generated:'.md5($remoteJid.'|'.$senderJid.'|'.$fallbackDate->toDateString().'|'.$text);

        $hasExplicitHash = str_starts_with(trim($text), '#');

        if ($isInputGroup && ! $hasImage && ! $hasExplicitHash) {
            $aiParsed = app(GeminiFlashService::class)->parseKerjaanMessage($text, [
                'work_date' => $fallbackDate->toDateString(),
                'sender_name' => $pushName,
            ]);

            if ($aiParsed && ($aiParsed['kind'] ?? 'unknown') !== 'unknown') {
                $parsed = $aiParsed;
                $isTrigger = true;
            } else {
                $parsed = $this->parser->parse($textForParse, $fallbackDate);
            }
        } else {
            $parsed = $isTrigger ? $this->parser->parse($textForParse, $fallbackDate) : [
                'kind' => 'photo',
                'command' => 'image',
                'action' => 'archive_progress_photo',
                'work_date' => $fallbackDate->toDateString(),
                'raw_text' => $text,
            ];
        }

        if ($isTrigger && $textForParse !== $text) {
            $parsed['meta'] = array_merge(is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [], [
                'language_memory_original_text' => $text,
                'language_memory_normalized_text' => $textForParse,
            ]);
        }
        if (($parsed['kind'] ?? null) === 'unknown' && $isInputGroup && ! $hasImage && $this->shouldAskLanguageMemory($text)) {
            return $this->askLanguageMemory($remoteJid, $senderJid, $pushName, $dedupeId, $text, $fallbackDate, $dryRun);
        }
        if ($isShareGroup && ! in_array((string) ($parsed['kind'] ?? ''), ['progress', 'photo'], true)) {
            return ['handled' => false, 'reason' => 'report_group_progress_only', 'parsed' => $parsed];
        }
        $workDate = (string) ($parsed['work_date'] ?? now('Asia/Jakarta')->toDateString());

        if (
            TechnicianWaCommandLog::query()->where('message_id', $dedupeId)->exists()
            || ($messageId !== '' && TechnicianProgressReport::query()->where('message_id', $dedupeId)->exists())
        ) {
            $duplicate = ['handled' => true, 'duplicate' => true, 'parsed' => $parsed];
            if ($hasImage && (bool) Setting::get('ticket_progress_photo_archive_enabled', true)) {
                $report = TechnicianProgressReport::query()->where('message_id', $dedupeId)->first();
                $duplicate['photo'] = $this->archiveProgressPhoto($msg, [
                    'work_date' => $workDate,
                    'group_jid' => $this->normalizeGroupJid($remoteJid),
                    'sender_jid' => $senderJid,
                    'sender_name' => $pushName,
                    'message_id' => $dedupeId,
                    'text' => $text,
                    'report_id' => $report?->id,
                    'task_id' => $report?->task_id,
                ]);
            }

            return $duplicate;
        }

        try {
            // Transactional: a concurrent duplicate that loses on the unique key rolls back every row it
            // already wrote for earlier batch items (photo archive / reply / command log stay outside).
            $stored = $isTrigger ? DB::transaction(fn () => $this->storeParsedInput($parsed, [
                'group_jid' => $this->normalizeGroupJid($remoteJid),
                'sender_jid' => $senderJid,
                'sender_name' => $pushName,
                'message_id' => $dedupeId,
                'dry_run' => $dryRun,
                'raw_text' => $text,
            ])) : ['handled' => false];
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent delivery of the same WhatsApp message: the other request already stored it.
            Log::info('[TechnicianProgress] duplicate message_id ignored (concurrent delivery)', [
                'message_id' => $dedupeId,
            ]);

            return ['handled' => true, 'duplicate' => true, 'parsed' => $parsed];
        }

        if ($hasImage && (bool) Setting::get('ticket_progress_photo_archive_enabled', true)) {
            $stored['photo'] = $this->archiveProgressPhoto($msg, [
                'work_date' => $workDate,
                'group_jid' => $this->normalizeGroupJid($remoteJid),
                'sender_jid' => $senderJid,
                'sender_name' => $pushName,
                'message_id' => $dedupeId,
                'text' => $text,
                'report_id' => $stored['report_id'] ?? null,
                'task_id' => $stored['task_id'] ?? null,
            ]);

            if (! $isTrigger && ! empty($stored['photo']['handled'])) {
                $stored['handled'] = true;
                if (! empty($stored['photo']['report_id'])) {
                    $stored['report_id'] = $stored['photo']['report_id'];
                }
                if (! empty($stored['photo']['task_id'])) {
                    $stored['task_id'] = $stored['photo']['task_id'];
                }
            }
        }

        $responsePreview = $this->responsePreview($parsed, $stored, $dryRun);
        $reply = $this->maybeReplyToInputGroup(
            $isInputGroup ? $this->normalizeGroupJid($remoteJid) : '',
            $responsePreview,
            $dryRun
        );

        TechnicianWaCommandLog::create([
            'work_date' => $workDate,
            'group_jid' => $this->normalizeGroupJid($remoteJid),
            'sender_jid' => $senderJid,
            'sender_name' => $pushName,
            'message_id' => $dedupeId,
            'command' => (string) ($parsed['command'] ?? $parsed['kind'] ?? 'unknown'),
            'action' => (string) ($parsed['action'] ?? 'ignored'),
            'is_dry_run' => $dryRun,
            'handled' => (bool) ($stored['handled'] ?? false),
            'response_preview' => $responsePreview,
            'payload' => ['parsed' => $parsed, 'stored' => $stored, 'reply' => $reply],
            'raw_text' => $text,
        ]);

        Log::info('[TechnicianProgress] WA input stored', [
            'group' => $remoteJid,
            'message_id' => $dedupeId,
            'kind' => $parsed['kind'] ?? null,
            'action' => $parsed['action'] ?? null,
            'dry_run' => $dryRun,
        ]);

        return ['handled' => true, 'parsed' => $parsed, 'stored' => $stored, 'dry_run' => $dryRun, 'reply' => $reply];
    }

    /**
     * @return array<string, mixed>
     */
    public function activitySnapshot(?string $date = null): array
    {
        $workDate = $date ? Carbon::parse($date, 'Asia/Jakarta')->toDateString() : now('Asia/Jakarta')->toDateString();

        return [
            'work_date' => $workDate,
            'tasks' => TechnicianDailyTask::query()
                ->whereDate('work_date', $workDate)
                ->orderBy('team_no')
                ->latest('id')
                ->limit(50)
                ->get(),
            'reports' => TechnicianProgressReport::query()
                ->whereDate('work_date', $workDate)
                ->orderBy('team_no')
                ->latest('id')
                ->limit(50)
                ->get(),
            'logs' => TechnicianWaCommandLog::query()
                ->whereDate('work_date', $workDate)
                ->latest('id')
                ->limit(30)
                ->get(),
            'photos' => TechnicianProgressPhoto::query()
                ->whereDate('work_date', $workDate)
                ->latest('id')
                ->limit(40)
                ->get(),
        ];
    }

    public function buildSummaryMessage(?string $date = null, ?string $at = null): string
    {
        $workDate = $date ? Carbon::parse($date, 'Asia/Jakarta')->toDateString() : now('Asia/Jakarta')->toDateString();
        $time = $at ?: now('Asia/Jakarta')->format('H:i');
        $tasks = TechnicianDailyTask::query()
            ->whereDate('work_date', $workDate)
            ->orderBy('team_no')
            ->get();
        $reports = TechnicianProgressReport::query()
            ->whereDate('work_date', $workDate)
            ->latest('created_at')
            ->get()
            ->groupBy(fn (TechnicianProgressReport $report) => (string) ($report->team_no ?: 'no-team'));
        $psbStats = $this->psbCompletionStats($workDate);

        $dateObj = Carbon::parse($workDate, 'Asia/Jakarta');
        $message = "*REKAP PROGRESS TEAM TEKNISI SKYNET*\n";
        $message .= $this->indoDay($dateObj).', '.$dateObj->format('j-n-Y').' - '.$time." WIB\n\n";
        $message .= "*RINGKASAN*\n";
        $message .= 'PSB OLT selesai: '.$psbStats['total'].$this->siteCountLine($psbStats['by_site'])."\n";
        $message .= 'Task aktif: '.$tasks->count().' team/job'."\n\n";

        foreach ($tasks as $task) {
            $key = (string) ($task->team_no ?: 'no-team');
            /** @var TechnicianProgressReport|null $latest */
            $latest = ($reports->get($key) ?? collect())->first();
            $techs = is_array($task->technicians) && $task->technicians !== []
                ? implode(' & ', $task->technicians)
                : ($task->team_label ?: '-');

            $message .= '*'.($task->team_no ? 'Team '.$task->team_no : 'Team').' - '.$techs."*\n";
            $message .= 'Site: '.($task->site ?: '-')."\n";
            $message .= 'Job: '.$task->task."\n";
            if ($latest) {
                $message .= 'Update: '.$this->listLine($latest->progress_items)."\n";
                $message .= 'Action: '.$this->listLine($latest->actions)."\n";
                $photoCount = $latest->photos()->count();
                if ($photoCount > 0) {
                    $message .= 'Foto: '.$photoCount." file arsip\n";
                }
                $message .= 'Status: '.($latest->status ?: '-')."\n";
                $constraints = $this->listLine($latest->constraints);
                if ($constraints !== '-') {
                    $message .= 'Kendala: '.$constraints."\n";
                }
                $materials = $this->listLine($latest->materials);
                if ($materials !== '-') {
                    $message .= 'Material: '.$materials."\n";
                }
            } else {
                $message .= "Update: belum ada report teknisi\n";
            }
            $message .= "\n";
        }

        $reportedTeams = $reports->keys()->all();
        $missing = $tasks
            ->filter(fn (TechnicianDailyTask $task) => $task->team_no && ! in_array((string) $task->team_no, $reportedTeams, true))
            ->pluck('team_no')
            ->unique()
            ->values()
            ->all();

        $message .= "*Belum report teknisi:*\n";
        $message .= $missing === []
            ? "- Semua team yang ada task sudah report\n"
            : collect($missing)->map(fn ($teamNo) => '- Team '.$teamNo)->implode("\n")."\n";

        return trim($message);
    }

    public function hasActivitySince(string $workDate, string $sinceTime): bool
    {
        $since = Carbon::parse($workDate.' '.$sinceTime, 'Asia/Jakarta')->utc();
        [$dayFrom, $dayTo] = OpsTime::dayBounds(Carbon::parse($workDate, OpsTime::TZ));

        return TechnicianProgressReport::query()
            ->whereDate('work_date', $workDate)
            ->where('created_at', '>=', $since)
            ->exists()
            || TechnicianProgressPhoto::query()
                ->whereDate('work_date', $workDate)
                ->where('created_at', '>=', $since)
                ->exists()
            || TechnicianDailyTask::query()
                ->whereDate('work_date', $workDate)
                ->where('updated_at', '>=', $since)
                ->exists()
            || PsbDraft::query()
                ->whereIn('status', [PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED])
                ->where(function ($q) use ($dayFrom, $dayTo) {
                    $q->whereBetween('olt_at', [$dayFrom, $dayTo])
                        ->orWhereBetween('applied_at', [$dayFrom, $dayTo]);
                })
                ->where(function ($q) use ($since) {
                    $q->where('olt_at', '>=', $since)
                        ->orWhere('applied_at', '>=', $since);
                })
                ->exists();
    }

    /**
     * @return array{total: int, by_site: array<string, int>}
     */
    protected function psbCompletionStats(string $workDate): array
    {
        [$dayFrom, $dayTo] = OpsTime::dayBounds(Carbon::parse($workDate, OpsTime::TZ));
        $rows = PsbDraft::query()
            ->with(['area:id,name'])
            ->whereIn('status', [PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED])
            ->where(function ($q) use ($dayFrom, $dayTo) {
                $q->whereBetween('olt_at', [$dayFrom, $dayTo])
                    ->orWhereBetween('applied_at', [$dayFrom, $dayTo]);
            })
            ->get();

        $bySite = [];
        foreach ($rows as $draft) {
            $site = Str::upper((string) ($draft->area?->name ?: $draft->daerah ?: $draft->site_profile_key ?: 'TANPA AREA'));
            $site = trim(preg_replace('/^SKYNET[-\s]*/u', '', $site) ?? $site) ?: 'TANPA AREA';
            $bySite[$site] = ($bySite[$site] ?? 0) + 1;
        }
        ksort($bySite);

        return ['total' => $rows->count(), 'by_site' => $bySite];
    }

    /**
     * @param  array<string, int>  $bySite
     */
    protected function siteCountLine(array $bySite): string
    {
        if ($bySite === []) {
            return '';
        }

        return ' ('.collect($bySite)
            ->map(fn (int $count, string $site) => $site.' '.$count)
            ->implode(', ').')';
    }

    /**
     * @param  list<array<string, mixed>>  $plannedTeams
     * @return array{created: int, updated: int}
     */
    public function materializePlannedTasks(string $workDate, array $plannedTeams): array
    {
        $schedule = $this->scheduleForDate($workDate);
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($plannedTeams as $plan) {
            $teamNo = isset($plan['team_no']) ? (int) $plan['team_no'] : null;
            $sourceRef = (string) ($plan['source_ref'] ?? '');
            if ($sourceRef === '') {
                $sourceRef = 'auto:t'.($teamNo ?: 'x').':'.md5(json_encode([
                    $plan['site'] ?? '',
                    $plan['task_type'] ?? '',
                ]) ?: '');
            }

            $task = TechnicianDailyTask::updateOrCreate(
                [
                    'work_date' => $workDate,
                    'source' => 'auto_planner',
                    'source_ref' => $sourceRef,
                ],
                [
                    'schedule_id' => $schedule->id,
                    'team_no' => $teamNo,
                    'team_label' => $plan['team_label'] ?? ($teamNo ? 'Team '.$teamNo : 'Team'),
                    'technicians' => $plan['technicians'] ?? [],
                    'site' => $plan['site'] ?? null,
                    'task_type' => $plan['task_type'] ?? 'maintenance',
                    'task' => $plan['task'] ?? 'Auto task',
                    'progress' => $plan['progress'] ?? null,
                    'status' => $plan['status'] ?? 'draft',
                    'priority' => $plan['priority'] ?? 'normal',
                    'raw_text' => null,
                    'meta' => $plan['meta'] ?? [],
                ]
            );

            $task->wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;
            $this->syncTeam(
                $workDate,
                $teamNo,
                $plan['technicians'] ?? [],
                $plan['site'] ?? null,
                $plan['status'] ?? 'draft'
            );
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function storeLanguageMemory(array $parsed, array $ctx): array
    {
        $alias = Str::lower($this->cleanMemoryTerm((string) ($parsed['alias'] ?? '')));
        $meaning = $this->cleanMemoryTerm((string) ($parsed['meaning'] ?? ''));
        if ($alias === '' || $meaning === '') {
            return ['handled' => false, 'reason' => 'memory_alias_or_meaning_empty'];
        }

        $items = array_values(array_filter(
            $this->languageMemory(),
            fn ($item) => Str::lower((string) ($item['alias'] ?? '')) !== $alias
        ));
        $items[] = [
            'alias' => $alias,
            'meaning' => $meaning,
            'added_at' => now('Asia/Jakarta')->toDateTimeString(),
            'added_by' => $ctx['sender_name'] ?? null,
            'message_id' => $ctx['message_id'] ?? null,
        ];

        Setting::set('ticket_progress_language_memory', $items, 'json', 'ticket', 'Ticket progress language memory');

        return ['handled' => true, 'alias' => $alias, 'meaning' => $meaning, 'count' => count($items)];
    }

    /**
     * @return list<array{alias: string, meaning: string}>
     */
    protected function languageMemory(): array
    {
        $items = Setting::get('ticket_progress_language_memory', []);
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_array($item)) {
                return null;
            }

            $alias = Str::lower($this->cleanMemoryTerm((string) ($item['alias'] ?? '')));
            $meaning = $this->cleanMemoryTerm((string) ($item['meaning'] ?? ''));

            return $alias !== '' && $meaning !== ''
                ? ['alias' => $alias, 'meaning' => $meaning]
                : null;
        }, $items)));
    }

    protected function applyLanguageMemory(string $text): string
    {
        $items = $this->languageMemory();
        usort($items, fn ($a, $b) => strlen((string) $b['alias']) <=> strlen((string) $a['alias']));

        foreach ($items as $item) {
            $alias = (string) ($item['alias'] ?? '');
            $meaning = (string) ($item['meaning'] ?? '');
            if ($alias === '' || $meaning === '') {
                continue;
            }
            if (in_array(Str::lower($alias), ['dismantle', 'dismantel', 'dismantling', 'bongkar', 'cabut'], true)) {
                continue;
            }

            $text = preg_replace('/(?<![\pL\pN_])'.preg_quote($alias, '/').'(?![\pL\pN_])/iu', $meaning, $text) ?? $text;
        }

        return $text;
    }

    protected function shouldAskLanguageMemory(string $text): bool
    {
        $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (strlen($plain) < 4 || preg_match('/https?:\/\//iu', $plain) === 1) {
            return false;
        }
        if (preg_match('/^(?:ok|oke|siap|gas|done|mantap|iya|ya|acc|thanks|terima kasih)$/iu', $plain) === 1) {
            return false;
        }

        return preg_match('/\b(?:team|tim|task|job|tugas|pekerjaan|jadwal|report|progres|progress|deploy|psb|maintenance|mtc|gangguan|pelurusan|terminasi|splicing|repair|dismantle|dismantel|dismantling|pemutusan|oemutusan|pemutisan|bongkar|cabut|odp|odc|site|area|tambah|nambah|hapus|batal|kirim|krim|fix|besok|hari ini|rw|rt|suko|gondang|kalirejo|capang|klampok|lawang)\b/iu', $plain) === 1;
    }

    protected function languageMemoryQuestion(string $text): string
    {
        $sample = Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 120, '');

        return "Aku belum paham format ini:\n\"{$sample}\"\n\n"
            ."Kalau ini istilah/format baru, balas:\n"
            ."*tambah memory: kata/format = maksudnya*\n\n"
            ."Contoh:\n"
            ."*tambah memory: pemvafuab = pembangunan*\n\n"
            .'Kalau cuma chat biasa, abaikan.';
    }

    protected function cleanMemoryTerm(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return trim(Str::limit($value, 120, ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function askLanguageMemory(
        string $remoteJid,
        string $senderJid,
        string $pushName,
        string $dedupeId,
        string $text,
        Carbon $fallbackDate,
        bool $dryRun
    ): array {
        if (TechnicianWaCommandLog::query()->where('message_id', $dedupeId)->exists()) {
            return ['handled' => true, 'duplicate' => true, 'reason' => 'language_memory_prompt'];
        }

        $responsePreview = $this->languageMemoryQuestion($text);
        $reply = $this->maybeReplyToInputGroup($this->normalizeGroupJid($remoteJid), $responsePreview, $dryRun);
        TechnicianWaCommandLog::create([
            'work_date' => $fallbackDate->toDateString(),
            'group_jid' => $this->normalizeGroupJid($remoteJid),
            'sender_jid' => $senderJid,
            'sender_name' => $pushName,
            'message_id' => $dedupeId,
            'command' => 'unknown_memory_prompt',
            'action' => 'ask_language_memory',
            'is_dry_run' => $dryRun,
            'handled' => true,
            'response_preview' => $responsePreview,
            'payload' => ['reply' => $reply],
            'raw_text' => $text,
        ]);

        return ['handled' => true, 'reason' => 'language_memory_prompt', 'reply' => $reply];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function storeParsedInput(array $parsed, array $ctx): array
    {
        $kind = (string) ($parsed['kind'] ?? 'unknown');
        $workDate = (string) ($parsed['work_date'] ?? now('Asia/Jakarta')->toDateString());

        if ($kind === 'language_memory') {
            return $this->storeLanguageMemory($parsed, $ctx);
        }

        if ($kind === 'task_cancel') {
            $cancelled = $this->cancelManualTasks($parsed, $ctx, $workDate);
            if (($cancelled['cancelled'] ?? 0) > 0) {
                $this->markScheduleDirty($workDate, 'wa_cancel', $ctx);
            }

            return $cancelled;
        }

        if ($kind === 'batch') {
            $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
            $storedItems = [];
            foreach ($items as $idx => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemCtx = array_merge($ctx, [
                    'message_id' => trim((string) ($ctx['message_id'] ?? '')).':'.($idx + 1),
                    'raw_text' => $item['raw_text'] ?? $ctx['raw_text'] ?? null,
                ]);
                $storedItems[] = $this->storeParsedInput($item, $itemCtx);
            }

            return [
                'handled' => $storedItems !== [],
                'batch_count' => count($storedItems),
                'task_ids' => collect($storedItems)->pluck('task_id')->filter()->values()->all(),
                'cancelled' => collect($storedItems)->sum(fn ($item) => (int) ($item['cancelled'] ?? 0)),
                'items' => $storedItems,
            ];
        }

        if ($kind === 'team_roster') {
            $stored = $this->replaceTeamRoster($parsed, $ctx, $workDate);
            if (! empty($stored['handled'])) {
                $this->markScheduleDirty($workDate, 'wa_team_roster', $ctx);
            }

            return $stored;
        }

        if ($kind === 'task') {
            $schedule = $this->scheduleForDate($workDate);
            $teamNo = $parsed['team_no'] ?? null;
            $technicians = is_array($parsed['technicians'] ?? null) ? $parsed['technicians'] : [];

            if (! $teamNo && $technicians !== []) {
                $upperTechs = array_map(fn ($n) => Str::upper((string) $n), $technicians);
                $foundMember = TechnicianTeamMember::whereIn('name', $upperTechs)
                    ->whereHas('team', fn ($q) => $q->whereDate('work_date', $workDate))
                    ->first();
                if ($foundMember) {
                    $teamNo = TechnicianTeam::find($foundMember->team_id)?->team_no;
                }
            }

            $this->syncTeam(
                $workDate,
                $teamNo,
                $technicians,
                $parsed['site'] ?? null,
                $parsed['status'] ?? 'draft'
            );
            $payload = [
                'schedule_id' => $schedule->id,
                'work_date' => $workDate,
                'team_no' => $teamNo,
                'team_label' => $teamNo ? 'Team '.(int) $teamNo : 'Team',
                'technicians' => $technicians,
                'site' => $parsed['site'] ?? null,
                'task_type' => $parsed['task_type'] ?? 'manual',
                'task' => $parsed['task'] ?? 'Manual task',
                'progress' => $parsed['progress'] ?? null,
                'status' => $parsed['status'] ?? 'draft',
                'priority' => $parsed['priority'] ?? 'normal',
                'source' => 'manual_wa',
                'source_ref' => $ctx['message_id'] ?? null,
                'raw_text' => $parsed['raw_text'] ?? $ctx['raw_text'] ?? null,
                'meta' => [
                    'sender' => $ctx['sender_name'] ?? null,
                    'sender_jid' => $ctx['sender_jid'] ?? null,
                    'parsed' => $parsed['meta'] ?? [],
                ],
            ];

            if (! empty($parsed['meta']['simple_input'])) {
                $payload['source_ref'] = $this->manualTaskSourceRef($workDate, $parsed);
                $task = TechnicianDailyTask::updateOrCreate(
                    ['work_date' => $workDate, 'source' => 'manual_wa', 'source_ref' => $payload['source_ref']],
                    $payload
                );
                $this->markScheduleDirty($workDate, 'wa_simple_input', $ctx);
            } else {
                $task = TechnicianDailyTask::create($payload);
            }

            return ['handled' => true, 'task_id' => $task->id];
        }

        if ($kind === 'progress') {
            $task = $this->matchTaskForReport($workDate, $parsed);
            $teamNo = $parsed['team_no'] ?? $task?->team_no;
            $teamLabel = $parsed['team_label'] ?? $task?->team_label;
            $technicians = $parsed['technicians'] ?? [];
            if ($technicians === [] && is_array($task?->technicians)) {
                $technicians = $task->technicians;
            }
            $this->syncTeam(
                $workDate,
                $teamNo,
                $technicians,
                $parsed['sites'][0] ?? null,
                $parsed['status'] ?? 'proses'
            );
            $report = TechnicianProgressReport::create([
                'task_id' => $task?->id,
                'work_date' => $workDate,
                'team_no' => $teamNo,
                'team_label' => $teamLabel,
                'group_jid' => $ctx['group_jid'] ?? null,
                'sender_jid' => $ctx['sender_jid'] ?? null,
                'sender_name' => $ctx['sender_name'] ?? null,
                'message_id' => $ctx['message_id'] ?? null,
                'technicians' => $technicians,
                'progress_items' => $parsed['progress_items'] ?? [],
                'sites' => $parsed['sites'] ?? [],
                'actions' => $parsed['actions'] ?? [],
                'photo_status' => $parsed['photo_status'] ?? [],
                'status' => $parsed['status'] ?? null,
                'constraints' => $parsed['constraints'] ?? [],
                'materials' => $parsed['materials'] ?? [],
                'note' => $parsed['note'] ?? null,
                'raw_text' => $parsed['raw_text'] ?? $ctx['raw_text'] ?? null,
                'meta' => $parsed['meta'] ?? [],
            ]);

            if ($task && ! empty($parsed['status'])) {
                $progressLine = $this->listLine($parsed['progress_items'] ?? []);
                $task->forceFill([
                    'status' => (string) $parsed['status'],
                    'progress' => $progressLine !== '-' ? $progressLine : $task->progress,
                ])->save();
            }

            return ['handled' => true, 'report_id' => $report->id, 'task_id' => $task?->id];
        }

        if ($kind === 'revision') {
            $revisionType = (string) ($parsed['revision_type'] ?? 'umum');
            $revisionText = (string) ($parsed['revision_text'] ?? 'Revisi');
            $report = TechnicianProgressReport::create([
                'task_id' => null,
                'work_date' => $workDate,
                'team_no' => $parsed['team_no'] ?? null,
                'team_label' => $parsed['team_label'] ?? null,
                'group_jid' => $ctx['group_jid'] ?? null,
                'sender_jid' => $ctx['sender_jid'] ?? null,
                'sender_name' => $ctx['sender_name'] ?? null,
                'message_id' => $ctx['message_id'] ?? null,
                'technicians' => [],
                'progress_items' => ['REVISI '.Str::upper($revisionType).': '.$revisionText],
                'sites' => $parsed['sites'] ?? [],
                'actions' => [],
                'photo_status' => [],
                'status' => 'revision',
                'constraints' => [],
                'materials' => [],
                'note' => $revisionText,
                'raw_text' => $parsed['raw_text'] ?? $ctx['raw_text'] ?? null,
                'meta' => [
                    'revision_type' => $revisionType,
                    'parsed' => $parsed['meta'] ?? [],
                    'sender' => $ctx['sender_name'] ?? null,
                ],
            ]);

            return ['handled' => true, 'report_id' => $report->id, 'revision_type' => $revisionType];
        }

        if ($kind === 'control') {
            return $this->handleControlCommand($parsed, $ctx, $workDate);
        }

        return ['handled' => false];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function handleControlCommand(array $parsed, array $ctx, string $workDate): array
    {
        if (($parsed['action'] ?? null) !== 'send_schedule') {
            if (($parsed['action'] ?? null) === 'approve_schedule') {
                $schedule = $this->scheduleForDate($workDate);
                $schedule->forceFill([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'meta' => array_merge(is_array($schedule->meta) ? $schedule->meta : [], [
                        'approved_from' => 'wa_kerjaan',
                        'approved_by' => $ctx['sender_name'] ?? null,
                        'approved_message_id' => $ctx['message_id'] ?? null,
                    ]),
                ])->save();

                return ['handled' => true, 'action' => 'approve_schedule', 'approved' => true, 'schedule_id' => $schedule->id];
            }

            return ['handled' => true, 'action' => $parsed['action'] ?? 'control'];
        }

        $groupJid = $this->normalizeGroupJid($this->getJidSetting('wa_ticket_progress_share_group_jid', ''));
        if ($groupJid === '') {
            return ['handled' => false, 'sent' => false, 'reason' => 'share_group_empty'];
        }

        $template = app(TechnicianProgressTemplateService::class);
        $snapshot = $template->snapshot($workDate);
        $this->materializePlannedTasks($workDate, $snapshot['planned_teams'] ?? []);
        $message = $template->messageForShare($snapshot);
        $dryRun = (bool) ($ctx['dry_run'] ?? true);

        if ($dryRun) {
            return [
                'handled' => true,
                'sent' => false,
                'reason' => 'dry_run',
                'target_group' => $groupJid,
                'channel' => 'ticket',
                'message_preview' => Str::limit($message, 500, ''),
            ];
        }

        $evolution = app(EvolutionApiService::class)->forChannel('ticket');
        $state = $evolution->getConnectionState();
        if (! $evolution->isConfigured() || $state !== 'open') {
            return ['handled' => false, 'sent' => false, 'reason' => 'ticket_wa_not_connected', 'state' => $state];
        }

        $result = $evolution->sendToGroup($groupJid, $message);
        Setting::set('ticket_progress_last_manual_report_send', [
            'at' => now('Asia/Jakarta')->toDateTimeString(),
            'date' => $workDate,
            'group_jid' => $groupJid,
            'channel' => 'ticket',
            'trigger_message_id' => $ctx['message_id'] ?? null,
            'result' => $result,
        ], 'json', 'ticket', 'Last manual technician report send');

        return [
            'handled' => (bool) ($result['success'] ?? false),
            'sent' => (bool) ($result['success'] ?? false),
            'target_group' => $groupJid,
            'channel' => 'ticket',
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function manualTaskSourceRef(string $workDate, array $parsed): string
    {
        $type = Str::slug((string) ($parsed['task_type'] ?? 'manual'), '-');
        $site = Str::slug((string) ($parsed['site'] ?? 'open'), '-');
        $task = (string) ($parsed['task'] ?? '');
        $suffix = in_array($type, ['psb', 'standby'], true)
            ? ''
            : ':'.substr(md5($task), 0, 10);

        return Str::limit('manualwa:'.$workDate.':'.$type.':'.$site.$suffix, 120, '');
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $ctx
     * @return array{handled: bool, cancelled: int, task_ids: list<int>}
     */
    protected function cancelManualTasks(array $parsed, array $ctx, string $workDate): array
    {
        $query = TechnicianDailyTask::query()
            ->whereDate('work_date', $workDate)
            ->whereIn('source', ['manual_wa', 'auto_planner'])
            ->whereNotIn('status', ['cancelled', 'canceled', 'close', 'closed']);

        $type = (string) ($parsed['task_type'] ?? '');
        if ($type !== '' && $type !== 'manual') {
            $query->where('task_type', $type);
        }
        if (! empty($parsed['team_no'])) {
            $query->where('team_no', (int) $parsed['team_no']);
        }

        $site = (string) ($parsed['site'] ?? '');
        if ($site !== '' && $site !== 'OPEN TICKET AREA') {
            $query->where('site', $site);
        }

        foreach (($parsed['cancel_query'] ?? []) as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $query->where('task', 'like', '%'.$keyword.'%');
            }
        }

        $tasks = $query->limit(20)->get();
        foreach ($tasks as $task) {
            $meta = is_array($task->meta) ? $task->meta : [];
            $meta['cancelled_from'] = 'wa_kerjaan';
            $meta['cancelled_by'] = $ctx['sender_name'] ?? null;
            $meta['cancelled_message_id'] = $ctx['message_id'] ?? null;
            $meta['cancelled_at'] = now('Asia/Jakarta')->toDateTimeString();
            $task->forceFill([
                'status' => 'cancelled',
                'progress' => 'DIBATALKAN',
                'meta' => $meta,
            ])->save();
        }

        return [
            'handled' => true,
            'cancelled' => $tasks->count(),
            'task_ids' => $tasks->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    protected function markScheduleDirty(string $workDate, string $reason, array $ctx): void
    {
        $schedule = $this->scheduleForDate($workDate);
        $meta = is_array($schedule->meta) ? $schedule->meta : [];
        $meta['dirty_reason'] = $reason;
        $meta['dirty_by'] = $ctx['sender_name'] ?? null;
        $meta['dirty_message_id'] = $ctx['message_id'] ?? null;
        $meta['dirty_at'] = now('Asia/Jakarta')->toDateTimeString();

        $schedule->forceFill([
            'status' => 'draft',
            'approved_at' => null,
            'meta' => $meta,
        ])->save();
    }

    protected function scheduleForDate(string $workDate): TechnicianDailySchedule
    {
        return TechnicianDailySchedule::firstOrCreate(
            ['schedule_date' => $workDate],
            ['status' => 'draft', 'previewed_at' => now()]
        );
    }

    protected function syncTeam(string $workDate, mixed $teamNo, mixed $technicians, mixed $site, mixed $status): void
    {
        $names = is_array($technicians)
            ? array_values(array_filter(array_map(fn ($name) => trim((string) $name), $technicians)))
            : [];

        if (! $teamNo && $names === []) {
            return;
        }

        if (! $teamNo && $names !== []) {
            $teamNo = $this->nextAvailableTeamNo($workDate);
        }

        $team = TechnicianTeam::updateOrCreate(
            [
                'work_date' => $workDate,
                'team_no' => $teamNo ? (int) $teamNo : null,
            ],
            [
                'label' => $teamNo ? 'Team '.(int) $teamNo : 'Team',
                'site' => is_string($site) && $site !== '' ? $site : null,
                'status' => is_string($status) && $status !== '' ? $status : 'draft',
            ]
        );

        // Lepas teknisi dari team lain pada tanggal yang sama agar roster bersih
        $otherTeamIds = TechnicianTeam::whereDate('work_date', $workDate)
            ->where('id', '!=', $team->id)
            ->pluck('id');

        $upperNames = array_map(fn ($n) => Str::upper((string) $n), $names);

        if ($otherTeamIds->isNotEmpty() && $upperNames !== []) {
            TechnicianTeamMember::whereIn('team_id', $otherTeamIds)
                ->whereIn('name', $upperNames)
                ->delete();
        }

        // Sync roster untuk team ini
        TechnicianTeamMember::where('team_id', $team->id)
            ->whereNotIn('name', $upperNames)
            ->delete();

        foreach ($upperNames as $name) {
            $member = TechnicianMember::firstOrCreate(
                ['name' => $name],
                ['role' => null, 'is_active' => true]
            );
            TechnicianTeamMember::firstOrCreate([
                'team_id' => $team->id,
                'name' => $member->name,
            ], [
                'member_id' => $member->id,
            ]);
        }
    }

    protected function nextAvailableTeamNo(string $workDate): int
    {
        $maxNo = (int) (TechnicianTeam::query()->whereDate('work_date', $workDate)->max('team_no') ?? 0);

        return max($maxNo + 1, 8);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function replaceTeamRoster(array $parsed, array $ctx, string $workDate): array
    {
        $teamNo = isset($parsed['team_no']) ? (int) $parsed['team_no'] : 0;
        $names = array_values(array_unique(array_filter(array_map(
            fn ($name) => Str::upper(trim((string) $name)),
            is_array($parsed['technicians'] ?? null) ? $parsed['technicians'] : []
        ))));
        if ($teamNo < 1 || $names === []) {
            return ['handled' => false, 'reason' => 'team_or_names_missing'];
        }

        $mode = (string) data_get($parsed, 'meta.mode', 'replace');
        if ($mode === 'append') {
            $existing = TechnicianTeam::query()
                ->with('members')
                ->whereDate('work_date', $workDate)
                ->where('team_no', $teamNo)
                ->first();
            $baseNames = $existing
                ? $existing->members->pluck('name')->map(fn ($name) => Str::upper((string) $name))->all()
                : $this->defaultTeamMembers($teamNo);
            $names = array_values(array_unique(array_merge($baseNames, $names)));
        }

        $team = TechnicianTeam::updateOrCreate(
            ['work_date' => $workDate, 'team_no' => $teamNo],
            [
                'label' => 'Team '.$teamNo,
                'site' => $parsed['site'] ?: null,
                'status' => $parsed['status'] ?? 'draft',
                'meta' => [
                    'updated_from' => 'wa_kerjaan',
                    'updated_by' => $ctx['sender_name'] ?? null,
                    'updated_message_id' => $ctx['message_id'] ?? null,
                ],
            ]
        );

        TechnicianTeamMember::query()
            ->whereHas('team', fn ($q) => $q->whereDate('work_date', $workDate)->where('team_no', '!=', $teamNo))
            ->whereIn('name', $names)
            ->delete();

        $team->members()->whereNotIn('name', $names)->delete();
        foreach ($names as $name) {
            $member = TechnicianMember::firstOrCreate(
                ['name' => $name],
                ['role' => null, 'is_active' => true]
            );
            TechnicianTeamMember::updateOrCreate(
                ['team_id' => $team->id, 'name' => $name],
                ['member_id' => $member->id]
            );
        }

        return ['handled' => true, 'team_no' => $teamNo, 'technicians' => $names, 'team_id' => $team->id];
    }

    /**
     * @return list<string>
     */
    protected function defaultTeamMembers(int $teamNo): array
    {
        return [
            1 => ['WIDI', 'FIKRI'],
            2 => ['REHAN', 'VALNO'],
            3 => ['ROBI', 'DIMAS'],
            4 => ['RAFLY', 'FATKAH'],
            5 => ['TYO', 'UPIN'],
            6 => ['DAPPA', 'ROY', 'FAHRI'],
            7 => ['JAZULI', 'BAIM', 'IPIN'],
        ][$teamNo] ?? [];
    }

    protected function latestTaskForReport(string $workDate, mixed $teamNo): ?TechnicianDailyTask
    {
        $query = TechnicianDailyTask::query()
            ->whereDate('work_date', $workDate)
            ->whereNotIn('status', ['cancelled', 'canceled']);
        if ($teamNo) {
            $query->where('team_no', (int) $teamNo);
        }

        return $query->latest('id')->first();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function matchTaskForReport(string $workDate, array $parsed): ?TechnicianDailyTask
    {
        if (! empty($parsed['team_no'])) {
            return $this->latestTaskForReport($workDate, $parsed['team_no']);
        }

        $tasks = TechnicianDailyTask::query()
            ->whereDate('work_date', $workDate)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->latest('id')
            ->limit(80)
            ->get();

        $best = null;
        $bestScore = 0;
        foreach ($tasks as $task) {
            $score = $this->reportTaskScore($task, $parsed);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $task;
            }
        }

        return $bestScore >= 50 ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function reportTaskScore(TechnicianDailyTask $task, array $parsed): int
    {
        $score = 0;
        $raw = Str::upper((string) ($parsed['raw_text'] ?? ''));
        $sites = array_map(fn ($site) => Str::upper((string) $site), $parsed['sites'] ?? []);
        $taskSite = Str::upper((string) ($task->site ?? ''));

        foreach ($sites as $site) {
            if ($site !== '' && ($site === $taskSite || str_contains($site, $taskSite) || str_contains($taskSite, $site))) {
                $score += 30;
                break;
            }
        }

        $taskTechs = array_map(fn ($name) => Str::upper((string) $name), is_array($task->technicians) ? $task->technicians : []);
        $reportTechs = array_map(fn ($name) => Str::upper((string) $name), $parsed['technicians'] ?? []);
        if (array_intersect($taskTechs, $reportTechs) !== []) {
            $score += 45;
        }

        $type = (string) $task->task_type;
        if ($type === 'psb' && str_contains($raw, 'PSB')) {
            $score += 25;
        }
        if ($type === 'maintenance' && preg_match('/\b(MTC|MAINT|MAINTENANCE|GANGGUAN|LOS|LEMOT)\b/u', $raw) === 1) {
            $score += 25;
        }
        if ($type === 'pembangunan' && preg_match('/\b(DEPLOY|PEMBANGUNAN|ODP|ODC|PELURUSAN|TERMINASI|SPLIC|REPAIR)\b/u', $raw) === 1) {
            $score += 25;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $stored
     */
    protected function responsePreview(array $parsed, array $stored, bool $dryRun = true): string
    {
        if (! empty($parsed['reply_text']) && is_string($parsed['reply_text'])) {
            return ($dryRun ? '[DRY RUN] ' : '').trim($parsed['reply_text']);
        }

        $prefix = $dryRun ? 'DRY RUN: ' : 'OK: ';
        $kind = (string) ($parsed['kind'] ?? 'unknown');
        if ($kind === 'task') {
            return $this->draftResponsePreview($prefix, (string) ($parsed['work_date'] ?? ''), (int) ($stored['task_id'] ?? 0), 1);
        }
        if ($kind === 'batch') {
            return $this->draftResponsePreview($prefix, (string) ($parsed['work_date'] ?? ''), 0, (int) ($stored['batch_count'] ?? 0));
        }
        if ($kind === 'team_roster') {
            $names = implode(' & ', $stored['technicians'] ?? []);

            return $prefix.'roster Team '.($stored['team_no'] ?? '-').' diupdate: '.($names ?: '-').'. Draft jadwal ikut berubah.';
        }
        if ($kind === 'task_cancel') {
            return $prefix.'task manual dibatalkan: '.($stored['cancelled'] ?? 0).' item.';
        }
        if ($kind === 'language_memory') {
            return $prefix.'memory bahasa disimpan: '.($stored['alias'] ?? '-').' = '.($stored['meaning'] ?? '-').'.';
        }
        if ($kind === 'progress') {
            return $prefix.'report progress tercatat. ID report: '.($stored['report_id'] ?? '-');
        }
        if ($kind === 'revision') {
            return $prefix.'revisi '.($stored['revision_type'] ?? 'umum').' tercatat. ID report: '.($stored['report_id'] ?? '-');
        }
        if ($kind === 'control') {
            if (in_array(($parsed['action'] ?? null), ['preview_schedule', 'show_schedule'], true)) {
                return $this->schedulePreviewResponse($prefix, (string) ($parsed['work_date'] ?? ''));
            }
            if (($parsed['action'] ?? null) === 'approve_schedule') {
                return $prefix.'jadwal difix/approved untuk tanggal '.$parsed['work_date'].'.';
            }
            if (($parsed['action'] ?? null) === 'send_schedule') {
                if (! empty($stored['sent'])) {
                    return $prefix.'report terkirim ke SKYNET REPORT via nomor 3.';
                }

                return $prefix.'report belum terkirim: '.($stored['reason'] ?? 'unknown');
            }

            return $prefix.'command jadwal diterima.';
        }
        if ($kind === 'photo') {
            $photo = is_array($stored['photo'] ?? null) ? $stored['photo'] : [];

            return ! empty($photo['handled'])
                ? $prefix.'foto progress diarsipkan. Report: '.($photo['report_id'] ?? '-')
                : $prefix.'foto progress diterima, tapi arsip belum tersimpan.';
        }

        return $prefix.'pesan diabaikan.';
    }

    protected function schedulePreviewResponse(string $prefix, string $workDate): string
    {
        $workDate = $workDate ?: now('Asia/Jakarta')->toDateString();

        try {
            $template = app(TechnicianProgressTemplateService::class);
            $snapshot = $template->snapshot($workDate, null, null, false);

            return ($prefix ? $prefix."\n\n" : '').$template->messageForShare($snapshot);
        } catch (\Throwable $e) {
            Log::warning('[TechnicianProgress] schedule preview failed', ['date' => $workDate, 'error' => $e->getMessage()]);

            return $prefix.'preview draft jadwal '.$workDate.' gagal dibuat. Coba lagi sebentar.';
        }
    }

    protected function teamOnlyDraftPreview(string $workDate, mixed $plannedTeams): string
    {
        $rows = collect(is_array($plannedTeams) ? $plannedTeams : [])
            ->filter(fn ($team) => is_array($team) && (int) ($team['team_no'] ?? 0) >= 1)
            ->groupBy(fn ($team) => (int) ($team['team_no'] ?? 0))
            ->sortKeys()
            ->map(function ($teams, int $teamNo) {
                $members = $teams->flatMap(fn ($team) => is_array($team['technicians'] ?? null) ? $team['technicians'] : [])
                    ->map(fn ($name) => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $sites = $teams->pluck('site')->map(fn ($site) => trim((string) $site))->filter(fn ($site) => $site !== '' && $site !== '-')->unique()->values()->all();
                $tasks = $teams->pluck('task')->map(fn ($task) => trim((string) $task))->filter(fn ($task) => $task !== '' && $task !== '-')->unique()->values()->all();
                $progress = $teams->pluck('progress')->map(fn ($item) => trim((string) $item))->filter(fn ($item) => $item !== '' && $item !== '-')->unique()->values()->all();
                $warning = (string) ($teams->pluck('meta.roster_warning')->filter()->first() ?? '');

                return sprintf(
                    "Team %d\n%s%s\nSite : %s\nTask : %s\nProgress : %s",
                    $teamNo,
                    $members === [] ? 'BELUM ADA TEKNISI' : implode(' & ', $members),
                    $warning !== '' ? "\nKekurangan team : ".$warning : '',
                    $sites === [] ? '-' : implode(', ', $sites),
                    $tasks === [] ? '-' : Str::limit(implode('; ', $tasks), 220, ''),
                    $progress === [] ? '-' : implode(' / ', $progress)
                );
            })
            ->values()
            ->all();

        if ($rows === []) {
            return 'Belum ada pembagian Team. Input task dulu, lalu preview ulang.';
        }

        return "*DRAFT JADWAL TEAM TEKNISI*\n".$workDate."\n\n".implode("\n\n", $rows);
    }

    protected function draftResponsePreview(string $prefix, string $workDate, int $taskId = 0, int $inputCount = 1): string
    {
        $line = $prefix.'draft jadwal '.($workDate ?: now('Asia/Jakarta')->toDateString()).' tersimpan';
        $line .= $inputCount > 1 ? ': '.$inputCount.' task.' : '. ID task: '.($taskId ?: '-');

        if ($workDate === '') {
            return $line;
        }

        try {
            $snapshot = app(TechnicianProgressTemplateService::class)->snapshot($workDate, null, null, false);
            $teams = collect($snapshot['planned_teams'] ?? [])->map(function ($team) {
                $label = (string) ($team['team_label'] ?? (($team['team_no'] ?? null) ? 'Team '.$team['team_no'] : 'Team'));
                $techs = is_array($team['technicians'] ?? null)
                    ? array_values(array_filter(array_map(fn ($name) => trim((string) $name), $team['technicians'])))
                    : [];
                $warning = (string) data_get($team, 'meta.roster_warning', '');

                return sprintf(
                    '%s %s%s | %s | %s',
                    $label,
                    $techs === [] ? 'BELUM ADA TEKNISI' : implode(' & ', $techs),
                    $warning !== '' ? ' (Kekurangan team: '.$warning.')' : '',
                    $team['site'] ?? '-',
                    $team['task'] ?? '-'
                );
            })->values()->all();

            return $teams === []
                ? $line."\nBelum ada pembagian team otomatis."
                : $line."\n".implode("\n", $teams);
        } catch (\Throwable $e) {
            Log::warning('[TechnicianProgress] draft preview failed', ['date' => $workDate, 'error' => $e->getMessage()]);

            return $line;
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function archiveProgressPhoto(array $msg, array $ctx): array
    {
        $messageId = (string) ($ctx['message_id'] ?? $msg['key']['id'] ?? '');
        if ($messageId === '') {
            return ['handled' => false, 'reason' => 'missing_message_id'];
        }

        $existing = TechnicianProgressPhoto::query()->where('message_id', $messageId)->first();
        if ($existing) {
            return [
                'handled' => true,
                'duplicate' => true,
                'photo_id' => $existing->id,
                'report_id' => $existing->report_id,
                'task_id' => $existing->task_id,
            ];
        }

        $bytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
        if (! is_string($bytes) || strlen($bytes) < 100) {
            return ['handled' => false, 'reason' => 'download_failed'];
        }

        $messageContent = $this->unwrapMessageContent($msg['message'] ?? []);
        $mime = (string) (
            $messageContent['imageMessage']['mimetype']
            ?? $messageContent['documentMessage']['mimetype']
            ?? 'image/jpeg'
        );
        $extension = str_contains($mime, 'png') ? 'png' : 'jpg';
        $workDate = (string) ($ctx['work_date'] ?? now('Asia/Jakarta')->toDateString());
        $sender = Str::slug((string) ($ctx['sender_name'] ?? 'teknisi'), '_') ?: 'teknisi';
        $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $messageId) ?: uniqid('wa_', true);
        $path = 'technician-progress/'.Carbon::parse($workDate, 'Asia/Jakarta')->format('Y/m')
            .'/'.$workDate.'_'.$sender.'_'.$safeId.'.'.$extension;

        Storage::disk(config('filesystems.default'))->put($path, $bytes);

        $report = null;
        if (! empty($ctx['report_id'])) {
            $report = TechnicianProgressReport::query()->find($ctx['report_id']);
        }
        if (! $report) {
            $report = $this->latestReportForPhoto(
                $workDate,
                (string) ($ctx['group_jid'] ?? ''),
                (string) ($ctx['sender_jid'] ?? '')
            );
        }

        $taskId = $ctx['task_id'] ?? $report?->task_id;
        $photo = TechnicianProgressPhoto::create([
            'report_id' => $report?->id,
            'task_id' => $taskId,
            'work_date' => $workDate,
            'group_jid' => $ctx['group_jid'] ?? null,
            'sender_jid' => $ctx['sender_jid'] ?? null,
            'sender_name' => $ctx['sender_name'] ?? null,
            'message_id' => $messageId,
            'storage_disk' => 'local',
            'path' => $path,
            'original_name' => $safeId.'.'.$extension,
            'mime_type' => $mime,
            'kind' => 'progress_photo',
            'meta' => [
                'caption' => $ctx['text'] ?? null,
                'bytes' => strlen($bytes),
                'evolution_instance' => app(EvolutionApiService::class)->forChannel('ticket')->getInstanceName(),
            ],
        ]);

        if ($report) {
            $meta = is_array($report->meta) ? $report->meta : [];
            $meta['archived_photos'][] = [
                'id' => $photo->id,
                'path' => $photo->path,
                'message_id' => $messageId,
            ];
            $report->forceFill(['meta' => $meta])->save();
        }

        return [
            'handled' => true,
            'photo_id' => $photo->id,
            'report_id' => $photo->report_id,
            'task_id' => $photo->task_id,
            'path' => $photo->path,
        ];
    }

    protected function latestReportForPhoto(string $workDate, string $groupJid, string $senderJid): ?TechnicianProgressReport
    {
        $minutes = max(5, (int) Setting::get('ticket_progress_photo_attach_minutes', 90));

        return TechnicianProgressReport::query()
            ->whereDate('work_date', $workDate)
            ->when($groupJid !== '', fn ($q) => $q->where('group_jid', $groupJid))
            ->when($senderJid !== '', fn ($q) => $q->where('sender_jid', $senderJid))
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->first();
    }

    protected function maybeReplyToInputGroup(string $groupJid, string $message, bool $dryRun): array
    {
        $enabled = (bool) Setting::get('ticket_progress_reply_enabled', false);
        if ($dryRun || ! $enabled || $groupJid === '') {
            return [
                'sent' => false,
                'reason' => $dryRun ? 'dry_run' : (! $enabled ? 'reply_disabled' : 'group_empty'),
            ];
        }

        $replyChannel = (string) Setting::get('ticket_progress_reply_channel', 'ticket');
        if (! in_array($replyChannel, ['ops', 'ticket'], true)) {
            $replyChannel = 'ticket';
        }

        $evolution = app(EvolutionApiService::class)->forChannel($replyChannel);
        if (! $evolution->isConfigured() || $evolution->getConnectionState() !== 'open') {
            return ['sent' => false, 'reason' => $replyChannel.'_wa_not_connected'];
        }

        $result = $evolution->sendToGroup($groupJid, $message);

        return [
            'sent' => (bool) ($result['success'] ?? false),
            'channel' => $replyChannel,
            'result' => $result,
        ];
    }

    protected function hasImage(array $msgContent): bool
    {
        $msgContent = $this->unwrapMessageContent($msgContent);

        return ! empty($msgContent['imageMessage'])
            || str_starts_with((string) ($msgContent['documentMessage']['mimetype'] ?? ''), 'image/');
    }

    /**
     * @param  array<string, mixed>  $messageKey
     */
    protected function downloadMedia(string $messageId, array $messageKey = []): ?string
    {
        $base = rtrim((string) config('services.evolution_api.base_url', ''), '/');
        $key = (string) config('services.evolution_api.api_key', '');
        $instance = app(EvolutionApiService::class)->forChannel('ticket')->getInstanceName();

        if ($base === '' || $key === '' || $instance === '') {
            return null;
        }

        $keyPayload = array_filter([
            'id' => $messageId,
            'remoteJid' => $messageKey['remoteJid'] ?? null,
            'fromMe' => $messageKey['fromMe'] ?? null,
            'participant' => $messageKey['participant']
                ?? $messageKey['participantAlt']
                ?? $messageKey['participantPn']
                ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout(90)
                ->connectTimeout(10)
                ->withHeaders(['apikey' => $key])
                ->post($base.'/chat/getBase64FromMediaMessage/'.$instance, [
                    'message' => ['key' => $keyPayload],
                    'convertToMp4' => false,
                ]);

            if (! $response->successful()) {
                Log::warning('[TechnicianProgress] media download failed', [
                    'message_id' => $messageId,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);

                return null;
            }

            $base64 = $response->json('base64') ?? $response->json('base64OrPath');
            if (! is_string($base64) || $base64 === '') {
                return null;
            }
            if (str_contains($base64, ',')) {
                $base64 = explode(',', $base64, 2)[1];
            }

            $bytes = base64_decode($base64, true);

            return is_string($bytes) ? $bytes : null;
        } catch (\Throwable $e) {
            Log::warning('[TechnicianProgress] media download exception', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function extractText(array $msgContent): string
    {
        $msgContent = $this->unwrapMessageContent($msgContent);

        return (string) (
            $msgContent['conversation']
            ?? $msgContent['extendedTextMessage']['text']
            ?? $msgContent['imageMessage']['caption']
            ?? $msgContent['documentWithCaptionMessage']['message']['documentMessage']['caption']
            ?? $msgContent['documentMessage']['caption']
            ?? ''
        );
    }

    protected function unwrapMessageContent(array $msgContent): array
    {
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'] as $wrapper) {
            if (! empty($msgContent[$wrapper]['message']) && is_array($msgContent[$wrapper]['message'])) {
                $msgContent = $msgContent[$wrapper]['message'];
            }
        }

        if (! empty($msgContent['documentWithCaptionMessage']['message'])
            && is_array($msgContent['documentWithCaptionMessage']['message'])) {
            $msgContent = $msgContent['documentWithCaptionMessage']['message'];
        }

        return $msgContent;
    }

    protected function normalizeGroupJid(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        if (! str_contains($jid, '@')) {
            $jid .= '@g.us';
        }

        return $jid;
    }

    protected function normalizeInputJid(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        if (str_contains($jid, '@')) {
            return $jid;
        }

        $digits = preg_replace('/\D+/', '', $jid) ?? '';
        if (strlen($digits) >= 10) {
            return WaGroupGuards::phoneToPersonalJid($digits);
        }

        return $this->normalizeGroupJid($jid);
    }

    /**
     * @param  mixed  $items
     */
    protected function listLine($items): string
    {
        if (is_string($items)) {
            return $items !== '' ? $items : '-';
        }
        if (! is_array($items) || $items === []) {
            return '-';
        }

        return implode(', ', array_filter(array_map('strval', $items))) ?: '-';
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
        ][(int) $date->dayOfWeek] ?? Str::upper($date->format('l'));
    }
}
