<?php

namespace Tests\Support;

use App\Models\PsbDraft;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\Psb\PsbDraftIntake;
use App\Services\Psb\PsbLiveCorrection;
use App\Services\Psb\PsbOcrBatchState;
use App\Services\Psb\PsbOcrEngine;
use App\Services\Psb\PsbOcrReaders;
use App\Services\Psb\PsbOltProvisioner;
use App\Services\Psb\PsbPhotoScorer;
use App\Services\Psb\PsbPppoeNaming;
use App\Services\Psb\PsbUncfgMatcher;

/**
 * Builders for the extracted PSB collaborators, for tests that used to reach
 * them through protected delegators on the orchestrator facade.
 *
 * Mirrors PsbOrchestratorService::intake()/correction(); facade wiring is
 * proven end-to-end by goldens S6, S11, S12.
 */
final class PsbCollaborators
{
    /**
     * @return array{0: PsbLiveCorrection, 1: PsbDraftIntake}
     */
    public static function correctionAndIntake(MikrotikService $mikrotik): array
    {
        $naming = new PsbPppoeNaming;
        $intake = null;

        // Late-bound (by reference): intake does not exist yet when correction is built.
        $correction = new PsbLiveCorrection(
            $mikrotik,
            fn (PsbDraft $draft): string => $naming->buildPppoeUsername($draft),
            fn (string $error): string => $naming->shortError($error),
            function (mixed $phone) use (&$intake): string {
                return $intake->normalizePsbPhone($phone);
            },
        );
        $intake = new PsbDraftIntake(
            fn (array $before): bool => $correction->isLivePsbDraft($before),
            fn (PsbDraft $draft): array => $correction->psbCorrectionSnapshot($draft),
        );

        return [$correction, $intake];
    }

    public static function scorer(?KtpOcrService $ktpOcr = null): PsbPhotoScorer
    {
        return new PsbPhotoScorer($ktpOcr ?? new KtpOcrService);
    }

    /** Same wiring as PsbOrchestratorService::ocrEngine(): the facade's KtpOcrService, its own scorer, its readers. */
    public static function ocrEngine(KtpOcrService $ktpOcr, PsbOcrReaders $readers): PsbOcrEngine
    {
        return new PsbOcrEngine($ktpOcr, new PsbPhotoScorer($ktpOcr), $readers);
    }

    public static function ocrBatch(): PsbOcrBatchState
    {
        return new PsbOcrBatchState;
    }

    /** Same wiring as the facade's conductor(): the OLT edge is built from a fresh uncfg matcher. */
    public static function oltProvisioner(): PsbOltProvisioner
    {
        return new PsbOltProvisioner(new PsbUncfgMatcher);
    }
}
