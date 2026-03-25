<?php

declare(strict_types=1);

use Hegel\Pest\HegelCall;
use Pest\Support\Backtrace;

if (! function_exists('hegel')) {
    function hegel(string $description, callable $property): HegelCall
    {
        if (! function_exists('test')) {
            throw new RuntimeException('The hegel() helper requires Pest to be installed and loaded.');
        }

        return HegelCall::create($description, $property, Backtrace::testFile());
    }
}
