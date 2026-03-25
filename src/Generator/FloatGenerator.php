<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class FloatGenerator extends SchemaGenerator
{
    private const float FLOAT32_MIN = -3.4028234663852886E+38;
    private const float FLOAT32_MAX = 3.4028234663852886E+38;

    private ?float $minValue = null;
    private ?float $maxValue = null;
    private bool $excludeMin = false;
    private bool $excludeMax = false;
    private ?bool $allowNan = null;
    private ?bool $allowInfinity = null;
    private int $width = 64;

    public function minValue(float $minValue): self
    {
        if ($this->maxValue !== null && $minValue > $this->maxValue) {
            throw new InvalidArgumentException('minValue cannot be greater than maxValue.');
        }

        if ($this->allowNan === true) {
            throw new InvalidArgumentException('allowNan cannot be true when bounds are set.');
        }

        $this->minValue = $minValue;

        if ($this->allowInfinity === true && $this->maxValue !== null) {
            throw new InvalidArgumentException('allowInfinity cannot be true when both bounds are set.');
        }

        return $this;
    }

    public function maxValue(float $maxValue): self
    {
        if ($this->minValue !== null && $maxValue < $this->minValue) {
            throw new InvalidArgumentException('maxValue cannot be less than minValue.');
        }

        if ($this->allowNan === true) {
            throw new InvalidArgumentException('allowNan cannot be true when bounds are set.');
        }

        $this->maxValue = $maxValue;

        if ($this->allowInfinity === true && $this->minValue !== null) {
            throw new InvalidArgumentException('allowInfinity cannot be true when both bounds are set.');
        }

        return $this;
    }

    public function excludeMin(bool $excludeMin = true): self
    {
        $this->excludeMin = $excludeMin;

        return $this;
    }

    public function excludeMax(bool $excludeMax = true): self
    {
        $this->excludeMax = $excludeMax;

        return $this;
    }

    public function allowNan(bool $allowNan = true): self
    {
        if ($allowNan && ($this->minValue !== null || $this->maxValue !== null)) {
            throw new InvalidArgumentException('allowNan cannot be true when bounds are set.');
        }

        $this->allowNan = $allowNan;

        return $this;
    }

    public function allowInfinity(bool $allowInfinity = true): self
    {
        if ($allowInfinity && $this->minValue !== null && $this->maxValue !== null) {
            throw new InvalidArgumentException('allowInfinity cannot be true when both bounds are set.');
        }

        $this->allowInfinity = $allowInfinity;

        return $this;
    }

    public function width(int $width): self
    {
        if ($width !== 32 && $width !== 64) {
            throw new InvalidArgumentException('Float width must be 32 or 64.');
        }

        $this->width = $width;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        $allowNan = $this->allowNan ?? ($this->minValue === null && $this->maxValue === null);
        $allowInfinity = $this->allowInfinity ?? ! ($this->minValue !== null && $this->maxValue !== null);

        if ($allowNan && ($this->minValue !== null || $this->maxValue !== null)) {
            throw new InvalidArgumentException('allowNan cannot be true when bounds are set.');
        }

        if ($allowInfinity && $this->minValue !== null && $this->maxValue !== null) {
            throw new InvalidArgumentException('allowInfinity cannot be true when both bounds are set.');
        }

        if ($this->minValue !== null && $this->maxValue !== null && $this->maxValue < $this->minValue) {
            throw new InvalidArgumentException('maxValue cannot be less than minValue.');
        }

        $schema = [
            'type' => 'float',
            'exclude_min' => $this->excludeMin,
            'exclude_max' => $this->excludeMax,
            'allow_nan' => $allowNan,
            'allow_infinity' => $allowInfinity,
            'width' => $this->width,
        ];

        $minValue = $this->minValue;
        $maxValue = $this->maxValue;

        if (! $allowNan && ! $allowInfinity) {
            $minValue ??= $this->finiteMin();
            $maxValue ??= $this->finiteMax();
        }

        if ($minValue !== null) {
            $schema['min_value'] = $minValue;
        }

        if ($maxValue !== null) {
            $schema['max_value'] = $maxValue;
        }

        return $schema;
    }

    protected function parse(mixed $value): mixed
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'Expected float-compatible generated value, got %s.',
                get_debug_type($value),
            ));
        }

        return (float) $value;
    }

    private function finiteMin(): float
    {
        return $this->width === 32 ? self::FLOAT32_MIN : -PHP_FLOAT_MAX;
    }

    private function finiteMax(): float
    {
        return $this->width === 32 ? self::FLOAT32_MAX : PHP_FLOAT_MAX;
    }
}
