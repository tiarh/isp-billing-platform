<?php

namespace App\Services\Psb;

use Closure;

/**
 * Late-bound edges of the OCR engine; built by the orchestrator's ocrReaders().
 *
 * - tesseractLanguage: `(): ?string`. Null = no usable language pack, so no
 *   Tesseract run, no text and no trace.
 * - tesseract: `(string $bytes, string $language, int $runCap, list<int> $psms, ?float $deadline): array`
 *   returning the trace. Null = TesseractRunner::extractBestTrace().
 * - clock: `(): float`. Null = the default microtime clock of OcrBudget.
 */
final readonly class PsbOcrReaders
{
    /**
     * @param  Closure(): ?string  $tesseractLanguage
     * @param  (Closure(string, string, int, list<int>, ?float): array<string, mixed>)|null  $tesseract
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        public Closure $tesseractLanguage,
        public ?Closure $tesseract = null,
        public ?Closure $clock = null,
    ) {}
}
