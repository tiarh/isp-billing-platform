<?php

namespace Tests\Support\PsbEdges;

use App\Models\PsbDraft;
use App\Services\PsbDocumentGateService;

/**
 * Test edge: the document gate always says "documents are fine". Replaces the old
 * `hasMinPhoto() => true` override of test harnesses, so provisioning tests reach
 * the device layer without a real KTP/SN photo.
 */
final class AllowAllDocumentGate extends PsbDocumentGateService
{
    public function isEnforced(PsbDraft $draft): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function evaluate(PsbDraft $draft, bool $persist = true): array
    {
        return [
            'status' => 'verified',
            'allowed' => true,
            'actor' => null,
            'source' => 'psb_wa',
            'message_id' => null,
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }
}
