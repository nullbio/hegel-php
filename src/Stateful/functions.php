<?php

declare(strict_types=1);

namespace Hegel\Stateful;

use Hegel\TestCase;

/**
 * @param object $machine
 */
function run(object $machine, TestCase $testCase): void
{
    StatefulRunner::run($machine, $testCase);
}

function variables(TestCase $testCase): Variables
{
    return StatefulRunner::variables($testCase);
}
