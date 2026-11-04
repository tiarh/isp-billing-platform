<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\PsbWaMessages;
use App\Services\Recap\RecapRequest;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "#rekap psb <area|semua> [tanggal]" reply: text + Excel.
 *
 * Read-only on business tables (only a rate-limit cache entry is written). No
 * OLT / MikroTik / RADIUS calls and no PSB / migration locks. Runs on the `wa`
 * queue, never on `psb` or `migration-*`.
 */
class ProcessPsbRecapCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const GROUP_COOLDOWN_SECONDS = 20;

    public const SENDER_PER_HOUR = 10;

    public const FILE_RETENTION_DAYS = 7;

    public int $tries = 2;

    public int $timeout = 60;

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
            return 'psb-recap-msg:'.$mid;
        }

        $group = (string) ($this->payload['group_jid'] ?? '');
        $text = substr(sha1((string) ($this->payload['text'] ?? '')), 0, 16);

        return 'psb-recap-fb:'.$group.':'.$text;
    }

    public static function isEnabled(): bool
    {
        return in_array(Setting::get('psb_recap_cmd_enabled', 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }

    public function handle(
        PsbRecapCommandParser $parser,
        PsbAreaRecapService $service,
        PsbWaMessages $messages,
        EvolutionApiService $evolution,
    ): void {
        // Flag turned off after dispatch: do nothing at all.
        if (! self::isEnabled()) {
            return;
        }

        $group = (string) ($this->payload['group_jid'] ?? '');
        if ($group === '') {
            return;
        }

        // Skynet Report group: re-checked at run time (flag turned off, group removed or
        // moved into the PSB allowlist after dispatch -> silence). Replies go out on the
        // Report channel (ops|ticket); the PSB group path below keeps using `ops`.
        $isReport = ($this->payload['context'] ?? null) === 'report';
        $channel = 'ops';
        if ($isReport) {
            $registry = app(ReportGroupRegistry::class);
            if (! $registry->commandsEnabled() || ! $registry->isReportGroup($group) || $registry->isPsbGroup($group)) {
                return;
            }
            $channel = $registry->channel();
        }

        $this->pruneOldFiles();

        $request = $parser->parse((string) ($this->payload['text'] ?? ''));
        if ($request === null) {
            return;
        }

        // Webhook replay / duplicate dispatch of an already handled message: silence.
        if ($this->messageId() !== '' && Cache::has($this->marker('done'))) {
            return;
        }

        $wa = $evolution->forChannel($channel);

        // Rate limit only on the first attempt (a retry must not be blocked by itself).
        if ($this->attempts() <= 1) {
            $blocked = $this->rateLimited();
            if ($blocked !== null) {
                // Done first: a retry must never turn a blocked request into a full recap.
                $this->markDone();
                // At most one notice per window; later blocked attempts stay silent.
                if ($this->claimNotice($blocked)) {
                    $this->sendText($wa, $group, $blocked === 'group' ? $messages->recapRateLimitedGroup() : $messages->recapRateLimitedSender());
                }

                return;
            }
        }

        try {
            $this->respond($request, $group, $wa, $service, $messages);
            $this->markDone();
        } catch (\Throwable $e) {
            Log::error('[PSB-RECAP] job failed', [
                'exception_class' => $e::class,
                'message_id' => $this->payload['message_id'] ?? null,
            ]);
            if ($this->attempts() >= $this->tries) {
                try {
                    $this->sendText($wa, $group, $messages->recapInternalError());
                } catch (\Throwable) {
                    // best effort
                }
                $this->markDone();
            }

            throw $e;
        }
    }

    private function respond(RecapRequest $request, string $group, EvolutionApiService $wa, PsbAreaRecapService $service, PsbWaMessages $messages): void
    {
        $reply = match ($request->error) {
            RecapRequest::ERROR_MISSING_AREA => $messages->recapUsage(),
            RecapRequest::ERROR_INVALID_DATE => $messages->recapInvalidDate(),
            RecapRequest::ERROR_RANGE_TOO_LONG => $messages->recapRangeTooLong($request->maxDays),
            default => null,
        };
        if ($reply !== null) {
            $this->sendMainText($wa, $group, $reply);

            return;
        }

        $area = $service->resolveArea($request);
        if ($area['status'] === 'ambiguous') {
            $this->sendMainText($wa, $group, $messages->recapAreaAmbiguous($request->areaQuery, $area['candidates']));

            return;
        }
        if ($area['status'] === 'unmatched') {
            $this->sendMainText($wa, $group, $messages->recapAreaUnmatched($request->areaQuery, $area['candidates']));

            return;
        }

        $data = $service->collect($request, $area['area_id'], $area['area_name']);
        $limit = max(1, (int) Setting::get('psb_recap_cmd_list_limit', 30));
        $this->sendMainText($wa, $group, $messages->areaRecap($data, $limit));

        if ($data['total'] === 0) {
            return;
        }

        // A retry after the document went out must not resend it.
        if ($this->messageId() !== '' && Cache::has($this->marker('doc'))) {
            return;
        }

        // The text is already out. A failed document only adds a short notice.
        $sent = false;
        $path = null;
        try {
            $path = $this->xlsxPath($data);
            $service->buildXlsx($data, $path);
            $result = $wa->sendDocument($group, $path, $this->caption($data), basename($path));
            $sent = ! empty($result['success']);
            if ($sent) {
                $this->remember('doc');
            } else {
                Log::warning('[PSB-RECAP] sendDocument failed', ['error' => (string) ($result['error'] ?? 'unknown')]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PSB-RECAP] excel build/send failed', ['exception_class' => $e::class]);
        } finally {
            // The file holds names + PPPoE users: never leave it on disk (a retry regenerates it).
            if ($path !== null) {
                try {
                    File::delete($path);
                } catch (\Throwable) {
                    // the 7-day prune is the safety net for orphans
                }
            }
        }

        if (! $sent) {
            $this->sendText($wa, $group, $messages->recapDocumentFailed());
        }
    }

    private function messageId(): string
    {
        return (string) ($this->payload['message_id'] ?? '');
    }

    private function marker(string $kind): string
    {
        return 'psb-recap:'.$kind.':'.$this->messageId();
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

    /**
     * The main reply text of this message. Sent once per inbound message id: a
     * retry after it went out skips it and only retries the Excel / notice part.
     */
    private function sendMainText(EvolutionApiService $wa, string $group, string $text): void
    {
        if ($this->messageId() !== '' && Cache::has($this->marker('text'))) {
            return;
        }
        $this->sendText($wa, $group, $text);
        $this->remember('text');
    }

    /** True for the first blocked attempt of a window only (group: cooldown, sender: the hour). */
    private function claimNotice(string $kind): bool
    {
        if ($kind === 'group') {
            $key = 'psb-recap:notice:group:'.md5((string) ($this->payload['group_jid'] ?? ''));
            $ttl = now()->addSeconds(self::GROUP_COOLDOWN_SECONDS);
        } else {
            $key = 'psb-recap:notice:sender:'.md5((string) ($this->payload['sender_jid'] ?? 'unknown')).':'.OpsTime::now()->format('YmdH');
            $ttl = now()->addHour();
        }

        return Cache::add($key, 1, $ttl);
    }

    private function sendText(EvolutionApiService $wa, string $group, string $text): void
    {
        $result = $wa->sendToGroup($group, $text);
        if (empty($result['success'])) {
            throw new \RuntimeException('Recap text send failed: '.($result['error'] ?? 'unknown'));
        }
    }

    /**
     * @return 'group'|'sender'|null
     */
    private function rateLimited(): ?string
    {
        $group = (string) ($this->payload['group_jid'] ?? '');
        if (! Cache::add('psb-recap:group:'.md5($group), 1, now()->addSeconds(self::GROUP_COOLDOWN_SECONDS))) {
            return 'group';
        }

        $sender = (string) ($this->payload['sender_jid'] ?? 'unknown');
        $key = 'psb-recap:sender:'.md5($sender).':'.OpsTime::now()->format('YmdH');
        Cache::add($key, 0, now()->addHour());
        if ((int) Cache::increment($key) > self::SENDER_PER_HOUR) {
            return 'sender';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function xlsxPath(array $data): string
    {
        $slug = Str::slug((string) $data['area_label']) ?: 'area';

        return storage_path('app/psb/recap/rekap-psb-'.$slug.'-'.$data['from'].'-'.$data['to'].'-'.OpsTime::now()->format('His').'-'.Str::lower(Str::random(6)).'.xlsx');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function caption(array $data): string
    {
        return 'Rekap PSB '.$data['area_label'].' '
            .date('d/m/Y', strtotime((string) $data['from'])).' - '.date('d/m/Y', strtotime((string) $data['to']));
    }

    /** Safety net for orphaned Excel files (7 days). Never touches anything outside the recap folder. */
    private function pruneOldFiles(): void
    {
        try {
            $dir = storage_path('app/psb/recap');
            if (! is_dir($dir)) {
                return;
            }
            $cutoff = now()->subDays(self::FILE_RETENTION_DAYS)->getTimestamp();
            foreach (File::files($dir) as $file) {
                if ($file->getExtension() === 'xlsx' && $file->getMTime() < $cutoff) {
                    File::delete($file->getPathname());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[PSB-RECAP] prune failed', ['exception_class' => $e::class]);
        }
    }
}
