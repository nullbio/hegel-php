<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class BooleanGenerator extends SchemaGenerator
{
    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return ['type' => 'boolean'];
    }

    protected function parse(mixed $value): mixed
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(sprintf(
                'Expected boolean generated value, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
