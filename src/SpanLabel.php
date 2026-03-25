<?php

declare(strict_types=1);

namespace Hegel;

final class SpanLabel
{
    public const int LIST = 1;
    public const int LIST_ELEMENT = 2;
    public const int SET = 3;
    public const int SET_ELEMENT = 4;
    public const int MAP = 5;
    public const int MAP_ENTRY = 6;
    public const int TUPLE = 7;
    public const int ONE_OF = 8;
    public const int OPTIONAL = 9;
    public const int FIXED_DICT = 10;
    public const int FLAT_MAP = 11;
    public const int FILTER = 12;
    public const int MAPPED = 13;
    public const int SAMPLED_FROM = 14;
    public const int ENUM_VARIANT = 15;

    private function __construct()
    {
    }
}
