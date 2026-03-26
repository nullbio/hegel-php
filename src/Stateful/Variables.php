<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Hegel\Exception\StatefulException;
use Hegel\StopTestException;
use Hegel\TestCase;
use Hegel\TestCaseControlFlow;

final class Variables
{
    /** @var array<int, mixed> */
    private array $values = [];

    public function __construct(
        private int $poolId,
        private TestCase $testCase,
    ) {
    }

    public function empty(): bool
    {
        return $this->values === [];
    }

    public function add(mixed $value): void
    {
        $variableId = $this->expectInteger(
            $this->sendRequest('pool_add', ['pool_id' => $this->poolId]),
            'variable id',
        );

        if (array_key_exists($variableId, $this->values)) {
            throw new StatefulException('Unexpected duplicate variable id.');
        }

        $this->values[$variableId] = $value;
    }

    public function draw(): mixed
    {
        $this->testCase->assume(! $this->empty());
        $variableId = $this->poolGenerate(false);

        if (! array_key_exists($variableId, $this->values)) {
            throw new StatefulException(sprintf('Unknown variable id %d.', $variableId));
        }

        return $this->values[$variableId];
    }

    public function consume(): mixed
    {
        $this->testCase->assume(! $this->empty());
        $variableId = $this->poolGenerate(true);

        if (! array_key_exists($variableId, $this->values)) {
            throw new StatefulException(sprintf('Unknown variable id %d.', $variableId));
        }

        $value = $this->values[$variableId];
        unset($this->values[$variableId]);

        return $value;
    }

    private function poolGenerate(bool $consume): int
    {
        return $this->expectInteger(
            $this->sendRequest('pool_generate', [
                'pool_id' => $this->poolId,
                'consume' => $consume,
            ]),
            'variable id',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendRequest(string $command, array $payload = []): mixed
    {
        try {
            return $this->testCase->sendRequest($command, $payload);
        } catch (StopTestException) {
            throw new TestCaseControlFlow(TestCase::STOP_TEST_STRING);
        }
    }

    private function expectInteger(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new StatefulException(sprintf(
            'Expected integer %s response, got %s.',
            $context,
            get_debug_type($value),
        ));
    }
}
