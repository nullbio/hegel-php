<?php

declare(strict_types=1);

namespace Hegel\Testing;

use Hegel\Generator\Generator as HegelGenerator;
use Hegel\Pest\SharedHegel;
use Hegel\Random\Randomizer;
use Hegel\Settings;
use Hegel\TestCase;
use LogicException;

/** @phpstan-require-extends \PHPUnit\Framework\TestCase */
trait InteractsWithHegel
{
    private ?TestCase $hegelTestCase = null;

    protected function hegel(callable $property, ?Settings $settings = null, ?string $databaseKey = null): void
    {
        $settings ??= new Settings();
        $databaseKey ??= $this->hegelDatabaseKey();

        SharedHegel::run(function (TestCase $testCase) use ($property): void {
            $previous = $this->hegelTestCase;
            $this->hegelTestCase = $testCase;

            try {
                $property($testCase);
            } finally {
                $this->hegelTestCase = $previous;
            }
        }, $settings, $databaseKey);
    }

    protected function draw(HegelGenerator $generator): mixed
    {
        return $this->activeHegelTestCase()->draw($generator);
    }

    protected function assume(bool $condition): void
    {
        $this->activeHegelTestCase()->assume($condition);
    }

    protected function note(string $message): void
    {
        $this->activeHegelTestCase()->note($message);
    }

    protected function randomizer(bool $useTrueRandom = false): Randomizer
    {
        return $this->activeHegelTestCase()->randomizer($useTrueRandom);
    }

    private function activeHegelTestCase(): TestCase
    {
        if ($this->hegelTestCase instanceof TestCase) {
            return $this->hegelTestCase;
        }

        throw new LogicException('Hegel helpers are only available while a hegel() property is running.');
    }

    private function hegelDatabaseKey(): string
    {
        $name = (string) $this->name();

        return $this::class . '::' . $name;
    }
}
