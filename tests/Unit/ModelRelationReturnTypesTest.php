<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Guards the relation types declared on App\Models (native return type and the
 * `@return BelongsTo<Related, $this>` PHPDoc used by Larastan).
 *
 * For every public zero-parameter method declared on a model whose native return
 * type or @return tag is a Relation subclass, the relation is built (this only
 * builds constraints, no query runs) and must be an instance of the declared
 * class. When the PHPDoc names the related model, that must match too.
 * A wrong native return type would otherwise TypeError at runtime.
 */
#[Group('blocking-core')]
class ModelRelationReturnTypesTest extends TestCase
{
    private const RELATIONS_NS = 'Illuminate\\Database\\Eloquent\\Relations\\';

    public function test_declared_relation_types_match_the_real_relations(): void
    {
        $checked = 0;
        $errors = [];

        foreach ($this->modelClasses() as $modelClass) {
            $reflection = new ReflectionClass($modelClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()
                    || $method->getNumberOfParameters() > 0
                    || $method->getDeclaringClass()->getName() !== $modelClass
                    // Relations that come from a framework trait (e.g. Notifiable) are not ours.
                    || $method->getFileName() !== $reflection->getFileName()
                ) {
                    continue;
                }

                $declared = $this->declaredRelationClass($method);
                if ($declared === null) {
                    continue;
                }

                $label = $modelClass.'::'.$method->getName().'()';

                if ($declared['problem'] !== null) {
                    $errors[] = $label.' '.$declared['problem'];

                    continue;
                }

                try {
                    $relation = (new $modelClass)->{$method->getName()}();
                } catch (\Throwable $e) {
                    $errors[] = $label.' threw '.get_class($e).': '.$e->getMessage();

                    continue;
                }

                $checked++;

                if (! $relation instanceof $declared['class']) {
                    $errors[] = $label.' declares '.$declared['class'].' but returns '.get_class($relation);

                    continue;
                }

                if ($declared['related'] !== null && ! $relation->getRelated() instanceof $declared['related']) {
                    $errors[] = $label.' @return names '.$declared['related'].' but relates to '.get_class($relation->getRelated());
                }
            }
        }

        $this->assertSame([], $errors, implode("\n", $errors));
        $this->assertGreaterThan(30, $checked, 'relation scan found too few relations');
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return array{class: class-string<Relation<*, *, *>>|null, related: class-string|null, problem: string|null}|null
     */
    private function declaredRelationClass(ReflectionMethod $method): ?array
    {
        $type = $method->getReturnType();
        $native = null;
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Relation::class)) {
            $native = $type->getName();
        }

        $docClass = null;
        $docRelated = null;
        $problem = null;
        $doc = $method->getDocComment();
        if ($doc !== false && preg_match('/@return\s+\\\\?([A-Za-z0-9_\\\\]+)<\s*\\\\?([A-Za-z0-9_\\\\]+)/', $doc, $m)) {
            $docClass = $this->resolveRelation($m[1]);
            if ($docClass !== null) {
                $docRelated = $this->resolveModel($m[2]);
                if ($docRelated === null) {
                    $problem = '@return names related model '.$m[2].' which does not resolve to an App\\Models class';
                }
            } elseif ($native !== null) {
                $problem = '@return names '.$m[1].' which does not resolve to a Relation class (native type is '.$native.')';
            } elseif (preg_match('/^(Belongs|Has|Morph)/', $m[1]) === 1) {
                $problem = '@return names '.$m[1].' which does not resolve to a Relation class';
            }
        }

        if ($native !== null && $docClass !== null && $native !== $docClass) {
            $problem = '@return declares '.$docClass.' but the native return type is '.$native;
        }

        $class = $native ?? $docClass;
        if ($class === null && $problem === null) {
            return null;
        }

        return ['class' => $class, 'related' => $docRelated, 'problem' => $problem];
    }

    private function resolveRelation(string $name): ?string
    {
        foreach ([$name, self::RELATIONS_NS.$name] as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Relation::class)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveModel(string $name): ?string
    {
        foreach ([$name, 'App\\Models\\'.$name] as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
