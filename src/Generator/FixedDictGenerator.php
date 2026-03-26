<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\SpanLabel;
use Hegel\TestCase;

final class FixedDictGenerator extends AbstractGenerator
{
    /**
     * @param list<array{name: string, generator: Generator}> $fields
     */
    public function __construct(
        private array $fields,
    ) {
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::FIXED_DICT);
        $result = [];

        foreach ($this->fields as $field) {
            $result[$field['name']] = $field['generator']->draw($testCase);
        }

        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $basics = [];

        foreach ($this->fields as $field) {
            $basic = $field['generator']->basic();

            if ($basic === null) {
                return null;
            }

            $basics[] = $basic;
        }

        $schema = [
            'type' => 'tuple',
            'elements' => array_map(
                static fn (BasicGeneratorDefinition $basic): array => $basic->schema(),
                $basics,
            ),
        ];

        return new BasicGeneratorDefinition(
            $schema,
            function (mixed $value) use ($basics): array {
                $items = GeneratorValue::expectTuple($value, 'fixed dict tuple', count($this->fields));
                $result = [];

                foreach ($this->fields as $index => $field) {
                    $result[$field['name']] = $basics[$index]->parse($items[$index]);
                }

                return $result;
            },
        );
    }
}
