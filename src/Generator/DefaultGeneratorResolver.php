<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class DefaultGeneratorResolver
{
    private function __construct()
    {
    }

    public static function fromDescriptor(string $type, Generator ...$arguments): Generator
    {
        /** @var list<Generator> $arguments */
        $arguments = array_values($arguments);
        $normalized = ltrim($type, '\\');

        if (str_starts_with($normalized, '?')) {
            self::assertArgumentCount($normalized, $arguments, 0);

            return new OptionalGenerator(self::fromDescriptor(substr($normalized, 1)));
        }

        /** @var list<class-string> $emptyStack */
        $emptyStack = [];

        return match (strtolower($normalized)) {
            'int', 'integer' => self::expectNoArguments($normalized, $arguments, new IntegerGenerator()),
            'float', 'double' => self::expectNoArguments($normalized, $arguments, new FloatGenerator()),
            'bool', 'boolean' => self::expectNoArguments($normalized, $arguments, new BooleanGenerator()),
            'string' => self::expectNoArguments($normalized, $arguments, new TextGenerator()),
            'binary', 'bytes' => self::expectNoArguments($normalized, $arguments, new BinaryGenerator()),
            'array', 'list' => self::arrayGenerator($normalized, $arguments),
            'set', 'hashset' => self::setGenerator($normalized, $arguments),
            'map', 'hashmap', 'dict' => self::mapGenerator($normalized, $arguments),
            default => self::fromNamedClass($normalized, $emptyStack, $arguments),
        };
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param list<class-string> $classStack
     */
    public static function fromParameter(
        ReflectionParameter $parameter,
        ReflectionClass $declaringClass,
        array $classStack = [],
    ): Generator {
        $type = $parameter->getType();

        if ($type === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return new JustGenerator($parameter->getDefaultValue());
            }

            throw new InvalidArgumentException(sprintf(
                'Cannot infer a default generator for %s::$%s without a type or default value.',
                $declaringClass->getName(),
                $parameter->getName(),
            ));
        }

        $resolved = self::fromReflectionType($type, $declaringClass, $classStack);

        if ($resolved !== null) {
            return $resolved;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return new JustGenerator($parameter->getDefaultValue());
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot infer a default generator for %s::$%s from type %s. Override it explicitly.',
            $declaringClass->getName(),
            $parameter->getName(),
            self::describeType($type),
        ));
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function instantiate(string $class, array $values): object
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Cannot build object generator for unknown class %s.', $class));
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Cannot build object generator for abstract class %s.', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            if ($values !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Object builder for %s cannot accept fields because the class has no constructor.',
                    $class,
                ));
            }

            return $reflection->newInstance();
        }

        if (! $constructor->isPublic()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot build object generator for %s because its constructor is not public.',
                $class,
            ));
        }

        $arguments = [];
        $remaining = $values;

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $remaining)) {
                $arguments[] = $remaining[$name];
                unset($remaining[$name]);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Object builder for %s is missing required field %s.',
                $class,
                $name,
            ));
        }

        if ($remaining !== []) {
            $unknown = (string) array_key_first($remaining);

            throw new InvalidArgumentException(sprintf(
                'Object builder for %s does not define constructor parameter %s.',
                $class,
                $unknown,
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param list<class-string> $classStack
     */
    private static function fromReflectionType(
        ReflectionType $type,
        ReflectionClass $declaringClass,
        array $classStack = [],
    ): ?Generator {
        if ($type instanceof ReflectionNamedType) {
            $generator = self::fromNamedType($type, $declaringClass, $classStack);

            if (! $type->allowsNull()) {
                return $generator;
            }

            return $generator === null ? null : new OptionalGenerator($generator);
        }

        if ($type instanceof ReflectionUnionType) {
            $nonNullTypes = array_values(array_filter(
                $type->getTypes(),
                static fn (ReflectionNamedType|ReflectionIntersectionType $innerType): bool => ! (
                    $innerType instanceof ReflectionNamedType && strtolower($innerType->getName()) === 'null'
                ),
            ));

            if (count($nonNullTypes) !== 1) {
                return null;
            }

            $inner = $nonNullTypes[0];

            if (! $inner instanceof ReflectionNamedType) {
                return null;
            }

            $generator = self::fromNamedType($inner, $declaringClass, $classStack);

            return $generator === null ? null : new OptionalGenerator($generator);
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param list<class-string> $classStack
     */
    private static function fromNamedType(
        ReflectionNamedType $type,
        ReflectionClass $declaringClass,
        array $classStack = [],
    ): ?Generator {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match (strtolower($name)) {
                'int' => new IntegerGenerator(),
                'float' => new FloatGenerator(),
                'bool' => new BooleanGenerator(),
                'string' => new TextGenerator(),
                default => null,
            };
        }

        /** @var list<Generator> $arguments */
        $arguments = [];

        return self::fromNamedClass($name, $classStack, $arguments, $declaringClass);
    }

    /**
     * @param ReflectionClass<object>|null $declaringClass
     * @param list<Generator> $arguments
     * @param list<class-string> $classStack
     */
    private static function fromNamedClass(
        string $name,
        array $classStack,
        array $arguments,
        ?ReflectionClass $declaringClass = null,
    ): Generator {
        $class = self::normalizeClassName($name, $declaringClass);

        if (enum_exists($class)) {
            self::assertArgumentCount($class, $arguments, 0);

            /** @var class-string<\UnitEnum> $class */
            return new SampledFromGenerator($class::cases());
        }

        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Unsupported default generator type %s.', $name));
        }

        self::assertArgumentCount($class, $arguments, 0);

        if (in_array($class, $classStack, true)) {
            throw new InvalidArgumentException(sprintf(
                'Recursive default generator resolution detected for %s.',
                $class,
            ));
        }

        if (is_a($class, ProvidesGenerator::class, true)) {
            /** @var class-string<ProvidesGenerator> $class */
            return $class::generator();
        }

        return new ObjectGenerator($class, array_merge($classStack, [$class]));
    }

    /**
     * @param list<Generator> $arguments
     */
    private static function arrayGenerator(string $type, array $arguments): ArrayGenerator
    {
        self::assertArgumentCount($type, $arguments, 1);

        return new ArrayGenerator($arguments[0]);
    }

    /**
     * @param list<Generator> $arguments
     */
    private static function setGenerator(string $type, array $arguments): HashSetGenerator
    {
        self::assertArgumentCount($type, $arguments, 1);

        return new HashSetGenerator($arguments[0]);
    }

    /**
     * @param list<Generator> $arguments
     */
    private static function mapGenerator(string $type, array $arguments): HashMapGenerator
    {
        self::assertArgumentCount($type, $arguments, 2);

        return new HashMapGenerator($arguments[0], $arguments[1]);
    }

    /**
     * @param list<Generator> $arguments
     */
    private static function expectNoArguments(string $type, array $arguments, Generator $generator): Generator
    {
        self::assertArgumentCount($type, $arguments, 0);

        return $generator;
    }

    /**
     * @param list<Generator> $arguments
     */
    private static function assertArgumentCount(string $type, array $arguments, int $expected): void
    {
        if (count($arguments) === $expected) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Default generator type %s expects %d generator argument(s), got %d.',
            $type,
            $expected,
            count($arguments),
        ));
    }

    /**
     * @param ReflectionClass<object>|null $declaringClass
     */
    private static function normalizeClassName(string $name, ?ReflectionClass $declaringClass = null): string
    {
        if ($name === 'parent') {
            if ($declaringClass === null) {
                throw new InvalidArgumentException('Cannot resolve parent without a parent class.');
            }

            $parent = $declaringClass->getParentClass();

            if ($parent === false) {
                throw new InvalidArgumentException('Cannot resolve parent without a parent class.');
            }

            return $parent->getName();
        }

        return match ($name) {
            'self', 'static' => $declaringClass?->getName()
                ?? throw new InvalidArgumentException(sprintf('Cannot resolve %s without a declaring class.', $name)),
            default => ltrim($name, '\\'),
        };
    }

    private static function describeType(ReflectionType $type): string
    {
        return match (true) {
            $type instanceof ReflectionNamedType => $type->getName(),
            $type instanceof ReflectionUnionType => self::describeUnionType($type),
            $type instanceof ReflectionIntersectionType => self::describeIntersectionType($type),
            default => 'unknown',
        };
    }

    private static function describeUnionType(ReflectionUnionType $type): string
    {
        $parts = [];

        foreach ($type->getTypes() as $innerType) {
            $parts[] = self::describeType($innerType);
        }

        return implode('|', $parts);
    }

    private static function describeIntersectionType(ReflectionIntersectionType $type): string
    {
        $parts = [];

        foreach ($type->getTypes() as $innerType) {
            $parts[] = self::describeType($innerType);
        }

        return implode('&', $parts);
    }
}
