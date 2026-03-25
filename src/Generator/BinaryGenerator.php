<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class BinaryGenerator extends SchemaGenerator
{
    private int $minSize = 0;
    private ?int $maxSize = null;

    public function minSize(int $minSize): self
    {
        if ($minSize < 0) {
            throw new InvalidArgumentException('minSize must be greater than or equal to zero.');
        }

        if ($this->maxSize !== null && $minSize > $this->maxSize) {
            throw new InvalidArgumentException('minSize cannot be greater than maxSize.');
        }

        $this->minSize = $minSize;

        return $this;
    }

    public function maxSize(?int $maxSize): self
    {
        if ($maxSize !== null && $maxSize < $this->minSize) {
            throw new InvalidArgumentException('maxSize cannot be less than minSize.');
        }

        $this->maxSize = $maxSize;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        $schema = [
            'type' => 'binary',
            'min_size' => $this->minSize,
        ];

        if ($this->maxSize !== null) {
            $schema['max_size'] = $this->maxSize;
        }

        return $schema;
    }

    protected function parse(mixed $value): mixed
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Expected binary generated value, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
