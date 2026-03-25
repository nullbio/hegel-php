<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Closure;
use Hegel\SpanLabel;
use Hegel\TestCase;

final class FilteredGenerator extends AbstractGenerator
{
    private Closure $predicate;

    public function __construct(
        private Generator $source,
        callable $predicate,
    ) {
        $this->predicate = $predicate instanceof Closure ? $predicate : Closure::fromCallable($predicate);
    }

    public function draw(TestCase $testCase): mixed
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $testCase->startSpan(SpanLabel::FILTER);
            $value = $this->source->draw($testCase);

            if (($this->predicate)($value)) {
                $testCase->stopSpan(false);

                return $value;
            }

            $testCase->stopSpan(true);
        }

        $testCase->assume(false);

        return null;
    }
}
