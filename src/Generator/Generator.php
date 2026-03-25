<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\TestCase;

interface Generator
{
    public function draw(TestCase $testCase): mixed;

    public function basic(): ?BasicGeneratorDefinition;
}
