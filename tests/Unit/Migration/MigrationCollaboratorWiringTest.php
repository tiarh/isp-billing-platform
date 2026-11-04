<?php

namespace Tests\Unit\Migration;

use App\Services\CustomerMigrationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards for the collaborators extracted from CustomerMigrationService (migration refactor series).
 *
 * 1. Every class in app/Services/Migration added by the series is final and has no static property.
 * 2. The facade can be built without its constructor (partial mocks do that) and every private
 *    builder still works then.
 * 3. No new class hard-codes a site value (same pattern as SiteConfigContractTest).
 * 4. No new class takes a lock with ->block(.
 * 5. The resolver and the plan builder read psb/parser only through the seams, at call time.
 *
 * The device ledger, partial execution and planner classes (M3) have their own source guards in
 * MigrationDeviceCollaboratorsGuardTest; the five M4 classes (senders, preview refresh, SN
 * correction, Huawei intake, guided flow) in MigrationGuidedCollaboratorsGuardTest.
 */
#[Group('blocking-psb')]
class MigrationCollaboratorWiringTest extends TestCase
{
    /** Classes added by the series. Older classes in the folder are not covered. */
    private const NEW_CLASSES = [
        'CustomerMigrationWaMessages',
        'MigrationDeviceActionPlanner',
        'MigrationDeviceLedger',
        'MigrationEvidenceReader',
        'MigrationGuidedPreviewRefresh',
        'MigrationGuidedWaFlow',
        'MigrationHuaweiOltOnlyIntake',
        'MigrationNormalizer',
        'MigrationPartialExecution',
        'MigrationPlanBuilder',
        'MigrationSeams',
        'MigrationSnCorrection',
        'MigrationTargetResolver',
        'MigrationWaSenders',
    ];

    public function test_new_classes_are_final_and_have_no_static_property(): void
    {
        foreach (self::NEW_CLASSES as $name) {
            $class = new ReflectionClass('App\\Services\\Migration\\'.$name);
            $this->assertTrue($class->isFinal(), $name.' must be final');
            $this->assertSame([], $class->getProperties(\ReflectionProperty::IS_STATIC), $name.' must have no static property');
        }
    }

    public function test_facade_builders_work_without_the_constructor(): void
    {
        $class = new ReflectionClass(CustomerMigrationService::class);
        $facade = $class->newInstanceWithoutConstructor();

        $built = 0;
        foreach ($class->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
            if ($method->getNumberOfRequiredParameters() !== 0 || $method->isStatic()) {
                continue;
            }
            $returnType = $method->getReturnType();
            if (! $returnType instanceof \ReflectionNamedType || ! str_starts_with($returnType->getName(), 'App\\Services\\Migration\\')) {
                continue;
            }
            $method->setAccessible(true);
            $first = $method->invoke($facade);
            $this->assertIsObject($first, $method->getName().' must return its collaborator');
            $this->assertSame($first, $method->invoke($facade), $method->getName().' must build once per instance');
            $built++;
        }

        $this->assertGreaterThanOrEqual(13, $built, 'the texts, evidence, seams, resolver, plans, ledgers, partials, planner, senders, refresh, SN correction, Huawei intake and guided flow builders were not found');
    }

    public function test_resolver_and_plan_builder_reach_facade_dependencies_only_through_seams(): void
    {
        foreach (['MigrationTargetResolver', 'MigrationPlanBuilder'] as $name) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/'.$name.'.php');
            $this->assertNotSame('', $source, $name.' unreadable');
            $this->assertDoesNotMatchRegularExpression('/\$this->(psb|parser|mikrotik)\b/', $source, $name.' reads a facade dependency directly');
            // A seam is called inside a method each time, never kept in a property.
            $this->assertDoesNotMatchRegularExpression('/\$this->\w+\s*=\s*\(\$this->seams/', $source, $name.' stores a seam result');
        }

        $resolver = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/MigrationTargetResolver.php');
        $this->assertMatchesRegularExpression('/\(\$this->seams->psb\)\(\)/', $resolver);
        $this->assertMatchesRegularExpression('/\(\$this->seams->parser\)\(\)/', $resolver);
        $this->assertMatchesRegularExpression('/function __construct\([^)]*\)\s*\{\s*\}/', $resolver, 'the resolver constructor must only store its collaborators');
    }

    public function test_resolver_keeps_resolve_customer_on_the_facade(): void
    {
        $resolver = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/MigrationTargetResolver.php');
        $this->assertDoesNotMatchRegularExpression('/function\s+resolveCustomer\s*\(/', $resolver, 'resolveCustomer is mocked in tests and must stay on the facade');
    }

    public function test_new_classes_hard_code_no_site_value(): void
    {
        $pattern = '/103\.156\.|172\.22\.254|172\.18\.0\.1|10\.181\.33\.|10\.99\.0\.|10\.77\.0\.|172\.95\.95\.|172\.20\.10\.18|sky\.net\.id|ebilling\.id|sslip\.io|host\.docker\.internal/';

        foreach (self::NEW_CLASSES as $name) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/'.$name.'.php');
            $this->assertNotSame('', $source, $name.' unreadable');
            $this->assertDoesNotMatchRegularExpression($pattern, $source, $name.' hard-codes a site value');
        }
    }

    public function test_new_classes_take_no_lock_with_block(): void
    {
        foreach (self::NEW_CLASSES as $name) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/'.$name.'.php');
            $this->assertStringNotContainsString('->block(', $source, $name.' must not call ->block(');
        }
    }
}
