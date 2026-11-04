<?php

namespace Tests\Unit;

use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbOrchestratorService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Golden list of the public + protected API of the three PSB orchestrator classes
 * (PSB refactor PR 0, tests only).
 *
 * The extraction PRs move code out of PsbOrchestratorService but must keep every
 * public and protected method, property and constant (production subclasses, the OCR
 * worker adapter and 11 test harnesses override or call them). For each class the
 * golden records the name, declaring class, modifiers, parameter list (type, default,
 * by-reference, variadic) and return type of every public/protected method, plus the
 * public/protected properties and constants. Private members are not part of the API.
 * Entries are sorted by name, so moving a method inside a file changes nothing.
 *
 * Golden: tests/Fixtures/psb-flow/orchestrator-api-surface.golden.json, written ONLY with
 * env PSB_FLOW_GOLDEN_WRITE=1. A change needs owner OK (plan Q8).
 */
#[Group('blocking-psb')]
class PsbOrchestratorApiSurfaceTest extends TestCase
{
    public function test_api_surface_matches_golden(): void
    {
        $surface = [];
        foreach ([PsbOrchestratorService::class, HuaweiAwarePsbOrchestratorService::class, PsbMediaOcrOrchestrator::class] as $class) {
            $surface[$class] = $this->describe(new ReflectionClass($class));
        }
        $recorded = json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = __DIR__.'/../Fixtures/psb-flow/orchestrator-api-surface.golden.json';

        if (getenv('PSB_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, 'No API surface golden. Record it with PSB_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, 'The public/protected API of the PSB orchestrators changed');
    }

    public function test_the_container_default_is_the_huawei_aware_final_class(): void
    {
        $this->assertTrue((new ReflectionClass(HuaweiAwarePsbOrchestratorService::class))->isFinal());
        $this->assertTrue((new ReflectionClass(HuaweiAwarePsbOrchestratorService::class))->isSubclassOf(PsbOrchestratorService::class));
        $this->assertTrue((new ReflectionClass(PsbMediaOcrOrchestrator::class))->isSubclassOf(PsbOrchestratorService::class));
    }

    /** @return array<string, mixed> */
    private function describe(ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            $methods[$method->getName()] = [
                'declaring' => $method->getDeclaringClass()->getShortName(),
                'visibility' => $method->isPublic() ? 'public' : 'protected',
                'static' => $method->isStatic(),
                'final' => $method->isFinal(),
                'params' => array_map(fn (ReflectionParameter $p): string => $this->param($p), $method->getParameters()),
                'returns' => $method->hasReturnType() ? $this->typeName($method->getReturnType()) : null,
            ];
        }
        ksort($methods);

        $properties = [];
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $properties[$property->getName()] = [
                'declaring' => $property->getDeclaringClass()->getShortName(),
                'visibility' => $property->isPublic() ? 'public' : 'protected',
                'static' => $property->isStatic(),
                'type' => $property->hasType() ? $this->typeName($property->getType()) : null,
            ];
        }
        ksort($properties);

        $constants = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->isPrivate()) {
                continue;
            }
            $constants[$constant->getName()] = $constant->getValue();
        }
        ksort($constants);

        return ['final' => $class->isFinal(), 'methods' => $methods, 'properties' => $properties, 'constants' => $constants];
    }

    private function param(ReflectionParameter $p): string
    {
        $text = ($p->hasType() ? $this->typeName($p->getType()).' ' : '')
            .($p->isPassedByReference() ? '&' : '')
            .($p->isVariadic() ? '...' : '')
            .'$'.$p->getName();
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
