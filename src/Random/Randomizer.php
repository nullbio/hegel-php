<?php

declare(strict_types=1);

namespace Hegel\Random;

use Hegel\Generator\GeneratorValue;
use Hegel\Generators;
use Hegel\TestCase;
use Random\Engine\Mt19937;
use Random\IntervalBoundary;

final class Randomizer
{
    private ?\Random\Randomizer $native;

    public function __construct(
        private TestCase $testCase,
        bool $useTrueRandom = false,
    ) {
        if (! $useTrueRandom) {
            $this->native = null;

            return;
        }

        $seed = GeneratorValue::toInteger(
            $this->testCase->draw(Generators::integers()->minValue(0)->maxValue(PHP_INT_MAX)),
            'randomizer seed',
        );

        $this->native = new \Random\Randomizer(new Mt19937($seed));
    }

    public function nextInt(): int
    {
        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->nextInt();
        }

        return $this->getInt(PHP_INT_MIN, PHP_INT_MAX);
    }

    public function nextFloat(): float
    {
        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->nextFloat();
        }

        return $this->getFloat(0.0, 1.0);
    }

    public function getInt(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \ValueError(
                'Randomizer::getInt(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)',
            );
        }

        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->getInt($min, $max);
        }

        return GeneratorValue::toInteger(
            $this->testCase->draw(Generators::integers()->minValue($min)->maxValue($max)),
            'randomizer integer',
        );
    }

    public function getFloat(
        float $min,
        float $max,
        IntervalBoundary $boundary = IntervalBoundary::ClosedOpen,
    ): float {
        if ($max < $min) {
            throw new \ValueError(
                'Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)',
            );
        }

        if ($max === $min && $boundary !== IntervalBoundary::ClosedClosed) {
            throw new \ValueError(
                'Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)',
            );
        }

        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->getFloat($min, $max, $boundary);
        }

        if ($max === $min) {
            return $min;
        }

        $generator = Generators::floats()
            ->minValue($min)
            ->maxValue($max)
            ->allowNan(false)
            ->allowInfinity(false);

        match ($boundary) {
            IntervalBoundary::ClosedOpen => $generator->excludeMax(),
            IntervalBoundary::ClosedClosed => null,
            IntervalBoundary::OpenClosed => $generator->excludeMin(),
            IntervalBoundary::OpenOpen => $generator->excludeMin()->excludeMax(),
        };

        return $this->testCase->draw($generator);
    }

    public function getBytes(int $length): string
    {
        if ($length <= 0) {
            throw new \ValueError(
                'Randomizer::getBytes(): Argument #1 ($length) must be greater than 0',
            );
        }

        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->getBytes($length);
        }

        return $this->testCase->draw(Generators::binary()->minSize($length)->maxSize($length));
    }

    public function getBytesFromString(string $string, int $length): string
    {
        if ($string === '') {
            throw new \ValueError(
                'Randomizer::getBytesFromString(): Argument #1 ($string) must not be empty',
            );
        }

        if ($length <= 0) {
            throw new \ValueError(
                'Randomizer::getBytesFromString(): Argument #2 ($length) must be greater than 0',
            );
        }

        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->getBytesFromString($string, $length);
        }

        $result = '';
        $maxIndex = strlen($string) - 1;

        for ($index = 0; $index < $length; $index++) {
            $result .= $string[$this->getInt(0, $maxIndex)];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return list<mixed>
     */
    public function shuffleArray(array $array): array
    {
        if ($this->native instanceof \Random\Randomizer) {
            return array_values($this->native->shuffleArray($array));
        }

        $values = array_values($array);

        for ($index = count($values) - 1; $index > 0; $index--) {
            $swapIndex = $this->getInt(0, $index);
            [$values[$index], $values[$swapIndex]] = [$values[$swapIndex], $values[$index]];
        }

        return array_values($values);
    }

    public function shuffleBytes(string $bytes): string
    {
        if ($this->native instanceof \Random\Randomizer) {
            return $this->native->shuffleBytes($bytes);
        }

        if (strlen($bytes) <= 1) {
            return $bytes;
        }

        return implode('', $this->shuffleArray(str_split($bytes)));
    }

    /**
     * @param array<array-key, mixed> $array
     * @return list<int|string>
     */
    public function pickArrayKeys(array $array, int $num): array
    {
        if ($array === []) {
            throw new \ValueError(
                'Randomizer::pickArrayKeys(): Argument #1 ($array) must not be empty',
            );
        }

        if ($num < 1 || $num > count($array)) {
            throw new \ValueError(
                'Randomizer::pickArrayKeys(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)',
            );
        }

        if ($this->native instanceof \Random\Randomizer) {
            /** @var list<int|string> $keys */
            $keys = array_values($this->native->pickArrayKeys($array, $num));

            return $keys;
        }

        /** @var list<int|string> $keys */
        $keys = array_keys($array);

        return array_slice($this->shuffleArray($keys), 0, $num);
    }
}
