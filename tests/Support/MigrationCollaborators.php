<?php

namespace Tests\Support;

use App\Models\CustomerMigration;
use App\Services\CustomerMigrationService;
use App\Services\Migration\CustomerMigrationWaMessages;
use App\Services\Migration\MigrationEvidenceReader;
use App\Services\Migration\MigrationPlanBuilder;
use App\Services\Migration\MigrationSeams;
use App\Services\Migration\MigrationTargetResolver;

/**
 * Builders for the extracted migration collaborators, for tests that call a moved
 * text, evidence, routing or plan helper directly.
 *
 * Mirrors CustomerMigrationService::texts()/evidence()/resolver()/plans(); facade wiring is
 * proven end-to-end by the migration flow goldens m01 to m08.
 */
final class MigrationCollaborators
{
    /** Same wiring as the facade: partial state read late from the container instance. */
    public static function texts(): CustomerMigrationWaMessages
    {
        return new CustomerMigrationWaMessages(
            fn (CustomerMigration $migration): ?array => app(CustomerMigrationService::class)->partialExecutionState($migration),
        );
    }

    public static function evidence(): MigrationEvidenceReader
    {
        return new MigrationEvidenceReader;
    }

    public static function plans(): MigrationPlanBuilder
    {
        return new MigrationPlanBuilder;
    }

    /** Same wiring as the facade: it uses the seams of the container instance, which read psb and parser late. */
    public static function resolver(): MigrationTargetResolver
    {
        return new MigrationTargetResolver(self::facadeSeams(), self::evidence(), self::plans());
    }

    private static function facadeSeams(): MigrationSeams
    {
        $service = app(CustomerMigrationService::class);
        $builder = new \ReflectionMethod($service, 'seams');
        $builder->setAccessible(true);

        return $builder->invoke($service);
    }
}
