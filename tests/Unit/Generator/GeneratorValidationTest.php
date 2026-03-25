<?php

declare(strict_types=1);

use Hegel\Generators;

it('rejects impossible integer bounds', function (): void {
    expect(fn () => Generators::integers()->minValue(10)->maxValue(1))
        ->toThrow(\InvalidArgumentException::class, 'maxValue cannot be less than minValue.');
});

it('rejects invalid float settings eagerly', function (): void {
    expect(fn () => Generators::floats()->allowNan()->minValue(0.0))
        ->toThrow(\InvalidArgumentException::class, 'allowNan cannot be true when bounds are set.');

    expect(fn () => Generators::floats()->minValue(0.0)->maxValue(1.0)->allowInfinity())
        ->toThrow(\InvalidArgumentException::class, 'allowInfinity cannot be true when both bounds are set.');

    expect(fn () => Generators::floats()->width(16))
        ->toThrow(\InvalidArgumentException::class, 'Float width must be 32 or 64.');
});

it('rejects invalid string and binary size bounds', function (): void {
    expect(fn () => Generators::text()->minSize(5)->maxSize(4))
        ->toThrow(\InvalidArgumentException::class, 'maxSize cannot be less than minSize.');

    expect(fn () => Generators::binary()->minSize(3)->maxSize(2))
        ->toThrow(\InvalidArgumentException::class, 'maxSize cannot be less than minSize.');
});

it('rejects empty sampledFrom collections', function (): void {
    expect(fn () => Generators::sampledFrom([]))
        ->toThrow(\InvalidArgumentException::class, 'sampledFrom requires at least one element.');
});

it('rejects invalid array and map size bounds', function (): void {
    expect(fn () => Generators::arrays(Generators::integers())->minSize(3)->maxSize(2))
        ->toThrow(\InvalidArgumentException::class, 'maxSize cannot be less than minSize.');

    expect(fn () => Generators::maps(Generators::text(), Generators::integers())->minSize(2)->maxSize(1))
        ->toThrow(\InvalidArgumentException::class, 'maxSize cannot be less than minSize.');
});

it('rejects empty oneOf generators', function (): void {
    expect(fn () => Generators::oneOf())
        ->toThrow(\InvalidArgumentException::class, 'oneOf requires at least one generator.');
});

it('rejects invalid domain length bounds', function (): void {
    expect(fn () => Generators::domains()->maxLength(3))
        ->toThrow(\InvalidArgumentException::class, 'maxLength must be between 4 and 255.');

    expect(fn () => Generators::domains()->maxLength(256))
        ->toThrow(\InvalidArgumentException::class, 'maxLength must be between 4 and 255.');
});
