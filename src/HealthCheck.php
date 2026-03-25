<?php

declare(strict_types=1);

namespace Hegel;

enum HealthCheck: string
{
    case FilterTooMuch = 'filter_too_much';
    case TooSlow = 'too_slow';
    case TestCasesTooLarge = 'test_cases_too_large';
    case LargeInitialTestCase = 'large_initial_test_case';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::FilterTooMuch,
            self::TooSlow,
            self::TestCasesTooLarge,
            self::LargeInitialTestCase,
        ];
    }
}
