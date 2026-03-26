<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Closure;
use Hegel\Exception\GenerationException;
use Hegel\SpanLabel;
use Hegel\TestCase;

final class FlatMappedGenerator extends AbstractGenerator
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
        $testCase->startSpan(SpanLabel::FLAT_MAP);
        $next = ($this->mapper)($this->source->draw($testCase));

        if (! $next instanceof Generator) {
            throw new GenerationException(sprintf(
                'flatMap callback must return a generator, got %s.',
                get_debug_type($next),
            ));
        }

        $result = $next->draw($testCase);
        $testCase->stopSpan(false);

        return $result;
    }
}
