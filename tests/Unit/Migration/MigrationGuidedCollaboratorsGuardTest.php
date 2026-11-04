<?php

namespace Tests\Unit\Migration;

use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use App\Services\Migration\MigrationGuidedPreviewRefresh;
use App\Services\Migration\MigrationGuidedWaFlow;
use App\Services\Migration\MigrationHuaweiOltOnlyIntake;
use App\Services\Migration\MigrationSeams;
use App\Services\Migration\MigrationSnCorrection;
use App\Services\Migration\MigrationWaSenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Guards for the five classes moved out of CustomerMigrationService in migration refactor
 * Phase 2, M4 (senders, preview refresh, SN correction, Huawei intake, guided WA flow). They
 * move bodies without a change of behaviour; these tests keep the moved code from reaching a
 * facade dependency by a shortcut, from taking a lock that is not on the allowlist, from losing
 * an import that a catch needs, and from reading a seam too early.
 *
 * 1. Source scan of the five classes (including: every caught/instanceof class is imported).
 * 2. Lock allowlist: key, TTL and wait of the two locks that moved with their bodies.
 * 3. A guided lookup/parser injected AFTER the flow was built is honoured (late binding).
 * 4. One instance of each helper per facade, wired to the facade's own instances.
 * 5. The re-pointed seams reach the new collaborators.
 */
#[Group('blocking-psb')]
class MigrationGuidedCollaboratorsGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSES = [
        'MigrationWaSenders',
        'MigrationGuidedPreviewRefresh',
        'MigrationSnCorrection',
        'MigrationHuaweiOltOnlyIntake',
        'MigrationGuidedWaFlow',
    ];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_five_classes_use_no_facade_dependency_or_strict_types(): void
    {
        foreach (self::CLASSES as $name) {
            $source = $this->source($name);
            $this->assertNotSame('', $source, $name.' unreadable');
            $this->assertDoesNotMatchRegularExpression('/\$this->(psb|parser|mikrotik|zte|genieAcs|controlPlane|huaweiOltOnly|operationClassifier|guidedLookup|uncfgCache)\b/', $source, $name.' reads a facade dependency directly');
            $this->assertStringNotContainsString('strict_types', $source, $name.' must not declare strict_types (the facade coerces)');
            $this->assertDoesNotMatchRegularExpression('/\$this->\w+\s*=\s*\(\$this->seams/', $source, $name.' stores a seam result');
            $this->assertDoesNotMatchRegularExpression('/function\s+(resolveCustomer|syncCustomerAfterNewOnu|attachStoredInboundImages|readUncfgOnus|reply|prepareDeviceActionApproval)\s*\(/', $source, $name.' re-declares a facade-owned method');

            preg_match_all('/\$this->host->(\w+)/', $source, $calls);
            foreach ($calls[1] as $called) {
                $this->assertContains($called, ['reply', 'prepareDeviceActionApproval'], $name.' calls the facade through $this->host->'.$called);
            }

            if (str_contains($source, 'Carbon::')) {
                $this->assertStringContainsString('use Illuminate\Support\Carbon;', $source, $name.' must use Illuminate\Support\Carbon like the facade');
            }
            $this->assertStringNotContainsString('Carbon\Carbon', $source, $name.' must not use Carbon\Carbon');
        }
    }

    public function test_every_caught_or_instanceof_class_is_imported(): void
    {
        foreach (self::CLASSES as $name) {
            $source = $this->source($name);
            preg_match_all('/catch\s*\(\s*([\\\\\w|\s]+?)\s*(?:\$\w+)?\s*\)/', $source, $catches);
            preg_match_all('/instanceof\s+([\\\\\w]+)/', $source, $instances);

            $classes = [];
            foreach ($catches[1] as $list) {
                foreach (explode('|', $list) as $class) {
                    $classes[] = trim($class);
                }
            }
            foreach ($instances[1] as $class) {
                $classes[] = trim($class);
            }

            foreach (array_unique($classes) as $class) {
                if ($class === '' || str_starts_with($class, '\\') || in_array($class, ['self', 'static'], true)) {
                    continue;
                }
                $this->assertMatchesRegularExpression(
                    '/^use\s+[\w\\\\]+\\\\'.preg_quote($class, '/').';$/m',
                    $source,
                    $name.' catches/tests '.$class.' without a use line: the catch would never match'
                );
            }
        }

        // The two exceptions that decide the busy text must be imported where they are caught.
        foreach (['MigrationGuidedWaFlow', 'MigrationHuaweiOltOnlyIntake'] as $name) {
            $this->assertStringContainsString('use App\Exceptions\LockBusyException;', $this->source($name));
        }
        $this->assertStringContainsString('use App\Exceptions\HuaweiOltSessionBusyException;', $this->source('MigrationHuaweiOltOnlyIntake'));
    }

    public function test_lock_allowlist_pins_key_ttl_and_wait(): void
    {
        $refresh = $this->source('MigrationGuidedPreviewRefresh');
        $this->assertSame(1, substr_count($refresh, "Cache::lock('customer-migration-review-'.\$migration->id, 60)"), 'review lock key/TTL changed');
        $this->assertSame(1, substr_count($refresh, 'CacheLockWait::acquire($lock, 5)'), 'review lock wait changed');
        $this->assertSame(1, substr_count($refresh, 'Cache::lock('), 'unexpected extra lock in the preview refresh');

        $correction = $this->source('MigrationSnCorrection');
        $this->assertSame(1, substr_count($correction, "Cache::lock('customer-migration-device-'.\$id, 120)"), 'device lock key/TTL changed');
        $this->assertSame(1, substr_count($correction, 'CacheLockWait::acquire($lock, 5)'), 'device lock wait changed');
        $this->assertSame(1, substr_count($correction, 'Cache::lock('), 'unexpected extra lock in the SN correction');

        foreach (['MigrationWaSenders', 'MigrationHuaweiOltOnlyIntake', 'MigrationGuidedWaFlow'] as $name) {
            $source = $this->source($name);
            $this->assertStringNotContainsString('Cache::', $source, $name.' must not touch the cache');
            $this->assertStringNotContainsString('CacheLockWait', $source, $name.' must not wait for a lock');
        }

        foreach (self::CLASSES as $name) {
            $source = $this->source($name);
            $this->assertStringNotContainsString('->block(', $source, $name.' blocks on a lock');
            $this->assertDoesNotMatchRegularExpression('/\$lock->get\(/', $source, $name.' takes a lock with ->get(');
        }

        $facade = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/CustomerMigrationService.php');
        $this->assertSame(9, substr_count($facade, 'Cache::lock('), 'the facade keeps exactly 9 locks (11 before M4)');
    }

    public function test_a_lookup_and_parser_injected_after_the_flow_was_built_are_honoured(): void
    {
        $service = Mockery::mock(CustomerMigrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('reply')->andReturnNull();

        // Build the flow FIRST, then inject the dependencies the constructor would have set.
        $this->callPrivate($service, 'guidedFlow');
        $this->inject($service, 'parser', app(CustomerMigrationMessageParser::class));
        $this->inject($service, 'guidedLookup', $this->fakeLookup('sentinel-a'));

        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'guard-'.uniqid(),
            'raw_text' => "#migrasi\nnama: budi contoh",
            'pppoe_user' => 'GUARD02',
            'new_sn' => 'FHTTC0FFEE02',
        ]);

        $result = $service->retryGuidedFromWeb($migration->fresh());
        $this->assertTrue($result['handled'], 'a guided draft must be handled by the web retry');
        $this->assertSame('sentinel-a', data_get($migration->fresh()->meta, 'guided_wa_preview.candidates.0.sentinel'), 'the first fake lookup must be used');

        $this->inject($service, 'guidedLookup', $this->fakeLookup('sentinel-b'));
        $service->retryGuidedFromWeb($migration->fresh());
        $this->assertSame('sentinel-b', data_get($migration->fresh()->meta, 'guided_wa_preview.candidates.0.sentinel'), 'the swapped fake lookup must be used');
    }

    public function test_one_instance_of_each_helper_per_facade(): void
    {
        $first = $this->app->build(CustomerMigrationService::class);
        $second = $this->app->build(CustomerMigrationService::class);
        $this->assertNotSame($first, $second);

        foreach (['senders', 'guidedRefresh', 'snCorrection', 'huaweiIntake', 'guidedFlow'] as $builder) {
            $this->assertSame($this->callPrivate($first, $builder), $this->callPrivate($first, $builder), $builder.' must build once per facade');
            $this->assertNotSame($this->callPrivate($first, $builder), $this->callPrivate($second, $builder), $builder.' must not be shared between facades');
        }

        $flow = $this->callPrivate($first, 'guidedFlow');
        foreach ([
            'senders' => 'senders',
            'refresh' => 'guidedRefresh',
            'snCorrection' => 'snCorrection',
            'huawei' => 'huaweiIntake',
            'partials' => 'partials',
            'ledger' => 'ledgers',
            'planner' => 'planner',
            'resolver' => 'resolver',
            'texts' => 'texts',
        ] as $property => $builder) {
            $this->assertSame($this->callPrivate($first, $builder), $this->property($flow, $property), 'GuidedWaFlow::$'.$property.' must be the facade instance');
        }

        $correction = $this->callPrivate($first, 'snCorrection');
        foreach (['planner' => 'planner', 'partials' => 'partials', 'refresh' => 'guidedRefresh', 'senders' => 'senders'] as $property => $builder) {
            $this->assertSame($this->callPrivate($first, $builder), $this->property($correction, $property), 'SnCorrection::$'.$property.' must be the facade instance');
        }

        $huawei = $this->callPrivate($first, 'huaweiIntake');
        $this->assertSame($this->callPrivate($first, 'senders'), $this->property($huawei, 'senders'), 'HuaweiIntake::$senders must be the facade instance');
        $this->assertSame($first, $this->property($flow, 'host'), 'the host must be the facade itself');

        $this->assertInstanceOf(MigrationGuidedWaFlow::class, $flow);
        $this->assertInstanceOf(MigrationGuidedPreviewRefresh::class, $this->callPrivate($first, 'guidedRefresh'));
        $this->assertInstanceOf(MigrationHuaweiOltOnlyIntake::class, $huawei);
        $this->assertInstanceOf(MigrationSnCorrection::class, $correction);
        $this->assertInstanceOf(MigrationWaSenders::class, $this->callPrivate($first, 'senders'));
    }

    public function test_re_pointed_seams_reach_the_new_collaborators(): void
    {
        $service = $this->app->build(CustomerMigrationService::class);
        /** @var MigrationSeams $seams */
        $seams = $this->callPrivate($service, 'seams');
        /** @var MigrationWaSenders $senders */
        $senders = $this->callPrivate($service, 'senders');
        /** @var MigrationSnCorrection $correction */
        $correction = $this->callPrivate($service, 'snCorrection');

        $payload = [
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'sender_jid_pn' => '6281200000011@s.whatsapp.net',
            'sender_jid_lid' => '9990000011@lid',
            'participant' => '6281200000011@s.whatsapp.net',
        ];
        $keys = ($seams->waSenderKeys)($payload);
        $this->assertSame($senders->waSenderKeys($payload), $keys);
        $this->assertContains('6281200000011', $keys);
        $this->assertContains('9990000011', $keys);

        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'message_id' => 'guard-'.uniqid(),
            'raw_text' => '#migrasi',
            'pppoe_user' => 'GUARD03',
            'new_sn' => 'FHTTC0FFEE92',
        ]);
        $this->assertTrue(($seams->canModifyMigrationFromWa)($migration, $payload));
        $this->assertSame($senders->canModifyMigrationFromWa($migration, $payload), ($seams->canModifyMigrationFromWa)($migration, $payload));
        $stranger = ['group_jid' => 'activation@g.us', 'sender_jid' => '6281200000099@s.whatsapp.net'];
        $this->assertSame($senders->canModifyMigrationFromWa($migration, $stranger), ($seams->canModifyMigrationFromWa)($migration, $stranger));
        $this->assertFalse(($seams->canModifyMigrationFromWa)($migration, $stranger));

        $olt = (new Olt)->forceFill(['id' => 1, 'name' => 'OLT UJI']);
        $uncfg = [['sn' => 'FHTTC0FFEE91', 'port' => '1/2/2', 'onu_id' => 3]];
        $this->assertSame(
            $correction->snTypoHints($migration, $olt, $uncfg),
            ($seams->snTypoHints)($migration, $olt, $uncfg)
        );
    }

    private function source(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Migration/'.$name.'.php');
    }

    private function fakeLookup(string $sentinel): GuidedMigrationLookupService
    {
        return new class($sentinel) extends GuidedMigrationLookupService
        {
            public function __construct(private string $sentinel) {}

            public function preview(array $input): array
            {
                return [
                    'status' => 'needs_review',
                    'reasons' => [],
                    'candidates' => [[
                        'number' => 1,
                        'customer_id' => 9001,
                        'name' => 'BUDI CONTOH',
                        'pppoe_user' => 'budicontoh',
                        'confidence' => 'medium',
                        'score' => 60,
                        'conflicts' => [],
                        'sources' => [],
                        'sentinel' => $this->sentinel,
                    ]],
                    'idempotency_key' => 'guard-'.$this->sentinel,
                ];
            }
        };
    }

    private function inject(object $service, string $property, object $value): void
    {
        $reflection = new ReflectionProperty(CustomerMigrationService::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($service, $value);
    }

    private function property(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function callPrivate(object $service, string $method): object
    {
        $reflection = new ReflectionMethod(CustomerMigrationService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service);
    }
}
