<?php

namespace App\Jobs;

use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\Recap\MigrationRecapService;
use App\Services\Recap\OnuHealthService;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapTopicMessages;
use App\Services\Recap\RecapTopics;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "#rekap <topic> <area|semua>" reply for the non-psb topics (onu, migrasi): text + Excel.
 *
 * Read-only on business tables (only rate-limit / marker cache entries are written). No OLT /
 * MikroTik / RADIUS calls, no PSB / migration locks, never PsbOrchestratorService::reply.
 * `wa` queue only. The rate-limit keys are SHARED with "#rekap psb" (same group / sender budget).
 */
class ProcessRecapTopicCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FILE_RETENTION_DAYS = 7;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /**
     * @param  array<string, mixed>  $payload  group_jid, sender_jid, sender_name, message_id, text, context?
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('wa');
    }

    public function uniqueId(): string
    {
        $mid = (string) ($this->payload['message_id'] ?? '');
        if ($mid !== '') {
            return 'recap-topic-msg:'.$mid;
        }

        $group = (string) ($this->payload['group_jid'] ?? '');
        $text = substr(sha1((string) ($this->payload['text'] ?? '')), 0, 16);

        return 'recap-topic-fb:'.$group.':'.$text;
    }

    public function handle(
        PsbAreaRecapService $areas,
        OnuHealthService $onu,
        RecapTopicMessages $messages,
        EvolutionApiService $evolution,
    ): void {
        $group = (string) ($this->payload['group_jid'] ?? '');
        if ($group === '') {
            return;
        }

        $text = (string) ($this->payload['text'] ?? '');
        $topic = RecapTopics::matchTopic($text);
        if ($topic === null) {
            return;
        }

        // Runtime re-checks (flag turned off / group moved after dispatch -> silence).
        $isReport = ($this->payload['context'] ?? null) === RecapTopics::CONTEXT_REPORT;
        $channel = 'ops';
        if ($isReport) {
            $registry = app(ReportGroupRegistry::class);
            if (! RecapTopics::enabled($topic, RecapTopics::CONTEXT_REPORT) || ! $registry->isReportGroup($group) || $registry->isPsbGroup($group)) {
                return;
            }
            $channel = $registry->channel();
        } elseif (! RecapTopics::enabled($topic, RecapTopics::CONTEXT_PSB)) {
            return;
        }

        $this->pruneOldFiles($topic);

        $request = RecapTopics::parser($topic)?->parse($text);
        if ($request === null) {
            return;
        }

        if ($this->messageId() !== '' && Cache::has($this->marker('done'))) {
            return;
        }

        $wa = $evolution->forChannel($channel);

        if ($this->attempts() <= 1) {
            $blocked = $this->rateLimited();
            if ($blocked !== null) {
                $this->markDone();
                if ($this->claimNotice($blocked)) {
                    $this->sendText($wa, $group, $blocked === 'group' ? $messages->rateLimitedGroup() : $messages->rateLimitedSender());
                }

                return;
            }
        }

        try {
            $this->respond($topic, $request, $group, $wa, $areas, $onu, $messages);
            $this->markDone();
        } catch (\Throwable $e) {
            Log::error('[RECAP-TOPIC] job failed', [
                'topic' => $topic,
                'exception_class' => $e::class,
                'message_id' => $this->payload['message_id'] ?? null,
            ]);
            if ($this->attempts() >= $this->tries) {
                try {
                    $this->sendText($wa, $group, $messages->internalError());
                } catch (\Throwable) {
                    // best effort
                }
                $this->markDone();
            }

            throw $e;
        }
    }

    private function respond(string $topic, RecapRequest $request, string $group, EvolutionApiService $wa, PsbAreaRecapService $areas, OnuHealthService $onu, RecapTopicMessages $messages): void
    {
        $reply = match ($request->error) {
            RecapRequest::ERROR_MISSING_AREA => $messages->usage($topic),
            RecapRequest::ERROR_INVALID_DATE => $messages->invalidDate($topic),
            RecapRequest::ERROR_RANGE_TOO_LONG => $messages->rangeTooLong($request->maxDays),
            default => null,
        };
        if ($reply !== null) {
            $this->sendMainText($wa, $group, $reply);

            return;
        }

        $area = $areas->resolveArea($request);
        if ($area['status'] === 'ambiguous') {
            $this->sendMainText($wa, $group, $messages->areaAmbiguous($request->areaQuery, $area['candidates']));

            return;
        }
        if ($area['status'] === 'unmatched') {
            $this->sendMainText($wa, $group, $messages->areaUnmatched($request->areaQuery, $area['candidates']));

            return;
        }

        if ($topic === RecapTopics::MIGRASI) {
            $migration = app(MigrationRecapService::class);
            $data = $migration->collect($request, $area['area_id'], $area['area_name']);
            $this->sendMainText($wa, $group, $messages->migrasiRecap($data));
            $rows = (int) $data['total'];
            $build = static fn (string $path): string => $migration->buildXlsx($data, $path);
            $caption = 'Rekap migrasi '.$data['area_label'].' '.CarbonImmutable::parse($data['from'])->format('d/m').'-'.CarbonImmutable::parse($data['to'])->format('d/m/Y');
        } else {
            $data = $onu->collect($area['area_id'], $area['area_name'], null, $request->dateIgnored);
            $this->sendMainText($wa, $group, $messages->onuRecap($data));
            $rows = (int) $data['problem_total'];
            $build = static fn (string $path): string => $onu->buildXlsx($data, $path);
            $caption = 'Rekap kesehatan ONU '.$data['area_label'].' '.OpsTime::format($data['generated_at']);
        }

        if ($rows === 0) {
            return;
        }

        // A retry after the document went out must not resend it.
        if ($this->messageId() !== '' && Cache::has($this->marker('doc'))) {
            return;
        }

        $sent = false;
        $path = null;
        try {
            $path = $this->xlsxPath($topic, $data);
            $build($path);
            $result = $wa->sendDocument($group, $path, $caption, basename($path));
            $sent = ! empty($result['success']);
            if ($sent) {
                $this->remember('doc');
            } else {
                Log::warning('[RECAP-TOPIC] sendDocument failed', ['error' => (string) ($result['error'] ?? 'unknown')]);
            }
        } catch (\Throwable $e) {
            Log::warning('[RECAP-TOPIC] excel build/send failed', ['exception_class' => $e::class]);
        } finally {
            // The file holds names + PPPoE users + serials: never leave it on disk (a retry regenerates it).
            if ($path !== null) {
                try {
                    File::delete($path);
                } catch (\Throwable) {
                    // the 7-day prune is the safety net for orphans
                }
            }
        }

        if (! $sent) {
            $this->sendText($wa, $group, $messages->documentFailed());
        }
    }

    private function messageId(): string
    {
        return (string) ($this->payload['message_id'] ?? '');
    }

    private function marker(string $kind): string
    {
        return 'recap-topic:'.$kind.':'.$this->messageId();
    }

    private function remember(string $kind): void
    {
        if ($this->messageId() !== '') {
            Cache::put($this->marker($kind), 1, now()->addDay());
        }
    }

    private function markDone(): void
    {
        $this->remember('done');
    }

    private function sendMainText(EvolutionApiService $wa, string $group, string $text): void
    {
        if ($this->messageId() !== '' && Cache::has($this->marker('text'))) {
            return;
        }
        $this->sendText($wa, $group, $text);
        $this->remember('text');
    }

    /** True for the first blocked attempt of a window only (shared with "#rekap psb"). */
    private function claimNotice(string $kind): bool
    {
        if ($kind === 'group') {
            $key = 'psb-recap:notice:group:'.md5((string) ($this->payload['group_jid'] ?? ''));
            $ttl = now()->addSeconds(ProcessPsbRecapCommandJob::GROUP_COOLDOWN_SECONDS);
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
        if (! Cache::add('psb-recap:group:'.md5($group), 1, now()->addSeconds(ProcessPsbRecapCommandJob::GROUP_COOLDOWN_SECONDS))) {
            return 'group';
        }

        $sender = (string) ($this->payload['sender_jid'] ?? 'unknown');
        $key = 'psb-recap:sender:'.md5($sender).':'.OpsTime::now()->format('YmdH');
        Cache::add($key, 0, now()->addHour());
        if ((int) Cache::increment($key) > ProcessPsbRecapCommandJob::SENDER_PER_HOUR) {
            return 'sender';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function xlsxPath(string $topic, array $data): string
    {
        $slug = Str::slug((string) $data['area_label']) ?: 'area';

        return storage_path('app/recap/'.$topic.'/rekap-'.$topic.'-'.$slug.'-'.OpsTime::now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.xlsx');
    }

    /** Safety net for orphaned Excel files (7 days). Never touches anything outside the topic recap folder. */
    private function pruneOldFiles(string $topic): void
    {
        try {
            $dir = storage_path('app/recap/'.$topic);
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
            Log::warning('[RECAP-TOPIC] prune failed', ['exception_class' => $e::class]);
        }
    }
}
