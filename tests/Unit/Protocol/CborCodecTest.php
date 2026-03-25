<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;

it('round-trips 64-bit integers through cbor', function (): void {
    $value = [
        'positive' => PHP_INT_MAX,
        'negative' => PHP_INT_MIN,
    ];

    expect(CborCodec::decode(CborCodec::encode($value)))->toBe($value);
});
