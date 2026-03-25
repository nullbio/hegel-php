<?php

declare(strict_types=1);

namespace Hegel\Stateful\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Rule
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
