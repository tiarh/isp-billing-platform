<?php

namespace Tests\Unit\Migration;

use App\Models\CustomerMigration;
use App\Services\CustomerMigrationService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\Migration\MigrationDeviceActionPlanner;
use App\Services\Migration\MigrationSeams;
use App\Services\Migration\MigrationSnCorrection;
use App\Services\Migration\MigrationWaSenders;
use App\Services\MigrationOperationClassifier;
use App\Services\MikrotikService;
use App\Services\ProductionControlPlaneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Guards for the device ledger, partial execution and device-action planner classes
 * (migration refactor Phase 2, M3). They move bodies out of CustomerMigrationService without a
 * change of behaviour; these tests keep the moved code from reaching a facade dependency by a
 * shortcut, from taking a lock, and from reading a seam too early.
 *
 * 1. Source scan of the three classes.
 * 2. Every seam closure has the same parameter and return types as the facade member it wraps.
 * 3. A seam is read when it is used: a Huawei dependency injected AFTER the planner was built is honoured.
 * 4. One instance of each helper per facade, and the planner shares the facade's partial-execution helper.
 * 5. One uncfg read per request, even when the hints are built by the partial-execution helper.
 */
#[Group('blocking-psb')]
class MigrationDeviceCollaboratorsGuardTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const CLASSES = ['MigrationDeviceLedger', 'MigrationPartialExecution', 'MigrationDeviceActionPlanner'];

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_three_classes_use_no_facade_dependency_lock_or_strict_types(): void
    {
        foreach (self::CLASSES as $name) {
            $source = $this->source($name);
            $this->assertNotSame('', $source, $name.' unreadable');
            $this->assertDoesNotMatchRegularExpression('/\$this->(psb|parser|mikrotik|zte|genieAcs|controlPlane|huaweiOltOnly|operationClassifier|guidedLookup|uncfgCache)\b/', $source, $name.' reads a facade dependency directly');
            $this->assertStringNotContainsString('Cache::lock', $source, $name.' takes a lock');
            $this->assertStringNotContainsString('CacheLockWait', $source, $name.' waits for a lock');
            $this->assertStringNotContainsString('->block(', $source, $name.' blocks on a lock');
            $this->assertStringNotContainsString('strict_types', $source, $name.' must not declare strict_types (the facade coerces)');
            $this->assertDoesNotMatchRegularExpression('/\$this->\w+\s*=\s*\(\$this->seams/', $source, $name.' stores a seam result');

            preg_match_all('/\$this->host->(\w+)/', $source, $calls);
            foreach ($calls[1] as $called) {
                $this->assertSame('reply', $called, $name.' calls the facade through $this->host->'.$called);
            }

            if (str_contains($source, 'Carbon::')) {
                $this->assertStringContainsString('use Illuminate\Support\Carbon;', $source, $name.' must use Illuminate\Support\Carbon like the facade');
            }
            $this->assertStringNotContainsString('Carbon\Carbon', $source, $name.' must not use Carbon\Carbon');
        }
    }

    public function test_seam_closures_match_the_signature_of_what_they_wrap(): void
    {
        $facade = (new ReflectionClass(CustomerMigrationService::class))->newInstanceWithoutConstructor();
        $builder = new ReflectionMethod(CustomerMigrationService::class, 'seams');
        $builder->setAccessible(true);
        /** @var MigrationSeams $seams */
        $seams = $builder->invoke($facade);

        $methodFields = [
            'resolveCustomer' => [CustomerMigrationService::class, 'resolveCustomer'],
            'verifyExistingPppoeSecret' => [CustomerMigrationService::class, 'verifyExistingPppoeSecret'],
            'verifyTargetMigrationProfile' => [CustomerMigrationService::class, 'verifyTargetMigrationProfile'],
            'huaweiOltOnlyWriteFlags' => [CustomerMigrationService::class, 'huaweiOltOnlyWriteFlags'],
            'snTypoHints' => [MigrationSnCorrection::class, 'snTypoHints'],
            'canModifyMigrationFromWa' => [MigrationWaSenders::class, 'canModifyMigrationFromWa'],
            'waSenderKeys' => [MigrationWaSenders::class, 'waSenderKeys'],
            'syncCustomerAfterNewOnu' => [CustomerMigrationService::class, 'syncCustomerAfterNewOnu'],
            'attachStoredInboundImages' => [CustomerMigrationService::class, 'attachStoredInboundImages'],
            'readUncfgOnus' => [CustomerMigrationService::class, 'readUncfgOnus'],
        ];
        $propertyFields = ['psb', 'parser', 'controlPlane', 'huaweiOltOnly', 'operationClassifier', 'guidedLookup'];
        $checked = 0;

        foreach ($methodFields as $field => [$class, $method]) {
            $this->assertSameSignature(new ReflectionFunction($seams->{$field}), new ReflectionMethod($class, $method), $field);
            $checked++;
        }
        $this->assertSameSignature(
            new ReflectionFunction($seams->prepareDeviceActionApprovalLocked),
            new ReflectionMethod(MigrationDeviceActionPlanner::class, 'prepareDeviceActionApprovalLocked'),
            'prepareDeviceActionApprovalLocked'
        );
        $checked++;

        foreach ($propertyFields as $field) {
            $closure = new ReflectionFunction($seams->{$field});
            $property = new ReflectionProperty(CustomerMigrationService::class, $field);
            $this->assertSame(0, $closure->getNumberOfParameters(), $field.' takes no argument');
            $this->assertSame((string) $property->getType(), (string) $closure->getReturnType(), $field.' return type differs from the facade property');
            $checked++;
        }

        $uncfg = new ReflectionFunction($seams->uncfgRows);
        $this->assertSame(['int'], array_map(fn ($p): string => (string) $p->getType(), $uncfg->getParameters()));
        $this->assertSame('array', (string) $uncfg->getReturnType());
        $checked++;

        $this->assertSame(count((new ReflectionClass(MigrationSeams::class))->getProperties()), $checked, 'a seam field is not covered by this guard');
    }

    public function test_a_huawei_dependency_injected_after_the_planner_is_built_is_honoured(): void
    {
        $service = Mockery::mock(CustomerMigrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $sentinel = ['migration_actions' => true, 'global_read_only' => false, 'olt' => true, 'genieacs' => true, 'mikrotik' => false, 'sentinel' => 'seam'];
        $service->shouldReceive('huaweiOltOnlyWriteFlags')->andReturn($sentinel);

        // Build the planner FIRST, then inject the dependencies the constructor would have set.
        $planner = new ReflectionMethod(CustomerMigrationService::class, 'planner');
        $planner->setAccessible(true);
        $this->assertInstanceOf(MigrationDeviceActionPlanner::class, $planner->invoke($service));

        $this->inject($service, 'controlPlane', app(ProductionControlPlaneService::class));
        $this->inject($service, 'operationClassifier', app(MigrationOperationClassifier::class));
        $this->inject($service, 'huaweiOltOnly', $this->fakeHuawei('hash-a'));

        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'guard-'.uniqid(),
            'raw_text' => '#migrasi',
            'pppoe_user' => 'GUARD01',
            'new_sn' => 'FHTTC0FFEE01',
            'meta' => ['migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE],
        ]);

        $service->prepareDeviceActionApproval($migration->fresh(), null, 'Uji seam terlambat A');
        $meta = (array) $migration->fresh()->meta;
        $this->assertSame('hash-a', data_get($meta, 'device_action_preflight.plan.command_hash'), 'the first fake must be used');
        $this->assertEquals($sentinel, data_get($meta, 'device_action_approval.write_flags'), 'the mocked write flags must reach the ledger meta');

        $this->inject($service, 'huaweiOltOnly', $this->fakeHuawei('hash-b'));
        $service->prepareDeviceActionApproval($migration->fresh(), null, 'Uji seam terlambat B');
        $this->assertSame('hash-b', data_get((array) $migration->fresh()->meta, 'device_action_preflight.plan.command_hash'), 'the swapped fake must be used');
    }

    public function test_one_instance_of_each_helper_per_facade(): void
    {
        $first = $this->app->build(CustomerMigrationService::class);
        $second = $this->app->build(CustomerMigrationService::class);
        $this->assertNotSame($first, $second);

        foreach (['ledgers', 'partials', 'planner'] as $builder) {
            $this->assertSame($this->callPrivate($first, $builder), $this->callPrivate($first, $builder), $builder.' must build once per facade');
            $this->assertNotSame($this->callPrivate($first, $builder), $this->callPrivate($second, $builder), $builder.' must not be shared between facades');
        }

        $planner = $this->callPrivate($first, 'planner');
        $property = new ReflectionProperty($planner, 'partials');
        $property->setAccessible(true);
        $this->assertSame($this->callPrivate($first, 'partials'), $property->getValue($planner), 'the planner must use the facade partial-execution helper');
        $ledgerProperty = new ReflectionProperty($planner, 'ledger');
        $ledgerProperty->setAccessible(true);
        $this->assertSame($this->callPrivate($first, 'ledgers'), $ledgerProperty->getValue($planner), 'the planner must use the facade ledger helper');
    }

    public function test_uncfg_is_read_once_per_request_and_the_hint_uses_the_cached_rows(): void
    {
        $this->bootMigrationGolden();
        $this->seedWorld();
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE91', 'port' => '1/2/2', 'onu_id' => 3]];

        $id = $this->guidedIntake('draft: guided intake', 'budi', 'FHTTC0FFEE91');
        // A device write already happened (the PPPoE step), so the SN correction goes through the partial-execution state.
        $row = CustomerMigration::query()->findOrFail($id);
        $meta = (array) $row->meta;
        $meta['mikrotik_migration'] = ['ok' => true, 'cross_router' => true, 'new_username' => (string) $row->pppoe_user];
        $row->meta = $meta;
        $row->new_olt_id = self::OLT_KRIAN;
        $row->save();

        $this->say('sn command with a typo of the SN in the uncfg list', '#migrasi sn #'.$id.' FHTTC0FFEE92');

        $events = $this->steps[array_key_last($this->steps)]['events'];
        $reads = array_values(array_filter($events, fn (array $event): bool => ($event['type'] ?? '') === 'olt.listUncfg'));
        $this->assertCount(1, $reads, 'the uncfg list must be read once per request');

        $hints = (array) data_get(CustomerMigration::query()->findOrFail($id)->meta, 'partial_execution.sn_hints', []);
        $this->assertNotSame([], $hints, 'the typo hint must be built from the cached uncfg rows');
        $this->assertSame('uncfg', $hints[0]['source'] ?? null, 'the hint must come from the uncfg read');
        $this->assertSame('FHTTC0FFEE91', $hints[0]['sn'] ?? null, 'the hint must name the SN of the uncfg row');
        $this->assertStringContainsString('Maksud Anda', (string) ($hints[0]['message'] ?? ''));
    }

    private function source(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/'.$name.'.php');
    }

    private function assertSameSignature(ReflectionFunction $closure, ReflectionMethod $method, string $field): void
    {
        $this->assertSame((string) $method->getReturnType(), (string) $closure->getReturnType(), $field.': return type differs');
        $params = $method->getParameters();
        $this->assertLessThanOrEqual(count($params), $closure->getNumberOfParameters(), $field.': the closure takes more arguments than the method');
        foreach ($closure->getParameters() as $position => $parameter) {
            $this->assertSame($this->typeOf($params[$position]->getType()), $this->typeOf($parameter->getType()), $field.': parameter '.$position.' type differs');
        }
    }

    private function typeOf(mixed $type): string
    {
        return $type instanceof ReflectionNamedType || $type instanceof \ReflectionUnionType ? (string) $type : '';
    }

    private function inject(object $service, string $property, object $value): void
    {
        $reflection = new ReflectionProperty(CustomerMigrationService::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($service, $value);
    }

    private function callPrivate(object $service, string $method): object
    {
        $reflection = new ReflectionMethod(CustomerMigrationService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service);
    }

    private function fakeHuawei(string $hash): HuaweiOltOnlyProvisioningService
    {
        return new class(new MikrotikService, $hash) extends HuaweiOltOnlyProvisioningService
        {
            public function __construct(MikrotikService $mikrotik, private string $hash)
            {
                parent::__construct($mikrotik);
            }

            public function preflight(CustomerMigration $migration): array
            {
                return [
                    'eligible' => true,
                    'olt' => ['id' => null],
                    'placement' => ['pon_port' => '0/0/0', 'onu_id' => 8, 'onu_ref' => '0/0/0:8'],
                    'plan' => ['commands' => ['config'], 'command_hash' => $this->hash],
                ];
            }
        };
    }
}
