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
```

## How It Works

hegel-php is a thin client that speaks the
[Hegel protocol](https://hegel.dev/) over a Unix socket. Primitive generation,
shrinking, and failure replay are handled by hegel-core, the same engine used
by [hegel-rust](https://github.com/hegeldev/hegel-rust). Richer combinators are
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

The package name is `nullbio/hegel-php`. Publishing and release automation are not
set up yet, so this command is not live on Packagist yet:

```bash
composer require --dev nullbio/hegel-php
```

## Development

```bash
composer test
composer analyse
```

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

## Stateful Testing

Stateful tests now support both attribute-based discovery and the explicit
`StateMachine` interface. The attribute form is the shortest way to write one:

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
