<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Services\PsbSiteResolver;

/**
 * Late-bound closures that reach protected members of the PSB orchestrator.
 *
 * Extracted classes (PsbWaCommands) must not call a copied version of an
 * overridable method. The orchestrator builds this object with closures such as
 * `fn (PsbDraft $d): bool => $this->hasMinPhoto($d)`, so an override in
 * HuaweiAwarePsbOrchestratorService or in a test harness still wins.
 */
final class PsbSeams
{
    /**
     * @param  \Closure(PsbDraft): bool  $hasMinPhoto  the orchestrator's hasMinPhoto
     * @param  \Closure(PsbDraft): ?string  $readVerifiedPppoePassword  the orchestrator's readVerifiedPppoePassword
     * @param  \Closure(): PsbSiteResolver  $siteResolver  the orchestrator's siteResolver
     */
    public function __construct(
        public readonly \Closure $hasMinPhoto,
        public readonly \Closure $readVerifiedPppoePassword,
        public readonly \Closure $siteResolver,
    ) {}
}
