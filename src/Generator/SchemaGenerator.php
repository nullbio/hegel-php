<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\TestCase;

abstract class SchemaGenerator extends AbstractGenerator
{
    final public function draw(TestCase $testCase): mixed
    {
        return $this->basic()->draw($testCase);
    }

    final public function basic(): BasicGeneratorDefinition
    {
        return new BasicGeneratorDefinition(
            $this->schema(),
            fn (mixed $value): mixed => $this->parse($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function schema(): array;

    protected function parse(mixed $value): mixed
    {
        return $value;
    }
}
