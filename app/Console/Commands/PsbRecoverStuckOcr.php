<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Models\PsbDraft;
use App\Services\PsbOrchestratorService;
use App\Support\OpsTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recovers PSB drafts stuck after OCR.
 *
 * When ProcessPsbMediaOcrJob times out, the post-OCR continuation
 * (ProcessPsbAfterMediaOcrJob) is never queued, so the draft stays at
 * status=received + document_gate.status=ocr_pending forever. This sweeper
 * re-queues the continuation for drafts whose OCR already completed.
 */
class PsbRecoverStuckOcr extends Command
{
    /** Continuation re-queues per OCR batch before the batch is ended. */
    private const MAX_ATTEMPTS = 4;

    protected $signature = 'psb:recover-stuck-ocr
        {--minutes=5 : Only drafts whose gate is older than this}
        {--apply : Dispatch the recovery jobs (default: dry run)}
        {--limit=100 : Max drafts to inspect}';

    protected $description = 'Re-queue the post-OCR continuation for drafts stuck at ocr_pending';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = OpsTime::now()->subMinutes($minutes)->utc();

        $drafts = PsbDraft::query()
            ->where('status', 'received')
            ->where('updated_at', '<=', $cutoff)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'meta', 'updated_at']);

        $recovered = 0;
        $needsManual = 0;
        $skipped = 0;
        $terminal = 0;

        foreach ($drafts as $draft) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $gate = $meta['document_gate'] ?? [];

            if (($gate['status'] ?? '') !== 'ocr_pending') {
                $skipped++;

                continue;
            }

            $photoClass = $meta['photo_class'] ?? [];
            if ($photoClass === []) {
                // OCR never produced a classification: the OCR job itself must be
                // re-run (paths may be gone). Report, never silently skip.
                $needsManual++;
                $this->line('  needs_manual #'.$draft->id.' (gate=ocr_pending, photo_class kosong)');

                continue;
            }

            // A slow-server batch waits for its one automatic re-check: leave it
            // alone until the retry window is over, then the normal backstop applies.
            if (($photoClass['status'] ?? '') === 'retry_pending' && $this->retryWindowOpen($photoClass)) {
                $this->line('  retry_pending #'.$draft->id.' (menunggu cek ulang)');
                $skipped++;

                continue;
            }

            $batchKey = (string) ($meta['ocr_batch_key'] ?? ($photoClass['ocr_batch_completed'] ?? ''));
            $recovery = is_array($meta['ocr_recovery'] ?? null) ? $meta['ocr_recovery'] : [];
            $attempts = ($recovery['batch'] ?? null) === $batchKey ? (int) ($recovery['attempts'] ?? 0) : 0;

            if ($attempts >= self::MAX_ATTEMPTS) {
                // The continuation was re-queued enough times and OCR still has
                // no result: end the batch so the technician can resend the photo.
                $this->line('  terminal #'.$draft->id.' batch='.($batchKey !== '' ? $batchKey : '-'));
                if ($apply && ! $this->markTerminal((int) $draft->id, $batchKey)) {
                    $skipped++;

                    continue;
                }
                $terminal++;

                continue;
            }

            $this->line('  recover #'.$draft->id.' batch='.($batchKey !== '' ? $batchKey : '-'));

            if ($apply) {
                // Optimistic write: only if the draft is unchanged since it was
                // read (a newer OCR result or photo must never be overwritten),
                // and keep updated_at so the idle clock is not reset.
                $meta['ocr_recovery'] = ['batch' => $batchKey, 'attempts' => $attempts + 1];
                $written = PsbDraft::query()
                    ->whereKey($draft->id)
                    ->where('updated_at', $draft->getRawOriginal('updated_at'))
                    ->update(['meta' => json_encode($meta), 'updated_at' => $draft->getRawOriginal('updated_at')]);
                if ($written === 0) {
                    $skipped++;

                    continue;
                }
                ProcessPsbAfterMediaOcrJob::dispatch((int) $draft->id, array_filter([
                    'batch_key' => $batchKey,
                    'mode' => 'recovery',
                ]));
            }
            $recovered++;
        }

        $this->info('apply='.($apply ? 'yes' : 'no').' recovered='.$recovered.' needs_manual='.$needsManual.' skipped='.$skipped.' terminal='.$terminal);

        if ($apply && $recovered > 0) {
            Log::info('[PSB] stuck ocr recovery', ['recovered' => $recovered, 'needs_manual' => $needsManual]);
        }

        $oltBusy = $this->recoverStaleOltBusyResumes($apply, $limit);
        $this->line('olt_busy_recovered='.$oltBusy);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $photoClass */
    private function retryWindowOpen(array $photoClass): bool
    {
        $until = data_get($photoClass, 'retry.until');
        if (! is_string($until) || $until === '') {
            return false;
        }
        try {
            return Carbon::parse($until)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Take the OCR draft lock without waiting, then end the batch as "KTP belum terbaca". */
    private function markTerminal(int $draftId, string $batchKey): bool
    {
        $lock = Cache::lock('psb-media-ocr-draft:'.$draftId, 60);
        if (! $lock->get()) {
            return false;
        }
        try {
            $draft = PsbDraft::query()->find($draftId);
            if (! $draft) {
                return false;
            }
            // Re-check after re-reading: a new photo or an OCR result may have landed.
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $currentKey = (string) ($meta['ocr_batch_key'] ?? data_get($meta, 'photo_class.ocr_batch_completed', ''));
            if (data_get($meta, 'document_gate.status') !== 'ocr_pending' || $currentKey !== $batchKey) {
                return false;
            }
            app(PsbOrchestratorService::class)->markMediaOcrTerminal($draft, $batchKey, 'ocr_timeout');
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Safety net for the OLT-busy auto-resume: a draft still waiting
     * ("OLT antri:") well past its planned retry time lost its queued job
     * (e.g. Redis loss). End it the way a normal OLT failure ends (secret_ok +
     * WA message) so the technician can #psb lanjut. Dry run only counts.
     */
    private function recoverStaleOltBusyResumes(bool $apply, int $limit): int
    {
        $drafts = PsbDraft::query()
            ->where('status', PsbDraft::STATUS_SECRET_OK)
            ->whereNull('olt_at')
            ->where('last_error', 'like', 'OLT antri:%')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        $psb = null;
        foreach ($drafts as $draft) {
            $marker = is_array($draft->meta) ? ($draft->meta['olt_busy_resume'] ?? null) : null;
            if (! is_array($marker) || empty($marker['token']) || empty($marker['next_at'])) {
                continue;
            }
            try {
                $nextAt = Carbon::parse((string) $marker['next_at']);
            } catch (\Throwable) {
                continue;
            }
            if ($nextAt->gt(now()->subMinutes(10))) {
                continue;
            }

            $this->line('  olt_busy stale #'.$draft->id);
            if ($apply) {
                $psb ??= app(PsbOrchestratorService::class);
                if (! $psb->giveUpOltBusyResume((int) $draft->id, (string) $marker['token'], 'sweeper')) {
                    continue;
                }
            }
            $count++;
        }

        return $count;
    }
}
