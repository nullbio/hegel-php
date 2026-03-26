<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Hegel\Exception\StatefulException;
use Hegel\Generators;
use Hegel\StopTestException;
use Hegel\TestCase;
use Hegel\TestCaseControlFlow;
use Throwable;

final class StatefulRunner
{
    private const int MAX_STEPS = 50;
    private const int MAX_ATTEMPTS_MULTIPLIER = 10;
    private const int MAX_INITIAL_ATTEMPTS = 1000;

    private function __construct()
    {
    }

    /**
     * @param object $machine
     */
    public static function run(object $machine, TestCase $testCase): void
    {
        $machineDefinition = $machine instanceof StateMachine
            ? $machine
            : AttributedStateMachine::from($machine);

        $rules = $machineDefinition->rules();

        if ($rules === []) {
            throw new StatefulException('Cannot run a machine with no rules.');
        }

        $ruleIndex = Generators::integers()->minValue(0)->maxValue(count($rules) - 1);

        $testCase->note('Initial invariant check.');
        self::checkInvariants($machineDefinition, $testCase);

        $unboundedStepCap = self::toInteger(
            $testCase->drawSilent(Generators::integers()->minValue(1)),
            'stateful step cap',
        );
        $stepCap = min($unboundedStepCap, self::MAX_STEPS);

        $stepsRunSuccessfully = 0;
        $stepsAttempted = 0;
        $step = 0;

        while (
            $stepsRunSuccessfully < $stepCap
            && (
                $stepsAttempted < self::MAX_ATTEMPTS_MULTIPLIER * $stepCap
                || ($stepsRunSuccessfully === 0 && $stepsAttempted < self::MAX_INITIAL_ATTEMPTS)
            )
        ) {
            $step++;
            $selectedRule = $rules[self::toInteger($testCase->drawSilent($ruleIndex), 'stateful rule index')];
            $testCase->note(sprintf('Step %d: %s', $step, $selectedRule->name()));

            $ruleTestCase = $testCase->child(2);
            $stepsAttempted++;

            try {
                $selectedRule->apply($ruleTestCase);
                $stepsRunSuccessfully++;
                self::checkInvariants($machineDefinition, $testCase);
            } catch (StopTestException $exception) {
                throw $exception;
            } catch (TestCaseControlFlow $exception) {
                $message = $exception->getMessage();

                if ($message === TestCase::STOP_TEST_STRING) {
                    break;
                }

                if ($message !== TestCase::ASSUME_FAIL_STRING) {
                    $testCase->note('Rule stopped early due to violated assumption.');
                    throw $exception;
                }
            } catch (Throwable $exception) {
                $testCase->note('Rule stopped early due to violated assumption.');
                throw $exception;
            }
        }
    }

    public static function variables(TestCase $testCase): Variables
    {
        $poolId = self::toInteger($testCase->sendRequest('new_pool'), 'pool id');

        return new Variables($poolId, clone $testCase);
    }

    private static function checkInvariants(StateMachine $machine, TestCase $testCase): void
    {
        foreach ($machine->invariants() as $invariant) {
            $invariant->check($testCase->child(2));
        }
    }

    private static function toInteger(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new StatefulException(sprintf('Expected integer for %s, got %s.', $context, get_debug_type($value)));
    }
}
