<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Closure;
use Hegel\TestCase;
use ReflectionFunction;

final class CompositeGenerator extends AbstractGenerator
{
    private Closure $composer;
    private int $label;

    public function __construct(callable $composer)
    {
        $this->composer = $composer instanceof Closure ? $composer : Closure::fromCallable($composer);
        $this->label = $this->computeLabel();
    }

    public function draw(TestCase $testCase): mixed
    {
        $child = clone $testCase;
        $child->startSpan($this->label);
        $result = ($this->composer)($child);
        $child->stopSpan(false);

        return $result;
    }

    private function computeLabel(): int
    {
        $reflection = new ReflectionFunction($this->composer);
        $source = sprintf(
            '%s:%d:%d',
            $reflection->getFileName() ?: 'closure',
            $reflection->getStartLine(),
            $reflection->getEndLine(),
        );

        return (int) hexdec(hash('fnv1a32', $source));
    }
}
