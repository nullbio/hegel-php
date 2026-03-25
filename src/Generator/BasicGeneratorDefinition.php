<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Closure;
use Hegel\TestCase;

final class BasicGeneratorDefinition
{
    private Closure $parser;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private array $schema,
        callable $parser,
    ) {
        $this->parser = $parser instanceof Closure ? $parser : Closure::fromCallable($parser);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    public function parse(mixed $value): mixed
    {
        return ($this->parser)($value);
    }

    public function draw(TestCase $testCase): mixed
    {
        return $this->parse($testCase->generate($this->schema));
    }

    public function map(callable $mapper): self
    {
        return new self(
            $this->schema,
            fn (mixed $value): mixed => $mapper($this->parse($value)),
        );
    }
}
