<?php

declare(strict_types=1);

namespace Hegel\Generator;

final class IpAddressGenerator extends StringSchemaGenerator
{
    private ?string $version = null;

    public function v4(): self
    {
        $this->version = 'ipv4';

        return $this;
    }

    public function v6(): self
    {
        $this->version = 'ipv6';

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return match ($this->version) {
            'ipv4' => ['type' => 'ipv4'],
            'ipv6' => ['type' => 'ipv6'],
            default => [
                'one_of' => [
                    ['type' => 'ipv4'],
                    ['type' => 'ipv6'],
                ],
            ],
        };
    }
}
