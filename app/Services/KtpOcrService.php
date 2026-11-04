<?php

namespace App\Services;

use App\Support\GdImageMemoryGuard;
use App\Support\KtpParserV2;
use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Accurate Indonesian KTP OCR via PaddleOCR/OCR.space + robust field parsing.
 * Prefer local preprocess (resize/contrast), then pick best NIK from available engines.
 */
class KtpOcrService
{
    /**
     * @return array{
     *   success:bool,
     *   nik:?string,
     *   name:?string,
     *   address:?string,
     *   rt:?string,
     *   rw:?string,
     *   place_of_birth:?string,
     *   gender:?string,
     *   raw_text:string,
     *   engine:?string,
     *   confidence:float,
     *   note:?string,
     *   error:?string
     * }
     */
    public function parseKtp(UploadedFile $file): array
    {
        return $this->parseKtpWithFallbacks($file, true);
    }

    /**
     * PSB/migration path. Tesseract text comes from the caller (the caller
     * already ran it once); this never runs Tesseract again when $localTried.
     * Paddle is only a fallback and is bounded by $budget.
     *
     * @return array<string, mixed>
     */
    public function parseKtpFast(
        UploadedFile $file,
        ?string $localRawText = null,
        ?OcrBudget $budget = null,
        bool $localTried = true,
        bool $allowPaddle = true,
    ): array {
        $this->paddleDisallowed = ! $allowPaddle;

        try {
            return $this->parseKtpWithFallbacks($file, false, $localRawText, $budget, $localTried);
        } finally {
            $this->paddleDisallowed = false;
        }
    }

    /** True while parseKtpFast runs with $allowPaddle=false (text-only parse, no Paddle call, not degraded). */
    protected bool $paddleDisallowed = false;

    /** Budget of the running parseKtpFast call (callPaddleOcr keeps its 2-arg signature). */
    protected ?OcrBudget $activeBudget = null;

    /** True while the web (interactive) parseKtp runs: shorter Paddle lock wait. */
    protected bool $webMode = false;

    /** Outcome of the last Paddle call: ok|timeout|busy|breaker_open|error|skipped. */
    protected string $paddleOutcome = 'skipped';

    /** HTTP timeout (s) of the last Paddle attempt; 0 = no call was made. For logs. */
    protected int $paddleTimeoutUsed = 0;

    /** Time (ms) spent waiting for Paddle to go idle after the last timeout/503. For logs. */
    protected int $paddleDrainMs = 0;

    /** @return array<string, mixed> */
    protected function parseKtpWithFallbacks(
        UploadedFile $file,
        bool $allowExternalFallbacks,
        ?string $localRawText = null,
        ?OcrBudget $budget = null,
        bool $localTried = true,
    ): array {
        $this->activeBudget = $budget;
        $this->webMode = $allowExternalFallbacks;
        $this->paddleOutcome = 'skipped';
        $this->paddleTimeoutUsed = 0;
        $this->paddleDrainMs = 0;

        try {
            return $this->runParse($file, $allowExternalFallbacks, $localRawText, $budget, $localTried);
        } finally {
            $this->activeBudget = null;
            $this->webMode = false;
        }
    }

    /** @return array<string, mixed> */
    private function runParse(
        UploadedFile $file,
        bool $allowExternalFallbacks,
        ?string $localRawText,
        ?OcrBudget $budget,
        bool $localTried,
    ): array {
        $v2 = $this->parserV2();
        $payload = null; // decoded/upscaled only when Paddle or OCR.space is really called
        $attempts = [];
        $paddleNeeded = false;

        $localText = trim((string) $localRawText);
        if ($localText === '' && ! $localTried) {
            $payload = $this->prepareImageBytes($file);
            $localText = trim((string) $this->callLocalTesseract($payload['bytes']));
        }
        $local = null;
        if ($localText !== '') {
            $local = $this->extractFields($localText);
            $local['engine'] = 'tesseract_local';
            $local['raw_text'] = $localText;
        }

        // Local OCR is preferred, but partial Tesseract output must not be
        // treated as a definitive miss. Ask the bounded local Paddle service
        // only when the text lacks strong KTP evidence; clear local text stays
        // fast and avoids an unnecessary network hop.
        if ($allowExternalFallbacks) {
            $paddleNeeded = true;
        } elseif ($v2) {
            $capNeed = $this->paddleCap() + 5;
            $enoughTime = $budget === null || $budget->remaining() >= $capNeed;
            if ($local === null) {
                $paddleNeeded = true;
            } elseif (! ($local['nik_valid'] ?? false)) {
                $paddleNeeded = true;
            } elseif ($local['name'] === null && $enoughTime) {
                $paddleNeeded = true;
            }
        } else {
            $paddleNeeded = $localText === '' || ! $this->hasStrongLocalKtpText($localText);
        }

        if ($this->paddleDisallowed) {
            $paddleNeeded = false;
        }

        if ($paddleNeeded) {
            $payload ??= $this->prepareImageBytes($file);
            $paddleRaw = $this->callPaddleOcr($payload['bytes'], $payload['filename']);
            if ($paddleRaw !== null) {
                $parsed = $this->extractFields($paddleRaw);
                $parsed['engine'] = 'paddleocr';
                $parsed['raw_text'] = $paddleRaw;
                $attempts[] = $parsed;
                if ($parsed['nik'] && $parsed['name'] && $parsed['confidence'] >= 0.85
                    && ($parsed['nik_valid'] ?? true)) {
                    return $this->withPaddleInfo($this->finishBest($attempts), $paddleNeeded);
                }
            }
        }

        // Engine 2 is usually better for cards; engine 1 as fallback.
        $apiKey = $allowExternalFallbacks
            ? (string) config('services.ocrspace.key', '')
            : '';
        if ($allowExternalFallbacks && $apiKey !== '') {
            $payload ??= $this->prepareImageBytes($file);
            foreach ([2, 1] as $engine) {
                $raw = $this->callOcrSpace($apiKey, $payload['bytes'], $payload['filename'], $engine);
                if ($raw === null) {
                    continue;
                }
                $parsed = $this->extractFields($raw);
                $parsed['engine'] = 'ocrspace_e'.$engine;
                $parsed['raw_text'] = $raw;
                $attempts[] = $parsed;
                // High confidence: valid NIK + name → stop
                if ($parsed['nik'] && $parsed['name'] && $parsed['confidence'] >= 0.85) {
                    break;
                }
            }
        }

        if ($allowExternalFallbacks && $local === null) {
            // Web path has no earlier Tesseract run: this is its (single) local run.
            $payload ??= $this->prepareImageBytes($file);
            $localText = trim((string) $this->callLocalTesseract($payload['bytes']));
            if ($localText !== '') {
                $local = $this->extractFields($localText);
                $local['engine'] = 'tesseract_local';
                $local['raw_text'] = $localText;
            }
        }
        if ($local !== null) {
            $attempts[] = $local;
        }

        if ($attempts === []) {
            return $this->withPaddleInfo(
                $this->fail('OCR gagal menghubungi server. Isi NIK manual atau coba foto lebih jelas.'),
                $paddleNeeded
            );
        }

        return $this->withPaddleInfo($this->finishBest($attempts), $paddleNeeded);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function withPaddleInfo(array $result, bool $paddleNeeded): array
    {
        $result['paddle_outcome'] = $this->paddleOutcome;
        $result['paddle_timeout_s'] = $this->paddleTimeoutUsed;
        $result['paddle_drain_ms'] = $this->paddleDrainMs;
        // Degraded = Paddle was needed to find the NIK but could not answer.
        $result['ocr_degraded'] = $paddleNeeded
            && ! in_array($this->paddleOutcome, ['ok', 'skipped'], true)
            && ! (bool) ($result['nik_valid'] ?? false)
            && $this->parserV2();

        return $result;
    }

    protected function parserV2(): bool
    {
        return (bool) config('services.ocrspace.ktp_parser_v2', true);
    }

    protected function hasStrongLocalKtpText(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if ($this->parserV2()) {
            $parsed = $this->extractFields($text);

            return ($parsed['nik_valid'] ?? false) && $parsed['name'] !== null;
        }

        $hasNik = preg_match('/\b\d[\s\d]{15,24}\b/u', $text) === 1;
        $hasName = preg_match('/\b(?:NAMA|NAME)\s*[:.]?\s*[A-Z][A-Z .\'-]{3,}/iu', $text) === 1;

        return $hasNik && $hasName;
    }

    /**
     * @param  array<int,array<string,mixed>>  $attempts
     * @return array<string,mixed>
     */
    protected function finishBest(array $attempts): array
    {
        $order = $attempts;
        // Pick best attempt by confidence then field count
        usort($order, function ($a, $b) {
            $sa = ($a['confidence'] * 10) + ($a['nik'] ? 3 : 0) + ($a['name'] ? 2 : 0) + ($a['rt'] ? 1 : 0);
            $sb = ($b['confidence'] * 10) + ($b['nik'] ? 3 : 0) + ($b['name'] ? 2 : 0) + ($b['rt'] ? 1 : 0);

            return $sb <=> $sa;
        });
        $best = $order[0];

        if ($this->parserV2() && count($attempts) > 1) {
            $best = $this->mergeAttempts($attempts, $order, $best);
        }

        $best['success'] = $best['nik'] !== null;
        $best['error'] = null;
        $best['note'] = $best['nik']
            ? null
            : 'NIK tidak terbaca otomatis. Pastikan foto tajam, cahaya cukup, KTP penuh dalam frame.';

        return $best;
    }

    /**
     * Field-by-field merge across engines. $attempts is in engine order
     * (Paddle / OCR.space first, local Tesseract last); $order is by score.
     * NIK: the value two engines agree on, else the first one that passes
     * isValidNik(), else the best-scoring attempt's. The merged result's engine
     * and raw_text are those of the NIK source.
     *
     * @param  array<int,array<string,mixed>>  $attempts
     * @param  array<int,array<string,mixed>>  $order
     * @param  array<string,mixed>  $best
     * @return array<string,mixed>
     */
    private function mergeAttempts(array $attempts, array $order, array $best): array
    {
        $source = null;
        $counts = [];
        foreach ($attempts as $a) {
            if (! empty($a['nik']) && ($a['nik_valid'] ?? false)) {
                $counts[$a['nik']] = ($counts[$a['nik']] ?? 0) + 1;
            }
        }
        foreach ($attempts as $a) {
            if (! empty($a['nik']) && ($counts[$a['nik']] ?? 0) >= 2) {
                $source = $a;
                break;
            }
        }
        if ($source === null) {
            foreach ($order as $a) {
                if (! empty($a['nik']) && ($a['nik_valid'] ?? false)) {
                    $source = $a;
                    break;
                }
            }
        }
        $source ??= $best;

        $merged = $source;
        foreach (['name', 'address', 'place_of_birth', 'gender'] as $field) {
            $merged[$field] = null;
            foreach ($attempts as $a) {
                if (! empty($a[$field])) {
                    $merged[$field] = $a[$field];
                    break;
                }
            }
        }
        $merged['rt'] = null;
        $merged['rw'] = null;
        foreach ($attempts as $a) {
            if (! empty($a['rt']) && ! empty($a['rw'])) {
                $merged['rt'] = $a['rt'];
                $merged['rw'] = $a['rw'];
                break;
            }
        }

        $confidence = 0.0;
        $nik = (string) ($merged['nik'] ?? '');
        if ($nik !== '' && $this->isPlausibleNik($nik)) {
            $confidence += 0.55;
        } elseif ($nik !== '') {
            $confidence += 0.25;
        }
        $confidence += $merged['name'] ? 0.2 : 0.0;
        $confidence += $merged['address'] ? 0.1 : 0.0;
        $confidence += ($merged['rt'] && $merged['rw']) ? 0.15 : 0.0;
        $merged['confidence'] = min(1.0, $confidence);

        return $merged;
    }

    protected function paddleCap(): int
    {
        return max(1, min(45, (int) config('services.ocrspace.paddle_timeout', 8)));
    }

    /**
     * @return array{bytes:string,filename:string}
     */
    protected function prepareImageBytes(UploadedFile $file): array
    {
        $path = $file->getPathname();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = 'ktp.'.$ext;
        $bytes = (string) file_get_contents($path);

        if (! function_exists('imagecreatefromstring')) {
            return ['bytes' => $bytes, 'filename' => $filename];
        }

        // A big phone photo can exhaust memory_limit inside GD: that is a fatal
        // error, not an exception. Downscale out of process with magick (own
        // memory caps); send the original bytes only when that fails too.
        if (! GdImageMemoryGuard::decodeFits($bytes)) {
            if (GdImageMemoryGuard::hasReadableSize($bytes)) {
                // On the PSB path the downscale shares the per-image budget with
                // Paddle: leave Paddle its cap. The web scan has no budget.
                $seconds = $this->activeBudget !== null
                    ? (int) max(3, min(20, floor($this->activeBudget->remaining()) - $this->paddleCap()))
                    : 20;
                $small = TesseractRunner::downscaleForOcr($bytes, $seconds);
                if ($small !== null) {
                    return ['bytes' => $small, 'filename' => 'ktp.jpg'];
                }
            }

            return ['bytes' => $bytes, 'filename' => $filename];
        }

        try {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                return ['bytes' => $bytes, 'filename' => $filename];
            }

            $w = imagesx($img);
            $h = imagesy($img);
            // Upscale small shots (phone far away) — OCR loves ~1600–2200px width
            $targetW = 1800;
            if ($w > 0 && $w < 1200) {
                $scale = $targetW / $w;
                $nw = (int) round($w * $scale);
                $nh = (int) round($h * $scale);
                $resized = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
                $w = $nw;
                $h = $nh;
            } elseif ($w > 2800) {
                $scale = 2200 / $w;
                $nw = (int) round($w * $scale);
                $nh = (int) round($h * $scale);
                $resized = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            }

            // Mild contrast for dark phone photos
            if (function_exists('imagefilter')) {
                @imagefilter($img, IMG_FILTER_CONTRAST, -12);
                @imagefilter($img, IMG_FILTER_BRIGHTNESS, 8);
            }

            ob_start();
            imagejpeg($img, null, 92);
            $out = (string) ob_get_clean();
            imagedestroy($img);

            if ($out !== '') {
                return ['bytes' => $out, 'filename' => 'ktp.jpg'];
            }
        } catch (\Throwable $e) {
            Log::debug('KTP OCR preprocess skipped', ['error' => $e->getMessage()]);
        }

        return ['bytes' => $bytes, 'filename' => $filename];
    }

    protected function callOcrSpace(string $apiKey, string $bytes, string $filename, int $engine): ?string
    {
        try {
            $response = Http::timeout(45)
                ->asMultipart()
                ->attach('file', $bytes, $filename)
                ->post('https://api.ocr.space/parse/image', [
                    'apikey' => $apiKey,
                    'language' => 'eng',
                    'OCREngine' => (string) $engine,
                    'isTable' => 'true',
                    'scale' => 'true',
                    'detectOrientation' => 'true',
                    'isOverlayRequired' => 'false',
                ]);
        } catch (\Throwable $e) {
            Log::warning('KTP OCR request failed', ['engine' => $engine, 'error' => $e->getMessage()]);

            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ($json['IsErroredOnProcessing'] ?? false)) {
            $msg = is_array($json['ErrorMessage'] ?? null)
                ? implode('; ', $json['ErrorMessage'])
                : (string) ($json['ErrorMessage'] ?? $response->body());
            Log::warning('KTP OCR processing error', ['engine' => $engine, 'msg' => $msg]);

            return null;
        }

        return (string) ($json['ParsedResults'][0]['ParsedText'] ?? '');
    }

    protected function callPaddleOcr(string $bytes, string $filename): ?string
    {
        if (! (bool) config('services.ocrspace.paddle_enabled', true)) {
            return null;
        }

        $url = trim((string) config('services.ocrspace.paddle_url', ''));
        if ($url === '') {
            return null;
        }

        $cap = $this->paddleCap();
        $budget = $this->activeBudget;

        if ($this->paddleBreakerOpen()) {
            $this->paddleOutcome = 'breaker_open';

            return null;
        }
        $minTimeout = $this->paddleMinTimeout($cap);
        // Not enough time left for a useful call: do not start one. A call with
        // less than a real Paddle run (3.5-24 s) can only time out and leaves a
        // running inference behind that blocks the next caller.
        if ($budget !== null && $minTimeout > 0 && $budget->remaining() < $minTimeout) {
            $this->paddleOutcome = 'skipped_short';
            $this->paddleTimeoutUsed = 0;

            return null;
        }
        if ($budget !== null && $budget->remaining() < 3) {
            $this->paddleOutcome = 'skipped';

            return null;
        }

        // The Paddle container runs one inference at a time and keeps working
        // after our HTTP timeout, so callers are serialised with a lock instead
        // of piling 503s (and hung inferences) onto each other.
        $lockWait = (int) config(
            $this->webMode ? 'services.ocrspace.paddle_lock_wait_web_seconds' : 'services.ocrspace.paddle_lock_wait_seconds',
            $this->webMode ? 5 : 15
        );
        if ($budget !== null) {
            // Wait for the lock only as long as a useful call still fits after it.
            $lockWait = (int) floor(min($lockWait, $budget->remaining() - ($minTimeout > 0 ? $minTimeout : $cap)));
        }
        $lockWait = max(0, $lockWait);
        $lock = null;
        $drainSeconds = $this->paddleDrainSeconds();
        try {
            // Longest possible hold: 3 attempts of up to $cap each + 2 s and 5 s
            // backoff (+ jitter) + the drain wait + margin, so the lock never
            // expires while held.
            $lock = Cache::lock('ocr:paddle:inference', 3 * $cap + 25 + $drainSeconds);
            $got = $lockWait > 0 ? $lock->block($lockWait) : $lock->get();
        } catch (LockTimeoutException) {
            // Another caller is inside Paddle: that is load, not a Paddle failure.
            $this->paddleOutcome = 'busy';

            return null;
        } catch (\Throwable $e) {
            // Cache/Redis trouble must not take OCR down: go on without the lock.
            Log::notice('KTP PaddleOCR lock unavailable', ['error' => $e->getMessage()]);
            $lock = null;
            $got = true;
        }
        if (! $got) {
            $this->paddleOutcome = 'busy';

            return null;
        }

        try {
            // The wait for the lock used up time: re-check a useful call still fits.
            if ($budget !== null && $minTimeout > 0 && $budget->remaining() < $minTimeout) {
                $this->paddleOutcome = 'skipped_short';
                $this->paddleTimeoutUsed = 0;

                return null;
            }

            return $this->paddleRequest($url, $bytes, $filename, $cap, $budget, $minTimeout, $drainSeconds);
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable) {
                // Lock already expired; nothing to do.
            }
        }
    }

    /** Smallest useful Paddle call (seconds), never above the per-call cap. 0 = guard off. */
    protected function paddleMinTimeout(int $cap): int
    {
        $min = (int) config('services.ocrspace.paddle_min_timeout_seconds', 15);

        return $min <= 0 ? 0 : min($min, $cap);
    }

    /** Max wait for Paddle to finish a left-over inference before the lock is released. Web path: never. */
    protected function paddleDrainSeconds(): int
    {
        if ($this->webMode) {
            return 0;
        }

        return max(0, min(120, (int) config('services.ocrspace.paddle_drain_seconds', 30)));
    }

    /**
     * Poll Paddle /health until no inference is running. Paddle keeps working
     * after our HTTP timeout, so the next lock holder must not meet it.
     * False = still busy after $seconds, or health not reachable.
     */
    protected function waitPaddleIdle(int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }
        $health = preg_replace('#/ocr/?$#', '/health', trim((string) config('services.ocrspace.paddle_url', '')));
        if (! is_string($health) || $health === '') {
            return false;
        }

        $startedAt = microtime(true);
        $polls = 0;
        try {
            do {
                $polls++;
                $response = Http::timeout(3)->connectTimeout(2)->get($health);
                if (! $response->successful()) {
                    return false;
                }
                // Idle = no run AND nothing queued (a request the caller gave up on would start next).
                if (! (bool) ($response->json('busy') ?? false) && (int) ($response->json('waiting') ?? 0) <= 0) {
                    return true;
                }
                if ((microtime(true) - $startedAt) >= $seconds || $polls > $seconds * 2) {
                    return false;
                }
                $this->sleepFor(1.0);
            } while (true);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->paddleDrainMs = (int) round((microtime(true) - $startedAt) * 1000);
        }
    }

    /** Timeout/503: wait for Paddle to go idle (load, not a failure) or count the failure for the breaker. */
    private function paddleFailed(int $drainSeconds): void
    {
        if ($drainSeconds > 0 && $this->waitPaddleIdle($drainSeconds)) {
            return;
        }
        $this->paddleRecordFailure();
    }

    private function paddleRequest(
        string $url,
        string $bytes,
        string $filename,
        int $cap,
        ?OcrBudget $budget,
        int $minTimeout = 0,
        int $drainSeconds = 0,
    ): ?string {
        // The single-engine service returns 503 "OCR engine is busy" when an
        // inference is already running. Back off (2 s, 5 s + jitter) while the
        // budget allows; the web path keeps the old single short retry.
        $backoffs = $budget !== null ? [2.0, 5.0] : [0.3];
        $response = null;
        for ($attempt = 0; $attempt <= count($backoffs); $attempt++) {
            $timeout = $budget !== null ? max(1, min($cap, (int) floor($budget->remaining()))) : $cap;
            if ($attempt > 0 && $minTimeout > 0 && $timeout < $minTimeout) {
                // A shrinking retry can only time out: stop here.
                break;
            }
            $this->paddleTimeoutUsed = $timeout;
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(min(2, $timeout))
                    ->asMultipart()
                    ->attach('file', $bytes, $filename)
                    ->post(trim((string) config('services.ocrspace.paddle_url', '')));
            } catch (\Throwable $e) {
                Log::warning('KTP PaddleOCR request failed', ['url' => config('services.ocrspace.paddle_url'), 'error' => $e->getMessage(), 'attempt' => $attempt + 1]);
                $this->paddleOutcome = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'cURL error 28') !== false
                    ? 'timeout'
                    : 'error';
                if ($this->paddleOutcome === 'timeout') {
                    // Paddle is most likely still running our image: hold the lock until it is done.
                    $this->paddleFailed($drainSeconds);
                } else {
                    $this->paddleRecordFailure();
                }

                return null;
            }

            if ($response->status() !== 503) {
                break;
            }
            if ($attempt >= count($backoffs)) {
                break;
            }
            $sleep = $backoffs[$attempt] + ($budget !== null ? mt_rand(0, 500) / 1000 : 0.0);
            if ($budget !== null && $budget->remaining() < $sleep + max(3, $minTimeout)) {
                break;
            }
            $this->sleepFor($sleep);
        }

        if (! $response || ! $response->successful()) {
            Log::warning('KTP PaddleOCR processing error', ['url' => config('services.ocrspace.paddle_url'), 'status' => $response?->status()]);
            $this->paddleOutcome = $response && $response->status() === 503 ? 'busy' : 'error';
            if ($this->paddleOutcome === 'busy') {
                $this->paddleFailed($drainSeconds);
            } else {
                $this->paddleRecordFailure();
            }

            return null;
        }

        $text = $this->extractOcrTextFromResponse($response->json(), $response->body());
        $this->paddleOutcome = 'ok';
        $this->paddleClearFailures();

        return trim($text) !== '' ? $text : null;
    }

    protected function sleepFor(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    private function paddleBreakerOpen(): bool
    {
        try {
            return (int) Cache::get('ocr:paddle:breaker_until', 0) > time();
        } catch (\Throwable) {
            return false;
        }
    }

    /** N timeouts/503 within 5 minutes open the breaker: Paddle is skipped for a while. */
    private function paddleRecordFailure(): void
    {
        try {
            $failures = (int) Cache::get('ocr:paddle:failures', 0) + 1;
            Cache::put('ocr:paddle:failures', $failures, 300);
            if ($failures >= max(1, (int) config('services.ocrspace.paddle_breaker_failures', 3))) {
                Cache::put(
                    'ocr:paddle:breaker_until',
                    time() + max(1, (int) config('services.ocrspace.paddle_breaker_seconds', 180)),
                    max(1, (int) config('services.ocrspace.paddle_breaker_seconds', 180))
                );
                Cache::forget('ocr:paddle:failures');
            }
        } catch (\Throwable) {
            // Breaker state is best-effort.
        }
    }

    private function paddleClearFailures(): void
    {
        try {
            Cache::forget('ocr:paddle:failures');
        } catch (\Throwable) {
        }
    }

    protected function callLocalTesseract(string $bytes): ?string
    {
        $language = $this->tesseractLanguageOption();
        if ($language === null) {
            return null;
        }

        try {
            // Only reached when no caller ran Tesseract first (web path). One
            // run cap, a hard wall-clock limit, and no repeat after a timeout.
            $cap = max(5, min(25, (int) config('services.ocrspace.tesseract_run_cap', 45)));
            $trace = TesseractRunner::extractBestTrace(
                $bytes,
                $language,
                $cap,
                [3, 6, 11],
                null,
                microtime(true) + 60,
            );

            return $trace['text'] !== '' ? $trace['text'] : null;
        } catch (\Throwable $e) {
            Log::debug('KTP local tesseract skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param list<string>|null $available */
    protected function tesseractLanguageOption(?array $available = null): ?string
    {
        $languages = $available ?? TesseractRunner::languages();
        $languages = array_values(array_filter(array_map('trim', $languages)));

        if (in_array('ind', $languages, true) && in_array('eng', $languages, true)) {
            return 'ind+eng';
        }

        return in_array('eng', $languages, true) ? 'eng' : (in_array('ind', $languages, true) ? 'ind' : null);
    }

    protected function extractOcrTextFromResponse(mixed $json, string $body): string
    {
        if (is_array($json)) {
            $known = $this->firstStringFromKeys($json, [
                'text', 'raw_text', 'ocr_text', 'ParsedText', 'parsed_text', 'result_text',
            ]);
            if ($known !== null) {
                return $known;
            }

            $lines = [];
            $this->collectOcrStrings($json, $lines);

            return implode("\n", array_values(array_unique($lines)));
        }

        return $body;
    }

    /**
     * @param  array<string,mixed>|array<int,mixed>  $data
     * @param  array<int,string>  $keys
     */
    protected function firstStringFromKeys(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return $data[$key];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->firstStringFromKeys($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $lines
     */
    protected function collectOcrStrings(mixed $value, array &$lines): void
    {
        if (is_string($value)) {
            $text = trim($value);
            if ($text !== '' && strlen($text) <= 160 && ! preg_match('/^[a-z0-9+\/=]{80,}$/i', $text)) {
                $lines[] = $text;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectOcrStrings($child, $lines);
        }
    }

    /**
     * @return array{
     *   nik:?string,name:?string,address:?string,rt:?string,rw:?string,
     *   place_of_birth:?string,gender:?string,confidence:float,success:bool,note:?string,error:?string,raw_text:string,engine:?string
     * }
     */
    public function extractFields(string $rawText): array
    {
        if ($this->parserV2()) {
            return $this->extractFieldsV2($rawText);
        }

        return $this->extractFieldsV1($rawText);
    }

    /**
     * Line-based parser (see KtpParserV2). Same output shape plus nik_valid.
     * The NIK is still returned when it fails isValidNik() (scoring unchanged);
     * callers decide whether to trust it.
     *
     * @return array<string,mixed>
     */
    protected function extractFieldsV2(string $rawText): array
    {
        $p = KtpParserV2::parse($rawText);
        $nik = $p['nik'];

        $confidence = 0.0;
        if ($nik && $this->isPlausibleNik($nik)) {
            $confidence += 0.55;
        } elseif ($nik) {
            $confidence += 0.25;
        }
        if ($p['name']) {
            $confidence += 0.2;
        }
        if ($p['address']) {
            $confidence += 0.1;
        }
        if ($p['rt'] && $p['rw']) {
            $confidence += 0.15;
        }

        return [
            'success' => $nik !== null,
            'nik' => $nik,
            'nik_valid' => $p['nik_valid'],
            'name' => $p['name'],
            'address' => $p['address'],
            'rt' => $p['rt'],
            'rw' => $p['rw'],
            'place_of_birth' => $p['place_of_birth'],
            'gender' => $p['gender'],
            'raw_text' => $rawText,
            'engine' => null,
            'confidence' => min(1.0, $confidence),
            'note' => null,
            'error' => null,
        ];
    }

    /** Province, birth date and serial all plausible (stricter than isPlausibleNik). */
    public function isValidNik(string $nik): bool
    {
        return KtpParserV2::isValidNik($nik);
    }

    /** @return array<string,mixed> */
    protected function extractFieldsV1(string $rawText): array
    {
        $text = $this->normalizeText($rawText);
        $nik = $this->extractNik($text);
        $name = $this->extractName($text);
        $address = $this->extractAddress($text);
        [$rt, $rw] = $this->extractRtRw($text);
        $pob = $this->extractLabeled($text, ['Tempat/Tgl Lahir', 'Tempat / Tgl Lahir', 'Tempat Tgl Lahir', 'TTL']);
        $gender = $this->extractLabeled($text, ['Jenis Kelamin', 'Jenis Keiamin']);

        $confidence = 0.0;
        if ($nik && $this->isPlausibleNik($nik)) {
            $confidence += 0.55;
        } elseif ($nik) {
            $confidence += 0.25;
        }
        if ($name) {
            $confidence += 0.2;
        }
        if ($address) {
            $confidence += 0.1;
        }
        if ($rt && $rw) {
            $confidence += 0.15;
        }

        return [
            'success' => $nik !== null,
            'nik' => $nik,
            'name' => $name,
            'address' => $address,
            'rt' => $rt,
            'rw' => $rw,
            'place_of_birth' => $pob,
            'gender' => $gender,
            'raw_text' => $rawText,
            'engine' => null,
            'confidence' => min(1.0, $confidence),
            'note' => null,
            'error' => null,
        ];
    }

    protected function normalizeText(string $raw): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $raw);
        // Common OCR confusions on Indonesian KTP labels
        $map = [
            'NlK' => 'NIK',
            'N1K' => 'NIK',
            'NIK.' => 'NIK',
            'Narna' => 'Nama',
            'Narua' => 'Nama',
            'Alamat.' => 'Alamat',
            'Aiamat' => 'Alamat',
            'RTRW' => 'RT/RW',
            'RTIRW' => 'RT/RW',
            'RT RW' => 'RT/RW',
        ];
        $t = str_ireplace(array_keys($map), array_values($map), $t);

        // Collapse spaces inside digit runs: "3 5 7 8 0 1 ..." → keep for NIK extractor
        return $t;
    }

    protected function extractNik(string $text): ?string
    {
        if (preg_match('/\bNIK\s*[:\-]?\s*([0-9OIl][0-9OIl\s.\-]{15,30})/iu', $text, $m)) {
            $cand = $this->digitsOnly($m[1]);
            if (strlen($cand) >= 16) {
                $nik = substr($cand, 0, 16);
                if ($this->isPlausibleNik($nik)) {
                    return $nik;
                }
            }
        }

        // Labeled NIK
        if (preg_match('/\bNIK\b\s*[:\-]?\s*([0-9OIl\s.\-]{14,30})/iu', $text, $m)) {
            $cand = $this->digitsOnly($m[1]);
            $cand = $this->fixOcrDigitNoise($cand);
            if (strlen($cand) >= 16) {
                $nik = substr($cand, 0, 16);
                if ($this->isPlausibleNik($nik)) {
                    return $nik;
                }
            }
        }

        // Any 16-digit run after stripping noise (prefer plausible NIK)
        $flat = $this->fixOcrDigitNoise(preg_replace('/[^\dOIl]/i', '', $text) ?? '');
        if (preg_match_all('/\d{16}/', $flat, $matches)) {
            foreach ($matches[0] as $cand) {
                if ($this->isPlausibleNik($cand)) {
                    return $cand;
                }
            }

            return $matches[0][0] ?? null;
        }

        return null;
    }

    protected function extractName(string $text): ?string
    {
        // Line-based: after "Nama" label
        if (preg_match('/\bNama\b\s*[:\-]?\s*([A-Z][A-Z .\'\-]{1,60})/imu', $text, $m)) {
            $name = $this->cleanName($m[1]);
            if ($name !== null) {
                return $name;
            }
        }

        // Next line after Nama
        $lines = preg_split('/\n+/', $text) ?: [];
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/^\s*Nama\b/i', $lines[$i])) {
                $same = preg_replace('/^\s*Nama\s*[:\-]?\s*/i', '', $lines[$i]);
                $cand = $this->cleanName((string) $same);
                if ($cand) {
                    return $cand;
                }
                if (isset($lines[$i + 1])) {
                    $cand = $this->cleanName($lines[$i + 1]);
                    if ($cand) {
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    protected function extractAddress(string $text): ?string
    {
        if (preg_match('/\bAlamat\b\s*[:\-]?\s*([A-Z0-9][A-Z0-9 .\/,\-]{2,80})/imu', $text, $m)) {
            $addr = trim($m[1]);
            $addr = preg_replace('/\s+(RT\/?RW|RTRW|Kel|Desa|Kec|Kota|Kab|Provinsi).*$/iu', '', $addr);

            return $addr !== '' ? $addr : null;
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function extractRtRw(string $text): array
    {
        // RT/RW 004/002 or 4/2
        if (preg_match('/\bRT\s*\/?\s*RW\b\s*[:\-]?\s*0*(\d{1,3})\s*[\/\-]\s*0*(\d{1,3})/iu', $text, $m)) {
            return [(string) (int) $m[1], (string) (int) $m[2]];
        }
        if (preg_match('/\bRT\b\s*[:\-]?\s*0*(\d{1,3}).{0,12}\bRW\b\s*[:\-]?\s*0*(\d{1,3})/iu', $text, $m)) {
            return [(string) (int) $m[1], (string) (int) $m[2]];
        }
        if (preg_match('/\b0*(\d{1,3})\s*[\/\-]\s*0*(\d{1,3})\b/', $text, $m)) {
            // Only accept if near RT keyword context
            $pos = strpos($text, $m[0]);
            $window = $pos !== false ? substr($text, max(0, $pos - 20), 40) : '';
            if (preg_match('/RT|RW/i', $window)) {
                return [(string) (int) $m[1], (string) (int) $m[2]];
            }
        }

        return [null, null];
    }

    protected function extractLabeled(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $q = preg_quote($label, '/');
            if (preg_match('/'.$q.'\s*[:\-]?\s*([A-Z0-9][A-Z0-9 .\/,\-]{1,60})/imu', $text, $m)) {
                $v = trim($m[1]);
                $v = preg_replace('/\s+(Jenis|Gol|Agama|Status|Pekerjaan|Kewarganegaraan|Berlaku).*$/iu', '', $v);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    protected function cleanName(string $raw): ?string
    {
        $name = strtoupper(trim($raw));
        $name = preg_replace('/[^A-Z .\'\-]/', '', $name) ?? '';
        $name = preg_replace('/\s+(TEMPAT|TGL|JENIS|ALAMAT|GOL|STATUS|AGAMA|PEKERJAAN|NIK).*$/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (strlen($name) < 3 || strlen($name) > 60) {
            return null;
        }
        // Reject pure labels
        if (preg_match('/^(NAMA|NIK|ALAMAT)$/i', $name)) {
            return null;
        }

        return $name;
    }

    protected function digitsOnly(string $s): string
    {
        return preg_replace('/\D/', '', $this->fixOcrDigitNoise($s)) ?? '';
    }

    protected function fixOcrDigitNoise(string $s): string
    {
        // O/o → 0, I/l → 1 inside digit contexts
        $s = str_replace(['O', 'o', 'Q'], '0', $s);
        $s = str_replace(['I', 'l', '|', '!'], '1', $s);
        $s = str_replace(['S'], '5', $s);
        $s = str_replace(['B'], '8', $s);

        return $s;
    }

    /**
     * Soft validation for Indonesian NIK (16 digits, date part plausible).
     */
    public function isPlausibleNik(string $nik): bool
    {
        if (! preg_match('/^\d{16}$/', $nik)) {
            return false;
        }
        // All zeros / repeated digits unlikely
        if (preg_match('/^(\d)\1{15}$/', $nik)) {
            return false;
        }
        $dd = (int) substr($nik, 6, 2);
        $mm = (int) substr($nik, 8, 2);
        $yy = (int) substr($nik, 10, 2);
        // Female: day + 40
        if ($dd > 40) {
            $dd -= 40;
        }
        if ($dd < 1 || $dd > 31) {
            return false;
        }
        if ($mm < 1 || $mm > 12) {
            return false;
        }
        // Year 00-99 always ok
        if ($yy < 0 || $yy > 99) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *   success:bool,nik:?string,name:?string,address:?string,rt:?string,rw:?string,
     *   place_of_birth:?string,gender:?string,raw_text:string,engine:?string,
     *   confidence:float,note:?string,error:?string
     * }
     */
    protected function fail(string $error): array
    {
        return [
            'success' => false,
            'nik' => null,
            'name' => null,
            'address' => null,
            'rt' => null,
            'rw' => null,
            'place_of_birth' => null,
            'gender' => null,
            'raw_text' => '',
            'engine' => null,
            'confidence' => 0.0,
            'note' => null,
            'error' => $error,
        ];
    }
}
