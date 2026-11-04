<?php

namespace Tests\Unit\Migration;

use App\Services\CustomerMigrationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Golden of the API that tests and other classes depend on in CustomerMigrationService
 * (migration refactor M0, tests only).
 *
 * The extraction steps move bodies out of the service. Production callers use the public
 * methods; tests call, override or mock a set of protected names (the "contract names"). The
 * golden records the constructor, every public method, the contract names (visibility, params,
 * return type) and the protected properties, so a step cannot change them by accident.
 * Other protected methods may move without a golden change (owner decision Q2).
 *
 * The other tests here guard the rules of the series: test subclasses and partial mocks touch
 * only contract names; a name that a test overrides or mocks is never moved or copied, and
 * moved code reaches it only through a closure created in the facade.
 *
 * Golden: tests/Fixtures/migration-flow/api-surface.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1. A change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationServiceApiSurfaceTest extends TestCase
{
    /** Names that tests reflect on, override in a subclass, or mock (plan section 1.4). */
    private const CONTRACT_NAMES = [
        'applyMigrationPppoeSecret', 'applyPayload', 'attachPhotoBytes', 'candidateSelectionPreviewMessage', 'confirmGuidedWaCandidate',
        'confirmHuaweiOltOnlyFromWa', 'confirmReplacementFromWa', 'customerSnapshot', 'deleteOldOnuIfSafe', 'deviceActionPreflight',
        'dispatchWaExecution', 'executeMigration', 'findOrCreateMigration', 'guidedPreviewMessage', 'helpMessage',
        'huaweiOltOnlySuccessMessage', 'huaweiOltOnlyWriteFlags', 'isHuaweiOltOnlyParsed', 'migrationOperationLockKey',
        'migrationPreservesCustomerPackage', 'migrationReasonHint', 'migrationSecretComment', 'migrationTargetContract',
        'migrationWritesAllowed', 'oltSnapshotForMigration', 'photoBatchBudget', 'photoLabelFromHint', 'pppoeMigrationPlan',
        'primeMigrationFromCustomer', 'processMigrationLocked', 'proposedCustomerSnapshot', 'provisionNewOnu', 'psbOverlapEvidence',
        'purgeOldOnuLocal', 'readVerifiedMigrationPassword', 'reply', 'replyIfWaitingForNewSn', 'resolveCustomer',
        'resolveMigrationRouterAlias', 'resolveMigrationTargetSite', 'resolveOltForMigration', 'resolveOltFromMigrationField',
        'resolvePppoeForHuaweiDraft', 'resolveRouterForMigration', 'resolveSourceRouterForMigration', 'shouldUseC300UncfgPath',
        'snOwnershipConflicts', 'successMessage', 'syncCustomerAfterNewOnu', 'syncTr069Tag', 'verifyExistingPppoeSecret',
    ];

    /** Names a test overrides in a subclass or mocks on a partial mock: they never move and are never copied. */
    private const OVERRIDDEN_OR_MOCKED = [
        'applyMigrationPppoeSecret', 'deleteOldOnuIfSafe', 'huaweiOltOnlyWriteFlags', 'photoBatchBudget', 'provisionNewOnu',
        'readVerifiedMigrationPassword', 'reply', 'resolveCustomer', 'syncCustomerAfterNewOnu', 'syncTr069Tag', 'verifyExistingPppoeSecret',
    ];

    public function test_api_surface_matches_golden(): void
    {
        $class = new ReflectionClass(CustomerMigrationService::class);
        $public = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $public[$method->getName()] = $this->describeMethod($method);
        }
        ksort($public);

        $contract = [];
        foreach (self::CONTRACT_NAMES as $name) {
            $this->assertTrue($class->hasMethod($name), 'contract name missing from the migration service: '.$name);
            $contract[$name] = $this->describeMethod($class->getMethod($name));
        }
        ksort($contract);

        $properties = [];
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $properties[$property->getName()] = [
                'visibility' => $property->isPublic() ? 'public' : 'protected',
                'static' => $property->isStatic(),
                'type' => $property->hasType() ? $this->typeName($property->getType()) : null,
            ];
        }
        ksort($properties);

        $constructor = $class->getConstructor();
        $surface = [
            'class' => ['final' => $class->isFinal(), 'abstract' => $class->isAbstract(), 'parent' => $class->getParentClass() ? $class->getParentClass()->getName() : null],
            'constructor' => $constructor ? array_map(fn (ReflectionParameter $p): string => $this->param($p), $constructor->getParameters()) : [],
            'public_methods' => $public,
            'contract_names' => $contract,
            'protected_properties' => $properties,
        ];
        $recorded = json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = __DIR__.'/../../Fixtures/migration-flow/api-surface.golden.json';

        if (getenv('MIGRATION_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, 'No API surface golden. Record it with MIGRATION_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, 'The API of the migration service changed');
    }

    public function test_test_subclasses_and_partial_mocks_touch_only_contract_names(): void
    {
        $contract = array_merge(self::CONTRACT_NAMES, ['__construct']);
        $found = 0;
        foreach ($this->scannedFiles() as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match_all('/\bextends\s+\\\\?(?:[\w\\\\]*\\\\)?CustomerMigrationService\b[^{]*\{/', $source, $declarations, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($declarations[0] as [$text, $offset]) {
                    $found++;
                    $body = $this->classBody(substr($source, $offset + strlen($text)));
                    preg_match_all('/function\s+&?(\w+)\s*\(/', $body, $names);
                    // A name the facade does not have is the probe's own helper; only an override must be a contract name.
                    $overrides = array_filter($names[1], fn (string $name): bool => method_exists(CustomerMigrationService::class, $name));
                    $this->assertSame([], array_values(array_diff($overrides, $contract)), 'a test subclass overrides a migration service name outside the contract: '.basename($path));
                }
            }
            if (str_contains($source, 'Mockery::mock(CustomerMigrationService::class)->makePartial()')) {
                preg_match_all('/->shouldReceive\(\'(\w+)\'\)/', $source, $mocked);
                $this->assertSame([], array_values(array_diff($mocked[1], $contract)), 'a partial mock of the migration service mocks a name outside the contract: '.basename($path));
            }
        }
        $this->assertGreaterThanOrEqual(2, $found, 'the two known test subclasses were not found');
    }

    public function test_overridden_and_mocked_names_stay_in_the_facade_and_are_never_copied(): void
    {
        $facade = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/CustomerMigrationService.php');
        foreach (self::OVERRIDDEN_OR_MOCKED as $name) {
            $this->assertMatchesRegularExpression('/\$this->'.$name.'\(/', $facade, $name.' is no longer called as $this->'.$name.'( in the facade');
            $this->assertMatchesRegularExpression('/function\s+'.$name.'\s*\(/', $facade, $name.' is no longer declared in the facade');
        }

        $dir = dirname(__DIR__, 3).'/app/Services/Migration';
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::OVERRIDDEN_OR_MOCKED as $name) {
                $this->assertDoesNotMatchRegularExpression('/function\s+'.$name.'\s*\(/', $source, basename($file).' declares the overridden or mocked name '.$name);
            }
        }
    }

    /** @return list<string> */
    private function scannedFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== basename(__FILE__)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** Text after a class's opening brace up to its matching closing brace. */
    private function classBody(string $afterOpenBrace): string
    {
        $depth = 1;
        $body = '';
        foreach (token_get_all('<?php '.$afterOpenBrace) as $i => $token) {
            if ($i === 0) {
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $body .= $text;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function describeMethod(ReflectionMethod $method): array
    {
        return [
            'declaring' => $method->getDeclaringClass()->getShortName(),
            'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
            'static' => $method->isStatic(),
            'final' => $method->isFinal(),
            'params' => array_map(fn (ReflectionParameter $p): string => $this->param($p), $method->getParameters()),
            'returns' => $method->hasReturnType() ? $this->typeName($method->getReturnType()) : null,
        ];
    }

    private function param(ReflectionParameter $p): string
    {
        $text = ($p->hasType() ? $this->typeName($p->getType()).' ' : '')
            .($p->isPassedByReference() ? '&' : '')
            .($p->isVariadic() ? '...' : '')
            .'$'.$p->getName();
        if ($p->isPromoted()) {
            $text = 'promoted '.$text;
        }
        if ($p->isDefaultValueAvailable()) {
            $text .= ' = '.var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional() && ! $p->isVariadic()) {
            $text .= ' = <optional>';
        }

        return $text;
    }

    private function typeName(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '').$type->getName();
        }

        return (string) $type;
    }
}
