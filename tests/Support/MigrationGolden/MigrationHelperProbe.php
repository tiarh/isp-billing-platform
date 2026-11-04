<?php

namespace Tests\Support\MigrationGolden;

use App\Services\CustomerMigrationService;
use App\Services\Migration\MigrationNormalizer;
use Tests\Support\MigrationCollaborators;

/**
 * Calls one text or pure helper of the migration service by name.
 *
 * The receiver table sends a moved helper to its new class (texts, evidence, normalizer,
 * routing, plans) and everything else to the
 * container instance of the facade. The matrix test and its golden never change when a
 * helper moves, so the same golden proves the move changed no output.
 */
final class MigrationHelperProbe
{
    /** Moved to CustomerMigrationWaMessages (M1). */
    private const TEXTS = [
        'guidedConflictText', 'guidedConflictsText', 'guidedCandidateLines', 'guidedListMessage', 'guidedPickFirstMessage',
        'guidedSelectionDroppedMessage', 'guidedPreviewMessage', 'guidedPreviewBlockedMessage', 'guidedSelectionConfirmationMessage',
        'helpMessage', 'candidateSelectionPreviewMessage', 'todaySummary', 'statusMessage', 'waitingNewSnMessage', 'reviewMessage',
        'ktpReviewLine', 'huaweiOltOnlySuccessMessage', 'shortWaError', 'mentionPrefixForMigration', 'partialExecutionShortMessage',
        'partialExecutionLines', 'partialRollbackHint', 'partialExecutionWaMessage', 'migrationRollbackHint',
    ];

    /** Moved to MigrationEvidenceReader (M1). */
    private const EVIDENCE = [
        'oltSnapshotForMigration', 'existingOnuBelongsToMigration', 'genieAcsSnapshotForMigration', 'reviewMigrationDraft',
        'confirmedMigrationEvidence', 'psbOverlapEvidence', 'applyConfirmedEvidence', 'migrationConflicts', 'customerSnapshot',
        'proposedCustomerSnapshot', 'oldOnuSnapshot', 'snOwnershipConflicts', 'onuNameDriftBelongsToMigration',
    ];

    /** Moved to MigrationNormalizer (M1), static pure helpers. */
    private const NORMALIZER = [
        'meta', 'appendTimeline', 'boundedError', 'boolSetting', 'migrationAlreadySynced', 'isHuaweiOltOnlyMigration',
        'normalizeUsername', 'normalizeSerial', 'normalizeTextForCompare', 'normalizeOnuRef', 'normalizePonPort', 'rwNumber',
        'onuNameToken', 'normalizeOltToken', 'normalizeSiteToken', 'odpSuffix',
    ];

    /** Moved to MigrationTargetResolver (M2). */
    private const ROUTING = [
        'findCustomerByPppoe', 'resolvePppoeForHuaweiDraft', 'primeMigrationFromCustomer', 'resolveRouterForMigration',
        'resolveSourceRouterForMigration', 'resolveMigrationRouterAlias', 'migrationHasExplicitSourceRouter', 'resolveOltForMigration',
        'resolveOltFromMigrationField', 'resolvePackageForMigration', 'targetPackageForMigration', 'resolvePackageFromHint',
        'vlanForOltPort', 'packageSpeed', 'resolveArea', 'isHuaweiOltOnlyParsed', 'isOltShortForm', 'shouldUseC300UncfgPath',
        'resolveMigrationTargetSite',
    ];

    /** Moved to MigrationPlanBuilder (M2). */
    private const PLANS = [
        'migrationTargetContract', 'keptPackageFallbackProfile', 'migrationReasonHint', 'migrationPreservesCustomerPackage',
        'findTargetPackage', 'krianDryRunContract', 'mikrotikDryRunDiff', 'pppoeMigrationPlan', 'ktpMigrationPlan', 'oltDryRunDiff',
        'genieAcsDryRunDiff', 'unknownMigrationInputs', 'migrationSecretComment', 'targetProfileForMigration', 'isSchoolOrInstitutionName',
    ];

    /** @param list<mixed> $args */
    public function call(string $method, array $args = []): mixed
    {
        if (in_array($method, self::ROUTING, true)) {
            return MigrationCollaborators::resolver()->$method(...$args);
        }
        if (in_array($method, self::PLANS, true)) {
            return MigrationCollaborators::plans()->$method(...$args);
        }
        if (in_array($method, self::NORMALIZER, true)) {
            return MigrationNormalizer::$method(...$args);
        }
        if (in_array($method, self::TEXTS, true)) {
            return MigrationCollaborators::texts()->$method(...$args);
        }
        if (in_array($method, self::EVIDENCE, true)) {
            return MigrationCollaborators::evidence()->$method(...$args);
        }

        $service = app(CustomerMigrationService::class);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }
}
