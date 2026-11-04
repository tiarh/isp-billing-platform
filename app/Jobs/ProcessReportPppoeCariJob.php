<?php

namespace App\Jobs;

use App\Services\EvolutionApiService;
use App\Services\PppoeSearchService;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "#pppoe cari <nama/user/telp>" inside the Skynet Report group. Same reply as the Aktivasi group
 * (PppoeSearchService), but sent straight through EvolutionApiService on the Report channel: it does
 * NOT use PsbOrchestratorService::reply(), so the PSB WA health cache (psb_wa:last_reply_*) is never
 * touched. Read-only (DB + live MikroTik read, password never shown), `wa` queue, idempotent per
 * inbound message id, rate limited per group (10 s) and per sender (30 / hour).
 */
class ProcessReportPppoeCariJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const GROUP_COOLDOWN_SECONDS = 10;

    public const SENDER_PER_HOUR = 30;

    public int $tries = 2;

    /** Live MikroTik reads for up to 5 customers (+ send) can exceed 60 s; stays below worker-wa stop timeout (330). */
    public int $timeout = 180;

    public int $uniqueFor = 300;

    /**
     * @param  array<string, mixed>  $payload  group_jid, sender_jid, sender_name, message_id, text
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('wa');
    }

    public function uniqueId(): string
    {
        $mid = (string) ($this->payload['message_id'] ?? '');
        if ($mid !== '') {
            return 'report-pppoe-cari-msg:'.$mid;
        }

        $group = (string) ($this->payload['group_jid'] ?? '');
        $text = substr(sha1((string) ($this->payload['text'] ?? '')), 0, 16);

        return 'report-pppoe-cari-fb:'.$group.':'.$text;
    }

    /**
     * Strict single-line "#pppoe cari [query]". Returns the query ('' = usage) or null when the text
     * is anything else (other words after #pppoe, "/pppoe", multi-line, text before the command...).
     */
    public static function extractQuery(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || preg_match('/[\r\n]/u', $text)) {
            return null;
        }
        if (! preg_match('/^#\s*pppoe\s+cari(?:\s+(\S.*))?$/iu', $text, $m)) {
            return null;
        }

        return trim($m[1] ?? '');
    }

    public function handle(PppoeSearchService $search, EvolutionApiService $evolution, ReportGroupRegistry $registry): void
    {
        $group = (string) ($this->payload['group_jid'] ?? '');
        // Flag/group re-checked at run time (turned off, group removed or moved into the PSB list
        // after dispatch -> silence).
        if ($group === '' || ! $registry->pppoeCommandEnabled() || ! $registry->isReportGroup($group) || $registry->isPsbGroup($group)) {
            return;
        }

        $query = self::extractQuery((string) ($this->payload['text'] ?? ''));
        if ($query === null) {
            return;
        }

        // Webhook replay / duplicate dispatch of an already handled message: silence.
        if ($this->messageId() !== '' && Cache::has($this->marker('done'))) {
            return;
        }

        $wa = $evolution->forChannel($registry->channel());

        // Rate limit only on the first attempt (a retry must not be blocked by itself).
        if ($this->attempts() <= 1) {
            $blocked = $this->rateLimited();
            if ($blocked !== null) {
                $this->markDone();
                // At most one notice per window; later blocked attempts stay silent.
                if ($this->claimNotice($blocked)) {
                    $this->sendText($wa, $group, $blocked === 'group'
                        ? '⏳ Pencarian PPPoE terlalu cepat. Coba lagi dalam beberapa detik.'
                        : '⏳ Batas pencarian PPPoE per jam tercapai ('.self::SENDER_PER_HOUR.'x). Coba lagi nanti.');
                }

                return;
            }
        }

        try {
            if ($this->messageId() === '' || ! Cache::has($this->marker('text'))) {
                $result = $search->search($query);
                $this->sendText($wa, $group, $result['text']);
                $this->remember('text');
            }
            $this->markDone();
        } catch (\Throwable $e) {
            Log::error('[REPORT-PPPOE] job failed', [
                'exception_class' => $e::class,
                'message_id' => $this->payload['message_id'] ?? null,
            ]);
            if ($this->attempts() >= $this->tries) {
                try {
                    $this->sendText($wa, $group, '⚠️ Pencarian PPPoE gagal diproses. Coba lagi nanti.');
                } catch (\Throwable) {
                    // best effort
                }
                $this->markDone();
            }

            throw $e;
        }
    }

    private function messageId(): string
    {
        return (string) ($this->payload['message_id'] ?? '');
    }

    private function marker(string $kind): string
    {
        return 'report-pppoe-cari:'.$kind.':'.$this->messageId();
    }

    private function remember(string $kind): void
    {
        if ($this->messageId() !== '') {
            Cache::put($this->marker($kind), 1, now()->addDay());
        }
    }

    /** Whole message handled (or finally failed): a webhook replay is silently skipped. */
    private function markDone(): void
    {
        $this->remember('done');
    }

    /** True for the first blocked attempt of a window only (group: cooldown, sender: the hour). */
    private function claimNotice(string $kind): bool
    {
        if ($kind === 'group') {
            $key = 'report-pppoe-cari:notice:group:'.md5((string) ($this->payload['group_jid'] ?? ''));
            $ttl = now()->addSeconds(self::GROUP_COOLDOWN_SECONDS);
        } else {
            $key = 'report-pppoe-cari:notice:sender:'.md5(trim((string) ($this->payload['sender_jid'] ?? ''))).':'.OpsTime::now()->format('YmdH');
            $ttl = now()->addHour();
        }

        return Cache::add($key, 1, $ttl);
    }

    private function sendText(EvolutionApiService $wa, string $group, string $text): void
    {
        $result = $wa->sendToGroup($group, $text);
        if (empty($result['success'])) {
            throw new \RuntimeException('Report pppoe-cari text send failed: '.($result['error'] ?? 'unknown'));
        }
    }

    /**
     * @return 'group'|'sender'|null
     */
    private function rateLimited(): ?string
    {
        $group = (string) ($this->payload['group_jid'] ?? '');
        // No sender id: no shared hourly bucket (that would let one anonymous message starve the
        // group); only the group cooldown applies.
        $sender = trim((string) ($this->payload['sender_jid'] ?? ''));
        $key = $sender !== '' ? 'report-pppoe-cari:sender:'.md5($sender).':'.OpsTime::now()->format('YmdH') : null;

        // Hourly limit first (read-only): an over-limit sender must not claim the group cooldown.
        if ($key !== null && (int) Cache::get($key, 0) >= self::SENDER_PER_HOUR) {
            return 'sender';
        }

        if (! Cache::add('report-pppoe-cari:group:'.md5($group), 1, now()->addSeconds(self::GROUP_COOLDOWN_SECONDS))) {
            return 'group';
        }

        if ($key !== null) {
            Cache::add($key, 0, now()->addHour());
            Cache::increment($key);
        }

        return null;
    }
}
