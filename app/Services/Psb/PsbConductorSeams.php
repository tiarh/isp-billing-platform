<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;

/**
 * Late-bound facade members shared with HuaweiAware; overrides still win.
 *
 * The orchestrator builds this object with closures such as
 * `fn (PsbDraft $d): bool => $this->hasMinPhoto($d)`, so an override in
 * HuaweiAwarePsbOrchestratorService or in a test harness still wins inside
 * the provisioning conductor. PsbSeams (used by PsbWaCommands) is separate.
 */
final readonly class PsbConductorSeams
{
    /**
     * @param  \Closure(PsbDraft): bool  $hasMinPhoto  the orchestrator's hasMinPhoto
     * @param  \Closure(PsbDraft): ?string  $readVerifiedPppoePassword  the orchestrator's readVerifiedPppoePassword
     * @param  \Closure(PsbDraft): array<string, mixed>  $applySiteToDraft  the orchestrator's applySiteToDraft
     * @param  \Closure(bool): void  $setDraftLockBusy  writes the orchestrator's draftLockBusy flag
     */
    public function __construct(
        public \Closure $hasMinPhoto,
        public \Closure $readVerifiedPppoePassword,
        public \Closure $applySiteToDraft,
        public \Closure $setDraftLockBusy,
    ) {}
}
