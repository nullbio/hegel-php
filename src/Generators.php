<?php

declare(strict_types=1);

namespace Hegel;

use Hegel\Generator\ArrayGenerator;
use Hegel\Generator\BinaryGenerator;
use Hegel\Generator\BooleanGenerator;
use Hegel\Generator\CompositeGenerator;
use Hegel\Generator\DateGenerator;
use Hegel\Generator\DateTimeGenerator;
use Hegel\Generator\DomainGenerator;
use Hegel\Generator\EmailGenerator;
use Hegel\Generator\FloatGenerator;
use Hegel\Generator\Generator as HegelGenerator;
use Hegel\Generator\HashMapGenerator;
use Hegel\Generator\IntegerGenerator;
use Hegel\Generator\IpAddressGenerator;
use Hegel\Generator\JustGenerator;
use Hegel\Generator\OneOfGenerator;
use Hegel\Generator\OptionalGenerator;
use Hegel\Generator\RegexGenerator;
use Hegel\Generator\SampledFromGenerator;
use Hegel\Generator\TextGenerator;
use Hegel\Generator\TimeGenerator;
use Hegel\Generator\UrlGenerator;

final class Generators
{
    public static function integers(): IntegerGenerator
    {
        return new IntegerGenerator();
    }

    public static function floats(): FloatGenerator
    {
        return new FloatGenerator();
    }

    public static function booleans(): BooleanGenerator
    {
        return new BooleanGenerator();
    }

    public static function text(): TextGenerator
    {
        return new TextGenerator();
    }

    public static function binary(): BinaryGenerator
    {
        return new BinaryGenerator();
    }

    public static function emails(): EmailGenerator
    {
        return new EmailGenerator();
    }

    public static function urls(): UrlGenerator
    {
        return new UrlGenerator();
    }

    public static function domains(): DomainGenerator
    {
        return new DomainGenerator();
    }

    public static function dates(): DateGenerator
    {
        return new DateGenerator();
    }

    public static function times(): TimeGenerator
    {
        return new TimeGenerator();
    }

    public static function datetimes(): DateTimeGenerator
    {
        return new DateTimeGenerator();
    }

    public static function ipAddresses(): IpAddressGenerator
    {
        return new IpAddressGenerator();
    }

    public static function fromRegex(string $pattern): RegexGenerator
    {
        return new RegexGenerator($pattern);
    }

    public static function arrays(HegelGenerator $elements): ArrayGenerator
    {
        return new ArrayGenerator($elements);
    }

    public static function maps(HegelGenerator $keys, HegelGenerator $values): HashMapGenerator
    {
        return new HashMapGenerator($keys, $values);
    }

    public static function just(mixed $value): JustGenerator
    {
        return new JustGenerator($value);
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public static function sampledFrom(array $values): SampledFromGenerator
    {
        return new SampledFromGenerator($values);
    }

    public static function oneOf(HegelGenerator ...$generators): OneOfGenerator
    {
        return new OneOfGenerator(array_values($generators));
    }

    public static function optional(HegelGenerator $generator): OptionalGenerator
    {
        return new OptionalGenerator($generator);
    }

    public static function composite(callable $composer): CompositeGenerator
    {
        return new CompositeGenerator($composer);
    }
}
