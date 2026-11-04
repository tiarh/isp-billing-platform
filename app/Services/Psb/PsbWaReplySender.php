<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PSB WA: group reply plumbing (dedupe key, send, health cache, mention prefix).
 *
 * Moved as-is from PsbOrchestratorService (PR 1 of the PSB refactor). The
 * orchestrator's reply() stays the single call point and still owns the
 * per-instance replyDeliveryFailed flag; it sets that flag when send() is false.
 */
final class PsbWaReplySender
{
    public function __construct(
        private readonly EvolutionApiService $evolution,
        private readonly PsbConfigResolver $config,
    ) {}

    /**
     * Send one reply to the group. Returns false (and records the error in the
     * health cache) when delivery failed; the caller records the failure flag.
     *
     * @param  list<string>  $mentionJids
     */
    public function send(string $groupJid, string $text, array $mentionJids = []): bool
    {
        try {
            $response = $this->evolution->forChannel('ops')->sendToGroup($groupJid, $text, $mentionJids);
            if (! empty($response['success'])) {
                Cache::put('psb_wa:last_reply_status', 'OK', now()->addDays(7));
                Cache::put('psb_wa:last_reply_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
                Cache::forget('psb_wa:last_reply_error');

                return true;
            } else {
                throw new \Exception('Evolution API returned failure: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            Cache::forget($this->replyDedupeKey($groupJid, $text, $mentionJids));
            Cache::put('psb_wa:last_reply_status', 'error', now()->addDays(7));
            Cache::put('psb_wa:last_reply_error', class_basename($e), now()->addDays(7));
            Log::warning('[PSB] sendToGroup failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * @param  list<string>  $mentionJids
     */
    public function allowReplyMessage(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $seconds = min(60, max(3, (int) Setting::get('psb_reply_dedupe_seconds', 15)));

        return Cache::add(
            $this->replyDedupeKey($groupJid, $text, $mentionJids),
            1,
            now()->addSeconds($seconds)
        );
    }

    /**
     * @param  list<string>  $mentionJids
     */
    public function replyDedupeKey(string $groupJid, string $text, array $mentionJids = []): string
    {
        sort($mentionJids);

        return 'psb:reply-dedupe:'.md5(
            $this->config->normalizeGroupJid($groupJid)."\n".trim($text)."\n".implode(',', $mentionJids)
        );
    }

    /**
     * Prefiks mention pengirim (P2.0) — pesan sukses PPPoE / OLT / HTB.
     *
     * WA HANYA tag kalau teks mengandung @628… (angka) + mentioned JID phone.
     *
     * @NamaTampilan TIDAK pernah memicu ping (bug format lama).
     *
     * @return array{0: string, 1: list<string>} [textPrefix, mentionJids]
     */
    public function mentionPrefixForDraft(PsbDraft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $jid = trim((string) ($meta['sender_jid_pn'] ?? $draft->sender_jid ?? ''));
        $lid = trim((string) ($draft->sender_jid ?? ''));
        $name = trim((string) ($draft->sender_name ?? ''));
        if ($jid === '' && $lid === '' && $name === '') {
            return ['', []];
        }

        $mentions = [];
        $phone = null;
        // Prefer phone JID
        foreach ([$jid, $lid] as $cand) {
            if ($cand === '') {
                continue;
            }
            if (preg_match('/^(\d+)@(s\.whatsapp\.net|c\.us)$/i', $cand, $m)) {
                $phone = $m[1];
                $mentions[] = $m[1].'@s.whatsapp.net';
                break;
            }
            if (preg_match('/^(\d+)$/', $cand)) {
                $phone = $cand;
                $mentions[] = $cand.'@s.whatsapp.net';
                break;
            }
        }
        // Fallback: still pass lid (may not ping)
        if ($mentions === [] && $lid !== '') {
            $mentions[] = $lid;
        }

        $label = $name !== '' ? $name : ($phone ?: 'teknisi');
        if ($phone) {
            // Correct WA format: @phone then display name
            $prefix = $label."\n";
        } else {
            // No phone — visual only, may not notify
            $prefix = $label."\n";
        }

        return [$prefix, $mentions];
    }
}
