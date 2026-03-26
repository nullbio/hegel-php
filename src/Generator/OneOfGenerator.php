<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\Exception\GenerationException;
use Hegel\SpanLabel;
use Hegel\TestCase;
use InvalidArgumentException;

final class OneOfGenerator extends AbstractGenerator
{
    /**
     * @param list<Generator> $generators
     */
    public function __construct(
        private array $generators,
    ) {
        if ($generators === []) {
            throw new InvalidArgumentException('oneOf requires at least one generator.');
        }
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::ONE_OF);
        $index = GeneratorValue::toInteger(
            (new IntegerGenerator())->minValue(0)->maxValue(count($this->generators) - 1)->draw($testCase),
            'oneOf branch index',
        );
        $result = $this->generators[$index]->draw($testCase);
        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $basics = [];

        foreach ($this->generators as $generator) {
            $basic = $generator->basic();

            if ($basic === null) {
                return null;
            }

            $basics[] = $basic;
        }

        $schema = [
            'one_of' => array_map(
                static fn (BasicGeneratorDefinition $basic, int $index): array => [
                    'type' => 'tuple',
                    'elements' => [
                        ['const' => $index],
                        $basic->schema(),
                    ],
                ],
                $basics,
                array_keys($basics),
            ),
        ];

        return new BasicGeneratorDefinition(
            $schema,
            static function (mixed $value) use ($basics): mixed {
                [$tag, $payload] = GeneratorValue::expectTuple($value, 'oneOf tagged tuple', 2);
                $index = GeneratorValue::toInteger($tag, 'oneOf tag');

                if (! array_key_exists($index, $basics)) {
                    throw new GenerationException(sprintf('Generated oneOf tag %d is out of range.', $index));
                }

                return $basics[$index]->parse($payload);
            },
        );
    }
}
