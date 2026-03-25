<?php

declare(strict_types=1);

namespace Hegel\Generator;

use InvalidArgumentException;

final class DomainGenerator extends StringSchemaGenerator
{
    private int $maxLength = 255;

    public function maxLength(int $maxLength): self
    {
        if ($maxLength < 4 || $maxLength > 255) {
            throw new InvalidArgumentException('maxLength must be between 4 and 255.');
        }

        $this->maxLength = $maxLength;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'domain',
            'max_length' => $this->maxLength,
        ];
    }
}
