<?php

declare(strict_types=1);

use Hegel\Stateful\AttributedStateMachine;
use Hegel\Stateful\Attributes\Invariant as InvariantAttribute;
use Hegel\Stateful\Attributes\Rule as RuleAttribute;

it('discovers attributed rules and invariants with custom names', function (): void {
    $machine = new class () {
        #[RuleAttribute]
        public function firstRule(): void
        {
        }

        #[RuleAttribute('second rule')]
        public function secondRule(): void
        {
        }

        #[InvariantAttribute('still valid')]
        public function checkInvariant(): void
        {
        }
    };

    $attributed = AttributedStateMachine::from($machine);

    expect(array_map(
        static fn (\Hegel\Stateful\Rule $rule): string => $rule->name(),
        $attributed->rules(),
    ))->toBe(['firstRule', 'second rule'])
        ->and(array_map(
            static fn (\Hegel\Stateful\Invariant $invariant): string => $invariant->name(),
            $attributed->invariants(),
        ))->toBe(['still valid']);
});

it('rejects attributed methods with unsupported signatures', function (): void {
    $machine = new class () {
        #[RuleAttribute]
        public function badRule(string $value): void
        {
            unset($value);
        }
    };

    expect(static fn (): AttributedStateMachine => AttributedStateMachine::from($machine))
        ->toThrow(RuntimeException::class, 'parameter must accept Hegel\\TestCase');
});
