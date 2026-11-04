<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WaChatSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WaChatIntelligenceService
{
    protected ?bool $tableReady = null;

    /**
     * @param  array<string, mixed>  $msg
     */
    public function observeWebhookMessage(array $msg, string $pushName = ''): ?WaChatSignal
    {
        if (! $this->tableReady() || ! empty($msg['key']['fromMe'])) {
            return null;
        }

        $groupJid = $this->normalizeJid((string) ($msg['key']['remoteJid'] ?? ''));
        if ($groupJid === '' || ! str_contains($groupJid, '@g.us')) {
            return null;
        }

        $channel = $this->resolveChannel($groupJid);
        if ($channel === null) {
            return null;
        }

        $text = $this->extractText($msg);
        if (trim($text) === '' && empty($msg['message']['imageMessage'])) {
            return null;
        }

        $messageId = (string) ($msg['key']['id'] ?? '');
        if ($messageId === '') {
            $messageId = 'fallback:'.sha1($groupJid.'|'.$text.'|'.($msg['messageTimestamp'] ?? ''));
        }

        if (WaChatSignal::query()->where('message_id', $messageId)->exists()) {
            return null;
        }

        $senderJid = (string) (
            $msg['key']['participantAlt']
            ?? $msg['key']['participantPn']
            ?? $msg['key']['participant']
            ?? ''
        );
        if ($senderJid !== '' && ! str_contains($senderJid, '@')) {
            $senderJid .= '@s.whatsapp.net';
        }

        $classified = $this->classify($text, $channel);
        $redacted = $this->redact($text);

        $signal = WaChatSignal::query()->create([
            'channel' => $channel,
            'domain' => $classified['domain'],
            'intent' => $classified['intent'],
            'severity' => $classified['severity'],
            'command' => $classified['command'],
            'group_jid' => $groupJid,
            'group_label' => $this->groupLabel($groupJid),
            'sender_jid' => $senderJid ?: null,
            'sender_name' => $pushName !== '' ? mb_substr($pushName, 0, 120) : null,
            'message_id' => $messageId,
            'summary' => $this->summary($redacted, $classified),
            'redacted_text' => $redacted,
            'payload' => [
                'read_only' => true,
                'auto_reply_enabled' => $this->autoReplyEnabled(),
                'has_image' => ! empty($msg['message']['imageMessage']),
            ],
            'occurred_at' => $this->occurredAt($msg),
        ]);

        return $signal;
    }

    public function autoReplyEnabled(): bool
    {
        return (bool) Setting::get('wa_chat_intelligence_reply_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array
    {
        if (! $this->tableReady()) {
            return $this->emptyPayload();
        }

        return Cache::remember('wa_chat_intelligence.dashboard', now()->addMinutes(5), fn () => $this->buildDashboardPayload());
    }

    protected function buildDashboardPayload(): array
    {
        $since7 = now()->subDays(7);
        $since30 = now()->subDays(30);

        $byIntent = WaChatSignal::query()
            ->where('created_at', '>=', $since30)
            ->selectRaw('intent, COUNT(*) as total')
            ->groupBy('intent')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['intent' => (string) $r->intent, 'total' => (int) $r->total])
            ->values()
            ->all();

        $latest = WaChatSignal::query()->latest('created_at')->first();
        $summary = WaChatSignal::query()
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7_days,
                 SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_30_days,
                 SUM(CASE WHEN channel = ? AND created_at >= ? THEN 1 ELSE 0 END) as ticketing,
                 SUM(CASE WHEN channel = ? AND created_at >= ? THEN 1 ELSE 0 END) as activation',
                [$since7, $since30, 'work', $since30, 'activation', $since30]
            )
            ->first();

        return [
            'ready' => true,
            'reply_enabled' => $this->autoReplyEnabled(),
            'last_sync' => $latest?->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'summary' => [
                'last_7_days' => (int) ($summary?->last_7_days ?? 0),
                'last_30_days' => (int) ($summary?->last_30_days ?? 0),
                'ticketing' => (int) ($summary?->ticketing ?? 0),
                'activation' => (int) ($summary?->activation ?? 0),
            ],
            'by_intent' => $byIntent,
            'work_signals' => $this->signalsFor('work', 5),
            'activation_signals' => $this->signalsFor('activation', 5),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function signalsFor(string $channel, int $limit = 6): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return Cache::remember("wa_chat_intelligence.signals.{$channel}.{$limit}", now()->addMinute(), fn () => WaChatSignal::query()
            ->where('channel', $channel)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (WaChatSignal $s) => [
                'id' => $s->id,
                'intent' => $s->intent,
                'domain' => $s->domain,
                'severity' => $s->severity,
                'summary' => $s->summary,
                'sender_name' => $s->sender_name,
                'group_label' => $s->group_label,
                'created_at' => $s->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            ])
            ->values()
            ->all());
    }

    protected function tableReady(): bool
    {
        return $this->tableReady ??= Schema::hasTable('wa_chat_signals');
    }

    /**
     * @return array<string, string>
     */
    public function commandExamples(): array
    {
        return [
            'help' => '#help',
            'psb' => "#psb contoh\nNama: GATOT SUBROTO\nODP: ODP-ARJO-01\nRT: 01\nRW: 04\nTikor: -7.98, 112.63\nDaerah: Randuagung\nRouter: ra\nPaket: 200M\nSN: FHTT12345678",
            'migrasi' => '#migrasi pppoe: USER_LAMA sn: FHTT12345678 daerah: suko odp: 01 rt: 03 rw: 04',
            'status' => '#status #ID',
            'lanjut' => '#lanjut #ID',
            'batal' => '#batal #ID',
            'pppoe' => '#pppoe cari nama/user/telp',
        ];
    }

    /**
     * @return array{domain: string, intent: string, severity: string, command: ?string}
     */
    public function classify(string $text, string $channel = 'work'): array
    {
        $t = mb_strtolower(trim($text));
        $command = null;
        if (preg_match('/^\s*(#help|#\s*psb|\/psb|#\s*migrasi|\/migrasi|#status|#lanjut|#batal|#\s*pppoe|\/pppoe)\b/iu', $text, $m)) {
            $command = preg_replace('/\s+/', ' ', strtolower($m[1])) ?: strtolower($m[1]);
        }

        if ($command !== null) {
            $domain = str_contains($command, 'migrasi') ? 'migration' : (str_contains($command, 'pppoe') ? 'network' : 'psb');
            $intent = match (true) {
                str_contains($command, 'help') => 'command_help',
                str_contains($command, 'pppoe') => 'pppoe_search',
                str_contains($command, 'status') => 'status_check',
                str_contains($command, 'lanjut') => 'continue_request',
                str_contains($command, 'batal') => 'cancel_request',
                str_contains($command, 'migrasi') => 'migration_command',
                default => 'psb_command',
            };

            return compact('domain', 'intent') + ['severity' => 'normal', 'command' => $command];
        }

        $intent = 'noise';
        $severity = 'normal';
        $domain = $channel === 'activation' ? 'psb' : 'ticketing';

        if (preg_match('/\b(gagal|error|timeout|hang|down|los|putus|offline|lampu merah|tidak bisa|ga bisa|gak bisa)\b/iu', $t)) {
            $intent = 'incident';
            $severity = preg_match('/\b(down|los|hang|timeout|massal|semua)\b/iu', $t) ? 'high' : 'normal';
        } elseif (preg_match('/\b(bug|salah|dobel|double|kacau|nyasar)\b/iu', $t)) {
            $intent = 'bug_report';
            $severity = 'high';
        } elseif (preg_match('/\b(tolong tambah|fitur|request|saran|seharusnya|enaknya)\b/iu', $t)) {
            $intent = 'feature_request';
        } elseif (preg_match('/\b(status|progress|sampai mana|cek)\b/iu', $t)) {
            $intent = 'status_check';
        } elseif (preg_match('/\b(psb|aktivasi|sn|odp|olt|vlan|pppoe|migrasi)\b/iu', $t)) {
            $intent = 'activation_signal';
            $domain = str_contains($t, 'migrasi') ? 'migration' : 'psb';
        }

        return compact('domain', 'intent', 'severity') + ['command' => null];
    }

    public function redact(string $text): string
    {
        $out = preg_replace('/\b\d{16}\b/', '[NIK]', $text) ?? $text;
        $out = preg_replace('/\b(?:\+?62|0)8\d{7,12}\b/', '[HP]', $out) ?? $out;
        $out = preg_replace('/(-?\d{1,2}[.,]\d{4,})\s*[, ]\s*(-?\d{2,3}[.,]\d{4,})/', '[TIKOR]', $out) ?? $out;

        return trim(mb_substr(preg_replace('/\s+/', ' ', $out) ?? $out, 0, 500));
    }

    protected function resolveChannel(string $groupJid): ?string
    {
        if (in_array($groupJid, $this->allowedGroups('work'), true)) {
            return 'work';
        }
        if (in_array($groupJid, $this->allowedGroups('activation'), true)) {
            return 'activation';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function allowedGroups(string $channel): array
    {
        $keys = $channel === 'work'
            ? ['wa_chat_work_group_ids', 'wa_ticket_progress_input_group_jid', 'wa_ticket_progress_share_group_jid', 'wa_ticket_group_jid']
            : ['wa_chat_activation_group_ids', 'psb_wa_group_ids'];

        $groups = [];
        foreach ($keys as $key) {
            $value = Setting::get($key, []);
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $jid) {
                $norm = $this->normalizeJid((string) $jid);
                if ($norm !== '') {
                    $groups[] = $norm;
                }
            }
        }

        return array_values(array_unique($groups));
    }

    protected function normalizeJid(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        if (! str_contains($jid, '@')) {
            $jid .= '@g.us';
        }

        return strtolower($jid);
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    protected function extractText(array $msg): string
    {
        $content = $msg['message'] ?? [];
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'] as $wrapper) {
            if (! empty($content[$wrapper]['message']) && is_array($content[$wrapper]['message'])) {
                $content = $content[$wrapper]['message'];
            }
        }

        return (string) (
            $content['conversation']
            ?? $content['extendedTextMessage']['text']
            ?? $content['imageMessage']['caption']
            ?? $content['documentWithCaptionMessage']['message']['documentMessage']['caption']
            ?? $content['documentMessage']['caption']
            ?? ''
        );
    }

    /**
     * @param  array<string, mixed>  $classified
     */
    protected function summary(string $redacted, array $classified): string
    {
        $prefix = Str::headline((string) $classified['intent']);
        $body = $redacted !== '' ? $redacted : '(media tanpa caption)';

        return mb_substr($prefix.': '.$body, 0, 180);
    }

    protected function groupLabel(string $groupJid): string
    {
        return preg_replace('/@g\.us$/', '', $groupJid) ?: $groupJid;
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    protected function occurredAt(array $msg): ?Carbon
    {
        $ts = $msg['messageTimestamp'] ?? null;
        if (is_numeric($ts) && (int) $ts > 0) {
            return now()->setTimestamp((int) $ts);
        }

        return now();
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPayload(): array
    {
        return [
            'ready' => false,
            'reply_enabled' => false,
            'last_sync' => null,
            'summary' => ['last_7_days' => 0, 'last_30_days' => 0, 'ticketing' => 0, 'activation' => 0],
            'by_intent' => [],
            'work_signals' => [],
            'activation_signals' => [],
        ];
    }
}
