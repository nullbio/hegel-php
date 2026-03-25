<?php

declare(strict_types=1);

namespace Hegel\Generator;

final class EmailGenerator extends StringSchemaGenerator
{
    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return ['type' => 'email'];
    }
}
