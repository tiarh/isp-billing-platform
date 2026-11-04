<?php

namespace Tests\Support;

use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\PsbDraft;
use App\Services\PsbMediaOcrOrchestrator;
use App\Support\PsbOcrBatchLedger;

/**
 * Real PSB OCR orchestrator with only the I/O edges replaced: WA replies are
 * recorded and processDraft can be intercepted. The OCR engines are faked at
 * their own edges: the test installs MarkerOcr (Tesseract) and binds
 * MarkerKtpOcr (KTP reader) before making this harness.
 */
class OcrTerminalHarness extends PsbMediaOcrOrchestrator
{
    /** @var list<string> */
    public array $replies = [];

    /** @var list<int> */
    public array $processed = [];

    public bool $interceptProcessDraft = false;

    /** Makes the staged OCR run throw, like a dead OCR worker. */
    public bool $failOcr = false;

    public function processStagedMediaOcr(
        PsbDraft $draft,
        array $stagedPaths,
        array $contexts,
        array $continuation = []
    ): PsbDraft {
        if ($this->failOcr) {
            throw new \RuntimeException('ocr worker down');
        }

        return parent::processStagedMediaOcr($draft, $stagedPaths, $contexts, $continuation);
    }

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }

    /** Stages an inbound batch the way handleInbound does (async path), without the WA parsing. */
    public function stageInbound(PsbDraft $draft, string $bytes): void
    {
        $batch = PsbCollaborators::ocrBatch();
        $staged = $batch->mergeParkedRetryFiles($draft, $batch->stageMediaForOcr([$bytes], [['source' => 'wa_inbound']]));
        $batch->markMediaOcrPending($draft, $staged['batch_key'], $staged['paths'], $staged['contexts']);
        $draft->save();
        PsbOcrBatchLedger::open((int) $draft->id, (string) $staged['batch_key']);
        ProcessPsbMediaOcrJob::dispatch((int) $draft->id, $staged['paths'], $staged['contexts'], [
            'mode' => 'inbound',
            'batch_key' => $staged['batch_key'],
            'group_jid' => 'ops@g.us',
            'is_update' => false,
            'before_update' => [],
            'old_sn' => '',
            'parsed' => [],
            'payload' => ['group_jid' => 'ops@g.us', 'image_paths' => [], 'media_contexts' => []],
        ]);
    }

    public function markPending(PsbDraft $draft, string $batchKey): void
    {
        PsbCollaborators::ocrBatch()->markMediaOcrPending($draft, $batchKey);
        $draft->save();
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        if (! $this->interceptProcessDraft) {
            return parent::processDraft($draft);
        }
        $this->processed[] = (int) $draft->id;

        return $draft;
    }
}
