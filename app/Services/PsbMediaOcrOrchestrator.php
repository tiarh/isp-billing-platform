<?php

namespace App\Services;

use App\Models\PsbDraft;
use App\Services\Psb\PsbOcrReaders;
use App\Support\TesseractRunner;

/**
 * OCR worker adapter. It reuses the PSB orchestrator's media and continuation
 * logic while making the OCR job's first phase a classification-only step.
 */
class PsbMediaOcrOrchestrator extends PsbOrchestratorService
{
    /**
     * The OCR worker may run in the lean production image where only the
     * English Tesseract data file is installed. Keep this fallback scoped to
     * the asynchronous media worker; the protected parent service stays
     * behavior-compatible for existing synchronous flows.
     */
    protected function ocrReaders(): PsbOcrReaders
    {
        return new PsbOcrReaders(tesseractLanguage: fn (): ?string => $this->tesseractLanguageOption());
    }

    /** @param list<string>|null $available */
    protected function tesseractLanguageOption(?array $available = null): ?string
    {
        $languages = $available ?? TesseractRunner::languages();
        $languages = array_values(array_filter(array_map('trim', $languages)));

        // Configurable pack order (PSB_OCR_TESS_LANG, default ind+eng); used only
        // when every named pack is installed.
        $preferred = array_values(array_filter(array_map('trim', explode('+', (string) config('services.ocrspace.tesseract_lang', 'ind+eng')))));
        if ($preferred !== [] && array_diff($preferred, $languages) === []) {
            return implode('+', $preferred);
        }

        if (in_array('ind', $languages, true) && in_array('eng', $languages, true)) {
            return 'ind+eng';
        }

        return in_array('eng', $languages, true) ? 'eng' : (in_array('ind', $languages, true) ? 'ind' : null);
    }

    /** Resume the existing PSB state machine on the normal PSB queue. */
    public function resumeAfterMediaOcr(PsbDraft $draft, array $continuation): PsbDraft
    {
        // A restaged batch that was already OCR'd (its job dispatch was dropped)
        // can be left "pending". Repair it here instead of re-running OCR.
        $key = (string) ($continuation['batch_key'] ?? '');
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $liveKey = (string) ($meta['ocr_batch_key'] ?? '');
        if ($key !== ''
            && $key === (string) data_get($meta, 'photo_class.ocr_batch_completed', '')
            && ($liveKey === '' || $liveKey === $key)
            && (data_get($meta, 'photo_class.status') !== 'completed'
                || data_get($meta, 'document_gate.status') === 'ocr_pending')) {
            $this->markMediaOcrCompleted($draft, $key);
        }

        return parent::continueAfterMediaOcr($draft, $continuation);
    }

    /**
     * This adapter extends the base orchestrator, so $this->processDraft()
     * would skip the container-bound HuaweiAware seam (Gadang). Run the
     * post-OCR processDraft on the bound instance instead. The bound
     * instance is never a PsbMediaOcrOrchestrator, so there is no recursion;
     * if a test/container binds this adapter itself, fall back to $this.
     */
    protected function draftProcessor(): PsbOrchestratorService
    {
        $bound = app(PsbOrchestratorService::class);

        return $bound instanceof self ? $this : $bound;
    }

    /** Keep device/PPPoE continuation out of the OCR worker. */
    protected function continueAfterMediaOcr(PsbDraft $draft, array $continuation): PsbDraft
    {
        return $draft->fresh() ?? $draft;
    }
}
