<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Closure;
use Hegel\TestCase;

final class Invariant
{
    private Closure $check;

    public function __construct(
        private string $name,
        callable $check,
    ) {
        $this->check = $check instanceof Closure ? $check : Closure::fromCallable($check);
    }

    public static function new(string $name, callable $check): self
    {
        return new self($name, $check);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(TestCase $testCase): void
    {
        ($this->check)($testCase);
    }
}
