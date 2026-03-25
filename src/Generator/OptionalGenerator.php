<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\SpanLabel;
use Hegel\TestCase;
use RuntimeException;

final class OptionalGenerator extends AbstractGenerator
{
    public function __construct(
        private Generator $inner,
    ) {
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::OPTIONAL);
        $isPresent = (new BooleanGenerator())->draw($testCase);
        $result = $isPresent ? $this->inner->draw($testCase) : null;
        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $inner = $this->inner->basic();

        if ($inner === null) {
            return null;
        }

        return new BasicGeneratorDefinition(
            [
                'one_of' => [
                    [
                        'type' => 'tuple',
                        'elements' => [
                            ['const' => 0],
                            ['type' => 'null'],
                        ],
                    ],
                    [
                        'type' => 'tuple',
                        'elements' => [
                            ['const' => 1],
                            $inner->schema(),
                        ],
                    ],
                ],
            ],
            static function (mixed $value) use ($inner): mixed {
                [$tag, $payload] = GeneratorValue::expectTuple($value, 'optional tagged tuple', 2);

                return match (GeneratorValue::toInteger($tag, 'optional tag')) {
                    0 => null,
                    1 => $inner->parse($payload),
                    default => throw new RuntimeException('Generated optional tag is out of range.'),
                };
            },
        );
    }
}
