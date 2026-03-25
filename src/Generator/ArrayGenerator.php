<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\Collection;
use Hegel\SpanLabel;
use Hegel\TestCase;
use InvalidArgumentException;

final class ArrayGenerator extends AbstractGenerator
{
    private int $minSize = 0;
    private ?int $maxSize = null;
    private bool $unique = false;

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

    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;

        return $this;
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::LIST);
        $collection = new Collection($testCase, 'composite_list', $this->minSize, $this->maxSize);
        $result = [];

        while ($collection->more()) {
            $result[] = $this->elements->draw($testCase);
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
            'unique' => $this->unique,
            'elements' => $elements->schema(),
            'min_size' => $this->minSize,
        ];

        if ($this->maxSize !== null) {
            $schema['max_size'] = $this->maxSize;
        }

        return new BasicGeneratorDefinition(
            $schema,
            static function (mixed $value) use ($elements): array {
                $items = GeneratorValue::expectList($value, 'array generator value');

                return array_map(
                    static fn (mixed $item): mixed => $elements->parse($item),
                    $items,
                );
            },
        );
    }
}
