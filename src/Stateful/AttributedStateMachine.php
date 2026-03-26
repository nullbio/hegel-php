<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Hegel\Exception\StatefulException;
use Hegel\Stateful\Attributes\Invariant as InvariantAttribute;
use Hegel\Stateful\Attributes\Rule as RuleAttribute;
use Hegel\TestCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use function count;
use function ctype_upper;
use function is_a;
use function sprintf;
use function strcmp;

final class AttributedStateMachine implements StateMachine
{
    /**
     * @param list<Rule> $rules
     * @param list<Invariant> $invariants
     */
    private function __construct(
        private array $rules,
        private array $invariants,
    ) {
    }

    public static function from(object $machine): self
    {
        $rules = [];
        $invariants = [];
        $discovered = self::discoverAttributedMethods($machine);

        if ($discovered === []) {
            $discovered = self::discoverConventionMethods($machine);
        }

        foreach ($discovered as $definition) {
            if ($definition['kind'] === 'rule') {
                $rules[] = Rule::new(
                    $definition['name'],
                    static function (TestCase $testCase) use ($machine, $definition): void {
                        self::invokeMethod($machine, $definition['method'], $testCase);
                    },
                );

                continue;
            }

            $invariants[] = Invariant::new(
                $definition['name'],
                static function (TestCase $testCase) use ($machine, $definition): void {
                    self::invokeMethod($machine, $definition['method'], $testCase);
                },
            );
        }

        if ($rules === []) {
            $class = $machine::class;

            throw new StatefulException(sprintf(
                'Stateful machine %s must implement %s or expose at least one public #[Rule] or ruleXxx method.',
                $class,
                StateMachine::class,
            ));
        }

        return new self($rules, $invariants);
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function invariants(): array
    {
        return $this->invariants;
    }

    /**
     * @return list<array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod}>
     */
    private static function discoverAttributedMethods(object $machine): array
    {
        $reflection = new ReflectionObject($machine);
        $definitions = [];

        foreach ($reflection->getMethods() as $method) {
            $ruleAttributes = $method->getAttributes(RuleAttribute::class);
            $invariantAttributes = $method->getAttributes(InvariantAttribute::class);

            if ($ruleAttributes === [] && $invariantAttributes === []) {
                continue;
            }

            if ($ruleAttributes !== [] && $invariantAttributes !== []) {
                throw new StatefulException(sprintf(
                    'Stateful method %s::%s cannot declare both #[Rule] and #[Invariant].',
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }

            $kind = $ruleAttributes !== [] ? 'rule' : 'invariant';
            self::assertMethodIsUsable($method, $kind);

            if ($kind === 'rule') {
                /** @var RuleAttribute $attribute */
                $attribute = $ruleAttributes[0]->newInstance();

                $definitions[] = [
                    'kind' => 'rule',
                    'name' => $attribute->name !== null ? $attribute->name : $method->getName(),
                    'method' => $method,
                ];

                continue;
            }

            /** @var InvariantAttribute $attribute */
            $attribute = $invariantAttributes[0]->newInstance();

            $definitions[] = [
                'kind' => 'invariant',
                'name' => $attribute->name !== null ? $attribute->name : $method->getName(),
                'method' => $method,
            ];
        }

        return self::sortDefinitions($definitions);
    }

    /**
     * @return list<array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod}>
     */
    private static function discoverConventionMethods(object $machine): array
    {
        $reflection = new ReflectionObject($machine);
        $definitions = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $name = $method->getName();

            if (self::matchesConvention($name, 'rule')) {
                self::assertMethodIsUsable($method, 'rule');
                $definitions[] = [
                    'kind' => 'rule',
                    'name' => self::normalizeConventionName($name, 'rule'),
                    'method' => $method,
                ];
                continue;
            }

            if (! self::matchesConvention($name, 'invariant')) {
                continue;
            }

            self::assertMethodIsUsable($method, 'invariant');
            $definitions[] = [
                'kind' => 'invariant',
                'name' => self::normalizeConventionName($name, 'invariant'),
                'method' => $method,
            ];
        }

        return self::sortDefinitions($definitions);
    }

    /**
     * @param list<array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod}> $definitions
     * @return list<array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod}>
     */
    private static function sortDefinitions(array $definitions): array
    {
        usort(
            $definitions,
            /**
             * @param array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod} $left
             * @param array{kind: 'rule'|'invariant', name: string, method: ReflectionMethod} $right
             */
            static function (array $left, array $right): int {
                $leftLine = $left['method']->getStartLine();
                $rightLine = $right['method']->getStartLine();
                $order = $leftLine <=> $rightLine;

                if ($order !== 0) {
                    return $order;
                }

                $order = strcmp(
                    $left['method']->getDeclaringClass()->getName(),
                    $right['method']->getDeclaringClass()->getName(),
                );

                if ($order !== 0) {
                    return $order;
                }

                return strcmp($left['method']->getName(), $right['method']->getName());
            },
        );

        return $definitions;
    }

    private static function assertMethodIsUsable(ReflectionMethod $method, string $kind): void
    {
        if (! $method->isPublic()) {
            throw new StatefulException(sprintf(
                'Stateful %s method %s::%s must be public.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        if ($method->isStatic()) {
            throw new StatefulException(sprintf(
                'Stateful %s method %s::%s must not be static.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        $parameters = $method->getParameters();

        if (count($parameters) > 1) {
            throw new StatefulException(sprintf(
                'Stateful %s method %s::%s must accept zero or one TestCase argument.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        if ($parameters !== [] && ! self::parameterAcceptsTestCase($parameters[0])) {
            throw new StatefulException(sprintf(
                'Stateful %s method %s::%s parameter must accept %s.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                TestCase::class,
            ));
        }
    }

    private static function matchesConvention(string $name, string $prefix): bool
    {
        if (! str_starts_with($name, $prefix)) {
            return false;
        }

        $suffix = substr($name, strlen($prefix));

        if ($suffix === '') {
            return true;
        }

        return $suffix[0] === '_' || ctype_upper($suffix[0]);
    }

    private static function normalizeConventionName(string $name, string $prefix): string
    {
        $suffix = substr($name, strlen($prefix));

        if ($suffix === '') {
            return $name;
        }

        $suffix = ltrim($suffix, '_');

        return $suffix === '' ? $name : lcfirst($suffix);
    }

    private static function parameterAcceptsTestCase(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }

        return self::typeAcceptsTestCase($type);
    }

    private static function typeAcceptsTestCase(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->getName() === 'mixed' || $type->getName() === 'object') {
                return true;
            }

            if ($type->isBuiltin()) {
                return false;
            }

            return is_a(TestCase::class, $type->getName(), true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                if (self::typeAcceptsTestCase($innerType)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $innerType) {
                if (! self::typeAcceptsTestCase($innerType)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function invokeMethod(object $machine, ReflectionMethod $method, TestCase $testCase): void
    {
        if ($method->getParameters() === []) {
            $method->invoke($machine);

            return;
        }

        $method->invoke($machine, $testCase);
    }
}
