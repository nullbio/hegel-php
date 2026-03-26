<?php

declare(strict_types=1);

use Hegel\Exception\GenerationException;
use Hegel\Generator\ArrayGenerator;
use Hegel\Generator\BasicGeneratorDefinition;
use Hegel\Generator\Generator as HegelGenerator;
use Hegel\Generator\HashMapGenerator;
use Hegel\Generator\HashSetGenerator;
use Hegel\Generator\JustGenerator;
use Hegel\Generator\ObjectGenerator;
use Hegel\Generator\ProvidesGenerator;
use Hegel\Generator\SampledFromGenerator;
use Hegel\Generators;

enum DefaultObjectTestStatus
{
    case Draft;
    case Paid;
}

final readonly class DefaultObjectTestUser
{
    public function __construct(
        public string $email,
        public int $age,
    ) {
    }
}

final readonly class DefaultObjectTestEnvelope
{
    public function __construct(
        public DefaultObjectTestUser $user,
        public ?DefaultObjectTestStatus $status,
    ) {
    }
}

final readonly class DefaultObjectTestMoney
{
    public function __construct(
        public int $amount,
        public string $currency = 'USD',
    ) {
    }
}

final readonly class DefaultObjectNeedsOverride
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}

final class DefaultObjectProvidedValue implements ProvidesGenerator
{
    public function __construct(
        public string $label,
    ) {
    }

    public static function generator(): \Hegel\Generator\Generator
    {
        return new JustGenerator(new self('provided'));
    }
}

function hegelBasic(HegelGenerator $generator): BasicGeneratorDefinition
{
    $basic = $generator->basic();

    expect($basic)->toBeInstanceOf(BasicGeneratorDefinition::class);
    assert($basic instanceof BasicGeneratorDefinition);

    return $basic;
}

it('resolves default generators for scalars containers enums providers and objects', function (): void {
    expect(Generators::default('int'))->toBeInstanceOf(\Hegel\Generator\IntegerGenerator::class)
        ->and(Generators::default('float'))->toBeInstanceOf(\Hegel\Generator\FloatGenerator::class)
        ->and(Generators::default('bool'))->toBeInstanceOf(\Hegel\Generator\BooleanGenerator::class)
        ->and(Generators::default('string'))->toBeInstanceOf(\Hegel\Generator\TextGenerator::class)
        ->and(Generators::default('binary'))->toBeInstanceOf(\Hegel\Generator\BinaryGenerator::class)
        ->and(Generators::default('array', Generators::default('int')))->toBeInstanceOf(ArrayGenerator::class)
        ->and(Generators::default('set', Generators::default('int')))->toBeInstanceOf(HashSetGenerator::class)
        ->and(Generators::default('map', Generators::default('string'), Generators::default('int')))
        ->toBeInstanceOf(HashMapGenerator::class)
        ->and(Generators::default(DefaultObjectTestStatus::class))->toBeInstanceOf(SampledFromGenerator::class)
        ->and(Generators::default(DefaultObjectProvidedValue::class))->toBeInstanceOf(JustGenerator::class)
        ->and(Generators::default(DefaultObjectTestUser::class))->toBeInstanceOf(ObjectGenerator::class);
});

it('parses derived objects and maps fixed dict builders into constructor arguments', function (): void {
    $user = hegelBasic(Generators::object(DefaultObjectTestUser::class)
        ->with('email', Generators::emails())
        ->with('age', Generators::integers()->minValue(18)->maxValue(99)))
        ->parse(['user@example.test', 42]);

    $envelope = hegelBasic(Generators::default(DefaultObjectTestEnvelope::class))
        ->parse([
            ['nested@example.test', 30],
            [1, 1],
        ]);

    $money = hegelBasic(Generators::fixedDicts()
        ->field('amount', Generators::integers()->minValue(1)->maxValue(9))
        ->field('currency', Generators::sampledFrom(['USD', 'EUR']))
        ->into(DefaultObjectTestMoney::class))
        ->parse([7, 1]);

    expect($user)->toEqual(new DefaultObjectTestUser('user@example.test', 42))
        ->and($envelope)->toEqual(new DefaultObjectTestEnvelope(
            new DefaultObjectTestUser('nested@example.test', 30),
            DefaultObjectTestStatus::Paid,
        ))
        ->and($money)->toEqual(new DefaultObjectTestMoney(7, 'EUR'));
});

it('surfaces derived parser failures through generation exceptions', function (): void {
    expect(fn () => hegelBasic(Generators::default(DefaultObjectTestEnvelope::class))
        ->parse([
            ['nested@example.test', 30],
            [2, 1],
        ]))
        ->toThrow(GenerationException::class, 'Generated optional tag is out of range.');
});

it('rejects unsupported defaults and unresolved object fields eagerly', function (): void {
    expect(fn () => Generators::default('array'))
        ->toThrow(InvalidArgumentException::class, 'Default generator type array expects 1 generator argument(s), got 0.')
        ->and(fn () => Generators::default('map', Generators::default('string')))
        ->toThrow(InvalidArgumentException::class, 'Default generator type map expects 2 generator argument(s), got 1.')
        ->and(fn () => Generators::default('missing-type'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported default generator type missing-type.')
        ->and(fn () => Generators::object(DefaultObjectNeedsOverride::class)->basic())
        ->toThrow(InvalidArgumentException::class, 'Override it explicitly.')
        ->and(fn () => Generators::object(DefaultObjectTestUser::class)
            ->with('missing', Generators::text())
            ->basic())
        ->toThrow(InvalidArgumentException::class, 'does not define constructor parameter missing.')
        ->and(fn () => hegelBasic(Generators::fixedDicts()
            ->field('amount', Generators::integers())
            ->into(DefaultObjectTestUser::class))
            ->parse([7]))
        ->toThrow(InvalidArgumentException::class, 'Object builder for DefaultObjectTestUser is missing required field email.');
});
