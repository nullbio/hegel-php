<?php

declare(strict_types=1);

namespace Hegel;

use CBOR\ByteStringObject;

final class Settings
{
    private const int DATABASE_UNSET = 0;
    private const int DATABASE_DISABLED = 1;
    private const int DATABASE_PATH = 2;

    private int $testCases;
    private Verbosity $verbosity;
    private ?int $seed;
    private bool $derandomize;
    private int $databaseMode;
    private ?string $databasePath;
    /** @var list<HealthCheck> */
    private array $suppressHealthChecks = [];

    public function __construct()
    {
        $inCi = self::isInCi();

        $this->testCases = 100;
        $this->verbosity = Verbosity::Normal;
        $this->seed = null;
        $this->derandomize = $inCi;
        $this->databaseMode = $inCi ? self::DATABASE_DISABLED : self::DATABASE_UNSET;
        $this->databasePath = null;
    }

    public function testCases(int $testCases): self
    {
        $this->testCases = $testCases;

        return $this;
    }

    public function verbosity(Verbosity $verbosity): self
    {
        $this->verbosity = $verbosity;

        return $this;
    }

    public function seed(?int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    public function derandomize(bool $derandomize): self
    {
        $this->derandomize = $derandomize;

        return $this;
    }

    public function disableDatabase(): self
    {
        $this->databaseMode = self::DATABASE_DISABLED;
        $this->databasePath = null;

        return $this;
    }

    public function useDefaultDatabase(): self
    {
        $this->databaseMode = self::DATABASE_UNSET;
        $this->databasePath = null;

        return $this;
    }

    public function databasePath(string $databasePath): self
    {
        $this->databaseMode = self::DATABASE_PATH;
        $this->databasePath = $databasePath;

        return $this;
    }

    public function suppressHealthCheck(HealthCheck ...$checks): self
    {
        $this->suppressHealthChecks = array_values($checks);

        return $this;
    }

    public function verbosityLevel(): Verbosity
    {
        return $this->verbosity;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRunTestPayload(int $channelId, ?string $databaseKey): array
    {
        $payload = [
            'command' => 'run_test',
            'test_cases' => $this->testCases,
            'seed' => $this->seed,
            'channel_id' => $channelId,
            'database_key' => $databaseKey === null ? null : ByteStringObject::create($databaseKey),
            'derandomize' => $this->derandomize,
        ];

        if ($this->databaseMode === self::DATABASE_DISABLED) {
            $payload['database'] = null;
        }

        if ($this->databaseMode === self::DATABASE_PATH && $this->databasePath !== null) {
            $payload['database'] = $this->databasePath;
        }

        if ($this->suppressHealthChecks !== []) {
            $payload['suppress_health_check'] = array_map(
                static fn (HealthCheck $check): string => $check->value,
                $this->suppressHealthChecks,
            );
        }

        return $payload;
    }

    private static function isInCi(): bool
    {
        $checks = [
            ['CI', null],
            ['TF_BUILD', 'true'],
            ['BUILDKITE', 'true'],
            ['CIRCLECI', 'true'],
            ['CIRRUS_CI', 'true'],
            ['CODEBUILD_BUILD_ID', null],
            ['GITHUB_ACTIONS', 'true'],
            ['GITLAB_CI', null],
            ['HEROKU_TEST_RUN_ID', null],
            ['TEAMCITY_VERSION', null],
            ['bamboo.buildKey', null],
        ];

        foreach ($checks as [$name, $expected]) {
            $value = getenv($name);

            if ($expected === null && $value !== false) {
                return true;
            }

            if ($expected !== null && $value === $expected) {
                return true;
            }
        }

        return false;
    }
}
