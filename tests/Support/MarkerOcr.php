<?php

namespace Tests\Support;

use App\Support\TesseractRunner;

/**
 * Fake Tesseract for PSB photo tests. Synthetic images carry a marker in their
 * bytes; the fake process runner "reads" the marker as OCR text, so the real
 * orchestrator and the real TesseractRunner run end to end.
 *
 * Pair with MarkerKtpOcr (the KTP side). Always call reset() in tearDown.
 */
final class MarkerOcr
{
    /** Fake OCR text for an image, read from its marker bytes. */
    public static function text(string $bytes): ?string
    {
        if (str_contains($bytes, 'ODP_MARKER')) {
            return 'ODP GONDANG L3.1';
        }

        return str_contains($bytes, 'SN_MARKER') ? 'SN: FHTTCANARY123' : null;
    }

    public static function install(): void
    {
        TesseractRunner::fakeRunner(function (array $cmd, int $timeout): array {
            if ($cmd[0] === 'tesseract' && in_array('--list-langs', $cmd, true)) {
                return ['text' => "List of available languages (2):\nind\neng", 'timed_out' => false, 'ms' => 1];
            }
            if ($cmd[0] === 'tesseract') {
                // $cmd[1] is the image file handed to Tesseract.
                return ['text' => self::text((string) file_get_contents($cmd[1])), 'timed_out' => false, 'ms' => 1];
            }

            // magick (preprocess) is unavailable: the raw image is used.
            return ['text' => null, 'timed_out' => false, 'ms' => 1];
        });
    }

    public static function reset(): void
    {
        TesseractRunner::fakeRunner(null);
    }
}
