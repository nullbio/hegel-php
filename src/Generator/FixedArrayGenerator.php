<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\SpanLabel;
use Hegel\TestCase;
use InvalidArgumentException;

final class FixedArrayGenerator extends AbstractGenerator
{
    public function __construct(
        private Generator $elements,
        private int $size,
    ) {
        if ($this->size < 0) {
            throw new InvalidArgumentException('fixed array size must be greater than or equal to zero.');
        }
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::TUPLE);
        $result = [];

        for ($index = 0; $index < $this->size; $index++) {
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

        $size = $this->size;

        return new BasicGeneratorDefinition(
            [
                'type' => 'tuple',
                'elements' => array_fill(0, $size, $elements->schema()),
            ],
            static function (mixed $value) use ($elements, $size): array {
                $items = GeneratorValue::expectTuple($value, 'fixed array generator value', $size);

                return array_map(
                    static fn (mixed $item): mixed => $elements->parse($item),
                    $items,
                );
            },
        );
    }
}
