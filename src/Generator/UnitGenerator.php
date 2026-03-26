<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\TestCase;

final class UnitGenerator extends AbstractGenerator
{
    public function draw(TestCase $testCase): mixed
    {
        unset($testCase);

        return null;
    }

    public function basic(): BasicGeneratorDefinition
    {
        return new BasicGeneratorDefinition(
            ['const' => null],
            static fn (mixed $value): mixed => null,
        );
    }
}
