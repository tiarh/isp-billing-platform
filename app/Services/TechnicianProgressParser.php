<?php

namespace App\Services;

use App\Models\TechnicianMember;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TechnicianProgressParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $text, ?Carbon $fallbackDate = null): array
    {
        $text = trim($text);
        $fallbackDate ??= now('Asia/Jakarta');

        if (preg_match('/^(?:#\s*)?(?:(?:tambah|add)\s+)?(?:memory|memori|kamus)\b/iu', $text)) {
            return $this->parseMemoryCommand($text, $fallbackDate);
        }

        if (preg_match('/^#\s*revisi\b/iu', $text)) {
            return $this->parseRevisionCommand($text, $fallbackDate);
        }

        if (preg_match('/^#task\b/iu', $text)) {
            return $this->parseTaskCommand($text, $fallbackDate);
        }

        if (preg_match('/^#team\b/iu', $text)) {
            return $this->parseTeamCommand($text, $fallbackDate);
        }

        if (preg_match('/^#(?:progress|progres|close|material)\b/iu', $text)) {
            return $this->parseProgressCommand($text, $fallbackDate);
        }

        if (preg_match('/^#(?:preview|draft|jadwal|approve|kirim|krim)\b/iu', $text)) {
            return $this->parseControlCommand($text, $fallbackDate);
        }

        // A single operator command may contain a planning prefix such as
        // "besok". Parse it before the batch splitter so that the whitespace
        // before "psb"/"deploy" is not mistaken for a second command line.
        if (! preg_match('/\R/u', $text)) {
            if ($simple = $this->parseSimpleCommand($text, $fallbackDate)) {
                return $simple;
            }
        }

        if ($this->isLegacyReport($text)) {
            return $this->parseLegacyReport($text, $fallbackDate);
        }

        if ($batch = $this->parseSimpleBatchCommand($text, $fallbackDate)) {
            return $batch;
        }

        if ($simple = $this->parseSimpleCommand($text, $fallbackDate)) {
            return $simple;
        }

        return [
            'kind' => 'unknown',
            'action' => 'ignored',
            'work_date' => $fallbackDate->toDateString(),
            'raw_text' => $text,
        ];
    }

    public function isTrigger(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return preg_match('/^#\s*revisi\b/iu', $text) === 1
            || preg_match('/^(?:#\s*)?(?:(?:tambah|add)\s+)?(?:memory|memori|kamus)\b/iu', $text) === 1
            || preg_match('/^#(?:task|team|progress|progres|close|material|preview|draft|jadwal|approve|kirim|krim)\b/iu', $text) === 1
            || $this->isSimpleTrigger($text)
            || $this->isLegacyReport($text);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseMemoryCommand(string $text, Carbon $fallbackDate): array
    {
        $body = trim((string) preg_replace('/^(?:#\s*)?(?:(?:tambah|add)\s+)?(?:memory|memori|kamus)\s*:?\s*/iu', '', $text, 1));
        $alias = '';
        $meaning = '';

        if (preg_match('/^(.+?)\s*(?:=>|=|\bartinya\b|\bmaksudnya\b|\bberarti\b|\bjadi\b)\s*(.+)$/isu', $body, $m)) {
            $alias = $this->cleanScalar((string) ($m[1] ?? ''));
            $meaning = $this->cleanScalar((string) ($m[2] ?? ''));
        } elseif (preg_match('/^(\S+)\s+(.+)$/isu', $body, $m)) {
            $alias = $this->cleanScalar((string) ($m[1] ?? ''));
            $meaning = $this->cleanScalar((string) ($m[2] ?? ''));
        }

        return [
            'kind' => 'language_memory',
            'command' => 'tambah memory',
            'action' => 'add_language_memory',
            'work_date' => $this->commandDate($text, $fallbackDate)->toDateString(),
            'alias' => $alias,
            'meaning' => $meaning,
            'raw_text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseRevisionCommand(string $text, Carbon $fallbackDate): array
    {
        $body = trim((string) preg_replace('/^#\s*revisi\b/iu', '', $text, 1));
        $revisionType = 'umum';
        $revisionText = $body;

        if (preg_match('/^([a-z0-9_-]+)\b\s*:?\s*(.*)$/isu', $body, $m)) {
            $candidate = $this->normalizeRevisionType((string) $m[1]);
            if ($candidate !== 'umum') {
                $revisionType = $candidate;
                $revisionText = trim((string) ($m[2] ?? ''));
            }
        }

        $kv = $this->keyValues($revisionText);
        $teamNo = $this->extractTeamNo($revisionText);

        return [
            'kind' => 'revision',
            'command' => '#revisi',
            'action' => 'record_revision',
            'work_date' => $this->planningDate($text, $fallbackDate)->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, []),
            'revision_type' => $revisionType,
            'revision_text' => $this->cleanScalar($revisionText) ?: 'Revisi '.$revisionType,
            'sites' => $this->cleanScalar($kv['site'] ?? null) ? [$this->cleanScalar($kv['site'])] : [],
            'raw_text' => $text,
            'meta' => ['fields' => $kv],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseTaskCommand(string $text, Carbon $fallbackDate): array
    {
        preg_match('/^#task\s+([a-z0-9_-]+)?\s*(.*)$/isu', $text, $m);
        $taskType = $this->normalizeTaskType((string) ($m[1] ?? 'manual'));
        $body = trim((string) ($m[2] ?? ''));
        $kv = $this->keyValues($body);
        $teamNo = $this->extractTeamNo($body);
        $technicians = $this->splitNames((string) ($kv['team'] ?? ''));
        $hasExplicitTask = ! empty($kv['task']) || ! empty($kv['pelanggan']);
        $target = $this->cleanScalar($kv['target'] ?? $kv['jumlah'] ?? null);
        $task = (string) ($kv['task'] ?? $kv['pelanggan'] ?? $body ?: $taskType);
        if (! $hasExplicitTask && ! empty($kv['site'])) {
            $task = $this->defaultAreaTask($taskType, (string) $kv['site'], $target);
        }
        if (! empty($kv['pelanggan']) && empty($kv['task'])) {
            $task = 'Maintenance '.$kv['pelanggan'];
        }

        return [
            'kind' => 'task',
            'command' => '#task',
            'action' => 'create_manual_task',
            'work_date' => $this->planningDate($text, $fallbackDate)->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, $technicians),
            'technicians' => $technicians,
            'site' => $this->cleanScalar($kv['site'] ?? null),
            'task_type' => $taskType,
            'task' => $this->cleanScalar($task) ?: Str::upper($taskType),
            'progress' => $this->cleanScalar($kv['progress'] ?? null),
            'status' => $this->normalizeStatus((string) ($kv['status'] ?? 'draft')),
            'priority' => $this->normalizePriority((string) ($kv['prioritas'] ?? $kv['priority'] ?? 'normal')),
            'raw_text' => $text,
            'meta' => ['fields' => $kv],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseSimpleCommand(string $text, Carbon $fallbackDate): ?array
    {
        $text = trim($text);
        $plain = $this->stripDateWords($text);

        if (preg_match('/^(?:kirim|krim)(?:\s+report)?$/iu', $plain)) {
            return $this->controlPayload('#kirim', 'send_schedule', $text, $fallbackDate);
        }
        if (preg_match('/^(?:(?:preview|draft|jadwal|pembagian(?:\s+(?:team|tim))?)(?:\s+(?:report|jadwal|draft|pembagian(?:\s+(?:team|tim))?|team|tim))?|(?:lihat|liat|cek)(?:\s+(?:report|jadwal|draft|pembagian(?:\s+(?:team|tim))?|team|tim))?)$/iu', $plain)) {
            return $this->controlPayload('#preview', 'preview_schedule', $text, $fallbackDate, true);
        }
        if (preg_match('/^fix(?:\s+jadwal)?$/iu', $plain)) {
            return $this->controlPayload('#approve', 'approve_schedule', $text, $fallbackDate, true);
        }
        if (preg_match('/^(?:hapus|batal|cancel|delete|clear)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->parseCancelCommand((string) ($m[1] ?? ''), $text, $fallbackDate);
        }
        if (preg_match('/^(?:task|job)\s+(\d+)\s+(.+?)\s+(?:hapus|batal|cancel|delete|clear)$/isu', $plain, $m)) {
            $payload = $this->parseCancelCommand((string) ($m[2] ?? ''), $text, $fallbackDate);
            $payload['team_no'] = (int) $m[1];
            $payload['team_label'] = 'Team '.(int) $m[1];
            if (($payload['cancel_query'] ?? []) === []) {
                $payload['cancel_query'] = $this->cancelKeywords(Str::upper((string) ($m[2] ?? '')));
                $payload['task'] = Str::upper((string) ($m[2] ?? ''));
            }

            return $payload;
        }
        if ($team = $this->parseHumanTeamCommand($plain, $text, $fallbackDate)) {
            return $team;
        }
        if (preg_match('/^(?:tambah|tambahkan|nambah|input|buat)\s+(?:task|job|pekerjaan)(?:\s+baru)?\s+(.+)$/isu', $plain, $m)) {
            $payload = $this->parseSimpleCommand((string) ($m[1] ?? ''), $fallbackDate);
            if ($payload && ($payload['kind'] ?? null) === 'task') {
                $payload['work_date'] = $this->planningDate($text, $fallbackDate)->toDateString();
                $payload['raw_text'] = $text;
            }

            return $payload;
        }

        if (preg_match('/^(?:psb|pasang\s*baru|pasang|aktivasi)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('psb', (string) ($m[1] ?? ''), $text, $fallbackDate, null, 'PSB');
        }
        if (preg_match('/^(?:deploy|pembangunan|bangun)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('pembangunan', (string) ($m[1] ?? ''), $text, $fallbackDate, 'DEPLOY', 'DEPLOY');
        }
        if (preg_match('/^(?:pelurusan|lurus(?:kan)?|rapikan)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('pembangunan', (string) ($m[1] ?? ''), $text, $fallbackDate, 'PELURUSAN', 'PELURUSAN');
        }
        if (preg_match('/^(?:terminasi|term|splicing|splice)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('pembangunan', (string) ($m[1] ?? ''), $text, $fallbackDate, 'TERMINASI', 'TERMINASI');
        }
        if (preg_match('/^(?:dismantle|dismantel|dismantling|pemutusan|oemutusan|pemutisan|bongkar|cabut)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('maintenance', (string) ($m[1] ?? ''), $text, $fallbackDate, 'DISMANTLE', 'DISMANTLE');
        }
        if (preg_match('/^(?:repair|perbaikan)\b\s*(.*)$/isu', $plain, $m)) {
            $body = (string) ($m[1] ?? '');
            $isOdpWork = preg_match('/\b(?:odp|odc|fo|kabel|splitter)\b/iu', $body) === 1;

            return $this->simpleTaskPayload('maintenance', $body, $text, $fallbackDate, $isOdpWork ? 'REPAIR ODP' : 'REPAIR', 'REPAIR');
        }
        if (preg_match('/^(?:mtc|maint(?:enance)?|maintenance|gangguan)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('maintenance', (string) ($m[1] ?? ''), $text, $fallbackDate, null, 'OPEN TICKET');
        }
        if (preg_match('/^(?:standby|stanby)\b\s*(.*)$/isu', $plain, $m)) {
            return $this->simpleTaskPayload('standby', (string) ($m[1] ?? ''), $text, $fallbackDate, null, 'STANDBY');
        }

        return null;
    }

    protected function isSimpleTrigger(string $text): bool
    {
        return preg_match('/^(?:edit|ubah|ganti|set|psb|pasang\s*baru|pasang|aktivasi|deploy|pembangunan|bangun|pelurusan|lurus(?:kan)?|rapikan|terminasi|term|splicing|splice|repair|perbaikan|dismantle|dismantel|dismantling|pemutusan|oemutusan|pemutisan|bongkar|cabut|mtc|maint(?:enance)?|maintenance|gangguan|standby|stanby|team|tim|tambah|tambahkan|nambah|input|buat|task|job|kirim|krim|preview|draft|jadwal|pembagian|lihat|liat|cek|fix|hapus|batal|cancel|delete|clear)\b/iu', $this->stripDateWords(trim($text))) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseHumanTeamCommand(string $plain, string $rawText, Carbon $fallbackDate): ?array
    {
        if (preg_match('/\R/u', $rawText) === 1 && preg_match('/\b(?:progress|progres|site|status|action)\s*:/iu', $rawText) === 1) {
            return null;
        }

        $teamNo = $this->extractTeamNo($plain);
        if ($teamNo === null || ! preg_match('/\b(?:team|tim)\b/iu', $plain)) {
            return null;
        }

        $body = trim((string) preg_replace('/\b(?:team|tim)\s+(?:\d+|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b/iu', ' ', $plain, 1));
        $body = preg_replace('/\b(?:edit|ubah|ganti|set|hanya|saja|cuma|isi|anggota)\b/iu', ' ', $body) ?? $body;
        $names = $this->humanNames($body);
        if ($names === []) {
            return null;
        }

        $append = preg_match('/\b(?:nambah|tambah|tambahkan|add)\b/iu', $plain) === 1;

        return [
            'kind' => 'team_roster',
            'command' => 'team',
            'action' => $append ? 'add_team_member' : 'set_team_roster',
            'work_date' => $this->planningDate($rawText, $fallbackDate)->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, $names),
            'technicians' => $names,
            'site' => '',
            'task_type' => 'manual',
            'task' => 'Update team',
            'progress' => null,
            'status' => 'draft',
            'priority' => 'normal',
            'raw_text' => $rawText,
            'meta' => ['fields' => [], 'mode' => $append ? 'append' : 'replace'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseSimpleBatchCommand(string $text, Carbon $fallbackDate): ?array
    {
        // Keep a planning prefix attached to its command. Without this guard,
        // "besok psb suko 20" becomes two pseudo-lines ("besok" and
        // "psb suko 20") and the item loses its next-day date.
        $text = preg_replace(
            '/\b(besok|bsk|hari\s+ini|today)\s+(?=(?:tambah|hapus|task|job|psb|deploy|pembangunan|pelurusan|terminasi|repair|dismantle|mtc|maintenance)\b)/iu',
            '$1__PLANNING__',
            $text,
        ) ?? $text;

        // Pisahkan frasa 'dan task' atau 'dan tambah team' agar klausa team_roster dan task terpisah sempurna
        $text = preg_replace('/\s+(?:lalu|terus|dan|serta|kemudian|sambil)?\s*(?=(?:edit|ubah|ganti|set|team|tim)\s+(?:\d+|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b|(?:tambah|hapus|task|job|psb|deploy|pembangunan|pelurusan|terminasi|repair|dismantle|mtc|maintenance)\b)/iu', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?:lalu|terus|dan|serta|kemudian|sambil)?\s*(?=(?:task|job|dismantle|repair|deploy)\b)/iu', "\n", $text) ?? $text;
        $text = str_replace('__PLANNING__', ' ', $text);

        $lines = array_values(array_filter(
            array_map(fn ($line) => trim((string) $line), preg_split('/\R/u', $text) ?: []),
            fn ($line) => $line !== ''
        ));
        if (count($lines) < 2) {
            return null;
        }

        $items = [];
        foreach ($lines as $line) {
            $parsed = $this->parseSimpleCommand($line, $fallbackDate);
            if (! $parsed || ! in_array((string) ($parsed['kind'] ?? ''), ['task', 'task_cancel', 'team_roster'], true)) {
                continue;
            }

            $items[] = $parsed;
        }

        if ($items === []) {
            return null;
        }

        return [
            'kind' => 'batch',
            'command' => 'batch',
            'action' => 'batch_input',
            'work_date' => (string) ($items[0]['work_date'] ?? $fallbackDate->toDateString()),
            'items' => $items,
            'raw_text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function controlPayload(string $command, string $action, string $text, Carbon $fallbackDate, bool $planningDate = false): array
    {
        return [
            'kind' => 'control',
            'command' => $command,
            'action' => $action,
            'work_date' => ($planningDate ? $this->planningDate($text, $fallbackDate) : $this->commandDate($text, $fallbackDate))->toDateString(),
            'raw_text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function simpleTaskPayload(string $taskType, string $body, string $rawText, Carbon $fallbackDate, ?string $taskLabel = null, ?string $defaultProgress = null): array
    {
        $teamNames = $this->humanTaskTeamNames($body);
        $bodyWithoutTeam = $this->stripHumanTaskTeam($body);
        $bodyForTask = $this->stripTaskLabelWords($bodyWithoutTeam, $taskLabel);
        $kv = $this->keyValues($bodyForTask);
        $site = $this->cleanScalar($kv['site'] ?? null) ?: $this->simpleSite($bodyForTask);
        $target = $this->cleanScalar($kv['target'] ?? $kv['jumlah'] ?? null) ?: $this->simpleTarget($body, $taskType);
        $rest = $this->simpleRemainder($bodyForTask, $site, $target);
        if (Str::upper((string) $taskLabel) === 'REPAIR ODP') {
            $rest = $this->cleanScalar((string) (preg_replace('/\bodp\b/iu', ' ', $rest) ?? $rest));
            $rest = $this->cleanScalar((string) (preg_replace('/\b'.preg_quote($site, '/').'\b/iu', ' ', $rest) ?? $rest));
        }
        $task = $this->cleanScalar($kv['task'] ?? null);
        if ($task === '') {
            $task = (Str::upper((string) $taskLabel) === 'REPAIR ODP' && $rest === '')
                ? (string) $taskLabel
                : (($taskType === 'psb' && $rest === '')
                    ? $this->defaultAreaTask($taskType, $site, $target)
                    : $this->simpleTaskText($taskType, $site, $rest, $target, $taskLabel));
        }

        $progress = $this->cleanScalar($kv['progress'] ?? null) ?: $defaultProgress;

        return [
            'kind' => 'task',
            'command' => '#task',
            'action' => 'create_manual_task',
            'work_date' => $this->planningDate($rawText, $fallbackDate)->toDateString(),
            'team_no' => $this->extractTeamNo($body),
            'team_label' => $this->teamLabel($this->extractTeamNo($body), []),
            'technicians' => $this->splitNames((string) ($kv['team'] ?? '')) ?: $teamNames,
            'site' => $site,
            'task_type' => $taskType,
            'task' => $task,
            'progress' => $progress,
            'status' => $this->normalizeStatus((string) ($kv['status'] ?? 'draft')),
            'priority' => $this->normalizePriority((string) ($kv['prioritas'] ?? $kv['priority'] ?? 'normal')),
            'raw_text' => $rawText,
            'meta' => ['fields' => $kv, 'simple_input' => true, 'target' => $target, 'task_label' => $taskLabel],
        ];
    }

    protected function stripTaskLabelWords(string $body, ?string $taskLabel): string
    {
        if (Str::upper((string) $taskLabel) === 'DISMANTLE') {
            $body = preg_replace('/\b(?:dismantle|dismantel|dismantling|pemutusan|oemutusan|pemutisan|bongkar|cabut)\b/iu', ' ', $body) ?? $body;
        }

        return trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseCancelCommand(string $body, string $rawText, Carbon $fallbackDate): array
    {
        $plain = $this->stripDateWords(trim($body));
        $type = 'manual';
        $rest = $plain;
        foreach ([
            'psb' => '/^(?:psb|pasang\s*baru|pasang|aktivasi)\b\s*(.*)$/isu',
            'pembangunan' => '/^(?:deploy|pembangunan|bangun|pelurusan|lurus(?:kan)?|rapikan|terminasi|term|splicing|splice|repair|perbaikan)\b\s*(.*)$/isu',
            'maintenance' => '/^(?:mtc|maint(?:enance)?|maintenance|gangguan)\b\s*(.*)$/isu',
            'standby' => '/^(?:standby|stanby)\b\s*(.*)$/isu',
        ] as $candidate => $pattern) {
            if (preg_match($pattern, $plain, $m)) {
                $type = $candidate;
                $rest = (string) ($m[1] ?? '');
                break;
            }
        }

        $payload = $this->simpleTaskPayload($type, $rest, $rawText, $fallbackDate);
        $payload['kind'] = 'task_cancel';
        $payload['command'] = '#hapus';
        $payload['action'] = 'cancel_manual_task';
        $payload['cancel_query'] = $this->cancelKeywords((string) ($payload['task'] ?? ''));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseTeamCommand(string $text, Carbon $fallbackDate): array
    {
        preg_match('/^#team\s+(\d+)?\s*(.*)$/isu', $text, $m);
        $teamNo = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : null;
        $body = trim((string) ($m[2] ?? ''));
        $kv = $this->keyValues($body);
        $namesText = trim((string) preg_replace('/\b(?:site|task|progress|status|material|kendala)\s*:.*$/isu', '', $body));
        $technicians = $this->splitNames((string) ($kv['team'] ?? $namesText));

        return [
            'kind' => 'team_roster',
            'command' => '#team',
            'action' => 'set_team_roster',
            'work_date' => $this->planningDate($text, $fallbackDate)->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, $technicians),
            'technicians' => $technicians,
            'site' => $this->cleanScalar($kv['site'] ?? null),
            'task_type' => 'manual',
            'task' => $this->cleanScalar($kv['task'] ?? 'Update team'),
            'progress' => $this->cleanScalar($kv['progress'] ?? null),
            'status' => $this->normalizeStatus((string) ($kv['status'] ?? 'draft')),
            'priority' => $this->normalizePriority((string) ($kv['prioritas'] ?? $kv['priority'] ?? 'normal')),
            'raw_text' => $text,
            'meta' => ['fields' => $kv],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseProgressCommand(string $text, Carbon $fallbackDate): array
    {
        preg_match('/^#([a-z]+)\b\s*(.*)$/isu', $text, $m);
        $command = '#'.Str::lower((string) ($m[1] ?? 'progress'));
        $body = trim((string) ($m[2] ?? ''));
        $teamNo = $this->extractTeamNo($body);
        $kv = $this->keyValues($body);
        $bodyWithoutTeam = trim((string) preg_replace('/\bteam\s+\d+\b/iu', '', $body));
        $bodyWithoutTrailingFields = trim((string) preg_replace('/\b(?:kendala|material)\s*:?.*$/iu', '', $bodyWithoutTeam));
        $progress = $this->cleanScalar($kv['progress'] ?? $bodyWithoutTrailingFields);
        $constraints = $this->extractInlineList($body, 'kendala');
        $materials = $command === '#material' ? [$bodyWithoutTeam] : $this->extractInlineList($body, 'material');
        $status = $command === '#close' ? 'close' : $this->normalizeStatus((string) ($kv['status'] ?? 'proses'));

        return [
            'kind' => 'progress',
            'command' => $command,
            'action' => $command === '#material' ? 'record_material' : 'record_progress',
            'work_date' => $this->commandDate($text, $fallbackDate)->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, []),
            'technicians' => [],
            'progress_items' => $progress !== '' ? [$progress] : [],
            'sites' => $this->cleanScalar($kv['site'] ?? null) ? [$this->cleanScalar($kv['site'])] : [],
            'actions' => [],
            'photo_status' => [],
            'status' => $status,
            'constraints' => $constraints,
            'materials' => $this->cleanList($materials),
            'note' => null,
            'raw_text' => $text,
            'meta' => ['fields' => $kv],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseControlCommand(string $text, Carbon $fallbackDate): array
    {
        preg_match('/^#([a-z]+)\b/iu', $text, $m);
        $command = '#'.Str::lower((string) ($m[1] ?? 'jadwal'));
        if ($command === '#krim') {
            $command = '#kirim';
        }
        $action = match ($command) {
            '#preview', '#draft', '#jadwal' => 'preview_schedule',
            '#approve' => 'approve_schedule',
            '#kirim' => 'send_schedule',
            default => 'show_schedule',
        };

        return [
            'kind' => 'control',
            'command' => $command,
            'action' => $action,
            'work_date' => $this->planningDate($text, $fallbackDate)->toDateString(),
            'raw_text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseLegacyReport(string $text, Carbon $fallbackDate): array
    {
        $sections = $this->sections($text);
        $workDate = $this->extractReportDate($text, $fallbackDate);
        $technicians = $this->cleanList($sections['technicians'] ?? []);
        $teamNo = $this->extractTeamNo($text);

        preg_match('/jumlah\s+tim\s*:\s*(\d+).*?\((\d+)\s*orang\)/iu', $text, $countMatch);

        return [
            'kind' => 'progress',
            'command' => 'legacy_report',
            'action' => 'record_progress',
            'work_date' => $workDate->toDateString(),
            'team_no' => $teamNo,
            'team_label' => $this->teamLabel($teamNo, $technicians),
            'technicians' => $technicians,
            'progress_items' => $this->cleanList($sections['progress_items'] ?? []),
            'sites' => $this->cleanList($sections['sites'] ?? []),
            'actions' => $this->cleanList($sections['actions'] ?? []),
            'photo_status' => $this->cleanList($sections['photo_status'] ?? []),
            'status' => $this->normalizeStatus((string) (($sections['status'][0] ?? null) ?: 'proses')),
            'constraints' => $this->cleanList($sections['constraints'] ?? []),
            'materials' => $this->cleanList($sections['materials'] ?? []),
            'note' => implode("\n", $this->cleanList($sections['note'] ?? [])) ?: null,
            'raw_text' => $text,
            'meta' => [
                'team_count' => isset($countMatch[1]) ? (int) $countMatch[1] : null,
                'people_count' => isset($countMatch[2]) ? (int) $countMatch[2] : null,
            ],
        ];
    }

    protected function isLegacyReport(string $text): bool
    {
        $lower = Str::lower($text);

        return preg_match('/\bprogres(?:s)?\s+\d{1,2}\s+[a-z]+\s+\d{4}/iu', $text) === 1
            || (preg_match('/\bteam\s+\d+\b/iu', $text) === 1 && preg_match('/\bprogress\s*:/iu', $text) === 1)
            || (str_contains($lower, 'skynet') && str_contains($lower, 'progress') && str_contains($lower, 'status'));
    }

    /**
     * @return array<string, list<string>>
     */
    protected function sections(string $text): array
    {
        $out = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([a-z ]+)\s*:\s*(.*)$/iu', $line, $m)) {
                $key = $this->sectionKey((string) $m[1]);
                if ($key !== null) {
                    $current = $key;
                    $value = $this->cleanScalar((string) $m[2]);
                    if ($value !== '') {
                        $out[$current][] = $value;
                    }

                    continue;
                }
            }

            if ($current !== null) {
                $item = $this->cleanScalar($line);
                if ($item !== '') {
                    $out[$current][] = $item;
                }
            }
        }

        return $out;
    }

    protected function sectionKey(string $key): ?string
    {
        $key = preg_replace('/\s+/u', ' ', Str::lower(trim($key))) ?? '';

        return match ($key) {
            'skynet', 'teknisi', 'team' => 'technicians',
            'progress', 'progres' => 'progress_items',
            'site', 'lokasi', 'area' => 'sites',
            'action', 'aksi', 'pekerjaan', 'task', 'tugas', 'jobdesk' => 'actions',
            'status foto', 'foto' => 'photo_status',
            'status' => 'status',
            'kendala' => 'constraints',
            'material' => 'materials',
            'note', 'catatan' => 'note',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function keyValues(string $body): array
    {
        $keys = 'site|team|task|pelanggan|kendala|progress|progres|status|material|prioritas|priority|target|jumlah';
        preg_match_all('/(?:^|\s)('.$keys.')\s*:\s*(.*?)(?=\s+(?:'.$keys.')\s*:|$)/isu', $body, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $m) {
            $key = Str::lower((string) $m[1]);
            if ($key === 'progres') {
                $key = 'progress';
            }
            $out[$key] = $this->cleanScalar((string) $m[2]);
        }

        return $out;
    }

    protected function commandDate(string $text, Carbon $fallbackDate): Carbon
    {
        $lower = Str::lower($text);
        if (str_contains($lower, 'besok')) {
            return $fallbackDate->copy()->addDay();
        }

        return $fallbackDate->copy();
    }

    protected function planningDate(string $text, Carbon $fallbackDate): Carbon
    {
        $lower = Str::lower($text);
        if (str_contains($lower, 'hari ini') || str_contains($lower, 'today')) {
            return $fallbackDate->copy();
        }
        if (str_contains($lower, 'besok') || preg_match('/\bbsk\b/u', $lower) === 1) {
            return $fallbackDate->copy()->addDay();
        }

        return (int) $fallbackDate->format('H') >= 17
            ? $fallbackDate->copy()->addDay()
            : $fallbackDate->copy();
    }

    protected function stripDateWords(string $text): string
    {
        $text = preg_replace('/\b(?:besok|bsk|tomorrow|hari\s+ini|today)\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    protected function extractReportDate(string $text, Carbon $fallbackDate): Carbon
    {
        if (! preg_match('/\bprogres(?:s)?\s+(\d{1,2})\s+([a-z]+)\s+(\d{4})/iu', $text, $m)) {
            return $fallbackDate->copy();
        }

        $month = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12,
        ][Str::lower((string) $m[2])] ?? (int) $fallbackDate->month;

        return Carbon::create((int) $m[3], $month, (int) $m[1], 0, 0, 0, 'Asia/Jakarta');
    }

    protected function extractTeamNo(string $text): ?int
    {
        if (preg_match('/\b(?:team|tim)\s+(\d+)\b/iu', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(?:team|tim)\s+(satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b/iu', $text, $m)) {
            return [
                'satu' => 1,
                'dua' => 2,
                'tiga' => 3,
                'empat' => 4,
                'lima' => 5,
                'enam' => 6,
                'tujuh' => 7,
                'delapan' => 8,
                'sembilan' => 9,
                'sepuluh' => 10,
            ][Str::lower((string) $m[1])] ?? null;
        }

        return null;
    }

    protected function simpleSite(string $body): string
    {
        $bodyUpper = Str::upper($body);
        $found = [];
        foreach ($this->knownSites() as $site) {
            if (preg_match('/\b'.preg_quote($site, '/').'\b/u', $bodyUpper)) {
                $found[] = $site;
            }
        }
        if ($found !== []) {
            return implode(' ', array_values(array_unique($found)));
        }

        $body = preg_replace('/\b(?:area|are|site|daerah|di|target|jumlah)\b\s*:?\s*/iu', ' ', $body) ?? $body;
        $parts = preg_split('/\s+/u', trim($body)) ?: [];

        return Str::upper($this->cleanScalar((string) ($parts[0] ?? 'OPEN TICKET AREA')));
    }

    protected function simpleTarget(string $body, string $taskType): string
    {
        if ($taskType !== 'psb') {
            return '';
        }
        if (preg_match('/\b(?:target|jumlah|jml|kuota)\s*:?\s*(\d{1,3})\b/iu', $body, $m)) {
            return (string) $m[1];
        }
        if (preg_match('/\b(\d{1,3})\s*(?:psb|pelanggan|titik|hari)?\s*$/iu', $body, $m)) {
            return (string) $m[1];
        }

        return '';
    }

    protected function simpleRemainder(string $body, string $site, string $target): string
    {
        $out = $this->stripDateWords($body);
        foreach (preg_split('/\s+/u', $site) ?: [] as $word) {
            $out = preg_replace('/\b'.preg_quote($word, '/').'\b/iu', ' ', $out) ?? $out;
        }
        $out = preg_replace('/\b(?:area|are|site|daerah|di)\b\s*:?\s*/iu', ' ', $out) ?? $out;
        $out = preg_replace('/\b(?:target|jumlah|jml|kuota)\s*:?\s*\d{1,3}\b/iu', ' ', $out) ?? $out;
        $out = preg_replace('/\b(?:task|job|pekerjaan|tugas)\b\s*$/iu', ' ', $out) ?? $out;
        if ($target !== '') {
            $out = preg_replace('/\b'.preg_quote($target, '/').'\b\s*(?:psb|pelanggan|titik|hari)?\s*$/iu', ' ', $out) ?? $out;
        }

        return $this->cleanScalar($out);
    }

    protected function simpleTaskText(string $taskType, string $site, string $rest, string $target, ?string $taskLabel = null): string
    {
        $rest = trim(preg_replace('/\s+/u', ' ', $rest) ?? $rest);
        if ($rest === '') {
            return $this->defaultAreaTask($taskType, $site, $target, $taskLabel);
        }

        $prefix = $taskLabel ?: match ($taskType) {
            'psb' => 'PSB',
            'pembangunan' => 'DEPLOY',
            'standby' => 'STANDBY',
            default => 'MAINTENANCE',
        };
        $suffix = $taskType === 'psb' && $target !== '' ? ' TARGET '.$target.'/HARI' : '';

        return trim($prefix.' '.Str::upper($rest).$suffix);
    }

    /**
     * @return list<string>
     */
    protected function cancelKeywords(string $task): array
    {
        $words = preg_split('/\s+/u', Str::upper($task)) ?: [];
        $skip = ['PSB', 'STANDBY', 'TARGET', 'HARI', 'DEPLOY', 'MAINTENANCE', 'OPEN', 'TICKET'];

        return array_values(array_filter($words, fn ($word) => strlen($word) >= 3 && ! in_array($word, $skip, true)));
    }

    /**
     * @return list<string>
     */
    protected function knownSites(): array
    {
        return [
            'SUKO',
            'GONDANG',
            'KALIREJO',
            'CAPANG',
            'COMBORAN',
            'ARJOSARI',
            'RANDUAGUNG',
            'RA',
            'RDG',
            'GADANG',
            'KRIAN',
            'BUMIAYU',
            'LAWANG',
            'KLAMPOK',
        ];
    }

    /**
     * @return list<string>
     */
    protected function splitNames(string $value): array
    {
        $value = str_ireplace([' dan ', ' & '], ',', $value);

        return $this->cleanList(preg_split('/[,\n]+/u', $value) ?: []);
    }

    /**
     * @return list<string>
     */
    protected function humanTaskTeamNames(string $body): array
    {
        if (! preg_match('/\bteam\s+((?!(?:\d+|satu|dua|tiga|empat|lima|enam|tujuh)\b).+)$/isu', $body, $m)) {
            return [];
        }

        return $this->humanNames((string) $m[1]);
    }

    protected function stripHumanTaskTeam(string $body): string
    {
        return trim((string) preg_replace('/\bteam\s+(?!(?:\d+|satu|dua|tiga|empat|lima|enam|tujuh)\b).+$/isu', '', $body));
    }

    /**
     * @return list<string>
     */
    protected function humanNames(string $text): array
    {
        $upper = Str::upper($text);
        $matches = [];
        foreach ($this->knownTechnicians() as $name) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/u', $upper, $m, PREG_OFFSET_CAPTURE)) {
                $matches[$name] = $m[0][1];
            }
        }
        asort($matches);
        if ($matches !== []) {
            return array_keys($matches);
        }

        $text = preg_replace('/\b(?:TEAM|TIM|Nambah|Tambah|Tambahkan|Add|Aja|Saja|Jadi|Ke|Kalo|Kalau|Misal|Misa|Orang|Task|Job|Deploy|Psb|Maintenance|Mtc|Pelurusan|Terminasi|Repair|Odp|Odc|Site)\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d+\b/u', ' ', $text) ?? $text;

        return array_values(array_filter(
            array_map(fn ($name) => Str::upper($this->cleanScalar((string) $name)), preg_split('/[\s,;&]+/u', $text) ?: []),
            fn ($name) => strlen($name) >= 3
        ));
    }

    /**
     * @return list<string>
     */
    protected function knownTechnicians(): array
    {
        $default = ['WIDI', 'FIKRI', 'REHAN', 'VALNO', 'ROBI', 'DIMAS', 'RAFLY', 'FATKAH', 'TYO', 'UPIN', 'DAPPA', 'ROY', 'FAHRI', 'JAZULI', 'BAIM', 'BIAM', 'IPIN', 'RIDWAN'];

        try {
            return cache()->remember('known_technicians_list', 300, function () use ($default) {
                $dbNames = TechnicianMember::query()
                    ->where('is_active', true)
                    ->pluck('name')
                    ->map(fn ($n) => Str::upper(trim((string) $n)))
                    ->filter()
                    ->all();

                return array_values(array_unique(array_merge($default, $dbNames)));
            });
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * @param  iterable<mixed>  $items
     * @return list<string>
     */
    protected function cleanList(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = $this->cleanScalar((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    protected function cleanScalar(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/^\s*[-*]\s*/u', '', $value) ?? $value;
        $value = trim($value);

        return in_array($value, ['', '-'], true) ? '' : $value;
    }

    protected function normalizeTaskType(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace(['maintance', 'maintenancee', 'maintenenace'], 'maintenance', $value);

        return match ($value) {
            'psb', 'pasangbaru', 'pasang_baru' => 'psb',
            'maintenance', 'mtc', 'gangguan' => 'maintenance',
            'pembangunan', 'deploy', 'odp', 'pelurusan', 'terminasi', 'term', 'splicing', 'splice', 'repair' => 'pembangunan',
            'standby', 'stanby' => 'standby',
            'survey', 'csr' => $value,
            default => $value !== '' ? $value : 'manual',
        };
    }

    protected function normalizeStatus(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace(['selesai', 'closed'], 'close', $value);
        $value = str_replace(['process', 'proses'], 'proses', $value);

        return $value !== '' ? $value : 'proses';
    }

    protected function normalizePriority(string $value): string
    {
        $value = Str::lower(trim($value));

        return in_array($value, ['low', 'normal', 'high', 'urgent'], true) ? $value : 'normal';
    }

    protected function defaultAreaTask(string $taskType, string $site, string $target = '', ?string $taskLabel = null): string
    {
        $site = Str::upper($this->cleanScalar($site));

        return match ($taskType) {
            'psb' => trim('STANDBY PSB '.$site.($target !== '' ? ' TARGET '.$target.'/HARI' : '')),
            'maintenance' => trim(($taskLabel ?: 'OPEN TICKET').' '.$site),
            'pembangunan' => trim(($taskLabel ?: 'DEPLOY').' '.$site),
            default => trim(Str::upper($taskType).' '.$site),
        };
    }

    protected function normalizeRevisionType(string $value): string
    {
        $value = Str::lower(trim($value));

        return match ($value) {
            'feature', 'fitur' => 'fitur',
            'team', 'tim' => 'team',
            'task', 'tugas', 'pekerjaan' => 'task',
            'jadwal', 'schedule' => 'jadwal',
            'progress', 'progres', 'laporan', 'report' => 'progress',
            'note', 'catatan' => 'note',
            default => 'umum',
        };
    }

    protected function teamLabel(?int $teamNo, array $technicians): ?string
    {
        if ($teamNo === null && $technicians === []) {
            return null;
        }

        $label = $teamNo !== null ? 'Team '.$teamNo : 'Team';
        if ($technicians !== []) {
            $label .= ' - '.implode(' & ', array_slice($technicians, 0, 4));
        }

        return $label;
    }

    /**
     * @return list<string>
     */
    protected function extractInlineList(string $body, string $key): array
    {
        if (! preg_match('/\b'.preg_quote($key, '/').'\s*:?\s*(.*)$/iu', $body, $m)) {
            return [];
        }

        return $this->cleanList([(string) $m[1]]);
    }
}
