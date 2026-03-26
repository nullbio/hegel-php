<?php

declare(strict_types=1);

namespace Hegel\Generator;

interface ProvidesGenerator
{
    public static function generator(): Generator;
}
