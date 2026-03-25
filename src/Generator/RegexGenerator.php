<?php

declare(strict_types=1);

namespace Hegel\Generator;

final class RegexGenerator extends StringSchemaGenerator
{
    private bool $fullMatch = false;

    public function __construct(
        private string $pattern,
    ) {
    }

    public function fullMatch(bool $fullMatch = true): self
    {
        $this->fullMatch = $fullMatch;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'regex',
            'pattern' => $this->pattern,
            'fullmatch' => $this->fullMatch,
        ];
    }
}
