<?php

declare(strict_types=1);

use Hegel\Generator\BasicGeneratorDefinition;
use Hegel\Generator\Generator as HegelGenerator;
use Hegel\Generators;

function basicGenerator(HegelGenerator $generator): BasicGeneratorDefinition
{
    $basic = $generator->basic();

    expect($basic)->toBeInstanceOf(BasicGeneratorDefinition::class);
    assert($basic instanceof BasicGeneratorDefinition);

    return $basic;
}

it('parses string-backed generators and rejects non-string values', function ($generator): void {
    expect(basicGenerator($generator)->parse('ok'))->toBe('ok')
        ->and(fn () => basicGenerator($generator)->parse(123))
        ->toThrow(InvalidArgumentException::class, 'Expected text generated value, got int.');
})->with([
    'text' => Generators::text(),
    'email' => Generators::emails(),
    'url' => Generators::urls(),
    'domain' => Generators::domains()->maxLength(64),
    'date' => Generators::dates(),
    'time' => Generators::times(),
    'datetime' => Generators::datetimes(),
    'ip-address' => Generators::ipAddresses(),
    'regex' => Generators::fromRegex('a+')->fullMatch(),
]);

it('parses scalar generator payloads and rejects incompatible values', function (): void {
    expect(basicGenerator(Generators::integers())->parse('42'))->toBe('42')
        ->and(fn () => basicGenerator(Generators::integers())->parse([]))
        ->toThrow(InvalidArgumentException::class, 'Expected integer-compatible generated value, got array.')
        ->and(basicGenerator(Generators::floats())->parse(2))->toBe(2.0)
        ->and(fn () => basicGenerator(Generators::floats())->parse('2.0'))
        ->toThrow(InvalidArgumentException::class, 'Expected float-compatible generated value, got string.')
        ->and(basicGenerator(Generators::booleans())->parse(true))->toBeTrue()
        ->and(fn () => basicGenerator(Generators::booleans())->parse(1))
        ->toThrow(InvalidArgumentException::class, 'Expected boolean generated value, got int.')
        ->and(basicGenerator(Generators::binary())->parse("\x00\x01"))->toBe("\x00\x01")
        ->and(fn () => basicGenerator(Generators::binary())->parse(123))
        ->toThrow(InvalidArgumentException::class, 'Expected binary generated value, got int.');
});

it('parses collection payloads and surfaces malformed structures', function (): void {
    expect(basicGenerator(Generators::arrays(Generators::integers()))->parse([1, '2']))->toBe([1, '2'])
        ->and(fn () => basicGenerator(Generators::arrays(Generators::integers()))->parse(['bad' => 1]))
        ->toThrow(RuntimeException::class, 'Expected list for array generator value, got array.')
        ->and(fn () => basicGenerator(Generators::arrays(Generators::booleans()))->parse([1]))
        ->toThrow(InvalidArgumentException::class, 'Expected boolean generated value, got int.')
        ->and(basicGenerator(Generators::maps(Generators::text(), Generators::integers()))->parse([
            ['left', 10],
            ['right', 20],
        ]))->toBe([
            'left' => 10,
            'right' => 20,
        ])
        ->and(fn () => basicGenerator(Generators::maps(Generators::text(), Generators::integers()))->parse([
            ['left'],
        ]))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for hash map entry, got 1 items.')
        ->and(fn () => basicGenerator(Generators::maps(Generators::booleans(), Generators::integers()))->parse([
            [true, 10],
        ]))
        ->toThrow(InvalidArgumentException::class, 'PHP associative array keys must be int or string, got bool.')
        ->and(fn () => basicGenerator(Generators::maps(Generators::text(), Generators::booleans()))->parse([
            ['left', 1],
        ]))
        ->toThrow(InvalidArgumentException::class, 'Expected boolean generated value, got int.');
});

it('parses tuple-like generator payloads and unit values', function (): void {
    expect(basicGenerator(Generators::hashSets(Generators::integers()))->parse([1, '2']))->toBe([1, '2'])
        ->and(fn () => basicGenerator(Generators::hashSets(Generators::integers()))->parse(['bad' => 1]))
        ->toThrow(RuntimeException::class, 'Expected list for hash set generator value, got array.')
        ->and(basicGenerator(Generators::tuples(Generators::integers(), Generators::text()))->parse([1, 'ok']))->toBe([1, 'ok'])
        ->and(fn () => basicGenerator(Generators::tuples(Generators::integers(), Generators::text()))->parse([1]))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for tuple generator value, got 1 items.')
        ->and(basicGenerator(Generators::fixedArrays(Generators::booleans(), 2))->parse([true, false]))->toBe([true, false])
        ->and(fn () => basicGenerator(Generators::fixedArrays(Generators::booleans(), 2))->parse([true]))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for fixed array generator value, got 1 items.')
        ->and(basicGenerator(Generators::fixedDicts()
            ->field('name', Generators::text())
            ->field('age', Generators::integers())
            ->build())
            ->parse(['Ada', 42]))->toBe([
                'name' => 'Ada',
                'age' => 42,
            ])
        ->and(fn () => basicGenerator(Generators::fixedDicts()
            ->field('name', Generators::text())
            ->field('age', Generators::integers())
            ->build())
            ->parse(['Ada']))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for fixed dict tuple, got 1 items.')
        ->and(basicGenerator(Generators::unit())->schema())->toBe(['const' => null])
        ->and(basicGenerator(Generators::unit())->parse('ignored'))->toBeNull();
});

it('parses tagged generator payloads and reports out-of-range values', function (): void {
    expect(basicGenerator(Generators::sampledFrom(['red', 'green']))->parse(1))->toBe('green')
        ->and(fn () => basicGenerator(Generators::sampledFrom(['red', 'green']))->parse(2))
        ->toThrow(RuntimeException::class, 'Generated sampledFrom index 2 is out of range.')
        ->and(basicGenerator(Generators::optional(Generators::text()))->parse([0, null]))->toBeNull()
        ->and(basicGenerator(Generators::optional(Generators::text()))->parse([1, 'ok']))->toBe('ok')
        ->and(fn () => basicGenerator(Generators::optional(Generators::text()))->parse([2, 'bad']))
        ->toThrow(RuntimeException::class, 'Generated optional tag is out of range.')
        ->and(basicGenerator(Generators::oneOf(Generators::integers(), Generators::text()))->parse([1, 'ok']))->toBe('ok')
        ->and(fn () => basicGenerator(Generators::oneOf(Generators::integers(), Generators::text()))->parse([1]))
        ->toThrow(RuntimeException::class, 'Expected 2-item tuple for oneOf tagged tuple, got 1 items.')
        ->and(fn () => basicGenerator(Generators::oneOf(Generators::integers(), Generators::text()))->parse([2, 'bad']))
        ->toThrow(RuntimeException::class, 'Generated oneOf tag 2 is out of range.');
});

it('builds finite float schemas when nan and infinity are disabled', function (): void {
    $floatSchema = Generators::floats()
        ->allowNan(false)
        ->allowInfinity(false)
        ->basic()
        ->schema();
    $float32Schema = Generators::floats()
        ->width(32)
        ->allowNan(false)
        ->allowInfinity(false)
        ->basic()
        ->schema();

    expect($floatSchema['allow_nan'])->toBeFalse()
        ->and($floatSchema['allow_infinity'])->toBeFalse()
        ->and($floatSchema['min_value'])->toBe(-PHP_FLOAT_MAX)
        ->and($floatSchema['max_value'])->toBe(PHP_FLOAT_MAX)
        ->and($float32Schema['width'])->toBe(32)
        ->and($float32Schema['min_value'])->toBe(-3.4028234663852886E+38)
        ->and($float32Schema['max_value'])->toBe(3.4028234663852886E+38);
});
