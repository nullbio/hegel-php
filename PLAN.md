# hegel-php Implementation Plan

## Overview

Build a PHP SDK for the Hegel property-based testing protocol. The SDK connects
to hegel-core (a Python server built on Hypothesis) via Unix socket and provides
PHP-native generators and test integration for Pest v4.

The reference implementation is hegel-rust (`/tmp/hegel-rust/` or clone from
https://github.com/hegeldev/hegel-rust). Follow its protocol behavior exactly.

---

## Protocol Specification

Everything below is derived from reading the hegel-rust source code. This is the
ground truth for the implementation.

### Packet Format

Every message is a binary packet with this structure:

```
[20-byte header][payload bytes][0x0A terminator]

Header layout (all big-endian):
  Bytes 0-3:   Magic number 0x4845474C ("HEGL")
  Bytes 4-7:   CRC32 checksum (computed with this field zeroed)
  Bytes 8-11:  Channel ID (u32)
  Bytes 12-15: Message ID (u32), with bit 31 = reply flag
  Bytes 16-19: Payload length (u32)
```

Checksum calculation:
1. Write the 20-byte header with checksum field (bytes 4-7) as zeros
2. CRC32 over [header-with-zeros + payload]
3. Write the checksum into bytes 4-7

The CRC32 algorithm is CRC-32/ISO-HDLC (same as PHP's `crc32()` — but note
PHP's `crc32()` returns a signed integer on 32-bit systems; use `hash('crc32b', ...)`
or `crc32()` with proper unsigned handling).

**IMPORTANT**: Verify CRC32 compatibility between PHP and hegel-core early. Write
a test that creates a packet, computes the checksum in PHP, and verifies hegel-core
accepts it. PHP's `crc32()` uses the same polynomial as Rust's `crc32fast`, but
the signedness and byte order need validation.

### Connection Model

- Transport: Unix domain socket
- The SDK creates a temporary directory, puts a socket path in it, and spawns
  the hegel-core server binary with that socket path as arg
- The server creates the socket and listens; the SDK connects to it
- One connection per test run, multiplexed into channels

### Channel Multiplexing

- Channel 0 is the control channel (handshake, run_test command)
- Client channels use odd IDs: `(next_id << 1) | 1`, starting from next_id=1
- Server channels use even IDs
- Each channel has independent message ID counters
- Close a channel by sending message_id `0x7FFFFFFF` with payload `[0xFE]`

### Request/Reply Payloads

- Channel requests and replies are packet-level request/reply messages, not a
  separate protocol layer
- CBOR request helpers should unwrap replies shaped like `{"result": ...}`
- Error replies are shaped like `{"error": ..., "type": ...}` and should be
  surfaced as transport/protocol errors
- Event acknowledgements are ordinary replies:
  - `test_case` ack: `{"result": null}`
  - `test_done` ack: `{"result": true}`

### Handshake

1. SDK sends `"hegel_handshake_start"` as raw bytes (not CBOR) on channel 0
2. Server replies with `"Hegel/0.7"` (or similar version string)
3. SDK validates version is within supported range (currently 0.6-0.7)

### Test Lifecycle

After handshake:

1. SDK sends `run_test` on the control channel and waits for the control-channel
   reply before reading test events:
   ```cbor
   {
     "command": "run_test",
     "test_cases": 100,
     "seed": null,
     "channel_id": <test_channel_id>,
     "database_key": <bytes or null>,
     "derandomize": false,
     "database": null,           // omit when unset; null when disabled; path when set
     "suppress_health_check": [] // include only when non-empty
   }
   ```

2. Server sends events on the test channel:
   - `{"event": "test_case", "channel_id": <new_channel_id>}` — run one test case
   - `{"event": "test_done", "results": {...}}` — all test cases complete

3. For each `test_case` event:
   a. SDK extracts the server-provided `channel_id`
   b. SDK connects to that channel and acks the event immediately with `{"result": null}` (before running the test)
   c. Runs the user's test function
   d. Uses that same channel for all draw/generate requests and, if the test case is still open, sends `mark_complete` on that channel:
      ```cbor
      {"command": "mark_complete", "status": "VALID"|"INVALID"|"INTERESTING", "origin": null|"..."}
      ```
      - VALID = test passed
      - INVALID = assumption failed or the server aborted the case with StopTest/overflow/data exhaustion
      - INTERESTING = test failed (assertion/exception)
   e. Closes the test case channel after `mark_complete`
   f. If the server already closed the channel due to StopTest/overflow/flaky replay handling, skip `mark_complete`

4. After `test_done`, SDK acks the event with `{"result": true}`, inspects the
   results payload, then reads exactly `interesting_test_cases` more `test_case`
   events as final replays. `is_final` is runner-local state, not a wire field.

5. Results structure from `test_done`:
   ```cbor
   {
     "passed": true|false,
     "interesting_test_cases": 0,
     "error": null|"...",
     "health_check_failure": null|"...",
     "flaky": null|"..."
   }
   ```

### Generator Protocol

Basic generators lower to a CBOR schema and send it to the server via a
`generate` command on the test case channel:

```cbor
{"command": "generate", "schema": {"type": "integer", "min_value": 0, "max_value": 100}}
```

Server responds with the generated CBOR value.

Not every generator is a single schema round-trip. `map`, `flat_map`, `filter`,
`just`, `sampled_from`, `oneOf`, `optional`, composite generators, and some
collection paths compose basic draws client-side to mirror `hegel-rust`.

Common schema shapes used by the Rust client include:
- `{"type": "integer", "min_value": N, "max_value": N}`
- `{"type": "float", "min_value": N, "max_value": N, "width": 32|64, "exclude_min": bool, "exclude_max": bool, "allow_nan": bool, "allow_infinity": bool}`
- `{"type": "string", "min_size": N, "max_size": N}`
- `{"type": "binary", "min_size": N, "max_size": N}`
- `{"type": "boolean"}`
- `{"const": <cbor_value>}`
- `{"type": "list", "elements": <schema>, "min_size": N, "max_size": N, "unique": bool}`
- `{"type": "dict", "keys": <schema>, "values": <schema>, "min_size": N, "max_size": N}`
- `{"type": "tuple", "elements": [<schema>, ...]}`
- `{"one_of": [<schema>, ...]}`
- `{"type": "regex", "pattern": "pattern", "fullmatch": bool}`
- `{"type": "email"}`
- `{"type": "domain", "max_length": N}`
- `{"type": "url"}`
- `{"type": "date"}`
- `{"type": "time"}`
- `{"type": "datetime"}`
- `{"type": "ipv4"}` / `{"type": "ipv6"}`

Notes from `hegel-rust`:
- `just($value)` lowers to a constant schema when possible
- `sampledFrom([...])` is implemented as an integer index draw plus local lookup
- `oneOf(...)` and `optional(...)` may use tagged tuple schemas when all branches
  are basic, otherwise they compose draws locally
- Fixed dictionaries and fixed-size arrays are tuple-based schemas

### Collection Protocol

Server-managed collections are only used when the client cannot lower the whole
collection to a single schema but still wants the server to control size and
shrinking. In `hegel-rust`, this is primarily the composite list path.

Basic vectors, sets, and maps prefer direct `list` / `dict` schemas. Non-basic
sets and maps are built client-side with retry/span bookkeeping rather than the
collection protocol.

1. `{"command": "new_collection", "name": "list", "min_size": 0}` → returns collection name string
   - Optional: `"max_size": N`
2. `{"command": "collection_more", "collection": "<name>"}` → returns bool
3. `{"command": "collection_reject", "collection": "<name>", "why": "..."}` — reject last element (optional `why`)

For each element, the SDK calls `collection_more`. If true, generate one element
using the element generator's schema. If false, stop.

### Span Protocol

Spans group related draws for better shrinking:

- `{"command": "start_span", "label": <u64>}` — start a span
- `{"command": "stop_span", "discard": bool}` — end a span, optionally discarding it

Labels are constants (from hegel-rust `labels` module):
- 1=LIST, 2=LIST_ELEMENT, 3=SET, 4=SET_ELEMENT, 5=MAP, 6=MAP_ENTRY,
  7=TUPLE, 8=ONE_OF, 9=OPTIONAL, 10=FIXED_DICT, 11=FLAT_MAP, 12=FILTER,
  13=MAPPED, 14=SAMPLED_FROM, 15=ENUM_VARIANT

### Server Installation

hegel-rust auto-installs hegel-core using `uv`:
1. Creates `.hegel/venv` directory
2. `uv venv --clear .hegel/venv`
3. `uv pip install --python .hegel/venv/bin/python hegel-core==0.2.2`
4. Binary is at `.hegel/venv/bin/hegel`
5. Caches version in `.hegel/venv/hegel-version`
6. Override with `HEGEL_SERVER_COMMAND` env var

Server is spawned with: `<binary> <socket_path> --verbosity <level>`
Server stdout/stderr go to `.hegel/server.log`

---

## Implementation Phases

### Phase 0: Project Scaffolding

- [x] `composer.json` with autoload and pinned dependencies (`nullbio/cbor-php`, Pest, PHPStan)
- [x] `phpunit.xml` / Pest config
- [x] PSR-4 autoload under `Hegel\` namespace
- [x] `.gitignore` updated for vendor and `.hegel`, with `composer.lock` committed
- [x] Basic directory structure

**Decision made: CBOR library.**
Use the forked package `nullbio/cbor-php`.

Constraints for the fork:
- Exact-pinned Composer dependencies
- Exact-pinned platform requirements (`php`, `ext-json`, `ext-mbstring`)
- Committed `composer.lock` in the fork
- No floating dev dependencies like `roave/security-advisories: dev-latest`

`hegel-php` should depend on the fork package directly rather than the upstream
package. If the fork is not yet published to Packagist,
consuming root projects can use a VCS or path repository override temporarily.

**Decision made: Namespace.**
Use `Hegel\`.

**Decision made: Package name.**
Use `nullbio/hegel-php`.

### Phase 1: Protocol Client

The foundation. Everything else depends on this.

- [x] `Packet.php` — read/write binary packets with CRC32 checksums
- [x] `Connection.php` — Unix socket connection with channel multiplexing
- [x] `Channel.php` — Request/reply abstraction with CBOR encode/decode
- [x] Unit tests with mock socket pairs (use `stream_socket_pair()`)
- [x] CRC32 compatibility test against a known packet from hegel-rust

Test approach: Create socket pairs, write packets from PHP, read them back,
verify checksums. The suite now also includes a fixed Rust packet test vector
and a live integration test that runs a real Pest property against `hegel-core`.

### Phase 2: Server Lifecycle

- [x] `Hegel.php` — Server spawning, socket connection, handshake
- [x] Auto-installation of hegel-core via `uv`
- [x] `HEGEL_SERVER_COMMAND` env var override
- [x] Server process monitoring (detect crashes)
- [x] `.hegel/` directory management and server logging
- [x] `Settings.php` — test_cases, verbosity, seed, derandomize, database, suppress_health_check
- [x] Integration test: spawn server, handshake, send a trivial run_test, get test_done

### Phase 3: TestCase and Core Event Loop

- [x] `TestCase.php` — draw(), drawSilent(), assume(), note()
- [x] `Collection.php` — Server-managed collection sizing
- [x] Test event loop in `Hegel.php`: handle test_case events, run user closure,
      report mark_complete, handle test_done
- [x] Span tracking (start_span/stop_span) for shrinking quality
- [x] Handle INVALID (assumption failure), INTERESTING (test failure), overflow/StopTest
- [x] Final replay handling (re-run interesting cases for output)

At this point we can run a trivial property test end-to-end:
```php
$hegel = new Hegel(function (TestCase $tc) {
    // no generators yet, just verify the lifecycle works
    $tc->assume(true);
});
$hegel->run();
```

### Phase 4: Basic Generators

The simplest generators that just send schemas:

- [x] `Generator.php` — Interface with `draw(TestCase): mixed` plus basic-schema support
- [x] `Generators.php` — Static factory class
- [x] `IntegerGenerator.php` — integers() with minValue/maxValue
- [x] `FloatGenerator.php` — floats() with minValue/maxValue/excludeMin/excludeMax/allowNan/allowInfinity
- [x] `BooleanGenerator.php` — booleans()
- [x] `TextGenerator.php` — text() with minSize/maxSize
- [x] `BinaryGenerator.php` — binary() with minSize/maxSize
- [x] `JustGenerator.php` — just($value)
- [x] `SampledFromGenerator.php` — sampledFrom([...])

Integration test:
```php
$hegel = new Hegel(function (TestCase $tc) {
    $a = $tc->draw(Generators::integers());
    $b = $tc->draw(Generators::integers());
    assert($a + $b === $b + $a); // will fail on overflow, that's fine
});
$hegel->run();
```

### Phase 5: Collection Generators

- [x] `ArrayGenerator.php` — arrays() with minSize/maxSize/unique; direct `list` schema when possible, collection protocol fallback otherwise
- [x] `HashMapGenerator.php` — maps() with key/value generators, minSize/maxSize; direct `dict` schema when possible, client-side fallback otherwise
- [x] Integration with Collection protocol where the Rust client uses it (`new_collection` / `collection_more` / `collection_reject`)

### Phase 6: Combinators

- [x] `->map(callable)` — Transform generated values
- [x] `->filter(callable)` — Keep values matching predicate (with retry limit)
- [x] `->flatMap(callable)` — Dependent generation
- [x] `OneOfGenerator.php` — oneOf(gen1, gen2, ...) with span labels
- [x] `OptionalGenerator.php` — optional(gen) returning ?T
- [x] `CompositeGenerator.php` — composite(function(TestCase $tc) { ... })

### Phase 7: Format Generators

- [x] `EmailGenerator.php` — emails()
- [x] `UrlGenerator.php` — urls()
- [x] `DomainGenerator.php` — domains()
- [x] `DateGenerator.php` — dates()
- [x] `TimeGenerator.php` — times()
- [x] `DateTimeGenerator.php` — datetimes()
- [x] `IpAddressGenerator.php` — ipAddresses() with ->v4() / ->v6()
- [x] `RegexGenerator.php` — fromRegex(pattern) with ->fullMatch()

### Phase 8: Pest Integration

**This is the phase that needs the most design discussion.**

The goal: make hegel tests feel native in Pest. Several approaches:

#### Option A: `hegel()` helper function

```php
// tests/Property/CartTest.php
hegel('adding item increases count', function (TestCase $tc) {
    $quantity = $tc->draw(Generators::integers()->minValue(1)->maxValue(100));
    $cart = new Cart();
    $cart->addItem('sku-1', $quantity);
    expect($cart->itemCount())->toBe($quantity);
});

// With settings
hegel('fuzz parser', function (TestCase $tc) {
    $input = $tc->draw(Generators::text());
    expect(fn () => Parser::parse($input))->not->toThrow();
})->testCases(500)->seed(42);
```

Implementation: `hegel()` creates a Pest `test()` that internally runs the Hegel
lifecycle. Settings via chained methods that configure the underlying `Settings`.

Status: implemented for v0.1. The helper now returns a thin wrapper that keeps
Hegel-specific settings chainable while delegating normal Pest methods like
`group()`, `skip()`, and `throws()` to the underlying `TestCall`.

Pros: Simple, no Pest internals needed, easy to understand.
Cons: Different syntax from regular `test()` / `it()`.

#### Option B: Pest plugin with chained `->hegel()` method

```php
test('adding item increases count', function (TestCase $tc) {
    $quantity = $tc->draw(Generators::integers()->minValue(1)->maxValue(100));
    $cart = new Cart();
    $cart->addItem('sku-1', $quantity);
    expect($cart->itemCount())->toBe($quantity);
})->hegel(testCases: 500);
```

Implementation: Register a Pest plugin that adds a `->hegel()` method to the
test case builder. This method wraps the test closure with the Hegel lifecycle.

Pros: Feels more native to Pest. Uses familiar `test()` syntax.
Cons: Depends on Pest plugin internals which may change.

#### Option C: Custom test case class

```php
uses(HegelTestCase::class);

test('adding item increases count', function () {
    $quantity = $this->draw(Generators::integers()->minValue(1)->maxValue(100));
    // ...
});
```

Pros: Very Pest-native, uses `$this->draw()`.
Cons: Requires a custom TestCase class, may conflict with Laravel's TestCase.
The `draw()` method would need to lazily initialize the Hegel server on first call.

Decision: ship Option A (`hegel()` helper) in v0.1. Keep Option B as a later
ergonomic enhancement if we decide the extra Pest integration surface is worth
the maintenance cost.

### Phase 9: Output and Reporting

- [x] Counterexample display on failure (draw values, notes)
- [x] Health check failure messages
- [x] Flaky test detection reporting
- [x] Server crash error messages
- [x] Integration with Pest's error output (format counterexamples nicely)

### Phase 10: Stateful Testing (v0.2)

- [x] State machine runner
- [x] Manual `Rule` and `Invariant` declaration via closures and a `StateMachine` interface
- [x] Variables (pools) for tracking dynamic resources
- [x] Attribute-based rule and invariant discovery for public methods
- [ ] Convention-based discovery beyond attributes, if we decide the extra magic is worth it later

---

## Open Questions

### 1. CBOR Codec Overhead

Property tests run 100+ test cases per test, each with multiple generate
round-trips. CBOR encode/decode is in the hot path. Need to benchmark:
- How fast is `nullbio/cbor-php` in the hot path?
- Is it fast enough for 100 test cases with complex generators?
- If not, can we cache schemas (they're static per generator)?
- If it is still too slow, do we want an optional native accelerator later?

### 2. Server Lifecycle Scope

hegel-rust spawns one server per test function. For PHP/Pest, should we:
- Spawn per `hegel()` call (simple, matches Rust)
- Spawn per test file (reuse across multiple hegel tests)
- Spawn per test suite run (best performance, but connection management)

Rust spawns per test because `cargo test` runs tests in parallel threads. Pest
runs tests sequentially by default, so per-test-file or per-suite could work.

Start with per-`hegel()` call (matches Rust), optimize later if the ~100ms
startup overhead matters.

### 3. PHP Type Safety

PHP doesn't have generics. Generator return types will be `mixed` at the
language level. Options:
- Accept it: `$n = $tc->draw(Generators::integers());` returns `int` at runtime but `mixed` statically
- PHPStan generics: `@template T` / `@return T` on the Generator interface
- Separate typed methods: `$tc->drawInt(Generators::integers())` — ugly, don't do this

Recommendation: Use PHPStan template annotations. IDE autocompletion works,
static analysis catches misuse, runtime is unaffected.

### 4. Error Handling

hegel-rust uses panics for assume failures and test failures. PHP equivalent:
- `assume(false)` → throw a sentinel exception (catch it in the runner, report INVALID)
- Test assertion failure → catch the exception, report INTERESTING
- Server crash → throw a descriptive RuntimeException

Current implementation uses private sentinel exceptions (`TestCaseControlFlow`
and `StopTestException`) for this. The remaining question is whether the public
error taxonomy should stay as raw `RuntimeException` messages or grow dedicated
exception types later.

### 5. Parallel Test Execution

Pest can run tests in parallel via `--parallel`. Each parallel worker would
need its own hegel-core server. The per-`hegel()` lifecycle handles this
naturally (each call spawns its own server with its own temp socket).

### 6. Windows Support

hegel-rust uses Unix sockets only. PHP on Windows can use Unix sockets if
running in WSL2 (which is our primary environment). For native Windows, we'd
need TCP sockets. Defer Windows native support.

---

## Testing Strategy

### Unit Tests (Phase 1-2)
- Packet encode/decode roundtrips
- CRC32 checksum verification
- Channel message routing
- Settings builder

### Integration Tests (Phase 2+)
- Full handshake with real hegel-core server
- Generate integer/text/bool values
- Collection generation
- Assume failure handling
- Test failure shrinking (verify minimal counterexample)
- Health check triggering

Static analysis:
- PHPStan now runs at level 8 via `composer analyse`

### Property Tests (Phase 8+, self-hosting)
- Once the Pest integration works, use hegel-php to test hegel-php
- Packet roundtrip: `decode(encode(packet)) === packet`
- Generator schema determinism: same generator config always produces same schema

---

## Dependencies

### Runtime
- PHP 8.5.0
- `nullbio/cbor-php` 3.3.0
- `uv` on PATH (for hegel-core installation)

### Dev
- `pestphp/pest` 4.4.3
- `phpstan/phpstan` 2.1.43
