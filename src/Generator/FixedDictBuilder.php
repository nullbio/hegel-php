<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class FixedDictBuilder
{
    /** @var list<array{name: string, generator: Generator}> */
    private array $fields = [];

    public function field(string $name, Generator $generator): self
    {
        foreach ($this->fields as $field) {
            if ($field['name'] === $name) {
                throw new InvalidArgumentException(sprintf('fixedDict field %s is already defined.', $name));
            }
        }

        $this->fields[] = [
            'name' => $name,
            'generator' => $generator,
        ];

        return $this;
    }

    public function build(): FixedDictGenerator
    {
        return new FixedDictGenerator($this->fields);
    }

    public function into(string $class): MappedGenerator
    {
        return $this->build()->map(
            static fn (array $values): object => DefaultGeneratorResolver::instantiate($class, $values),
        );
    }
}
