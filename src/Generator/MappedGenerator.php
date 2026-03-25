<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Closure;
use Hegel\SpanLabel;
use Hegel\TestCase;

final class MappedGenerator extends AbstractGenerator
{
    private Closure $mapper;

    public function __construct(
        private Generator $source,
        callable $mapper,
    ) {
        $this->mapper = $mapper instanceof Closure ? $mapper : Closure::fromCallable($mapper);
    }

    public function draw(TestCase $testCase): mixed
    {
        $basic = $this->basic();

        if ($basic !== null) {
            return $basic->draw($testCase);
        }

        $testCase->startSpan(SpanLabel::MAPPED);
        $result = ($this->mapper)($this->source->draw($testCase));
        $testCase->stopSpan(false);

        return $result;
    }

    public function basic(): ?BasicGeneratorDefinition
    {
        $source = $this->source->basic();

        if ($source === null) {
            return null;
        }

        return $source->map($this->mapper);
    }
}
