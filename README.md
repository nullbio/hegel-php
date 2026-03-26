# hegel-php

PHP SDK for the [Hegel](https://hegel.dev/) property-based testing protocol.
Built on top of [hegel-core](https://github.com/hegeldev/hegel-core) (powered by
[Hypothesis](https://hypothesis.readthedocs.io/)), this library brings
property-based testing to PHP with automatic shrinking, failure databases, and
integration with [Pest](https://pestphp.com/).

> **Status: In Development** — The core runner, generator surface, Pest helper,
> manual stateful API, live `hegel-core` integration coverage, and level-8
> static analysis are in place, but the package is still pre-release. See
> [PLAN.md](PLAN.md) for the remaining roadmap and tradeoffs.

## What is Property-Based Testing?

Instead of writing tests with specific example values, you describe *properties*
that should hold for all valid inputs. The framework generates hundreds of random
inputs, and when a test fails, automatically shrinks the failing input to the
smallest possible counterexample.

```php
hegel('array reverse is an involution', function (TestCase $tc) {
    $arr = $tc->draw(Generators::arrays(Generators::integers()));
    expect(array_reverse(array_reverse($arr)))->toBe($arr);
});

hegel('sort preserves length', function (TestCase $tc) {
    $arr = $tc->draw(Generators::arrays(Generators::integers()));
    $original = count($arr);
    sort($arr);
    expect(count($arr))->toBe($original);
});

hegel('parse never crashes on arbitrary input', function (TestCase $tc) {
    $input = $tc->draw(Generators::text());
    // Just verify it doesn't throw — any result is fine
    MyParser::parse($input);
});

hegel('shuffle preserves contents', function (TestCase $tc) {
    $random = $tc->randomizer();
    $values = $tc->draw(Generators::arrays(Generators::integers()));
    $shuffled = $random->shuffleArray($values);

    sort($values);
    sort($shuffled);

    expect($shuffled)->toBe($values);
});
```

## How It Works

hegel-php is a thin client that speaks the
[Hegel protocol](https://hegel.dev/) over a persistent subprocess connection.
By default it uses `hegel-core --stdio`, with an explicit Unix-socket fallback
available via `HEGEL_SERVER_TRANSPORT=socket`. Primitive generation, shrinking,
and failure replay are handled by hegel-core, the same engine used by
[hegel-rust](https://github.com/hegeldev/hegel-rust). Richer combinators are
composed client-side to match the reference implementation instead of inventing
PHP-specific protocol behavior.

This means PHP gets the same battle-tested shrinking behavior and core data
generation strategies as other Hegel SDKs, powered by Hypothesis under the
hood.

## Requirements

- Currently pinned against PHP 8.5.0 and Pest 4.4.3
- [uv](https://docs.astral.sh/uv/) on PATH (used to auto-install hegel-core)
- Unix-like OS (Linux, macOS, WSL2)

## Installation

The package name is `nullbio/hegel-php`:

```bash
composer require --dev nullbio/hegel-php
```

## Development

```bash
composer test
composer analyse
composer bench
```

`composer bench` runs the local hot-path micro-benchmarks for CBOR encode/decode
and generator schema construction.

## Generators

| Generator | Description |
|-----------|-------------|
| `Generators::integers()` | Integer values with optional `->minValue()` / `->maxValue()` |
| `Generators::floats()` | Float values with bounds, NaN/infinity control |
| `Generators::booleans()` | Boolean values |
| `Generators::text()` | Unicode strings with optional size bounds |
| `Generators::binary()` | Raw byte strings |
| `Generators::arrays($gen)` | Arrays of generated elements with size bounds and `->unique()` |
| `Generators::maps($keyGen, $valueGen)` | Associative arrays (keys must be `int` or `string` in PHP) |
| `Generators::hashSets($gen)` | Unique list-backed sets with size bounds |
| `Generators::fixedDicts()` | Fixed-key dictionary builder via `->field(...)->build()` or `->into(Foo::class)` |
| `Generators::tuples($gen1, $gen2, ...)` | Heterogeneous tuple/list generator |
| `Generators::fixedArrays($gen, $size)` | Fixed-length homogeneous tuple/list generator |
| `Generators::unit()` | Constant `null` unit-like generator |
| `Generators::default($type, ...$args)` | PHP-native default resolver for scalars, containers, enums, and objects |
| `Generators::object(Foo::class)` | Derived object generator with per-field overrides via `->with()` |
| `Generators::just($value)` | Always returns the given value |
| `Generators::sampledFrom([...])` | Uniformly sample from a fixed set |
| `Generators::oneOf($gen1, $gen2, ...)` | Choose between generators |
| `Generators::optional($gen)` | Value or null |
| `Generators::emails()` | Valid email addresses |
| `Generators::urls()` | Valid URLs |
| `Generators::domains()` | Valid domain names with `->maxLength()` |
| `Generators::dates()` | Dates in `YYYY-MM-DD` format |
| `Generators::times()` | Times in `HH:MM:SS` format |
| `Generators::datetimes()` | ISO 8601 datetimes |
| `Generators::ipAddresses()` | IPv4/IPv6 addresses with `->v4()` / `->v6()` |
| `Generators::fromRegex($pattern)` | Strings matching a regex |
| `Generators::composite(fn)` | Build custom generators from other generators |

### Derived Objects

When you want PHP-native defaults instead of spelling out every field manually,
use `Generators::default(...)` or `Generators::object(...)`.

```php
final readonly class UserData
{
    public function __construct(
        public string $email,
        public int $age,
    ) {
    }
}

$user = $tc->draw(Generators::default(UserData::class));

$strictUser = $tc->draw(
    Generators::object(UserData::class)
        ->with('email', Generators::emails())
        ->with('age', Generators::integers()->minValue(18)->maxValue(99))
);

$money = $tc->draw(
    Generators::fixedDicts()
        ->field('amount', Generators::integers()->minValue(1))
        ->field('currency', Generators::sampledFrom(['USD', 'EUR']))
        ->into(Money::class)
);
```

`Generators::default(...)` supports:
- scalar aliases like `'int'`, `'float'`, `'bool'`, `'string'`, and `'binary'`
- container aliases like `'array'`, `'set'`, and `'map'` with element generators
- enums via `sampledFrom(cases())`
- constructor-derived objects
- opt-in custom class generators via `Hegel\Generator\ProvidesGenerator`

Runtime notes:
- `hegel-core` is pinned to `0.2.3`
- default transport is `--stdio`
- Unix-socket transport remains available for compatibility via `HEGEL_SERVER_TRANSPORT=socket`

### Randomizer

Use `$tc->randomizer()` when you want shrinkable randomness through a PHP-native
API instead of drawing every primitive manually.

```php
hegel('randomizer stays within requested bounds', function (TestCase $tc) {
    $random = $tc->randomizer();

    $id = $random->getInt(1000, 9999);
    $fraction = $random->getFloat(0.0, 1.0);
    $token = $random->getBytes(16);

    expect($id)->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(9999);
    expect($fraction)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    expect(strlen($token))->toBe(16);
});
```

Available methods currently mirror the useful native `Random\Randomizer` subset:
- `getInt()`, `nextInt()`
- `getFloat()`, `nextFloat()`
- `getBytes()`, `getBytesFromString()`
- `shuffleArray()`, `shuffleBytes()`
- `pickArrayKeys()`

Use `$tc->randomizer(true)` to get a seeded native randomizer backed by one
drawn seed instead of shrinkable per-call randomness.

### Combinators

All generators support:

```php
// Transform values
Generators::integers()->minValue(1)->map(fn($n) => str_repeat('x', $n));

// Filter values (use sparingly)
Generators::integers()->filter(fn($n) => $n % 2 === 0);

// Dependent generation
Generators::integers()->minValue(1)->maxValue(10)->flatMap(
    fn($n) => Generators::arrays(Generators::integers())->minSize($n)->maxSize($n)
);
```

## Configuration

```php
hegel('intensive test', function (TestCase $tc) {
    // ...
})->testCases(500)->seed(42)->verbosity('verbose');
```

The returned helper stays chainable with normal Pest methods too, so calls like
`->group()`, `->skip()`, and `->throws()` still work.

| Setting | Default | Description |
|---------|---------|-------------|
| `testCases` | 100 | Number of random inputs to generate |
| `seed` | random | Fixed seed for reproducibility |
| `verbosity` | normal | quiet, normal, verbose, debug |
| `derandomize` | auto | Use deterministic seed from test name (auto-enabled in CI) |

Public runtime failures are now grouped into:
- `Hegel\Exception\ProtocolException`
- `Hegel\Exception\GenerationException`
- `Hegel\Exception\StatefulException`

Argument validation still uses standard PHP exceptions like
`InvalidArgumentException` and `ValueError`.

## Class-Based Tests

For Laravel-style or class-based Pest tests, use `Hegel\Testing\InteractsWithHegel`.

```php
use Hegel\Generators;
use Hegel\Settings;
use Hegel\Testing\InteractsWithHegel;
use Hegel\Verbosity;

final class CartTest extends Tests\TestCase
{
    use InteractsWithHegel;

    public function test_add_item_is_stable(): void
    {
        $settings = (new Settings())
            ->testCases(200)
            ->verbosity(Verbosity::Debug);

        $this->hegel(function (): void {
            $quantity = $this->draw(Generators::integers()->minValue(1)->maxValue(10));
            expect($quantity)->toBeGreaterThan(0);
        }, $settings);
    }
}
```

Inside the `hegel()` callback, the trait exposes the same convenience methods as
the standalone `TestCase`: `draw()`, `assume()`, `note()`, and `randomizer()`.

## Stateful Testing

Stateful tests now support explicit `StateMachine` implementations,
attribute-based discovery, and a conservative naming convention fallback.
If a machine has no `#[Rule]` / `#[Invariant]` attributes, public
`ruleXxx()` and `invariantXxx()` methods are discovered automatically.
The attribute form is still the shortest way to write one:

```php
use Hegel\Stateful\Attributes\Invariant;
use Hegel\Stateful\Attributes\Rule;
use function Hegel\Stateful\run as runStateMachine;
use function Hegel\Stateful\variables;

hegel('queue model stays consistent', function (TestCase $tc) {
    $machine = new class ($tc) {
        private \Hegel\Stateful\Variables $items;
        private array $model = [];

        public function __construct(TestCase $tc)
        {
            $this->items = variables($tc);
        }

        #[Rule]
        public function enqueue(TestCase $tc): void
        {
            $value = $tc->draw(Generators::integers());
            $this->items->add($value);
            $this->model[] = $value;
        }

        #[Rule]
        public function dequeue(): void
        {
            if ($this->items->empty()) {
                return;
            }

            expect($this->items->consume())->toBe(array_shift($this->model));
        }

        #[Invariant('model length stays nonnegative')]
        public function checkModelLength(): void
        {
            expect(count($this->model))->toBeGreaterThanOrEqual(0);
        }
    };

    runStateMachine($machine, $tc);
});
```

The original explicit API still works when you want full control:

```php
use Hegel\Stateful\Invariant;
use Hegel\Stateful\Rule;
use Hegel\Stateful\StateMachine;
use function Hegel\Stateful\run as runStateMachine;
use function Hegel\Stateful\variables;

hegel('queue model stays consistent', function (TestCase $tc) {
    $machine = new class ($tc) implements StateMachine {
        private \Hegel\Stateful\Variables $items;
        private array $model = [];

        public function __construct(TestCase $tc)
        {
            $this->items = variables($tc);
        }

        public function rules(): array
        {
            return [
                Rule::new('enqueue', function (TestCase $tc): void {
                    $value = $tc->draw(Generators::integers());
                    $this->items->add($value);
                    $this->model[] = $value;
                }),
                Rule::new('dequeue', function (): void {
                    if ($this->items->empty()) {
                        return;
                    }

                    expect($this->items->consume())->toBe(array_shift($this->model));
                }),
            ];
        }

        public function invariants(): array
        {
            return [
                Invariant::new('model length stays nonnegative', function (): void {
                    expect(count($this->model))->toBeGreaterThanOrEqual(0);
                }),
            ];
        }
    };

    runStateMachine($machine, $tc);
});
```

Attributed methods must be public, non-static, and accept either no arguments
or a single `TestCase` argument.

## Related Projects

- [Hegel](https://hegel.dev/) — The universal property-based testing protocol
- [hegel-core](https://github.com/hegeldev/hegel-core) — Core engine (Python/Hypothesis)
- [hegel-rust](https://github.com/hegeldev/hegel-rust) — Rust SDK (reference implementation)

## License

MIT — see [LICENSE](LICENSE).
