<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class IntegerGenerator extends SchemaGenerator
{
    private int $minValue = PHP_INT_MIN;
    private int $maxValue = PHP_INT_MAX;

    public function minValue(int $minValue): self
    {
        if ($minValue > $this->maxValue) {
            throw new InvalidArgumentException('minValue cannot be greater than maxValue.');
        }

        $this->minValue = $minValue;

        return $this;
    }

    public function maxValue(int $maxValue): self
    {
        if ($maxValue < $this->minValue) {
            throw new InvalidArgumentException('maxValue cannot be less than minValue.');
        }

        $this->maxValue = $maxValue;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'integer',
            'min_value' => $this->minValue,
            'max_value' => $this->maxValue,
        ];
    }

    protected function parse(mixed $value): mixed
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Expected integer-compatible generated value, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
