<?php

declare(strict_types=1);

use Hegel\Generator\GeneratorValue;
use Hegel\Generators;

it('deep copies arrays and cloneable objects', function (): void {
    $object = new class ([1]) {
        /**
         * @param list<int> $items
         */
        public function __construct(
            public array $items,
        ) {
        }
    };

    $arrayCopy = GeneratorValue::copy([
        'nested' => ['value' => 1],
    ]);
    $objectCopy = GeneratorValue::copy($object);

    $arrayCopy['nested']['value'] = 2;
    $objectCopy->items[] = 2;

    expect($arrayCopy)->toBe([
        'nested' => ['value' => 2],
    ])
        ->and($objectCopy)->not->toBe($object)
        ->and($object->items)->toBe([1]);
});

it('validates list and tuple shapes', function (): void {
    expect(GeneratorValue::expectList(['a', 'b'], 'letters'))->toBe(['a', 'b'])
        ->and(fn () => GeneratorValue::expectList(['a' => 'b'], 'letters'))
        ->toThrow(RuntimeException::class, 'Expected list for letters, got array.')
        ->and(GeneratorValue::expectTuple([1, 2], 'pair', 2))->toBe([1, 2])
        ->and(fn () => GeneratorValue::expectTuple([1], 'pair', 2))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for pair, got 1 items.');
});

it('converts integer-compatible values and rejects invalid array keys', function (): void {
    expect(GeneratorValue::toInteger('42', 'index'))->toBe(42)
        ->and(fn () => GeneratorValue::toInteger(1.5, 'index'))
        ->toThrow(RuntimeException::class, 'Expected integer for index, got float.')
        ->and(GeneratorValue::toArrayKey('key'))->toBe('key')
        ->and(fn () => GeneratorValue::toArrayKey(new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'PHP associative array keys must be int or string, got stdClass.');
});

it('returns fresh copies from just generators', function (): void {
    $generator = Generators::just([
        'nested' => ['value' => 1],
    ])->basic();

    $first = $generator->parse('ignored');
    $second = $generator->parse('ignored');
    $first['nested']['value'] = 2;

    expect($generator->schema())->toBe(['const' => null])
        ->and($second)->toBe([
            'nested' => ['value' => 1],
        ]);
});
