<?php

declare(strict_types=1);

namespace Hegel;

enum Verbosity: string
{
    case Quiet = 'quiet';
    case Normal = 'normal';
    case Verbose = 'verbose';
    case Debug = 'debug';
}
