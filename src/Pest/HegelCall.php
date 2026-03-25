<?php

declare(strict_types=1);

namespace Hegel\Pest;

use Hegel\HealthCheck;
use Hegel\Settings;
use Hegel\Verbosity;
use InvalidArgumentException;
use Pest\PendingCalls\TestCall;
use RuntimeException;

/**
 * @mixin TestCall
 */
final class HegelCall
{
    public function __construct(
        private TestCall $testCall,
        private HegelCallState $state,
    ) {
    }

    public static function create(string $description, callable $property, string $filename): self
    {
        $state = new HegelCallState(new Settings(), $filename);

        $testCall = test($description, function () use ($property, $state): void {
            $printableDescription = method_exists($this, 'getPrintableTestCaseMethodName')
                ? (string) $this->getPrintableTestCaseMethodName()
                : null;

            SharedHegel::run(
                $property,
                clone $state->settings,
                $state->databaseKey($printableDescription),
            );
        });

        if (! $testCall instanceof TestCall) {
            throw new RuntimeException('Pest did not return a TestCall for hegel().');
        }

        return new self($testCall, $state);
    }

    public function testCases(int $testCases): self
    {
        $this->state->settings->testCases($testCases);

        return $this;
    }

    public function verbosity(Verbosity|string $verbosity): self
    {
        $this->state->settings->verbosity(self::normalizeVerbosity($verbosity));

        return $this;
    }

    public function seed(?int $seed): self
    {
        $this->state->settings->seed($seed);

        return $this;
    }

    public function derandomize(bool $derandomize = true): self
    {
        $this->state->settings->derandomize($derandomize);

        return $this;
    }

    public function disableDatabase(): self
    {
        $this->state->settings->disableDatabase();

        return $this;
    }

    public function useDefaultDatabase(): self
    {
        $this->state->settings->useDefaultDatabase();

        return $this;
    }

    public function databasePath(string $databasePath): self
    {
        $this->state->settings->databasePath($databasePath);

        return $this;
    }

    public function suppressHealthCheck(HealthCheck|string ...$checks): self
    {
        $this->state->settings->suppressHealthCheck(
            ...array_map(self::normalizeHealthCheck(...), $checks),
        );

        return $this;
    }

    public function settings(Settings $settings): self
    {
        $this->state->settings = $settings;

        return $this;
    }

    public function databaseKey(?string $databaseKey): self
    {
        $this->state->databaseKeyOverride = $databaseKey;
        $this->state->hasDatabaseKeyOverride = true;

        return $this;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->testCall->{$name}(...$arguments);

        if ($result instanceof TestCall) {
            return $this;
        }

        return $result;
    }

    public static function normalizeFilename(string $filename): string
    {
        $cwd = getcwd();

        if ($cwd !== false && str_starts_with($filename, $cwd . DIRECTORY_SEPARATOR)) {
            $filename = substr($filename, strlen($cwd) + 1);
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $filename);
    }

    private static function normalizeVerbosity(Verbosity|string $verbosity): Verbosity
    {
        if ($verbosity instanceof Verbosity) {
            return $verbosity;
        }

        return Verbosity::tryFrom($verbosity)
            ?? throw new InvalidArgumentException(sprintf('Unknown verbosity level: %s', $verbosity));
    }

    private static function normalizeHealthCheck(HealthCheck|string $check): HealthCheck
    {
        if ($check instanceof HealthCheck) {
            return $check;
        }

        return HealthCheck::tryFrom($check)
            ?? throw new InvalidArgumentException(sprintf('Unknown health check: %s', $check));
    }
}

final class HegelCallState
{
    public ?string $databaseKeyOverride = null;
    public bool $hasDatabaseKeyOverride = false;

    public function __construct(
        public Settings $settings,
        public string $filename,
    ) {
    }

    public function databaseKey(?string $description): ?string
    {
        if ($this->hasDatabaseKeyOverride) {
            return $this->databaseKeyOverride;
        }

        $description ??= 'unknown test';

        return HegelCall::normalizeFilename($this->filename) . '::' . $description;
    }
}
