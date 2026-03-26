<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\SpanLabel;
use Hegel\TestCase;

final class TupleGenerator extends AbstractGenerator
{
    /**
     * @param list<Generator> $elements
     */
    public function __construct(
        private array $elements,
    ) {
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::TUPLE);
        $result = [];

        foreach ($this->elements as $element) {
            $result[] = $element->draw($testCase);
        }

        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $basics = [];

        foreach ($this->elements as $element) {
            $basic = $element->basic();

            if ($basic === null) {
                return null;
            }

            $basics[] = $basic;
        }

        return new BasicGeneratorDefinition(
            [
                'type' => 'tuple',
                'elements' => array_map(
                    static fn (BasicGeneratorDefinition $basic): array => $basic->schema(),
                    $basics,
                ),
            ],
            static function (mixed $value) use ($basics): array {
                $items = GeneratorValue::expectTuple($value, 'tuple generator value', count($basics));

                return array_map(
                    static fn (mixed $item, BasicGeneratorDefinition $basic): mixed => $basic->parse($item),
                    $items,
                    $basics,
                );
            },
        );
    }
}
