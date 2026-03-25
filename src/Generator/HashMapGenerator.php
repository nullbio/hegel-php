<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\SpanLabel;
use Hegel\TestCase;
use InvalidArgumentException;
use RuntimeException;

final class HashMapGenerator extends AbstractGenerator
{
    private int $minSize = 0;
    private ?int $maxSize = null;

    public function __construct(
        private Generator $keys,
        private Generator $values,
    ) {
    }

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

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::MAP);
        $max = $this->maxSize ?? 100;
        $length = GeneratorValue::toInteger(
            (new IntegerGenerator())->minValue($this->minSize)->maxValue($max)->draw($testCase),
            'hash map size',
        );

        $map = [];
        $maxAttempts = $length * 10;
        $attempts = 0;

        while (count($map) < $length && $attempts < $maxAttempts) {
            $testCase->startSpan(SpanLabel::MAP_ENTRY);
            $key = GeneratorValue::toArrayKey($this->keys->draw($testCase));

            if (! array_key_exists($key, $map)) {
                $map[$key] = $this->values->draw($testCase);
            }

            $testCase->stopSpan(false);
            $attempts++;
        }

        if (count($map) < $this->minSize) {
            throw new RuntimeException('Failed to generate enough unique map keys.');
        }

        $testCase->stopSpan(false);

        return $map;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $keys = $this->keys->basic();
        $values = $this->values->basic();

        if ($keys === null || $values === null) {
            return null;
        }

        $schema = [
            'type' => 'dict',
            'keys' => $keys->schema(),
            'values' => $values->schema(),
            'min_size' => $this->minSize,
        ];

        if ($this->maxSize !== null) {
            $schema['max_size'] = $this->maxSize;
        }

        return new BasicGeneratorDefinition(
            $schema,
            static function (mixed $value) use ($keys, $values): array {
                $entries = GeneratorValue::expectList($value, 'hash map generator value');
                $map = [];

                foreach ($entries as $entry) {
                    [$rawKey, $rawValue] = GeneratorValue::expectTuple($entry, 'hash map entry', 2);
                    $key = GeneratorValue::toArrayKey($keys->parse($rawKey));
                    $map[$key] = $values->parse($rawValue);
                }

                return $map;
            },
        );
    }
}
