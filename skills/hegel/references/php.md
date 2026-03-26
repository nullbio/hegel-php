# Hegel PHP SDK Reference

## Setup

Add to `composer.json`:

```json
{
  "require-dev": {
    "nullbio/hegel-php": "^0.2.0"
  }
}
```

Requires:
- [`uv`](https://docs.astral.sh/uv/) on PATH so `hegel-core` can be installed automatically
- Pest in the test suite
- Linux, macOS, or WSL2

Run tests with:

```bash
./vendor/bin/pest
```

`hegel-php` talks to `hegel-core` over `--stdio` by default. Unix-socket
fallback is available via `HEGEL_SERVER_TRANSPORT=socket`.

## Test Structure

### `hegel()` helper (preferred)

Use the global Pest helper for normal function-style tests:

```php
use Hegel\Generators;
use Hegel\TestCase;

hegel('reverse is an involution', function (TestCase $tc): void {
    $values = $tc->draw(Generators::arrays(Generators::integers()));

    expect(array_reverse(array_reverse($values)))->toBe($values);
});
```

With configuration:

```php
hegel('intensive property', function (TestCase $tc): void {
    // ...
})->testCases(500)->seed(42)->verbosity('verbose');
```

The returned helper stays chainable with normal Pest methods like `->group()`,
`->skip()`, and `->throws()`.

### Class-based tests

For Laravel-style or PHPUnit-style class tests, use the trait:

```php
use Hegel\Generators;
use Hegel\Testing\InteractsWithHegel;

final class CartTest extends Tests\TestCase
{
    use InteractsWithHegel;

    public function test_quantity_stays_positive(): void
    {
        $this->hegel(function (): void {
            $quantity = $this->draw(Generators::integers()->minValue(1));
            expect($quantity)->toBeGreaterThan(0);
        });
    }
}
```

Inside the callback, the trait exposes `draw()`, `assume()`, `note()`, and
`randomizer()`.

## `TestCase` Methods

| Method | Purpose |
|--------|---------|
| `$tc->draw($generator)` | Draw a value and record it for shrinking/reporting |
| `$tc->assume($condition)` | Reject the current test case if false |
| `$tc->note($message)` | Attach debug output shown on final replay |
| `$tc->randomizer()` | Get shrinkable PHP-native randomness |
| `$tc->randomizer(true)` | Get a seeded native `Random\Randomizer` backed by one draw |

Use normal Pest `expect(...)` assertions or PHPUnit assertions. There is no
special assertion DSL.

## Generator Reference

Import the main factories with:

```php
use Hegel\Generators;
```

### Core generators

| Generator | Notes |
|-----------|-------|
| `Generators::integers()` | PHP ints, optional `->minValue()` / `->maxValue()` |
| `Generators::floats()` | Bounds, `excludeMin`, `excludeMax`, `allowNan`, `allowInfinity` |
| `Generators::booleans()` | Booleans |
| `Generators::text()` | Unicode strings |
| `Generators::binary()` | Raw byte strings |
| `Generators::just($value)` | Constant value |
| `Generators::sampledFrom([...])` | Fixed choice |
| `Generators::oneOf($a, $b, ...)` | Choose between generators |
| `Generators::optional($gen)` | Value or `null` |

### Collections and structure

| Generator | Notes |
|-----------|-------|
| `Generators::arrays($gen)` | PHP lists, supports `->minSize()`, `->maxSize()`, `->unique()` |
| `Generators::maps($keyGen, $valueGen)` | PHP associative arrays with `int|string` keys |
| `Generators::hashSets($gen)` | Unique list-backed set surface |
| `Generators::fixedDicts()` | Fixed-key dictionary builder via `->field(...)->build()` |
| `Generators::tuples($a, $b, ...)` | Heterogeneous fixed-position list |
| `Generators::fixedArrays($gen, $size)` | Fixed-length homogeneous list |
| `Generators::unit()` | Constant `null` |

### Formats

| Generator | Notes |
|-----------|-------|
| `Generators::emails()` | Valid email addresses |
| `Generators::urls()` | Valid URLs |
| `Generators::domains()` | Domain names, `->maxLength()` |
| `Generators::dates()` | `YYYY-MM-DD` |
| `Generators::times()` | `HH:MM:SS` |
| `Generators::datetimes()` | ISO 8601 datetime strings |
| `Generators::ipAddresses()` | `->v4()` / `->v6()` |
| `Generators::fromRegex($pattern)` | Strings matching a regex, with `->fullMatch()` |

### Combinators

All generators support:

```php
Generators::integers()->map(fn (int $n): string => (string) $n);
Generators::integers()->filter(fn (int $n): bool => $n % 2 === 0);
Generators::integers()->flatMap(
    fn (int $n) => Generators::arrays(Generators::integers())->minSize(abs($n))
);
```

Prefer direct construction or dependent draws over heavy `filter()` / `assume()`
when rejection rates would be high.

### Derived PHP-native generators

`hegel-php` has PHP-specific surfaces that do not exist in Rust syntax:

```php
$status = $tc->draw(Generators::default(Status::class));

$user = $tc->draw(
    Generators::object(UserData::class)
        ->with('email', Generators::emails())
        ->with('age', Generators::integers()->minValue(18))
);
```

`Generators::default(...)` supports:
- scalar aliases like `'int'`, `'float'`, `'bool'`, `'string'`, `'binary'`
- container aliases like `'array'`, `'set'`, and `'map'`
- enums
- constructor-derived objects
- opt-in class providers via `Hegel\Generator\ProvidesGenerator`

## Randomness

If the code under test needs randomness, do **not** seed `mt_rand()`,
`Random\Randomizer`, or another RNG from a hegel-generated integer. That only
makes the seed shrinkable, not the actual random decisions.

Use:

```php
$random = $tc->randomizer();
```

Useful methods:
- `getInt()`, `nextInt()`
- `getFloat()`, `nextFloat()`
- `getBytes()`, `getBytesFromString()`
- `shuffleArray()`, `shuffleBytes()`
- `pickArrayKeys()`

Use `$tc->randomizer(true)` only when the code under test depends on
statistically random-looking output and the shrinkable mode is too artificial.

## Configuration and Errors

Common settings on the Pest helper:
- `->testCases(500)`
- `->seed(42)`
- `->verbosity('verbose')`
- `->derandomize(true)`

Public runtime failure types:
- `Hegel\Exception\ProtocolException`
- `Hegel\Exception\GenerationException`
- `Hegel\Exception\StatefulException`

Argument validation still uses normal PHP exceptions like
`InvalidArgumentException` and `ValueError`.

## Stateful Testing

Stateful tests use:

```php
use Hegel\Stateful\Attributes\Invariant;
use Hegel\Stateful\Attributes\Rule;
use function Hegel\Stateful\run as runStateMachine;
use function Hegel\Stateful\variables;
```

Rules and invariants can be:
- explicit `StateMachine` implementations
- public methods annotated with `#[Rule]` / `#[Invariant]`
- public `ruleXxx()` / `invariantXxx()` methods when no attributes are present

## PHP-specific guidance

### Use PHP-realistic properties

Do not copy Rust properties blindly. Rust integer overflow examples often fail
because Rust panics in debug/test builds. PHP integers behave differently and
may widen to `float` instead of panicking.

Use properties that are meaningful in PHP's runtime semantics.

### Keep tests with the code they cover

Add Hegel properties to the existing `*Test.php` file that already covers the
module. Do not create a separate `PropertyTest.php` file unless there is no
relevant test file yet.

### Prefer broad generators first

If a function accepts any PHP int, start with:

```php
Generators::integers()
```

Not:

```php
Generators::integers()->minValue(0)->maxValue(100)
```

unless the contract justifies it.
