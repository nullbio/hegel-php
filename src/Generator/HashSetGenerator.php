<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\Exception\GenerationException;
use Hegel\SpanLabel;
use Hegel\TestCase;
use InvalidArgumentException;

final class HashSetGenerator extends AbstractGenerator
{
    private int $minSize = 0;
    private ?int $maxSize = null;

    public function __construct(
        private Generator $elements,
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

        $testCase->startSpan(SpanLabel::SET);
        $max = $this->maxSize ?? 100;
        $length = GeneratorValue::toInteger(
            (new IntegerGenerator())->minValue($this->minSize)->maxValue($max)->draw($testCase),
            'hash set size',
        );

        $seen = [];
        $result = [];
        $attempts = 0;
        $maxAttempts = $length * 10;

        while (count($result) < $length && $attempts < $maxAttempts) {
            $testCase->startSpan(SpanLabel::SET_ELEMENT);
            $value = $this->elements->draw($testCase);
            $key = self::uniqueKey($value);

            if (! array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $result[] = $value;
            }

            $testCase->stopSpan(false);
            $attempts++;
        }

        if (count($result) < $this->minSize) {
            throw new GenerationException('Failed to generate enough unique set values.');
        }

        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $elements = $this->elements->basic();

        if ($elements === null) {
            return null;
        }

        $schema = [
            'type' => 'list',
            'unique' => true,
            'elements' => $elements->schema(),
            'min_size' => $this->minSize,
        ];

        if ($this->maxSize !== null) {
            $schema['max_size'] = $this->maxSize;
        }

        return new BasicGeneratorDefinition(
            $schema,
            static function (mixed $value) use ($elements): array {
                $items = GeneratorValue::expectList($value, 'hash set generator value');

                return array_map(
                    static fn (mixed $item): mixed => $elements->parse($item),
                    $items,
                );
            },
        );
    }

    private static function uniqueKey(mixed $value): string
    {
        return serialize($value);
    }
}
