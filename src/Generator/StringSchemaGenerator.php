<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

abstract class StringSchemaGenerator extends SchemaGenerator
{
    protected function parse(mixed $value): mixed
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Expected text generated value, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
