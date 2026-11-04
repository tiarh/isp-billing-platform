<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TechnicianMember;
use App\Models\User;
use App\Models\WaChatSignal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dataset `wa_work_signals.v1`: one row per work-group WA message in
 * wa_chat_signals (channel=work). Internal training data, read-only on the DB.
 *
 * Privacy: sender/group/message identities are HMAC-SHA256(salt) only,
 * sender_name is never exported, text is re-redacted (phones, NIK, coordinates,
 * SN, PPPoE usernames, IPs, emails, URLs, customer and team-member names).
 *
 * Label semantics: became_ticket is true only when the linked ticket was created
 * 0-120 minutes AFTER the message (progress messages on an older ticket get false);
 * resolution_minutes is ticket-level (created_at to resolved_at) and repeats on every
 * message linked to the same ticket; link_method says how the ticket was found
 * (explicit = ticket ref in progress/command payload, code_in_text, none).
 * Name/PPPoE redaction is list-based and therefore best effort.
 */
class WaSignalDatasetExporter
{
    public const DATASET = 'wa_work_signals.v1';

    public const FIELDS = [
        'dataset_version', 'signal_id', 'message_hash', 'occurred_at', 'group_hash', 'group_role',
        'sender_hash', 'sender_role', 'text', 'has_image',
        'intent_rule', 'domain_rule', 'severity_rule', 'command',
        'progress_source', 'progress_action', 'progress_site', 'progress_status',
        'linked_ticket_id', 'link_method',
        'ticket_type', 'ticket_priority', 'ticket_category', 'ticket_status',
        'became_ticket', 'resolution_minutes', 'label_human',
    ];

    /** Ticket must be created within this many minutes after the message to count as "became ticket". */
    private const TICKET_WINDOW_MINUTES = 120;

    private const STOPWORDS = [
        'internet', 'down', 'gangguan', 'pelanggan', 'teknisi', 'tolong', 'sudah', 'belum', 'kabel',
        'redaman', 'selesai', 'pasang', 'baru', 'cabut', 'ganti', 'lokasi', 'kendala', 'sekitar',
        'toko', 'warung', 'kantor', 'rumah', 'desa', 'jaya', 'sari', 'putra', 'putri', 'merah', 'mati', 'hijau',
        'biru', 'kuning', 'abadi', 'makmur', 'sentosa', 'sejahtera', 'mandiri', 'utama', 'bengkel', 'kost',
        'masjid', 'sekolah', 'ruko', 'gang', 'jalan', 'blok', 'tower', 'kopi', 'cafe', 'resto', 'depot',
    ];

    /** @var array<string,string> normalized phone => role */
    private array $phoneRoles = [];

    /** @var array<string,true> */
    private array $nameTokens = [];

    /** @var array<string,true> */
    private array $pppoeUsers = [];

    /** @var array<string,string> jid => group role */
    private array $groupRoles = [];

    /** @var list<string>|null */
    private ?array $pppoePatterns = null;

    private bool $prepared = false;

    public function __construct(private readonly string $salt)
    {
        if (strlen($salt) < 16) {
            throw new \InvalidArgumentException('Export salt must be at least 16 characters.');
        }
    }

    public function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, $this->salt);
    }

    /**
     * Stream rows (chunked by id). Yields associative arrays ordered as FIELDS.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(?CarbonInterface $since = null, ?CarbonInterface $until = null, int $chunk = 500): \Generator
    {
        $this->prepare();

        $query = WaChatSignal::query()->where('channel', 'work');
        if ($since) {
            $query->where('occurred_at', '>=', $since);
        }
        if ($until) {
            $query->where('occurred_at', '<=', $until);
        }

        $buffer = [];
        foreach ($query->lazyById($chunk) as $signal) {
            $buffer[] = $signal;
            if (count($buffer) >= $chunk) {
                yield from $this->buildChunk($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            yield from $this->buildChunk($buffer);
        }
    }

    public function redact(string $text): string
    {
        $this->prepare();

        $out = preg_replace('/\b\d{16}\b/', '[NIK]', $text) ?? $text;
        $out = preg_replace('/(-?\d{1,2}[.,]\d{4,})\s*[, ]\s*(-?\d{2,3}[.,]\d{4,})/', '[TIKOR]', $out) ?? $out;
        $out = preg_replace('/(?<![\w])(?:\+?62[\s.-]?|0)8\d{1,3}[\s.-]?\d{3,4}[\s.-]?\d{2,5}\b/', '[HP]', $out) ?? $out;
        $out = preg_replace('/\b(?:\+?62|0)8\d{7,12}\b/', '[HP]', $out) ?? $out;
        $out = preg_replace('/\b(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/', '[MAC]', $out) ?? $out;
        $out = preg_replace('/\b(?=[0-9A-Fa-f]*\d)(?=[0-9A-Fa-f]*[A-Fa-f])[0-9A-Fa-f]{16}\b/', '[SN]', $out) ?? $out;
        $out = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP]', $out) ?? $out;
        $out = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '[EMAIL]', $out) ?? $out;
        $out = preg_replace('~\bhttps?://\S+~i', '[URL]', $out) ?? $out;
        // ONU serials: vendor prefix + hex, or 4 letters + 8 hex containing a digit.
        $out = preg_replace('/\b(?:ZTEG|FHTT|HWTC|HWTT|ALCL|CMCC|DSNW|TPLG|VSOL|ZICG|GPON)[0-9A-F]{6,}\b/i', '[SN]', $out) ?? $out;
        $out = preg_replace_callback('/\b[A-Za-z]{4}[0-9A-Fa-f]{8}\b/', fn ($m) => preg_match('/\d/', $m[0]) ? '[SN]' : $m[0], $out) ?? $out;

        // List-based: PPPoE usernames, then customer / team-member name tokens.
        // Known usernames are hidden wherever they occur, including embedded in a longer
        // token ("xa001x-a001x", "a001x.b002y"). Longest first, minimum length 4.
        foreach ($this->pppoePatterns() as $pattern) {
            $out = preg_replace($pattern, '[PPPOE]', $out) ?? $out;
        }
        $out = preg_replace_callback('/\p{L}{4,}/u', function ($m) {
            return isset($this->nameTokens[mb_strtolower($m[0])]) ? '[NAMA]' : $m[0];
        }, $out) ?? $out;

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    public function roleForJid(?string $jid): string
    {
        $this->prepare();
        $phone = $this->normalizePhone((string) $jid);

        return $phone !== '' ? ($this->phoneRoles[$phone] ?? 'unknown') : 'unknown';
    }

    /**
     * @param  list<WaChatSignal>  $signals
     * @return \Generator<int, array<string, mixed>>
     */
    private function buildChunk(array $signals): \Generator
    {
        $ids = array_map(fn ($s) => (string) $s->message_id, $signals);
        $progress = $this->progressByMessage($ids);

        // Ticket linking: explicit (progress/command payload) first, then ticket code in text.
        $candidates = [];
        $codes = [];
        $explicitIds = [];
        foreach ($signals as $s) {
            $p = $progress[$s->message_id] ?? null;
            $list = [];
            if ($p && $p['ticket_id']) {
                $explicitIds[] = $p['ticket_id'];
                $list[] = ['explicit', (int) $p['ticket_id'], null];
            }
            if ($p && $p['ticket_code']) {
                $codes[] = $p['ticket_code'];
                $list[] = ['explicit', null, $p['ticket_code']];
            }
            if (preg_match('/\b(?:TCK|RPT)-\d{8}-[A-Z0-9]{4,5}\b/i', (string) $s->redacted_text, $m)) {
                $codes[] = strtoupper($m[0]);
                $list[] = ['code_in_text', null, strtoupper($m[0])];
            }
            $candidates[$s->message_id] = $list;
        }

        $tickets = $this->loadTickets($explicitIds, $codes);

        foreach ($signals as $s) {
            $p = $progress[$s->message_id] ?? null;
            $ticket = null;
            $method = 'none';
            foreach ($candidates[$s->message_id] ?? [] as [$cMethod, $tid, $code]) {
                $found = $tid ? ($tickets['id'][$tid] ?? null) : ($tickets['code'][strtoupper((string) $code)] ?? null);
                if ($found) {
                    $ticket = $found;
                    $method = $cMethod;
                    break;
                }
            }

            $occurred = $s->occurred_at;
            $became = false;
            $resolution = null;
            if ($ticket) {
                if ($occurred && $ticket->created_at) {
                    $delta = $occurred->diffInMinutes($ticket->created_at, false);
                    $became = $delta >= 0 && $delta <= self::TICKET_WINDOW_MINUTES;
                }
                if ($ticket->resolved_at && $ticket->created_at) {
                    $resolution = max(0, (int) $ticket->created_at->diffInMinutes($ticket->resolved_at));
                }
            }

            $senderKey = $s->sender_jid ? ($this->normalizePhone((string) $s->sender_jid) ?: (string) $s->sender_jid) : null;

            yield [
                'dataset_version' => self::DATASET,
                'signal_id' => $s->id,
                'message_hash' => $this->hash((string) $s->message_id),
                'occurred_at' => $occurred?->copy()->setTimezone('Asia/Jakarta')->toIso8601String(),
                'group_hash' => $this->hash((string) $s->group_jid),
                'group_role' => $this->groupRoles[(string) $s->group_jid] ?? 'work',
                'sender_hash' => $this->hash($senderKey),
                'sender_role' => $this->roleForJid($s->sender_jid),
                'text' => $this->redact((string) $s->redacted_text),
                'has_image' => (bool) ($s->payload['has_image'] ?? false),
                'intent_rule' => $s->intent,
                'domain_rule' => $s->domain,
                'severity_rule' => $s->severity,
                'command' => $s->command,
                'progress_source' => $p['source'] ?? 'none',
                'progress_action' => isset($p['action']) ? $this->redact((string) $p['action']) : null,
                'progress_site' => isset($p['site']) ? $this->redact((string) $p['site']) : null,
                'progress_status' => $p['status'] ?? null,
                'linked_ticket_id' => $ticket?->id,
                'link_method' => $method,
                'ticket_type' => $ticket?->type,
                'ticket_priority' => $ticket?->priority,
                'ticket_category' => $ticket?->gangguan ? $this->redact((string) $ticket->gangguan) : null,
                'ticket_status' => $ticket?->status,
                'became_ticket' => $became,
                'resolution_minutes' => $resolution,
                'label_human' => null,
            ];
        }
    }

    /**
     * @param  list<string>  $messageIds
     * @return array<string, array<string, mixed>>
     */
    private function progressByMessage(array $messageIds): array
    {
        $out = [];

        if (Schema::hasTable('technician_wa_command_logs')) {
            foreach (DB::table('technician_wa_command_logs')->whereIn('message_id', $messageIds)->get(['message_id', 'command', 'action', 'payload']) as $row) {
                $payload = $this->decode($row->payload);
                $out[$row->message_id] = [
                    'source' => 'command_log',
                    'action' => $row->action ?: $row->command,
                    'site' => null,
                    'status' => null,
                    'ticket_id' => $this->pickId($payload),
                    'ticket_code' => $this->pickCode($payload),
                ];
            }
        }

        // Progress reports are the stronger label and take precedence.
        if (Schema::hasTable('technician_progress_reports')) {
            foreach (DB::table('technician_progress_reports')->whereIn('message_id', $messageIds)->get(['message_id', 'actions', 'sites', 'status', 'meta']) as $row) {
                $meta = $this->decode($row->meta);
                $existing = $out[$row->message_id] ?? [];
                $out[$row->message_id] = [
                    'source' => 'progress_report',
                    'action' => $this->firstScalar($this->decode($row->actions)) ?? ($existing['action'] ?? null),
                    'site' => $this->firstScalar($this->decode($row->sites)),
                    'status' => $row->status,
                    'ticket_id' => $this->pickId($meta) ?? ($existing['ticket_id'] ?? null),
                    'ticket_code' => $this->pickCode($meta) ?? ($existing['ticket_code'] ?? null),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @param  list<string>  $codes
     * @return array{id: array<int, SupportTicket>, code: array<string, SupportTicket>}
     */
    private function loadTickets(array $ids, array $codes): array
    {
        $res = ['id' => [], 'code' => []];
        if ($ids === [] && $codes === []) {
            return $res;
        }

        $q = SupportTicket::query()->where(function ($w) use ($ids, $codes) {
            if ($ids !== []) {
                $w->whereIn('id', $ids);
            }
            if ($codes !== []) {
                $w->orWhereIn('code', $codes)->orWhereIn('report_id', $codes);
            }
        });
        foreach ($q->get(['id', 'code', 'report_id', 'type', 'priority', 'status', 'gangguan', 'created_at', 'resolved_at']) as $t) {
            $res['id'][$t->id] = $t;
            $res['code'][strtoupper((string) $t->code)] = $t;
            $res['code'][strtoupper((string) $t->report_id)] = $t;
        }

        return $res;
    }

    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;

        foreach (User::query()->whereNotNull('phone')->get(['phone', 'role', 'name']) as $u) {
            $phone = $this->normalizePhone((string) $u->phone);
            if ($phone !== '') {
                $this->phoneRoles[$phone] = $u->role === 'teknisi' ? 'teknisi' : 'admin';
            }
            $this->addNameTokens((string) $u->name);
        }

        if (Schema::hasTable('technician_members')) {
            foreach (TechnicianMember::query()->pluck('name') as $name) {
                $this->addNameTokens((string) $name);
            }
        }

        Customer::withTrashed()->select(['id', 'name', 'pppoe_user'])->chunkById(2000, function ($rows) {
            foreach ($rows as $c) {
                $this->addNameTokens((string) $c->name);
                $pppoe = strtolower(trim((string) $c->pppoe_user));
                if (strlen($pppoe) >= 4) {
                    $this->pppoeUsers[$pppoe] = true;
                }
            }
        });

        $roles = [
            'work' => 'wa_chat_work_group_ids',
            'ticket' => 'wa_ticket_group_jid',
            'input' => 'wa_ticket_progress_input_group_jid',
            'share' => 'wa_ticket_progress_share_group_jid',
        ];
        foreach ($roles as $role => $key) {
            foreach ((array) Setting::get($key) as $jid) {
                if (is_string($jid) && $jid !== '') {
                    $this->groupRoles[$jid] = $role;
                }
            }
        }
    }

    /** @return list<string> regexes over chunks of the username list, longest names first */
    private function pppoePatterns(): array
    {
        if ($this->pppoePatterns !== null) {
            return $this->pppoePatterns;
        }
        $users = array_values(array_filter(
            array_map('strval', array_keys($this->pppoeUsers)),
            fn (string $u) => strlen($u) >= 4 && ! str_contains('pppoe nama tikor email url', $u),
        ));
        usort($users, fn ($x, $y) => strlen($y) <=> strlen($x));
        $this->pppoePatterns = [];
        foreach (array_chunk($users, 400) as $chunk) {
            $this->pppoePatterns[] = '/'.implode('|', array_map(fn ($u) => preg_quote($u, '/'), $chunk)).'/i';
        }

        return $this->pppoePatterns;
    }

    private function addNameTokens(string $name): void
    {
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) >= 4 && ! in_array($token, self::STOPWORDS, true)) {
                $this->nameTokens[$token] = true;
            }
        }
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', explode('@', $value)[0]) ?? '';
        if ($digits === '' || strlen($digits) < 8) {
            return '';
        }
        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $d = is_string($value) ? json_decode($value, true) : null;

        return is_array($d) ? $d : [];
    }

    /**
     * Real writers nest ticket references: progress reports keep meta.fields,
     * command logs keep payload.parsed / payload.stored (TechnicianProgressParser/Service).
     *
     * @return list<array>
     */
    private function scopes(array $data): array
    {
        $scopes = [$data];
        foreach (['fields', 'stored', 'parsed'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $scopes[] = $data[$k];
                foreach (['fields', 'meta'] as $k2) {
                    if (isset($data[$k][$k2]) && is_array($data[$k][$k2])) {
                        $scopes[] = $data[$k][$k2];
                        if (isset($data[$k][$k2]['fields']) && is_array($data[$k][$k2]['fields'])) {
                            $scopes[] = $data[$k][$k2]['fields'];
                        }
                    }
                }
            }
        }

        return $scopes;
    }

    private function pickId(array $data): ?int
    {
        foreach ($this->scopes($data) as $scope) {
            foreach (['ticket_id', 'support_ticket_id'] as $k) {
                if (isset($scope[$k]) && is_numeric($scope[$k])) {
                    return (int) $scope[$k];
                }
            }
        }

        return null;
    }

    private function pickCode(array $data): ?string
    {
        foreach ($this->scopes($data) as $scope) {
            foreach (['ticket_code', 'ticket_report_id'] as $k) {
                if (! empty($scope[$k]) && is_string($scope[$k])) {
                    return strtoupper($scope[$k]);
                }
            }
        }

        return null;
    }

    private function firstScalar(array $data): ?string
    {
        foreach ($data as $v) {
            if (is_scalar($v) && (string) $v !== '') {
                return (string) $v;
            }
            if (is_array($v)) {
                $inner = $this->firstScalar($v);
                if ($inner !== null) {
                    return $inner;
                }
            }
        }

        return null;
    }
}
