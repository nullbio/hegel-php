<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;
use ReflectionClass;

final class ObjectGenerator extends AbstractGenerator
{
    /** @var array<string, Generator> */
    private array $overrides = [];

    /**
     * @param list<class-string> $classStack
     */
    public function __construct(
        private string $class,
        private array $classStack = [],
    ) {
    }

    public function with(string $name, Generator $generator): self
    {
        $this->overrides[$name] = $generator;

        return $this;
    }

    public function draw(\Hegel\TestCase $testCase): mixed
    {
        return $this->mapped()->draw($testCase);
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        return $this->mapped()->basic();
    }

    private function mapped(): MappedGenerator
    {
        return $this->source()->map(
            fn (array $values): object => DefaultGeneratorResolver::instantiate($this->class, $values),
        );
    }

    private function source(): FixedDictGenerator
    {
        return new FixedDictGenerator($this->resolveFields());
    }

    /**
     * @return list<array{name: string, generator: Generator}>
     */
    private function resolveFields(): array
    {
        if (! class_exists($this->class)) {
            throw new InvalidArgumentException(sprintf('Cannot build object generator for unknown class %s.', $this->class));
        }

        $reflection = new ReflectionClass($this->class);

        if ($reflection->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Cannot build object generator for abstract class %s.', $this->class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            if ($this->overrides !== []) {
                $unknown = (string) array_key_first($this->overrides);

                throw new InvalidArgumentException(sprintf(
                    'Object generator for %s does not define constructor parameter %s.',
                    $this->class,
                    $unknown,
                ));
            }

            return [];
        }

        $fields = [];
        $consumed = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                throw new InvalidArgumentException(sprintf(
                    'Object generator for %s does not support variadic constructor parameter %s.',
                    $this->class,
                    $parameter->getName(),
                ));
            }

            $name = $parameter->getName();

            if (array_key_exists($name, $this->overrides)) {
                $consumed[$name] = true;
                $fields[] = [
                    'name' => $name,
                    'generator' => $this->overrides[$name],
                ];
                continue;
            }

            $fields[] = [
                'name' => $name,
                'generator' => DefaultGeneratorResolver::fromParameter(
                    $parameter,
                    $reflection,
                    array_merge($this->classStack, [$this->class]),
                ),
            ];
        }

        foreach (array_keys($this->overrides) as $name) {
            if (array_key_exists($name, $consumed)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Object generator for %s does not define constructor parameter %s.',
                $this->class,
                $name,
            ));
        }

        return $fields;
    }
}
