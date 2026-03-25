<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;
use ReflectionObject;
use RuntimeException;

final class GeneratorValue
{
    private function __construct()
    {
    }

    public static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];

            foreach ($value as $key => $item) {
                $copy[$key] = self::copy($item);
            }

            return $copy;
        }

        if (is_object($value)) {
            if (! (new ReflectionObject($value))->isCloneable()) {
                return $value;
            }

            return clone $value;
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function expectList(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('Expected list for %s, got %s.', $context, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function expectTuple(mixed $value, string $context, int $length): array
    {
        $tuple = self::expectList($value, $context);

        if (count($tuple) !== $length) {
            throw new RuntimeException(sprintf(
                'Expected %d-item tuple for %s, got %d items.',
                $length,
                $context,
                count($tuple),
            ));
        }

        return $tuple;
    }

    public static function toInteger(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf('Expected integer for %s, got %s.', $context, get_debug_type($value)));
    }

    public static function toArrayKey(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'PHP associative array keys must be int or string, got %s.',
            get_debug_type($value),
        ));
    }
}
