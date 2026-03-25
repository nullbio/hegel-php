<?php

declare(strict_types=1);

namespace Hegel\Generator;

abstract class AbstractGenerator implements Generator
{
    public function basic(): ?BasicGeneratorDefinition
    {
        return null;
    }

    public function map(callable $mapper): MappedGenerator
    {
        return new MappedGenerator($this, $mapper);
    }

    public function filter(callable $predicate): FilteredGenerator
    {
        return new FilteredGenerator($this, $predicate);
    }

    public function flatMap(callable $mapper): FlatMappedGenerator
    {
        return new FlatMappedGenerator($this, $mapper);
    }
}
