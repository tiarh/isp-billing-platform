<?php

namespace Tests\Unit\Psb;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScansPsbSubclasses;

/**
 * The PSB refactor moves code into App\Services\Psb\* classes. Calls BETWEEN
 * moved methods now stay inside the extracted class, so a subclass of the
 * orchestrator that declares one of those names would be silently bypassed
 * (before the move, the call went through $this and the override won).
 *
 * This guard fails when any subclass (production or test harness) declares such
 * a name. The names below are the ones the facade or an extracted class calls
 * internally without going through a facade method that a subclass could win.
 */
#[Group('blocking-psb')]
class PsbExtractedNamesGuardTest extends TestCase
{
    use ScansPsbSubclasses;

    /** Inner names that live only inside an extracted class (PR 1, PR 2 and PR 3). */
    private const GUARDED = [
        // PR 1
        'normalizeGroupJid', 'allowedGroupJids', 'profileMatchesSpeed', 'packageSpeedFromHint',
        'odpUsernameSuffix', 'normalizeOcrSignalText', 'replyDedupeKey',
        // PR 2: PsbDraftIntake inner calls
        'mergeCandidates', 'isClearlyOtherCustomer', 'normalizePsbPhone', 'applyParsedToDraft',
        // PR 2: PsbLiveCorrection inner calls
        'isLivePsbDraft', 'normalizeCorrectionValue', 'addPhoneDiff', 'psbCorrectionSnapshot',
        'psbCorrectionPreflight', 'applyLocked', 'cancelLocked',
        // PR 3: PsbOcrBatchState inner names (new names only; the moved names stay on the facade as delegators)
        'markTerminalMeta', 'terminalNotifiedForOtherBatch',
        // PR 4: PsbWaCommands. The handle* names stay on the facade and handleInbound still
        // dispatches through them (an override there still wins). These are now called
        // directly inside the extracted classes, so a subclass override would be bypassed.
        'probeLiveForDraft', 'resolveDraftInGroup', 'findDraftForCmd', 'findLanjutDraft',
        // PR 5: names now called directly inside PsbAreaResolver, PsbPppoeIpAllocator,
        // PsbOltBusyMarker and PsbUncfgMatcher. The seams (resolveArea, missingRequired,
        // suggestLiveIp, findUncfgForDraft, clearOltBusyMarker, oltResumeTokenStale,
        // deferOltAfterBusyLock, oltLockWaitSeconds) were NOT listed then; step 3 (below) lists them.
        'psbAreaUnmatchedPolicy', 'isRaOltDraft', 'areaIdFromOlt', 'areaKey',
        'hasStaticAllocatorBlocks', 'normalizeIp', 'ipPrefix24', 'nextFreeRemoteIp',
        'oltBusyMarker', 'oltBusyResumable', 'vlanMapForOlt', 'normalizePonPortKey',
        // PSB feature PR s30
        'isSameNameOtherLiveCustomer', 'openDraftsForCmd',
        // phase 1b step 1: deleted from the facade. A subclass declaring one of these would be silently ignored,
        // because the name no longer exists on the facade or lives inside an extracted class.
        'allowReplyMessage', 'stageMediaForOcr', 'mergeParkedRetryFiles', 'markMediaOcrPending',
        'completedBatchNeedsFreshOcr', 'ocrRetryEnabled', 'markOcrRetryPending', 'albumWaitSeconds',
        'isKtpUnreadable', 'findMergeableDraft', 'findSnReuseConflict', 'markLiveCorrectionPendingIfNeeded',
        'shortError', 'handleCek', 'handleAntri', 'handleResend', 'handleBatal', 'handleSnCmd',
        'handleConfirmSn', 'handleLanjut', 'handleRouterCmd', 'handleVlanCmd', 'handleDone',
        'correctionWritesEnabled', 'collectTodayStats', 'todayStatsFingerprint', 'missingRequired',
        'ocrSnFromBytes',
        // phase 1b step 2: moved into PsbOcrEngine (normalizeOcrSignalText is guarded above)
        'attachPhotosAndOcr', 'classifyBatch', 'applyBatch', 'imageOcrStatus', 'tryKtpOcr',
        'terminalPhotoClassification', 'photoTypeFromScores', 'hasKtpTextEvidence', 'hasSnTextEvidence',
        'scoreOdpPhoto', 'scoreKtpPhoto', 'scoreKtpFromOcr', 'scoreSnPhoto', 'extractSnFromText',
        'hasStrongKtpEvidence', 'tesseractText', 'tesseractRunCap', 'tesseractPsms', 'newOcrBudget',
        // phase 1b step 3: deleted from the facade (oltBusyMarker is guarded above). They now live inside
        // PsbProvisioningConductor, PsbSecretProvisioner or PsbOltProvisioner.
        'processDraftLocked', 'processDraftHtb', 'isIpPoolError', 'inOltResumeRun', 'findUncfgForDraft',
        'provisionOlt', 'oltLockWaitSeconds', 'oltBusyResumeEnabled', 'giveUpLocked', 'suggestLiveIp',
        'releaseAllocatedIpIfAny', 'registryAliasExamples', 'psbBlockUnknownSite', 'oltPaketForDraft',
        'isPppoeStaticAddressValid', 'markRemoteIpUsed', 'mentionPrefixForDraft', 'buildPppoeUsername',
        'draftKtpPathForCustomer', 'draftSnPhotoPathForCustomer',
        // phase 1b step 3: facade members the conductor now bypasses (it calls the collaborator directly, not
        // the facade). HuaweiAware only calls them and never declares them; a subclass declaring one would be ignored.
        'createSecretAndDraftCustomer', 'draftLockBusyOutcome', 'secretQueueBusy', 'deferOltAfterBusyLock',
        'blockIfAreaMissing', 'inPollerRun', 'oltResumeTokenStale', 'clearOltBusyMarker', 'writesEnabled',
        'packageMappingError',
    ];

    public function test_no_subclass_declares_a_name_that_now_lives_inside_an_extracted_class(): void
    {
        $classes = $this->subclassMethods();

        $this->assertNotEmpty($classes, 'the scan must find the known harnesses (guard is not vacuous)');
        $this->assertArrayHasKey('Sprint1PsbHarness', $classes);
        $this->assertArrayHasKey('HuaweiAwarePsbOrchestratorService', $classes);

        $hits = [];
        foreach ($classes as $class => $methods) {
            foreach (array_intersect($methods, self::GUARDED) as $name) {
                $hits[] = $class.'::'.$name;
            }
        }

        $this->assertSame([], $hits, 'A subclass overrides a name that the extracted classes call directly, so the override would be ignored. Route the call through the facade (closure) first.');
    }

    /** @return array<string, list<string>> class short name => declared function names */
    private function subclassMethods(): array
    {
        return array_map(
            fn (array $info): array => $info['methods'],
            $this->scanPsbSubclasses([basename(__FILE__)]),
        );
    }
}
