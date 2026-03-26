# Porting PHP Tests to Hegel

## From Pest datasets or PHPUnit data providers

Existing example grids are often latent properties.

Pest / PHPUnit before:

```php
it('normalizes order ids', function (string $raw, string $expected): void {
    expect(normalizeOrderId($raw))->toBe($expected);
})->with([
    ['abc-1', 'ABC-1'],
    ['x9', 'X9'],
    ['z', 'Z'],
]);
```

Hegel after:

```php
use Hegel\Generators;
use Hegel\TestCase;

hegel('normalizeOrderId uppercases valid input', function (TestCase $tc): void {
    $raw = $tc->draw(Generators::fromRegex('[A-Za-z0-9-]+')->fullMatch());

    expect(normalizeOrderId($raw))->toBe(strtoupper($raw));
});
```

Port the underlying property, not the example table.

## From manual loops over examples

Before:

```php
it('sorts correctly', function (): void {
    foreach ([[3, 1, 2], [1], []] as $values) {
        expect(mySort($values))->toBe(collect($values)->sort()->values()->all());
    }
});
```

After:

```php
hegel('mySort matches PHP sort semantics', function (TestCase $tc): void {
    $values = $tc->draw(Generators::arrays(Generators::integers()));

    $expected = $values;
    sort($expected);

    expect(mySort($values))->toBe($expected);
});
```

## From seeded randomness

Before:

```php
it('sampling is stable', function (): void {
    $random = new Random\Randomizer(new Random\Engine\Mt19937(42));
    $result = sampleIds($random, [1, 2, 3, 4]);

    expect($result)->toHaveCount(2);
});
```

After:

```php
hegel('sampling returns requested number of ids', function (TestCase $tc): void {
    $random = $tc->randomizer();
    $ids = $tc->draw(Generators::arrays(Generators::integers())->unique());

    expect(sampleIds($random, $ids))->toHaveCount(min(2, count($ids)));
});
```

Do not port seeded RNG tests by generating a seed and constructing your own RNG
unless you explicitly need true random-looking output. Prefer `$tc->randomizer()`.

## Mapping common PHP test shapes

| Existing PHP pattern | Hegel rewrite |
|----------------------|---------------|
| Pest dataset / PHPUnit data provider | Replace with one property plus generators |
| Loop over hardcoded arrays | Draw the collection with `Generators::arrays()` |
| Hand-built optional cases | `Generators::optional($gen)` |
| Regex-based fixture strings | `Generators::fromRegex($pattern)` |
| Randomized helper with fixed seed | `$tc->randomizer()` |
| Repeated object fixtures | `Generators::default(Foo::class)` or `Generators::object(Foo::class)` |

## Pest vs hegel style

Pest example:

```php
it('trims surrounding whitespace', function (string $value): void {
    expect(trim($value))->toBe($value);
})->with(['x', ' y ', "\tz\t"]);
```

Hegel:

```php
hegel('trim is idempotent', function (TestCase $tc): void {
    $value = $tc->draw(Generators::text());

    expect(trim(trim($value)))->toBe(trim($value));
});
```

The main shift is imperative generation:
- draw with `$tc->draw(...)`
- use normal Pest `expect(...)`
- use `$tc->assume(...)` only when the contract genuinely excludes some inputs

## Porting checklist

1. Keep the test in the existing `*Test.php` file.
2. Replace datasets, providers, or random loops with `$tc->draw(...)`.
3. Start with broad generators. Do not carry over narrow fixture ranges unless
   the code's contract requires them.
4. Replace manual RNG seeding with `$tc->randomizer()`.
5. Prefer a real oracle or structural property over hardcoded expected values.
6. Run the test and treat surprising edge-case failures as likely real bugs
   until proven otherwise.
