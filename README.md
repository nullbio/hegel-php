# hegel-php

PHP SDK for the [Hegel](https://hegel.dev/) property-based testing protocol.
Built on top of [hegel-core](https://github.com/hegeldev/hegel-core) (powered by
[Hypothesis](https://hypothesis.readthedocs.io/)), this library brings
property-based testing to PHP with automatic shrinking, failure databases, and
integration with [Pest](https://pestphp.com/).

> **Status: In Development** — Not yet usable. See [PLAN.md](PLAN.md) for the
> implementation roadmap.

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
[Hegel protocol](https://hegel.dev/) over a Unix socket. The heavy lifting —
random data generation, intelligent shrinking, failure replay — is handled by
hegel-core, the same engine used by [hegel-rust](https://github.com/hegeldev/hegel-rust).

This means PHP gets the same battle-tested generation and shrinking strategies
as every other Hegel SDK, powered by Hypothesis under the hood.

## Requirements

- PHP >= 8.2
- [uv](https://docs.astral.sh/uv/) on PATH (used to auto-install hegel-core)
- Unix-like OS (Linux, macOS, WSL2)

## Installation

```bash
composer require --dev hegel/hegel-php
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
| `Generators::maps($keyGen, $valueGen)` | Associative arrays |
| `Generators::just($value)` | Always returns the given value |
| `Generators::sampledFrom([...])` | Uniformly sample from a fixed set |
| `Generators::oneOf($gen1, $gen2, ...)` | Choose between generators |
| `Generators::optional($gen)` | Value or null |
| `Generators::emails()` | Valid email addresses |
| `Generators::urls()` | Valid URLs |
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

| Setting | Default | Description |
|---------|---------|-------------|
| `testCases` | 100 | Number of random inputs to generate |
| `seed` | random | Fixed seed for reproducibility |
| `verbosity` | normal | quiet, normal, verbose, debug |
| `derandomize` | auto | Use deterministic seed from test name (auto-enabled in CI) |

## Related Projects

- [Hegel](https://hegel.dev/) — The universal property-based testing protocol
- [hegel-core](https://github.com/hegeldev/hegel-core) — Core engine (Python/Hypothesis)
- [hegel-rust](https://github.com/hegeldev/hegel-rust) — Rust SDK (reference implementation)

## License

MIT — see [LICENSE](LICENSE).
