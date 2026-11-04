<?php

namespace App\Services\Psb;

use App\Services\KtpOcrService;

/**
 * PSB WA: pure photo scoring (KTP / SN sticker / ODP) over already-read OCR text.
 *
 * Moved as-is from PsbOrchestratorService (PR 1 of the PSB refactor). The
 * Tesseract engine stays in the orchestrator; where a scorer used to call
 * `$this->tesseractText($bytes)` it now calls the `$tesseractText` closure that
 * the orchestrator hands in (late-bound, so test overrides still win).
 */
final class PsbPhotoScorer
{
    public function __construct(private readonly KtpOcrService $ktpOcr) {}

    /**
     * Per-image OCR status for logs and the retry decision.
     *
     * @param  array<string,mixed>|null  $trace  TesseractRunner::extractBestTrace result (null = not traced)
     * @param  array<string,mixed>  $ktpOcr
     * @return array{0:string,1:bool} [status, degraded]
     */
    public function imageOcrStatus(string $rawText, ?array $trace, array $ktpOcr): array
    {
        if (trim($rawText) === '') {
            if ($trace !== null && ($trace['timed_out'] ?? false)) {
                return ['timeout', true];
            }
            if ($trace !== null && ($trace['deadline_hit'] ?? false)) {
                return ['deadline', true];
            }
            if (($ktpOcr['ocr_degraded'] ?? false) === true) {
                return ['paddle_busy', true];
            }

            return ['no_text', false];
        }
        if ($trace !== null && ($trace['timed_out'] ?? false)) {
            return ['timeout', true];
        }
        // skipped_short (too little time left for Paddle) is reported as degraded
        // by the KTP parser too, so it lands here as paddle_busy.
        if (($ktpOcr['ocr_degraded'] ?? false) === true) {
            return ['paddle_busy', true];
        }

        return ['ok', false];
    }

    /** @return array{type: string, sn: ?string, ktp_ocr: array<string, mixed>, ktp_score: int, sn_score: int, classification_status: string, classification_error: string} */
    public function terminalPhotoClassification(string $reason): array
    {
        return [
            'type' => 'unknown',
            'sn' => null,
            'ktp_ocr' => [],
            'ktp_score' => 0,
            'sn_score' => 0,
            'classification_status' => 'terminal',
            'classification_error' => $reason,
        ];
    }

    public function photoTypeFromScores(
        ?string $sn,
        int $snScore,
        int $ktpScore,
        bool $hardGuard = false,
        int $odpScore = 0,
        bool $ktpText = false,
        bool $snText = false
    ): string {
        if ($hardGuard) {
            if ($sn !== null && $snScore >= $ktpScore) {
                return 'sn';
            }

            if ($odpScore >= 50) {
                return 'odp';
            }

            return ($ktpText && $ktpScore >= 50) ? 'ktp' : 'unknown';
        }

        // A sticker (SN/router label) wins when there is a serial hit or clear
        // sticker keywords; a bare aspect-ratio score must never outrank it.
        if (($sn !== null || $snText || $snScore >= 60) && $snScore >= $ktpScore) {
            return 'sn';
        }
        // KTP requires text evidence (NIK/name/ID keywords), not just the card
        // aspect ratio (score 25) — otherwise a rectangular SN sticker that OCR
        // failed to read lands in the customer's KTP field.
        if ($ktpText && $ktpScore >= 25) {
            return 'ktp';
        }
        if ($odpScore >= 50) {
            return 'odp';
        }

        return 'unknown';
    }

    /** Text/OCR evidence that a photo is a KTP (not just its aspect ratio). */
    public function hasKtpTextEvidence(?string $rawText, array $ocr = []): bool
    {
        $nik = preg_replace('/\D/', '', (string) ($ocr['nik'] ?? '')) ?? '';
        if (strlen($nik) === 16) {
            return true;
        }

        $text = $this->normalizeOcrSignalText((string) ($rawText ?? ($ocr['raw_text'] ?? '')));

        return preg_match(
            '/\bNIK\b|REPUBLIK|PROVINSI|KABUPATEN|KECAMATAN|KELURAHAN|JENIS KELAMIN|STATUS PERKAWINAN|KEWARGANEGARAAN|PEKERJAAN|\bAGAMA\b/',
            $text,
        ) === 1;
    }

    /** Text evidence that a photo is an ONT/router sticker (serial or label). */
    public function hasSnTextEvidence(?string $rawText, ?string $sn = null): bool
    {
        if ($sn !== null && $sn !== '') {
            return true;
        }

        $text = $this->normalizeOcrSignalText((string) $rawText);

        return preg_match(
            '/\b(?:FHTT|HWTC|ZTEG|CMDC|ALCL|CIOT)[A-Z0-9]{6,}\b|FIBERHOME|GPON ONU|WLAN SSID|MODEL\s*:|\bS\/N\b|SERIAL\s*(?:NO|NUMBER)?|PON\s*SN|MADE IN CHINA/',
            $text,
        ) === 1;
    }

    public function scoreOdpPhoto(string $rawText): int
    {
        $text = $this->normalizeOcrSignalText($rawText);
        if ($text === '') {
            return 0;
        }
        $score = preg_match('/\bODP\b|\bONU\b|PON\s*(PORT|NO|NUMBER)/', $text) ? 60 : 0;
        if (preg_match('/\b(?:L\d+(?:\.\d+)?|\d+\.\d+|\d+\/\d+\/\d+)\b/', $text)) {
            $score += 25;
        }

        return min(100, $score);
    }

    /**
     * @param  \Closure(string): ?string  $tesseractText  the orchestrator's tesseractText seam (only called when no text was handed in)
     */
    public function scoreKtpPhoto(string $bytes, array $ocr, ?string $rawText, \Closure $tesseractText): int
    {
        $score = 0;
        // Image aspect ratio helper (KTP card aspect ratio ~1.58)
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && ! empty($info[0]) && ! empty($info[1])) {
            $w = (float) $info[0];
            $h = (float) $info[1];
            $ratio = $w > $h ? ($w / $h) : ($h / $w);
            if ($ratio >= 1.38 && $ratio <= 1.82) {
                $score += 25; // Standard ID card / KTP aspect ratio
            }
        }

        $nik = preg_replace('/\D/', '', (string) ($ocr['nik'] ?? '')) ?? '';
        if (strlen($nik) === 16) {
            $score += 60;
        } elseif (strlen($nik) >= 14) {
            $score += 30;
        }
        if (! empty($ocr['name'])) {
            $score += 20;
        }
        if (! empty($ocr['rt']) || ! empty($ocr['rw'])) {
            $score += 10;
        }
        // Use the text already read (parser text first, else the caller's Tesseract
        // text). Only when NO text was handed in at all is the image read again:
        // an empty string means "read, found nothing", and re-reading it burned up
        // to 90 s on a slow host for the same empty result.
        $ocrText = (string) ($ocr['raw_text'] ?? '');
        $raw = $this->normalizeOcrSignalText(trim($ocrText) !== '' ? $ocrText : (string) ($rawText ?? ''));
        if ($raw === '' && $rawText === null && ! isset($ocr['raw_text'])) {
            $raw = $this->normalizeOcrSignalText($tesseractText($bytes) ?? '');
        }
        $ktpSignals = preg_match_all('/\bNIK\b|\bNAMA\b|REPUBLIK|IDENTITAS|PROVINSI|KABUPATEN|KECAMATAN|\bAGAMA\b|STATUS PERKAWINAN|JENIS KELAMIN|KEWARGANEGARAAN|PEKERJAAN|ALAMAT/', $raw);
        if ($ktpSignals >= 4) {
            $score += 45;
        } elseif ($ktpSignals >= 2) {
            $score += 35;
        } elseif ($ktpSignals === 1) {
            $score += 25;
        }
        // Penalize heavily if looks like device sticker / barcode / serial
        if (preg_match('/FHTT|GPON|SERIAL|S\/N|MAC\s*ADDR|PON\s*SN|SSID|MODEL\s*:/', $raw)) {
            $score = max(0, $score - 50);
        }

        return min(100, $score);
    }

    /**
     * @param  \Closure(string): ?string  $tesseractText  the orchestrator's tesseractText seam (only called when no text was handed in)
     */
    public function scoreSnPhoto(string $bytes, ?string $rawText, \Closure $tesseractText): int
    {
        $score = 0;
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && ! empty($info[0]) && ! empty($info[1])) {
            $w = (float) $info[0];
            $h = (float) $info[1];
            $ratio = $w > $h ? ($w / $h) : ($h / $w);
            if ($ratio >= 0.90 && $ratio <= 1.35) {
                $score += 20; // Square router sticker aspect ratio
            }
        }

        $text = $this->normalizeOcrSignalText($rawText ?? $tesseractText($bytes) ?? '');
        if (preg_match('/\b(?:FHTT|HWTC|ZTEG|CMDC|ALCL|CIOT)[A-Z0-9]{6,}\b/', $text)) {
            $score += 85;
        }
        if (preg_match('/\b(?:SERIAL|S\/N|SN|PON\s*SN|GPON|MAC|SSID|MODEL|ONT|ONU)\b/', $text)) {
            $score += 40;
        }
        if (preg_match('/\bNIK\b|REPUBLIK INDONESIA/', $text)) {
            $score = max(0, $score - 60);
        }

        return min(100, $score);
    }

    public function normalizeOcrSignalText(string $text): string
    {
        $text = strtoupper(trim($text));
        if ($text === '') {
            return '';
        }

        $text = str_replace(["\xC2\xA0", "\r"], ' ', $text);
        $text = preg_replace('/\bN1K\b/', 'NIK', $text) ?: $text;
        $text = preg_replace('/\b(FHTT|HWTC|ZTEG|CMDC|ALCL|CIOT)\s*[-: ]*\s*([A-Z0-9]{6,})\b/', '$1$2', $text) ?: $text;

        return preg_replace('/\s+/', ' ', $text) ?: $text;
    }

    public function extractSnFromText(string $text): ?string
    {
        if (preg_match('/\b(FHTT[A-Z0-9]{8,})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b([A-Z]{4}[A-Z0-9]{8,})\b/i', $text, $m)) {
            $candidate = strtoupper($m[1]);
            $blockedWords = [
                'REPUBLIK',
                'INDONESIA',
                'KABUPATEN',
                'KECAMATAN',
                'KEWARGANEGARAAN',
                'PERKAWINAN',
                'PEKERJAAN',
                'PROVINSI',
                'KELURAHAN',
            ];

            if (preg_match('/\d/', $candidate) && ! in_array($candidate, $blockedWords, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $ocr */
    public function hasStrongKtpEvidence(array $ocr): bool
    {
        $nik = preg_replace('/\D/', '', (string) ($ocr['nik'] ?? '')) ?? '';
        if (strlen($nik) !== 16 || ! $this->ktpOcr->isPlausibleNik($nik)) {
            return false;
        }

        return (bool) preg_match(
            '/\bNIK\b|REPUBLIK|PROVINSI|KABUPATEN|KECAMATAN|JENIS KELAMIN|STATUS PERKAWINAN/i',
            (string) ($ocr['raw_text'] ?? '')
        );
    }
}
