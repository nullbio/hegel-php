<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Closure;
use Hegel\TestCase;

final class Rule
{
    private Closure $apply;

    public function __construct(
        private string $name,
        callable $apply,
    ) {
        $this->apply = $apply instanceof Closure ? $apply : Closure::fromCallable($apply);
    }

    public static function new(string $name, callable $apply): self
    {
        return new self($name, $apply);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function apply(TestCase $testCase): void
    {
        ($this->apply)($testCase);
    }
}
