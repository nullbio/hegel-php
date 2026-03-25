<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\TestCase;

final class JustGenerator extends AbstractGenerator
{
    public function __construct(
        private mixed $value,
    ) {
    }

    public function draw(TestCase $testCase): mixed
    {
        unset($testCase);

        return GeneratorValue::copy($this->value);
    }

    public function basic(): BasicGeneratorDefinition
    {
        return new BasicGeneratorDefinition(
            ['const' => null],
            fn (mixed $raw): mixed => GeneratorValue::copy($this->value),
        );
    }
}
