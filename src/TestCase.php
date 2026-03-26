<?php

declare(strict_types=1);

namespace Hegel;

use Hegel\Exception\ProtocolException;
use Hegel\Generator\Generator as HegelGenerator;
use Hegel\Protocol\Channel;
use Hegel\Protocol\Connection;
use Hegel\Random\Randomizer;
use Throwable;

final class TestCase
{
    public const string ASSUME_FAIL_STRING = '__HEGEL_ASSUME_FAIL';
    public const string STOP_TEST_STRING = '__HEGEL_STOP_TEST';

    private int $spanDepth = 0;
    private int $drawCount = 0;
    private int $indent = 0;

    public function __construct(
        private TestCaseState $state,
    ) {
    }

    public static function create(
        Connection $connection,
        Channel $channel,
        Verbosity $verbosity,
        bool $isFinal,
    ): self {
        return new self(new TestCaseState($connection, $channel, $verbosity, $isFinal));
    }

    public function __clone()
    {
    }

    public function draw(HegelGenerator $generator): mixed
    {
        $value = $generator->draw($this);

        if ($this->spanDepth === 0) {
            $this->recordDraw($value);
        }

        return $value;
    }

    public function drawSilent(HegelGenerator $generator): mixed
    {
        return $generator->draw($this);
    }

    public function assume(bool $condition): void
    {
        if (! $condition) {
            throw new TestCaseControlFlow(self::ASSUME_FAIL_STRING);
        }
    }

    public function note(string $message): void
    {
        if (! $this->state->isFinal) {
            return;
        }

        $this->appendOutput($message);
    }

    public function randomizer(bool $useTrueRandom = false): Randomizer
    {
        return new Randomizer(clone $this, $useTrueRandom);
    }

    public function child(int $extraIndent): self
    {
        $child = clone $this;
        $child->spanDepth = 0;
        $child->drawCount = 0;
        $child->indent += $extraIndent;

        return $child;
    }

    public function startSpan(int $label): void
    {
        $this->spanDepth++;

        try {
            $this->sendRequest('start_span', ['label' => $label]);
        } catch (StopTestException) {
            $this->spanDepth--;
            throw new TestCaseControlFlow(self::STOP_TEST_STRING);
        }
    }

    public function stopSpan(bool $discard): void
    {
        if ($this->spanDepth <= 0) {
            throw new ProtocolException('Cannot stop a span when no span is active.');
        }

        $this->spanDepth--;

        try {
            $this->sendRequest('stop_span', ['discard' => $discard]);
        } catch (StopTestException) {
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendRequest(string $command, array $payload = []): mixed
    {
        if ($this->state->testAborted) {
            throw new StopTestException();
        }

        $request = ['command' => $command] + $payload;

        try {
            return $this->state->channel->requestCbor($request);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();

            if (
                str_contains($message, 'overflow')
                || str_contains($message, 'StopTest')
                || str_contains($message, 'channel is closed')
                || str_contains($message, 'FlakyStrategyDefinition')
                || str_contains($message, 'FlakyReplay')
            ) {
                $this->state->channel->markClosed();
                $this->state->testAborted = true;
                throw new StopTestException(previous: $exception);
            }

            if ($this->state->connection->serverHasExited()) {
                throw new ProtocolException(Connection::SERVER_EXITED_MESSAGE, 0, $exception);
            }

            throw new ProtocolException(sprintf('Failed to communicate with Hegel: %s', $message), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function generate(array $schema): mixed
    {
        try {
            return $this->sendRequest('generate', ['schema' => $schema]);
        } catch (StopTestException) {
            throw new TestCaseControlFlow(self::STOP_TEST_STRING);
        }
    }

    public function sendMarkComplete(string $status, ?string $origin): void
    {
        try {
            $this->state->channel->requestCbor([
                'command' => 'mark_complete',
                'status' => $status,
                'origin' => $origin,
            ]);
        } catch (\RuntimeException) {
        }

        try {
            $this->state->channel->close();
        } catch (\RuntimeException) {
        }
    }

    public function testAborted(): bool
    {
        return $this->state->testAborted;
    }

    /**
     * @return list<string>
     */
    public function outputLines(): array
    {
        return $this->state->outputLines;
    }

    private function recordDraw(mixed $value): void
    {
        if (! $this->state->isFinal) {
            return;
        }

        $this->drawCount++;

        $this->appendOutput(sprintf('Draw %d: %s', $this->drawCount, $this->stringify($value)));
    }

    private function appendOutput(string $message): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $message) ?: [$message] as $line) {
            $this->state->outputLines[] = sprintf('%s%s', str_repeat(' ', $this->indent), $line);
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return var_export($value, true);
        }

        $encoded = json_encode($value);

        if ($encoded !== false) {
            return $encoded;
        }

        return get_debug_type($value);
    }
}

final class StopTestException extends ProtocolException
{
    public function __construct(int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct('Server ran out of data (StopTest)', $code, $previous);
    }
}

final class TestCaseControlFlow extends ProtocolException
{
}

final class TestCaseState
{
    public bool $testAborted = false;
    /** @var list<string> */
    public array $outputLines = [];

    public function __construct(
        public readonly Connection $connection,
        public readonly Channel $channel,
        public readonly Verbosity $verbosity,
        public readonly bool $isFinal,
    ) {
    }
}
