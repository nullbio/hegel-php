<?php

declare(strict_types=1);

namespace Hegel\Stateful;

interface StateMachine
{
    /**
     * @return list<Rule>
     */
    public function rules(): array;

    /**
     * @return list<Invariant>
     */
    public function invariants(): array;
}
