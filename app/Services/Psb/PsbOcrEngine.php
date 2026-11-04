<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Services\KtpOcrService;
use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PSB WA: the OCR engine (Tesseract pass, KTP parser pass, scoring, picking the
 * KTP and SN photo, writing the result to the draft).
 *
 * Moved as-is from PsbOrchestratorService (phase 1b step 2). One instance per
 * orchestrator instance (= per job); all state below is per instance, never static.
 */
final class PsbOcrEngine
{
    /** Trace of the last real Tesseract run (null when it was not run). */
    private ?array $lastTesseractTrace = null;

    /** Per-image budget handed to the KTP parser (Paddle) while one image is classified (async OCR job only). */
    private ?OcrBudget $imageBudget = null;

    /** False = the next KTP parse must not call Paddle (batch call limit / NIK already found). */
    private bool $paddleAllowed = true;

    /** Per-run Tesseract cap override while classifyWaActivationPhoto runs. */
    private ?int $runCapOverride = null;

    public function __construct(
        private readonly KtpOcrService $ktpOcr,
        private readonly PsbPhotoScorer $scorer,
        private readonly PsbOcrReaders $readers,
    ) {}

    /** Budget factory (the clock comes from the readers; null = real clock). */
    private function newOcrBudget(float $seconds): OcrBudget
    {
        return OcrBudget::start($seconds, $this->readers->clock);
    }

    /**
     * Simpan 2 foto PSB WA: KTP + foto SN/stiker ONU.
     * Klasifikasi by isi (OCR), BUKAN urutan kirim — teknisi sering kirim SN dulu.
     *
     * @param  list<string>  $images  raw bytes
     */
    public function attachPhotosAndOcr(PsbDraft $draft, array $images, array $contexts = []): void
    {
        $batch = $this->classifyBatch($draft, $images, $contexts);
        if ($batch !== null) {
            $this->applyBatch($draft, $batch);
        }
    }

    /**
     * OCR + classify a batch of photos WITHOUT writing anything to the draft or
     * storage. Returns null when there is nothing to classify.
     *
     * @param  list<string>  $images  raw bytes
     * @return array<string,mixed>|null
     */
    public function classifyBatch(PsbDraft $draft, array $images, array $contexts = []): ?array
    {
        $records = [];
        $seen = [];
        foreach ($images as $index => $bytes) {
            if (! is_string($bytes) || strlen($bytes) <= 100) {
                continue;
            }
            $hash = md5($bytes);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $records[] = [
                'bytes' => $bytes,
                'context' => $contexts[$index] ?? [],
            ];
        }
        if ($records === []) {
            return null;
        }

        // Dedupe identical files (WA kadang 1 foto di-assign 2x → KTP=SN sama)
        $images = array_column($records, 'bytes');
        $contexts = array_column($records, 'context');

        $scored = [];
        $fastPath = (bool) config('services.ocrspace.media_fast_path', false);
        $hardGuard = (bool) config('services.ocrspace.media_hard_guard', false);
        // Bound total OCR time for this draft so a batch of slow photos cannot
        // overrun the OCR worker timeout (multi-PSM x multi-image).
        $async = (bool) config('services.ocrspace.ocr_async_job', false);
        $batchBudget = $this->newOcrBudget((float) max(15, (int) config('services.ocrspace.ocr_total_budget_seconds', 90)));
        $deadline = $batchBudget->deadline();
        $imageSeconds = (float) max(15, (int) config('services.ocrspace.media_image_budget_seconds', 90));
        // OCR worker only: Tesseract stops this many seconds before the image
        // budget ends, so the Paddle pass below always has time left.
        $reserve = $async ? max(0, (int) config('services.ocrspace.paddle_reserve_seconds', 35)) : 0;
        $maxPaddleCalls = $async ? max(1, (int) config('services.ocrspace.paddle_max_calls_per_batch', 2)) : PHP_INT_MAX;

        // Pass 1: Tesseract for every photo (each exactly once; the text is reused by every scorer).
        $pre = [];
        foreach ($images as $idx => $bytes) {
            $startedAt = microtime(true);
            // In the OCR worker every image also gets its own budget so one slow
            // photo cannot eat the whole batch; the sync path keeps the batch deadline only.
            $imageBudget = $async ? $batchBudget->forImage($imageSeconds) : null;
            $this->lastTesseractTrace = null;
            $this->imageBudget = $imageBudget;
            $tessDeadline = $imageBudget?->deadline() ?? $deadline;
            if ($imageBudget !== null && $reserve > 0) {
                $tessDeadline = max(
                    $imageBudget->deadline() - $reserve,
                    $imageBudget->deadline() - $imageBudget->remaining() + min(15.0, $imageBudget->remaining())
                );
                // Batch level: many photos must not use up the whole batch budget;
                // one Paddle slice (the reserve) always stays for pass 2. A photo
                // keeps at least 15 s of Tesseract, but only while the reserve
                // line is not reached yet (a KTP candidate cannot be told apart
                // before Tesseract), and that floor never passes batch end - 15 s.
                $now = $imageBudget->deadline() - $imageBudget->remaining();
                $reserveLine = $batchBudget->deadline() - $reserve;
                $floor = $now < $reserveLine
                    ? min($now + min(15.0, $imageBudget->remaining()), $batchBudget->deadline() - 15.0)
                    : $reserveLine;
                $tessDeadline = min($tessDeadline, max($floor, $reserveLine));
            }
            $rawText = (string) ($this->tesseractText($bytes, $tessDeadline) ?? '');
            $trace = $this->lastTesseractTrace;
            $signalText = $this->normalizeOcrSignalText($rawText);
            $snHit = $this->extractSnFromText($signalText);
            $snText = $this->hasSnTextEvidence($rawText, $snHit);
            $snScore = $snHit ? 90 : $this->scoreSnPhoto($bytes, $rawText);
            $odpScore = $this->scoreOdpPhoto($rawText);
            $this->imageBudget = null;
            // A strong serial hit is sufficient to classify a sticker. Avoid
            // spending another OCR request trying to parse that same image as KTP.
            $candidate = ! ($snHit !== null || $snScore >= 80);
            $pre[$idx] = [
                'ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'raw_text' => $rawText,
                'trace' => $trace,
                'sn_hit' => $snHit,
                'sn_text' => $snText,
                'sn_score' => $snScore,
                'odp_score' => $odpScore,
                'candidate' => $candidate,
                // Best KTP candidate first: KTP words in the text, card aspect ratio, not an ODP/label photo.
                'signal' => $candidate
                    ? ($this->hasKtpTextEvidence($rawText) ? 100 : 0)
                        + $this->scoreKtpFromOcr([], $bytes, $rawText)
                        - ($odpScore >= 50 ? 50 : 0)
                    : -1000,
            ];
        }

        // Pass 2: the KTP parser (and Paddle when Tesseract was not enough).
        // OCR worker: best candidate first, a few Paddle calls per batch; a photo
        // after a valid NIK, or after the call limit, is parsed from its text only.
        $order = array_keys($pre);
        if ($async) {
            usort($order, static fn (int $a, int $b): int => ($pre[$b]['signal'] <=> $pre[$a]['signal']) ?: ($a <=> $b));
        }
        $ktpResults = [];
        $paddleCalls = 0;
        $nikFound = false;
        foreach ($order as $idx) {
            if (! $pre[$idx]['candidate']) {
                $ktpResults[$idx] = [];

                continue;
            }
            $startedAt = microtime(true);
            $allowPaddle = ! $async || (! $nikFound && $paddleCalls < $maxPaddleCalls);
            // The Paddle pass gets a fresh slice of the batch budget (the
            // Tesseract pass ended early), never more than what is left of it.
            $this->imageBudget = $async && $allowPaddle ? $batchBudget->forImage($imageSeconds) : null;
            $this->paddleAllowed = $allowPaddle;
            try {
                $ktpOcr = $this->tryKtpOcr($images[$idx], $pre[$idx]['raw_text'], true);
            } finally {
                $this->paddleAllowed = true;
                $this->imageBudget = null;
            }
            $ktpResults[$idx] = $ktpOcr;
            $pre[$idx]['ms'] += (int) round((microtime(true) - $startedAt) * 1000);
            if (in_array((string) ($ktpOcr['paddle_outcome'] ?? ''), ['ok', 'timeout', 'busy', 'error'], true)) {
                $paddleCalls++;
            }
            if (! empty($ktpOcr['nik']) && ($ktpOcr['nik_valid'] ?? false)) {
                $nikFound = true;
            }
        }

        // Pass 3: score every photo.
        foreach ($images as $idx => $bytes) {
            $rawText = (string) $pre[$idx]['raw_text'];
            $trace = $pre[$idx]['trace'];
            $snHit = $pre[$idx]['sn_hit'];
            $snText = $pre[$idx]['sn_text'];
            $snScore = $pre[$idx]['sn_score'];
            $odpScore = $pre[$idx]['odp_score'];
            $ktpOcr = $ktpResults[$idx] ?? [];
            $ktpScore = $this->scoreKtpFromOcr($ktpOcr, $bytes, $rawText);
            $ktpText = $this->hasKtpTextEvidence($rawText, $ktpOcr);
            $ktpEvidence = $this->hasStrongKtpEvidence($ktpOcr);
            if ($hardGuard && ! $ktpEvidence) {
                $ktpScore = min($ktpScore, 20);
            }
            if ($hardGuard && $snHit === null) {
                $snScore = min($snScore, 50);
            }
            // If clearly SN sticker, suppress false KTP score
            if (($snScore >= 70 || $snText) && ! $ktpText) {
                $ktpScore = min($ktpScore, 15);
            }
            if ($odpScore >= 50 && ! $ktpEvidence) {
                $ktpScore = min($ktpScore, 15);
            }
            // If clearly KTP, suppress SN
            if ($ktpText && $ktpScore >= 50 && $snScore < 70 && ! $snText) {
                $snScore = min($snScore, 20);
            }
            [$ocrStatus, $degraded] = $this->imageOcrStatus($rawText, $trace, $ktpOcr);
            $scored[] = [
                'ocr_status' => $ocrStatus,
                'degraded' => $degraded,
                'trace' => $trace !== null ? array_map(
                    static fn (array $run): array => ['psm' => $run['psm'], 'ms' => $run['ms'], 'timed_out' => $run['timed_out']],
                    $trace['runs'] ?? []
                ) : [],
                'paddle_outcome' => (string) ($ktpOcr['paddle_outcome'] ?? ''),
                'idx' => $idx,
                'bytes' => $bytes,
                'sn_hit' => $snHit,
                'sn_score' => $snScore,
                'sn_text' => $snText,
                'odp_score' => $odpScore,
                'ktp_score' => $ktpScore,
                'ktp_ocr' => $ktpOcr,
                'ktp_text' => $ktpText,
                'ktp_evidence' => $ktpEvidence,
                'sha256' => hash('sha256', $bytes),
                'ocr_ms' => (int) $pre[$idx]['ms'],
                'paddle_timeout_s' => (int) ($ktpOcr['paddle_timeout_s'] ?? 0),
                'paddle_drain_ms' => (int) ($ktpOcr['paddle_drain_ms'] ?? 0),
                'mime' => (string) (@finfo_buffer(new \finfo(FILEINFO_MIME_TYPE), $bytes) ?: 'application/octet-stream'),
                'context' => $contexts[$idx] ?? [],
                'classification_status' => 'terminal',
            ];
        }

        // Pick best KTP and best SN photo (may be different images)
        $ktpPick = null;
        $snPick = null;
        foreach ($scored as $row) {
            $type = $this->photoTypeFromScores(
                $row['sn_hit'],
                (int) $row['sn_score'],
                (int) $row['ktp_score'],
                $hardGuard,
                (int) ($row['odp_score'] ?? 0),
                (bool) ($row['ktp_text'] ?? false),
                (bool) ($row['sn_text'] ?? false)
            );
            if ($type === 'ktp' && ($ktpPick === null || $row['ktp_score'] > $ktpPick['ktp_score'])) {
                $ktpPick = $row;
            }
            if ($type === 'sn' && ($snPick === null || $row['sn_score'] > $snPick['sn_score'])) {
                $snPick = $row;
            }
        }

        // 1 foto saja: jangan taruh di KTP jika jelas SN
        if (count($scored) === 1) {
            $only = $scored[0];
            $type = $this->photoTypeFromScores(
                $only['sn_hit'],
                (int) $only['sn_score'],
                (int) $only['ktp_score'],
                $hardGuard,
                (int) ($only['odp_score'] ?? 0),
                (bool) ($only['ktp_text'] ?? false),
                (bool) ($only['sn_text'] ?? false)
            );
            $ktpPick = $type === 'ktp' ? $only : null;
            $snPick = $type === 'sn' ? $only : null;
        } else {
            // 2+ foto: pastikan tidak assign image yang sama ke keduanya
            if ($ktpPick && $snPick && $ktpPick['idx'] === $snPick['idx']) {
                // same image won both — assign by stronger signal
                if ($ktpPick['ktp_score'] >= $snPick['sn_score'] && $ktpPick['ktp_score'] >= 40) {
                    // find alternate for SN
                    $altSn = null;
                    foreach ($scored as $row) {
                        if ($row['idx'] === $ktpPick['idx']) {
                            continue;
                        }
                        if ($altSn === null || $row['sn_score'] > $altSn['sn_score']) {
                            $altSn = $row;
                        }
                    }
                    $snPick = $altSn;
                } else {
                    $altKtp = null;
                    foreach ($scored as $row) {
                        if ($row['idx'] === $snPick['idx']) {
                            continue;
                        }
                        if ($altKtp === null || $row['ktp_score'] > $altKtp['ktp_score']) {
                            $altKtp = $row;
                        }
                    }
                    $ktpPick = $altKtp;
                }
            }
            // Threshold: jangan simpan sebagai KTP jika skor rendah (peta/screenshot/SN)
            if ($ktpPick && (int) $ktpPick['ktp_score'] < 25) {
                $ktpPick = null;
            }
        }

        if ($hardGuard && $ktpPick !== null && ! $ktpPick['ktp_evidence']) {
            $ktpPick = null;
        }
        if ($hardGuard && $snPick !== null && $snPick['sn_hit'] === null) {
            $snPick = null;
        }

        // Absolute: a KTP needs text evidence (NIK/name/ID keywords), not just
        // the card aspect ratio. Prevents an unread SN sticker landing in the
        // customer's KTP field.
        if ($ktpPick !== null && ((int) $ktpPick['ktp_score'] < 25 || ! ($ktpPick['ktp_text'] ?? false))) {
            $ktpPick = null;
        }

        // Degraded = the server was too slow for at least one photo AND no KTP
        // was found. (A KTP that was read is never "degraded".)
        $degradedRows = array_values(array_filter($scored, static fn (array $row): bool => (bool) ($row['degraded'] ?? false)));
        $degraded = $ktpPick === null && $degradedRows !== [];

        return [
            'scored' => $scored,
            'ktpPick' => $ktpPick,
            'snPick' => $snPick,
            'fastPath' => $fastPath,
            'hardGuard' => $hardGuard,
            'deadline' => $deadline,
            'degraded' => $degraded,
            'degraded_count' => count($degradedRows),
            'degraded_reason' => $degradedRows !== [] ? (string) ($degradedRows[0]['ocr_status'] ?? 'slow_ocr') : null,
            'statuses' => array_column($scored, 'ocr_status'),
            'traces' => array_column($scored, 'trace'),
            'paddle_outcomes' => array_column($scored, 'paddle_outcome'),
            // Log only: candidates with no Tesseract text that did not get a Paddle call.
            'unread_no_paddle' => array_map(
                static fn (array $row): bool => (bool) ($pre[$row['idx']]['candidate'] ?? false)
                    && trim((string) ($pre[$row['idx']]['raw_text'] ?? '')) === ''
                    && ! in_array((string) $row['paddle_outcome'], ['ok', 'timeout', 'busy', 'error'], true),
                $scored
            ),
            'tess_runs' => array_sum(array_map(static fn (array $row): int => count($row['trace'] ?? []), $scored)),
        ];
    }

    /**
     * Per-image OCR status for logs and the retry decision.
     *
     * @param  array<string,mixed>|null  $trace  TesseractRunner::extractBestTrace result (null = not traced)
     * @param  array<string,mixed>  $ktpOcr
     * @return array{0:string,1:bool} [status, degraded]
     */
    private function imageOcrStatus(string $rawText, ?array $trace, array $ktpOcr): array
    {
        return $this->scorer->imageOcrStatus($rawText, $trace, $ktpOcr);
    }

    /**
     * Write a classified batch to the draft and storage (KTP/SN photos, fields,
     * photo_class meta). A KTP-read NIK only reaches the draft when it passes
     * the validity check; a failing one is kept as last 4 digits in meta.
     *
     * @param  array<string,mixed>  $batch  classifyBatch() result
     * @param  array{retry?:int}  $options
     */
    public function applyBatch(PsbDraft $draft, array $batch, array $options = []): void
    {
        $dir = 'psb/'.now()->format('Y/m');
        $scored = $batch['scored'];
        $ktpPick = $batch['ktpPick'];
        $snPick = $batch['snPick'];
        $fastPath = (bool) $batch['fastPath'];
        $hardGuard = (bool) $batch['hardGuard'];
        $deadline = (float) $batch['deadline'];
        $nikUnverifiedLast4 = null;

        if ($ktpPick !== null) {
            $path = $dir.'/ktp_'.Str::random(8).'.jpg';
            Storage::disk(config('filesystems.default'))->put($path, $ktpPick['bytes']);
            $draft->ktp_photo_path = $path;
            $ocr = $ktpPick['ktp_ocr'] ?? [];
            if (! empty($ocr['name']) && ! $draft->full_name) {
                $draft->full_name = $ocr['name'];
            }
            if (! empty($ocr['rt']) && ! $draft->rt) {
                $draft->rt = preg_replace('/\D/', '', (string) $ocr['rt']) ?: $draft->rt;
            }
            if (! empty($ocr['rw']) && ! $draft->rw) {
                $draft->rw = preg_replace('/\D/', '', (string) $ocr['rw']) ?: $draft->rw;
            }
            if (! empty($ocr['nik'])) {
                // The parser flags a NIK that fails province/date/serial checks;
                // such a read must not overwrite the draft. No flag (older
                // parser, other engines) = write as before.
                $nikValid = array_key_exists('nik_valid', $ocr) ? (bool) $ocr['nik_valid'] : true;
                if ($nikValid) {
                    $draft->nik = $ocr['nik'];
                } else {
                    $nikUnverifiedLast4 = substr(preg_replace('/\D/', '', (string) $ocr['nik']) ?? '', -4);
                }
            }
        }

        if ($snPick !== null) {
            $path = $dir.'/sn_'.Str::random(8).'.jpg';
            Storage::disk(config('filesystems.default'))->put($path, $snPick['bytes']);
            $draft->router_photo_path = $path; // field draft: foto SN/ONU (bukan KTP)
            $sn = $snPick['sn_hit'];
            if ($sn && ! $draft->sn) {
                $draft->sn = $sn;
            }
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['photo_class'] = [
            'images' => count($scored),
            'ktp_idx' => $ktpPick['idx'] ?? null,
            'ktp_score' => $ktpPick['ktp_score'] ?? null,
            'sn_idx' => $snPick['idx'] ?? null,
            'sn_score' => $snPick['sn_score'] ?? null,
            'odp_scores' => array_column($scored, 'odp_score'),
            'ktp_path' => $draft->ktp_photo_path,
            'sn_path' => $draft->router_photo_path,
            'fast_path' => $fastPath,
            'hard_guard' => $hardGuard,
            'ocr_ms' => array_sum(array_column($scored, 'ocr_ms')),
            'sha256' => array_column($scored, 'sha256'),
            'unknown_count' => count(array_filter($scored, fn (array $row): bool => ($this->photoTypeFromScores($row['sn_hit'], (int) $row['sn_score'], (int) $row['ktp_score'], $hardGuard, (int) ($row['odp_score'] ?? 0), (bool) ($row['ktp_text'] ?? false), (bool) ($row['sn_text'] ?? false))) === 'unknown')),
            'degraded_count' => (int) ($batch['degraded_count'] ?? 0),
            'ocr_status' => $batch['statuses'] ?? [],
            // After the automatic re-check was slow too: the one terminal WA says so.
            'slow_unreadable' => (bool) ($batch['degraded'] ?? false) && (int) ($options['retry'] ?? 0) === 1,
        ];
        if ($nikUnverifiedLast4 !== null && $nikUnverifiedLast4 !== '') {
            $meta['photo_class']['ktp_nik_unverified'] = $nikUnverifiedLast4;
        }
        $newAttachments = array_values(array_map(function (array $row): array {
            return [
                'path' => $row['context']['path'] ?? null,
                'sha256' => $row['sha256'],
                'mime' => $row['mime'],
                'classifier' => $this->photoTypeFromScores($row['sn_hit'], (int) $row['sn_score'], (int) $row['ktp_score'], false, (int) ($row['odp_score'] ?? 0), (bool) ($row['ktp_text'] ?? false), (bool) ($row['sn_text'] ?? false)),
                'ktp_score' => $row['ktp_score'],
                'sn_score' => $row['sn_score'],
                'odp_score' => $row['odp_score'] ?? 0,
                'classification_status' => $row['classification_status'] ?? 'terminal',
                'message_id' => $row['context']['message_id'] ?? null,
                'sender' => $row['context']['sender'] ?? null,
                'received_at' => $row['context']['received_at'] ?? null,
            ];
        }, $scored));
        $oldAttachments = is_array($meta['photo_attachments'] ?? null) ? $meta['photo_attachments'] : [];
        $meta['photo_attachments'] = array_values(array_slice(array_merge($oldAttachments, $newAttachments), -20));
        $draft->meta = $meta;

        Log::info('[PSB] photo classify', [
            'draft' => $draft->id,
            'n' => count($scored),
            'ktp_score' => $ktpPick['ktp_score'] ?? null,
            'sn_score' => $snPick['sn_score'] ?? null,
            'ktp_path' => $draft->ktp_photo_path,
            'sn_path' => $draft->router_photo_path,
            'ocr_ms_total' => array_sum(array_column($scored, 'ocr_ms')),
            'deadline_hit' => microtime(true) >= $deadline,
            'per_image_ms' => array_column($scored, 'ocr_ms'),
            'degraded_count' => (int) ($batch['degraded_count'] ?? 0),
            'ocr_status' => $batch['statuses'] ?? [],
            'ocr_trace' => $batch['traces'] ?? [],
            'retry' => (int) ($options['retry'] ?? 0),
            'tess_runs' => $batch['tess_runs'] ?? null,
            'paddle' => $batch['paddle_outcomes'] ?? [],
            // Card-shaped photos Tesseract could not read (past the reserve line) that got no Paddle call.
            'paddle_unread_candidates' => count(array_filter(
                (array) ($batch['unread_no_paddle'] ?? []),
                static fn ($v): bool => (bool) $v
            )),
            'paddle_skipped_short' => count(array_filter(
                (array) ($batch['paddle_outcomes'] ?? []),
                static fn ($o): bool => $o === 'skipped_short'
            )),
            'paddle_timeout_s' => array_column($scored, 'paddle_timeout_s'),
            'paddle_drain_ms' => array_column($scored, 'paddle_drain_ms'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tryKtpOcr(
        string $bytes,
        ?string $localRawText = null,
        bool $fastPath = false
    ): array {
        try {
            $tmp = tmpfile();
            $meta = stream_get_meta_data($tmp);
            file_put_contents($meta['uri'], $bytes);
            $uploaded = new UploadedFile($meta['uri'], 'ktp.jpg', 'image/jpeg', null, true);
            $ocr = $fastPath
                ? $this->ktpOcr->parseKtpFast($uploaded, $localRawText, $this->imageBudget, true, $this->paddleAllowed)
                : $this->ktpOcr->parseKtp($uploaded);
            fclose($tmp);

            return is_array($ocr) ? $ocr : [];
        } catch (\Throwable $e) {
            Log::info('[PSB] KTP OCR skip', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Reuse PSB WA local OCR/photo heuristics for migration photos.
     *
     * @return array{type: string, sn: ?string, ktp_ocr: array<string, mixed>, ktp_score: int, sn_score: int, classification_status: string, classification_error?: string}
     */
    public function classifyWaActivationPhoto(string $bytes, ?OcrBudget $batch = null): array
    {
        if (strlen($bytes) < 100) {
            return $this->terminalPhotoClassification('invalid_media');
        }

        try {
            $hardGuard = (bool) config('services.ocrspace.media_hard_guard', false);
            // One bounded time budget per photo (it had none: a slow host could
            // spend minutes here). Classification stays 'terminal' either way.
            $startedAt = microtime(true);
            $imageSeconds = (float) max(15, (int) config('services.ocrspace.media_image_budget_seconds', 90));
            // A caller with several photos passes its batch budget: this photo
            // never runs past what is left of it.
            $budget = $batch !== null ? $batch->forImage($imageSeconds) : OcrBudget::start($imageSeconds);
            $this->lastTesseractTrace = null;
            $this->imageBudget = $budget;
            $this->runCapOverride = max(5, min(60, (int) config('services.ocrspace.tesseract_run_cap', 45)));
            // OCR each image exactly once; reuse the text for every scorer.
            $rawText = (string) ($this->tesseractText($bytes, $budget->deadline()) ?? '');
            $trace = $this->lastTesseractTrace;
            $signalText = $this->normalizeOcrSignalText($rawText);
            $sn = $this->extractSnFromText($signalText);
            $snText = $this->hasSnTextEvidence($rawText, $sn);
            $snScore = $sn ? 90 : $this->scoreSnPhoto($bytes, $rawText);
            $odpScore = $this->scoreOdpPhoto($rawText);
            $ktpOcr = ($sn !== null || $snScore >= 80)
                ? []
                : $this->tryKtpOcr($bytes, $rawText, true);
            $ktpScore = $this->scoreKtpFromOcr($ktpOcr, $bytes, $rawText);
            $ktpText = $this->hasKtpTextEvidence($rawText, $ktpOcr);

            if ($hardGuard && ! $this->hasStrongKtpEvidence($ktpOcr)) {
                $ktpScore = min($ktpScore, 20);
            }
            if ($hardGuard && $sn === null) {
                $snScore = min($snScore, 50);
            }
            if (($snScore >= 70 || $snText) && ! $ktpText) {
                $ktpScore = min($ktpScore, 15);
            }
            if ($ktpText && $ktpScore >= 50 && $snScore < 70 && ! $snText) {
                $snScore = min($snScore, 20);
            }

            $type = $this->photoTypeFromScores($sn, (int) $snScore, (int) $ktpScore, $hardGuard, $odpScore, $ktpText, $snText);
            [, $degraded] = $this->imageOcrStatus($rawText, $trace, $ktpOcr);

            return [
                'type' => $type,
                'sn' => $sn,
                'ktp_ocr' => $ktpOcr,
                'ktp_score' => (int) $ktpScore,
                'sn_score' => (int) $snScore,
                'odp_score' => $odpScore,
                'ktp_text' => $ktpText,
                'sn_text' => $snText,
                'classification_status' => 'terminal',
                // Additive keys: the photo could not be read because the server was slow.
                'ocr_degraded' => $degraded,
                'ocr_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (\Throwable $e) {
            Log::notice('[PSB] photo classification failed closed', ['error' => $e->getMessage()]);

            return $this->terminalPhotoClassification('classifier_error');
        } finally {
            $this->imageBudget = null;
            $this->runCapOverride = null;
        }
    }

    /** @return array{type: string, sn: ?string, ktp_ocr: array<string, mixed>, ktp_score: int, sn_score: int, classification_status: string, classification_error: string} */
    private function terminalPhotoClassification(string $reason): array
    {
        return $this->scorer->terminalPhotoClassification($reason);
    }

    private function photoTypeFromScores(
        ?string $sn,
        int $snScore,
        int $ktpScore,
        bool $hardGuard = false,
        int $odpScore = 0,
        bool $ktpText = false,
        bool $snText = false
    ): string {
        return $this->scorer->photoTypeFromScores($sn, $snScore, $ktpScore, $hardGuard, $odpScore, $ktpText, $snText);
    }

    /** Text/OCR evidence that a photo is a KTP (not just its aspect ratio). */
    private function hasKtpTextEvidence(?string $rawText, array $ocr = []): bool
    {
        return $this->scorer->hasKtpTextEvidence($rawText, $ocr);
    }

    /** Text evidence that a photo is an ONT/router sticker (serial or label). */
    private function hasSnTextEvidence(?string $rawText, ?string $sn = null): bool
    {
        return $this->scorer->hasSnTextEvidence($rawText, $sn);
    }

    private function scoreOdpPhoto(string $rawText): int
    {
        return $this->scorer->scoreOdpPhoto($rawText);
    }

    private function scoreKtpPhoto(string $bytes, array $ocr = [], ?string $rawText = null): int
    {
        return $this->scorer->scoreKtpPhoto(
            $bytes,
            $ocr,
            $rawText,
            fn (string $b): ?string => $this->tesseractText($b),
        );
    }

    /**
     * Compatibility wrapper for the newer photo classifier call.
     *
     * @param  array<string, mixed>  $ocr
     */
    private function scoreKtpFromOcr(array $ocr, string $bytes, ?string $rawText = null): int
    {
        return $this->scoreKtpPhoto($bytes, $ocr, $rawText);
    }

    private function scoreSnPhoto(string $bytes, ?string $rawText = null): int
    {
        return $this->scorer->scoreSnPhoto(
            $bytes,
            $rawText,
            fn (string $b): ?string => $this->tesseractText($b),
        );
    }

    private function normalizeOcrSignalText(string $text): string
    {
        return $this->scorer->normalizeOcrSignalText($text);
    }

    /**
     * The Tesseract call. The language rule and the (test) reader come from the
     * readers: the OCR worker adapter picks the language from the installed packs
     * (null = no pack, so no run, no text and no trace).
     */
    private function tesseractText(string $bytes, ?float $deadline = null): ?string
    {
        $language = ($this->readers->tesseractLanguage)();
        if ($language === null) {
            return null;
        }
        $trace = $this->readers->tesseract !== null
            ? ($this->readers->tesseract)($bytes, $language, $this->tesseractRunCap(), $this->tesseractPsms(), $deadline)
            : TesseractRunner::extractBestTrace($bytes, $language, $this->tesseractRunCap(), $this->tesseractPsms(), null, $deadline);
        $this->lastTesseractTrace = $trace;

        return $trace['text'] !== '' ? $trace['text'] : null;
    }

    /**
     * Per-run Tesseract cap. The OCR worker (and the migration classifier) use
     * services.ocrspace.tesseract_run_cap (45 s); the synchronous path keeps its
     * old 15 s clamp.
     */
    private function tesseractRunCap(): int
    {
        if ($this->runCapOverride !== null) {
            return $this->runCapOverride;
        }
        if ((bool) config('services.ocrspace.ocr_async_job', false)) {
            return max(5, min(60, (int) config('services.ocrspace.tesseract_run_cap', 45)));
        }

        return max(1, min(15, (int) config('services.ocrspace.tesseract_timeout', 5)));
    }

    /** @return list<int> */
    private function tesseractPsms(): array
    {
        $psms = [];
        foreach (explode(',', (string) config('services.ocrspace.tesseract_psms', '3,6,11')) as $part) {
            $mode = (int) trim($part);
            if ($mode > 0) {
                $psms[] = $mode;
            }
        }

        return $psms !== [] ? array_values(array_unique($psms)) : [3, 6, 11];
    }

    private function extractSnFromText(string $text): ?string
    {
        return $this->scorer->extractSnFromText($text);
    }

    /** @param array<string, mixed> $ocr */
    private function hasStrongKtpEvidence(array $ocr): bool
    {
        return $this->scorer->hasStrongKtpEvidence($ocr);
    }
}
