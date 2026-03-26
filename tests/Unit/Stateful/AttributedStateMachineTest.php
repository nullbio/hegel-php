<?php

declare(strict_types=1);

use Hegel\Exception\StatefulException;
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

it('discovers convention-based rules and invariants when no attributes are present', function (): void {
    $machine = new class () {
        public function ruleFirstStep(): void
        {
        }

        public function rule_second_step(): void
        {
        }

        public function invariantStillValid(): void
        {
        }
    };

    $attributed = AttributedStateMachine::from($machine);

    expect(array_map(
        static fn (\Hegel\Stateful\Rule $rule): string => $rule->name(),
        $attributed->rules(),
    ))->toBe(['firstStep', 'second_step'])
        ->and(array_map(
            static fn (\Hegel\Stateful\Invariant $invariant): string => $invariant->name(),
            $attributed->invariants(),
        ))->toBe(['stillValid']);
});

it('keeps explicit attributes authoritative over naming conventions', function (): void {
    $machine = new class () {
        #[RuleAttribute('attribute rule')]
        public function run(): void
        {
        }

        public function ruleImplicit(): void
        {
        }
    };

    $attributed = AttributedStateMachine::from($machine);

    expect(array_map(
        static fn (\Hegel\Stateful\Rule $rule): string => $rule->name(),
        $attributed->rules(),
    ))->toBe(['attribute rule']);
});

it('raises stateful exceptions for invalid convention-discovered methods', function (): void {
    $machine = new class () {
        public static function ruleBroken(): void
        {
        }
    };

    expect(static fn (): AttributedStateMachine => AttributedStateMachine::from($machine))
        ->toThrow(StatefulException::class, 'must not be static');
});
