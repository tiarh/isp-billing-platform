<?php

namespace App\Support;

/**
 * Bounded Tesseract adapter for synchronous and asynchronous OCR paths.
 */
final class TesseractRunner
{
    /** @var list<string>|null */
    private static ?array $languages = null;

    private static ?bool $magickAvailable = null;

    /**
     * Page segmentation mode defaults to 3 (fully automatic). PSM 6
     * ("assume a single uniform block") returns empty/garbled text on KTP
     * photos, which made the PSB media classifier treat real KTPs as
     * "unknown" and blocked drafts with `ktp_missing`. PSM 3 reads the same
     * images correctly. Callers may still pass an explicit PSM.
     */
    public static function extract(string $bytes, string $language, int $timeoutSeconds = 5, int $psm = 3): ?string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        // ImageMagick infers the format from the file extension, so the temp
        // input needs one. Content is sniffed; default to jpg.
        $base = tempnam($dir, 'ocr_');
        if ($base === false) {
            return null;
        }
        @unlink($base);
        $image = $base.'.'.self::extensionFor($bytes);
        if (@file_put_contents($image, $bytes) === false) {
            @unlink($image);

            return null;
        }

        $prepared = $image;
        try {
            // Downscale + grayscale first. Tesseract on a full-resolution phone
            // photo (with OpenMP thread contention) can take 40s+ and return
            // nothing; a bounded preprocess makes it fast and reliable.
            $optimized = self::preprocess($image, $dir, $timeoutSeconds);
            if ($optimized !== null) {
                $prepared = $optimized;
            }

            return self::run([
                'tesseract', $prepared, 'stdout', '-l', $language, '--psm', (string) $psm,
            ], $timeoutSeconds);
        } finally {
            @unlink($image);
            if ($prepared !== $image) {
                @unlink($prepared);
            }
        }
    }

    /** Strong KTP / ONU-sticker signals that make further PSM runs unnecessary. */
    public const STRONG_DOCUMENT_SIGNALS = '/\bNIK\b|REPUBLIK|PROVINSI|KABUPATEN|KECAMATAN|KELURAHAN|PEKERJAAN|\bAGAMA\b|(?:FHTT|HWTC|ZTEG|CMDC|ALCL|CIOT)[A-Z0-9]{6,}|GPON\s*ONU|SERIAL\s*(?:NO|NUMBER)/i';

    /**
     * Robust OCR for mixed KTP/sticker photos: the best page-segmentation mode
     * varies per image (a KTP that reads with PSM 3 may return nothing with
     * PSM 6, and vice versa). Run a small set of PSMs and concatenate the
     * distinct results so keyword evidence from any mode is available.
     *
     * Bounded on two axes so a slow photo can never dominate a batch:
     * - stops early once the accumulated text already carries a strong KTP/SN
     *   signal (the common case costs a single Tesseract run);
     * - stops when an optional wall-clock deadline passes.
     *
     * @param  list<int>  $psms
     * @param  float|null  $deadline  Unix timestamp (microtime) after which no new PSM is started
     */
    public static function extractBest(
        string $bytes,
        string $language,
        int $timeoutSeconds = 5,
        array $psms = [3, 6, 11],
        ?string $strongSignals = null,
        ?float $deadline = null,
    ): ?string {
        $trace = self::extractBestTrace($bytes, $language, $timeoutSeconds, $psms, $strongSignals, $deadline);

        return $trace['text'] !== '' ? $trace['text'] : null;
    }

    /**
     * Same as extractBest() but reports what happened. A run that hit its
     * timeout is flagged (it is NOT the same as "no text") and is never
     * repeated or followed by another PSM: under host load the next run would
     * be killed the same way and only burn the batch budget.
     *
     * @param  list<int>  $psms
     * @param  float|null  $deadline  Unix timestamp after which no new run starts
     * @return array{text:string, runs:list<array{psm:int,ms:int,timed_out:bool,chars:int}>, timed_out:bool, deadline_hit:bool}
     */
    public static function extractBestTrace(
        string $bytes,
        string $language,
        int $runCap = 25,
        array $psms = [3, 6, 11],
        ?string $strongSignals = null,
        ?float $deadline = null,
    ): array {
        $strongSignals ??= self::STRONG_DOCUMENT_SIGNALS;
        $runCap = max(5, min(60, $runCap));
        $trace = ['text' => '', 'runs' => [], 'timed_out' => false, 'deadline_hit' => false];

        $dir = storage_path('app/temp');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $trace;
        }

        $base = tempnam($dir, 'ocr_');
        if ($base === false) {
            return $trace;
        }
        @unlink($base);
        $image = $base.'.'.self::extensionFor($bytes);
        if (@file_put_contents($image, $bytes) === false) {
            @unlink($image);

            return $trace;
        }

        // Preprocess ONCE for all PSMs. Previously each PSM ran its own
        // ImageMagick pass, tripling CPU on an already-loaded host and causing
        // Tesseract timeouts (a KTP then read as empty -> "unknown").
        // Nothing is prepared once the budget is spent, and preparing never
        // gets more time than is left.
        $prepared = $image;
        if ($deadline !== null && $deadline - microtime(true) <= 0) {
            $trace['deadline_hit'] = true;
            @unlink($image);

            return $trace;
        }
        $prepCap = $deadline !== null ? max(1, min($runCap, (int) ceil($deadline - microtime(true)))) : $runCap;
        $prepared = self::preprocess($image, $dir, $prepCap) ?? $image;

        try {
            $texts = [];
            $lastMs = 0;
            foreach ($psms as $position => $psm) {
                $remaining = $deadline !== null ? $deadline - microtime(true) : null;
                if ($remaining !== null && $remaining <= 0) {
                    $trace['deadline_hit'] = true;
                    break;
                }
                // A further PSM only starts when the time left comfortably
                // covers one more run of the length the last one took.
                if ($position > 0 && $remaining !== null && $remaining < 1.5 * ($lastMs / 1000)) {
                    $trace['deadline_hit'] = true;
                    break;
                }

                $runTimeout = $remaining !== null ? max(1, min($runCap, (int) ceil($remaining))) : $runCap;
                $run = self::runDetailed([
                    'tesseract', $prepared, 'stdout', '-l', $language, '--psm', (string) $psm,
                ], $runTimeout);
                $lastMs = $run['ms'];
                $text = trim((string) $run['text']);
                $trace['runs'][] = [
                    'psm' => (int) $psm,
                    'ms' => $run['ms'],
                    'timed_out' => $run['timed_out'],
                    'chars' => mb_strlen($text),
                ];

                if ($text !== '') {
                    $texts[] = $text;
                }
                if ($run['timed_out']) {
                    $trace['timed_out'] = true;
                    break;
                }
                if ($text === '') {
                    continue;
                }
                $combined = implode("\n", array_values(array_unique($texts)));
                if (preg_match($strongSignals, $combined) === 1 || mb_strlen($combined) >= 150) {
                    break;
                }
            }

            $trace['text'] = implode("\n", array_values(array_unique($texts)));

            return $trace;
        } finally {
            @unlink($image);
            if ($prepared !== $image) {
                @unlink($prepared);
            }
        }
    }

    /**
     * Test seam: replaces the process runner. The closure gets (list<string> $command, int $timeoutSeconds)
     * and returns array{text:?string,timed_out:bool,ms:int}. Pass null to restore.
     *
     * @param  (\Closure(list<string>,int):array{text:?string,timed_out:bool,ms:int})|null  $runner
     */
    public static function fakeRunner(?\Closure $runner): void
    {
        self::$runner = $runner;
        self::$magickAvailable = null;
        self::$languages = null;
    }

    /** @var (\Closure(list<string>,int):array{text:?string,timed_out:bool,ms:int})|null */
    private static ?\Closure $runner = null;

    private static function extensionFor(string $bytes): string
    {
        $mime = (string) (@finfo_buffer(new \finfo(FILEINFO_MIME_TYPE), $bytes) ?: '');

        return match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'bmp') => 'bmp',
            str_contains($mime, 'tiff') => 'tiff',
            default => 'jpg',
        };
    }

    /**
     * Bounded ImageMagick preprocessing (resize + grayscale + contrast).
     * Returns the prepared file path, or null to fall back to the raw image.
     */
    private static function preprocess(string $image, string $dir, int $timeoutSeconds): ?string
    {
        if (! self::magickAvailable()) {
            return null;
        }

        $out = tempnam($dir, 'ocr_prep_').'.png';
        @unlink(substr($out, 0, -4));

        // Keep preprocessing cheap; it must never dominate the OCR budget.
        $prepTimeout = max(3, min(15, $timeoutSeconds));
        $result = self::run([
            'magick', $image,
            '-resize', '1600x',
            '-colorspace', 'Gray',
            '-contrast-stretch', '1%x1%',
            $out,
        ], $prepTimeout);

        if ($result === null || ! is_file($out) || @filesize($out) === 0) {
            @unlink($out);

            return null;
        }

        return $out;
    }

    /**
     * Downscale a photo out of process (long side 2200, JPEG q85) with memory
     * caps, for photos too big to decode with GD inside PHP's memory_limit.
     * Returns the JPEG bytes, or null when magick is missing, fails or times out.
     */
    public static function downscaleForOcr(string $bytes, int $timeoutSeconds = 20): ?string
    {
        if (! self::magickAvailable()) {
            return null;
        }
        $dir = storage_path('app/temp');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }
        $base = tempnam($dir, 'ocr_big_');
        if ($base === false) {
            return null;
        }
        @unlink($base);
        $in = $base.'.'.self::extensionFor($bytes);
        $out = $base.'_out.jpg';

        try {
            if (@file_put_contents($in, $bytes) === false) {
                return null;
            }
            $run = self::runDetailed([
                'magick',
                '-limit', 'memory', '256MiB',
                '-limit', 'map', '256MiB',
                '-limit', 'disk', '512MiB',
                '-limit', 'thread', '1',
                '-define', 'jpeg:size=2200x2200',
                $in.'[0]',
                '-auto-orient',
                '-resize', '2200x2200>',
                '-strip',
                '-quality', '85',
                $out,
            ], max(3, min(30, $timeoutSeconds)));

            // magick prints nothing on success, so stdout (text) says nothing:
            // judge by timeout, output file and a readable image header.
            if ($run['timed_out'] || ! is_file($out) || (int) @filesize($out) === 0) {
                return null;
            }
            $small = @file_get_contents($out);
            if (! is_string($small) || $small === '' || @getimagesizefromstring($small) === false) {
                return null;
            }

            return $small;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private static function magickAvailable(): bool
    {
        if (self::$magickAvailable !== null) {
            return self::$magickAvailable;
        }

        if (! function_exists('proc_open')) {
            return self::$magickAvailable = false;
        }

        return self::$magickAvailable = self::run(['magick', '-version'], 3) !== null;
    }

    /** @return list<string> */
    public static function languages(int $timeoutSeconds = 2): array
    {
        if (self::$languages !== null) {
            return self::$languages;
        }

        $output = self::run(['tesseract', '--list-langs'], $timeoutSeconds);
        if ($output === null) {
            return self::$languages = [];
        }

        $languages = preg_split('/\R+/', trim($output)) ?: [];
        array_shift($languages);

        return self::$languages = array_values(array_filter(array_map(
            static fn (string $language): string => trim($language),
            $languages,
        )));
    }

    /** @param list<string> $command */
    private static function run(array $command, int $timeoutSeconds): ?string
    {
        return self::runDetailed($command, $timeoutSeconds)['text'];
    }

    /**
     * @param  list<string>  $command
     * @return array{text:?string,timed_out:bool,ms:int}
     */
    private static function runDetailed(array $command, int $timeoutSeconds): array
    {
        $startedAt = microtime(true);
        if (self::$runner !== null) {
            return (self::$runner)($command, $timeoutSeconds);
        }

        $timedOut = false;
        $text = self::runProcess($command, $timeoutSeconds, $timedOut);

        return [
            'text' => $text,
            'timed_out' => $timedOut,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** @param list<string> $command */
    private static function runProcess(array $command, int $timeoutSeconds, bool &$timedOut): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $pipes = [];
        $process = null;
        try {
            $env = getenv();
            if (! is_array($env)) {
                $env = [];
            }
            // Tesseract's bundled OpenMP spins all cores and can stall for 40s+
            // on high-resolution photos (returning nothing). Pinning it to one
            // thread makes OCR fast and deterministic on the shared host.
            $env['OMP_THREAD_LIMIT'] = '1';

            $process = @proc_open($command, [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $env);
            if (! is_resource($process)) {
                return null;
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $deadline = microtime(true) + max(1, $timeoutSeconds);

            while (true) {
                $output .= (string) stream_get_contents($pipes[1]);
                // Drain stderr as well. Tesseract can block on a full stderr
                // pipe even when stdout is being polled.
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (! ($status['running'] ?? false)) {
                    $output .= (string) stream_get_contents($pipes[1]);
                    stream_get_contents($pipes[2]);
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    self::closePipes($pipes);
                    proc_close($process);

                    return null;
                }

                usleep(50_000);
            }

            self::closePipes($pipes);
            proc_close($process);

            return trim($output) !== '' ? trim($output) : null;
        } catch (\Throwable) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            self::closePipes($pipes);

            return null;
        }
    }

    /** @param array<int, resource> $pipes */
    private static function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
