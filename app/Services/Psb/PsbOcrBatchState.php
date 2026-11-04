<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Support\PsbOcrBatchLedger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PSB WA: OCR batch bookkeeping. Staged media files, the batch key, the meta
 * written when a batch is pending, retry-pending, completed or terminal, and
 * the small settings and checks around them.
 *
 * Moved as-is from PsbOrchestratorService (PR 3 of the PSB refactor, no
 * behaviour change). The orchestrator keeps same-name delegators and the
 * conductors (processStagedMediaOcr, markMediaOcrTerminal), so callers, the OCR
 * worker adapter and test harnesses still go through the facade. This class has
 * no state: the batch key and mode live on the facade (postOcr) and are passed in.
 */
final class PsbOcrBatchState
{
    /**
     * Stage media without OCR. This keeps the PSB acknowledgement worker
     * bounded and gives the OCR worker an idempotent, resumable input set.
     *
     * @param  list<string>  $images
     * @param  list<array<string,mixed>>  $contexts
     * @return array{paths:list<string>,contexts:list<array<string,mixed>>,batch_key:string}
     */
    public function stageMediaForOcr(array $images, array $contexts = []): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $paths = [];
        $stagedContexts = [];
        $seen = [];

        foreach ($images as $index => $bytes) {
            if (! is_string($bytes) || strlen($bytes) <= 100) {
                continue;
            }
            $sha = hash('sha256', $bytes);
            if (isset($seen[$sha])) {
                continue;
            }
            $seen[$sha] = true;
            $path = 'psb/'.now()->format('Y/m').'/ocr-pending/'.$sha.'.bin';
            if (! $disk->exists($path)) {
                $disk->put($path, $bytes);
            }
            $paths[] = $path;
            $context = is_array($contexts[$index] ?? null) ? $contexts[$index] : [];
            $context['sha256'] = $sha;
            $stagedContexts[] = $context;
        }

        return [
            'paths' => $paths,
            'contexts' => $stagedContexts,
            'batch_key' => sha1(implode('|', $paths)),
        ];
    }

    /**
     * A new batch is staged while the previous one waits for its automatic
     * re-check (nothing of it was applied to the draft yet). Carry the parked
     * photos over, so the superseded re-check is truly redundant and its KTP
     * photo is not lost when that re-check is skipped.
     *
     * @param  array{paths:list<string>,contexts:list<array<string,mixed>>,batch_key:string}  $staged
     * @return array{paths:list<string>,contexts:list<array<string,mixed>>,batch_key:string}
     */
    public function mergeParkedRetryFiles(PsbDraft $draft, array $staged): array
    {
        if ($staged['paths'] === []) {
            return $staged;
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $parked = $meta['ocr_batch_paths'] ?? null;
        if (data_get($meta, 'photo_class.status') !== 'retry_pending' || ! is_array($parked) || $parked === []) {
            return $staged;
        }

        $disk = Storage::disk(config('filesystems.default'));
        $have = [];
        foreach ($staged['paths'] as $path) {
            $have[pathinfo($path, PATHINFO_FILENAME)] = true;
        }
        $added = 0;
        foreach ($parked as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $sha = pathinfo($path, PATHINFO_FILENAME);
            if (isset($have[$sha])) {
                continue;
            }
            try {
                if (! $disk->exists($path)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $have[$sha] = true;
            $staged['paths'][] = $path;
            // Keep the original message/sender of the parked photo when it is known.
            $original = $meta['ocr_batch_contexts'][$sha] ?? null;
            $staged['contexts'][] = is_array($original)
                ? array_merge($original, ['sha256' => $sha, 'carried' => true])
                : ['sha256' => $sha, 'source' => 'parked_retry'];
            $added++;
        }
        if ($added > 0) {
            $staged['batch_key'] = sha1(implode('|', $staged['paths']));
            // The parked batch is replaced by this one: it must not keep counting as open.
            PsbOcrBatchLedger::close((int) $draft->id, (string) ($meta['ocr_batch_key'] ?? ''), PsbOcrBatchLedger::STATE_SKIPPED);
            Log::info('[PSB] parked OCR photos carried into the new batch', [
                'draft' => $draft->id,
                'carried' => $added,
                'n' => count($staged['paths']),
            ]);
        }

        return $staged;
    }

    /**
     * @param  list<string>  $paths  staged files of this batch (lets a superseded retry clean up only its own files)
     * @param  list<array<string,mixed>>  $contexts  photo contexts of this batch (kept by sha, so a carried-over photo keeps its message/sender)
     */
    public function markMediaOcrPending(PsbDraft $draft, string $batchKey, array $paths = [], array $contexts = []): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['ocr_batch_key'] = $batchKey;
        if ($paths !== []) {
            $meta['ocr_batch_paths'] = array_values($paths);
            $bySha = [];
            foreach ($contexts as $context) {
                $sha = is_array($context) ? (string) ($context['sha256'] ?? '') : '';
                if ($sha !== '') {
                    $bySha[$sha] = $context;
                }
            }
            if ($bySha !== []) {
                $meta['ocr_batch_contexts'] = $bySha;
            } else {
                unset($meta['ocr_batch_contexts']);
            }
        }
        // A new user message starts a new intake: the same photo bytes give the
        // same batch key, so the once-per-intake reply marker must be reset here.
        unset($meta['ocr_terminal_notified']);
        $meta['document_gate'] = [
            'status' => 'ocr_pending',
            'reason' => 'queued_ocr_media',
            'updated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        $meta['photo_class'] = array_merge(
            is_array($meta['photo_class'] ?? null) ? $meta['photo_class'] : [],
            ['status' => 'pending', 'queue' => 'ocr-media']
        );
        // A late photo on a draft past the photo stage is still stored, but must not
        // move the draft back to "received", leave a stale "sedang diproses" note or
        // keep an old waiting_photo flag that would restart provisioning.
        if (in_array($draft->status, PsbDraft::PHOTO_LOCKED_STATUSES, true)) {
            unset($meta['waiting_photo']);
            $draft->meta = $meta;

            return;
        }
        $draft->meta = $meta;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->last_error = 'Foto diterima, validasi KTP/SN sedang diproses.';
    }

    /**
     * The same photo bytes resent after a completed batch skip OCR (no duplicate
     * work/replies). Exception: the earlier run did not read the photo (timeout,
     * deadline, Paddle busy, no text), so a fresh OCR is allowed. Only photos that
     * matter for the KTP count: a photo classified as SN/ODP never does, and a
     * "no text" photo only counts while no KTP was accepted. No recorded statuses
     * (older drafts) or no staged file left on disk keep the short-circuit.
     *
     * @param  array<string,mixed>  $meta
     * @param  list<string>  $stagedPaths
     */
    public function completedBatchNeedsFreshOcr(array $meta, array $stagedPaths): bool
    {
        $statuses = data_get($meta, 'photo_class.ocr_status');
        if (! is_array($statuses) || $statuses === []) {
            return false;
        }

        // Nothing to read again (files already consumed): repair + continue as before.
        $disk = Storage::disk(config('filesystems.default'));
        $filesLeft = false;
        foreach ($stagedPaths as $path) {
            if (is_string($path) && $disk->exists($path)) {
                $filesLeft = true;
                break;
            }
        }
        if (! $filesLeft) {
            return false;
        }

        $shas = data_get($meta, 'photo_class.sha256');
        $shas = is_array($shas) ? array_values($shas) : [];
        $types = [];
        foreach ((array) ($meta['photo_attachments'] ?? []) as $att) {
            if (is_array($att) && isset($att['sha256'])) {
                $types[(string) $att['sha256']] = (string) ($att['classifier'] ?? 'unknown');
            }
        }
        $ktpAccepted = data_get($meta, 'photo_class.ktp_idx') !== null;

        foreach (array_values($statuses) as $i => $status) {
            if ($status === 'ok') {
                continue;
            }
            $type = isset($shas[$i]) ? ($types[(string) $shas[$i]] ?? 'unknown') : 'unknown';
            if (! in_array($type, ['ktp', 'unknown'], true)) {
                continue;
            }
            if ($status === 'no_text' && $ktpAccepted) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** Mark a media batch terminal without repeating the expensive OCR call. */
    public function markMediaOcrCompleted(PsbDraft $draft, string $batchKey): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $photoClass = is_array($meta['photo_class'] ?? null) ? $meta['photo_class'] : [];
        $photoClass['ocr_batch_completed'] = $batchKey;
        $photoClass['queue'] = 'ocr-media';
        $photoClass['status'] = 'completed';
        $meta['photo_class'] = $photoClass;
        $documentGate = is_array($meta['document_gate'] ?? null) ? $meta['document_gate'] : [];
        $documentGate['status'] = 'classified';
        $meta['document_gate'] = $documentGate;
        unset($meta['ocr_batch_key'], $meta['ocr_batch_paths'], $meta['ocr_batch_contexts']);
        $draft->meta = $meta;
        $draft->save();
    }

    /**
     * A superseded retry batch: delete ONLY its own staged files, and only when the
     * draft's current batch is known and shares none of them (staged names are
     * content hashes, so the same photo resent lands on the same path).
     *
     * @param  list<string>  $stagedPaths
     */
    public function cleanupSupersededRetryFiles(PsbDraft $draft, array $stagedPaths): void
    {
        // Re-read from the DB right before deleting: a new batch may have been
        // staged after the caller loaded the draft, and the protected set (current
        // batch key + paths) must come from the latest state.
        $fresh = PsbDraft::query()->find($draft->id);
        $meta = $fresh && is_array($fresh->meta) ? $fresh->meta : [];
        $current = $meta['ocr_batch_paths'] ?? null;
        if ((string) ($meta['ocr_batch_key'] ?? '') === '' || ! is_array($current) || $current === []) {
            return;
        }
        $disk = Storage::disk(config('filesystems.default'));
        foreach ($stagedPaths as $path) {
            if (! is_string($path) || in_array($path, $current, true)) {
                continue;
            }
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
    }

    /**
     * Read part of anotherBatchAlreadyAnswered: true when a terminal reply was
     * already sent for a different OCR batch (staging clears the marker). The
     * facade checks postOcr and passes the current batch key in.
     */
    public function terminalNotifiedForOtherBatch(PsbDraft $draft, string $batchKey): bool
    {
        $stored = PsbDraft::query()->whereKey($draft->id)->value('meta');
        $stored = is_array($stored) ? $stored : (is_string($stored) ? (json_decode($stored, true) ?: []) : []);
        $notified = (string) ($stored['ocr_terminal_notified'] ?? '');

        return $notified !== '' && $notified !== $batchKey;
    }

    /** Setting psb_album_wait_seconds: missing or empty = 90, 0 = off (old flow), clamped to 0-180. */
    public function albumWaitSeconds(): int
    {
        $v = Setting::get('psb_album_wait_seconds', null);
        if (is_bool($v)) {
            return $v ? 90 : 0;
        }
        if (is_string($v) && in_array(strtolower(trim($v)), ['off', 'false', 'no'], true)) {
            return 0;
        }
        if ($v === null || ! is_numeric($v)) {
            return 90;
        }

        return max(0, min(180, (int) $v));
    }

    /** Runtime kill switch: Setting psb_ocr_retry_enabled (missing = on, 0/false/off = old behaviour). */
    public function ocrRetryEnabled(): bool
    {
        $v = Setting::get('psb_ocr_retry_enabled', null);
        if ($v === null || $v === '') {
            return true;
        }
        if (is_bool($v)) {
            return $v;
        }

        return ! in_array(strtolower(trim((string) $v)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Park a degraded (slow server) batch for ONE automatic re-check. Nothing from
     * this pass is applied, the staged files stay, no WA is sent, and the draft
     * keeps its OCR batch key so the retry job (and only that job) can finish it.
     *
     * @param  array<string,mixed>  $batch
     */
    public function markOcrRetryPending(PsbDraft $draft, string $batchKey, array $batch): void
    {
        $delay = max(30, (int) config('services.ocrspace.media_ocr_retry_delay_seconds', 120));
        $dueAt = now('Asia/Jakarta')->addSeconds($delay);

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $photoClass = is_array($meta['photo_class'] ?? null) ? $meta['photo_class'] : [];
        $photoClass['status'] = 'retry_pending';
        $photoClass['retry'] = [
            'reason' => $batch['degraded_reason'] ?? 'slow_ocr',
            'due_at' => $dueAt->toIso8601String(),
            'until' => $dueAt->copy()->addMinutes(15)->toIso8601String(),
        ];
        $photoClass['degraded_count'] = $batch['degraded_count'] ?? null;
        $photoClass['ocr_status'] = $batch['statuses'] ?? [];
        $meta['photo_class'] = $photoClass;
        $meta['ocr_batch_key'] = $batchKey;
        $meta['document_gate'] = [
            'status' => 'ocr_pending',
            'reason' => 'ocr_retry_scheduled',
            'updated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        $draft->meta = $meta;
        $draft->last_error = 'Foto diterima, server lambat. Dicek ulang otomatis.';
        $draft->save();
        PsbOcrBatchLedger::markRetry((int) $draft->id, $batchKey);

        Log::info('[PSB] photo classify degraded, retry scheduled', [
            'draft' => $draft->id,
            'batch_key' => $batchKey,
            'reason' => $photoClass['retry']['reason'],
            'due_at' => $photoClass['retry']['due_at'],
            'ocr_status' => $batch['statuses'] ?? [],
            'ocr_trace' => $batch['traces'] ?? [],
            'tess_runs' => $batch['tess_runs'] ?? null,
            'paddle' => $batch['paddle_outcomes'] ?? [],
            'paddle_timeout_s' => array_column($batch['scored'] ?? [], 'paddle_timeout_s'),
            'paddle_drain_ms' => array_column($batch['scored'] ?? [], 'paddle_drain_ms'),
        ]);
    }

    /** True when OCR finished, no KTP photo was accepted and a photo was seen but not recognised. */
    public function isKtpUnreadable(PsbDraft $draft): bool
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];

        return data_get($meta, 'photo_class.status') === 'completed'
            && trim((string) $draft->ktp_photo_path) === ''
            && (int) data_get($meta, 'photo_class.unknown_count', 0) > 0;
    }

    /**
     * Meta part of markMediaOcrTerminal (the sweeper): end the batch as
     * "KTP belum terbaca", log it and close the ledger row as failed. The
     * facade then sends the one-per-batch WA text.
     */
    public function markTerminalMeta(PsbDraft $draft, string $batchKey, string $reason): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $photoClass = is_array($meta['photo_class'] ?? null) ? $meta['photo_class'] : [];
        $photoClass['status'] = 'failed';
        $photoClass['ocr_batch_failed'] = $batchKey;
        $meta['photo_class'] = $photoClass;
        unset($meta['ocr_batch_key'], $meta['ocr_batch_paths'], $meta['ocr_batch_contexts']);
        $meta['document_gate'] = [
            'status' => 'missing',
            'reason' => 'ocr_failed',
            'updated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        $meta['waiting_photo'] = true;
        $draft->meta = $meta;
        $draft->last_error = 'KTP belum terbaca. Kirim ulang: #psb ktp #'.$draft->id;
        $draft->save();
        Log::warning('[PSB] media OCR terminal', ['draft' => $draft->id, 'reason' => $reason]);
        PsbOcrBatchLedger::close((int) $draft->id, $batchKey, PsbOcrBatchLedger::STATE_FAILED);
    }
}
