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

### Handshake

1. SDK sends `"hegel_handshake_start"` as raw bytes (not CBOR) on channel 0
2. Server replies with `"Hegel/0.7"` (or similar version string)
3. SDK validates version is within supported range (currently 0.6-0.7)

### Test Lifecycle

After handshake:

1. SDK sends `run_test` command on control channel:
   ```cbor
   {
     "command": "run_test",
     "test_cases": 100,
     "seed": null,
     "channel_id": <test_channel_id>,
     "database_key": <bytes or null>,
     "derandomize": false,
     "database": null,           // optional
     "suppress_health_check": [] // optional
   }
   ```

2. Server sends events on the test channel:
   - `{"event": "test_case", "channel_id": <new_channel_id>}` — run one test case
   - `{"event": "test_done", "results": {...}}` — all test cases complete

3. For each `test_case` event:
   a. SDK acks the event immediately (before running the test)
   b. Creates a new channel for this test case's generate requests
   c. Runs the user's test function
   d. Sends `mark_complete` on the test case channel:
      ```cbor
      {"command": "mark_complete", "status": "VALID"|"INVALID"|"INTERESTING", "origin": null|"..."}
      ```
      - VALID = test passed
      - INVALID = assumption failed (tc.assume(false))
      - INTERESTING = test failed (assertion/exception)
   e. Closes the test case channel

4. After `test_done`, server may send final replay test cases (for each
   interesting example found). These are run the same way but with `is_final=true`.

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

Generators don't generate values in PHP. They build a CBOR schema and send it
to the server via a `generate` command on the test case channel:

```cbor
{"command": "generate", "schema": {"type": "integer", "min_value": 0, "max_value": 100}}
```

Server responds with the generated CBOR value.

Schema types (from hegel-rust generators):
- `{"type": "integer", "min_value": N, "max_value": N}`
- `{"type": "float", "min_value": N, "max_value": N, "width": 32|64, "exclude_min": bool, "exclude_max": bool, "allow_nan": bool, "allow_infinity": bool}`
- `{"type": "text", "min_size": N, "max_size": N}`
- `{"type": "bytes", "min_size": N, "max_size": N}`
- `{"type": "boolean"}`
- `{"type": "just", "value": <cbor_value>}`
- `{"type": "sampled_from", "elements": [...]}`
- `{"type": "one_of", "options": [<schema>, ...]}`
- `{"type": "none"}` (for optional's None case)
- `{"type": "emails"}`
- `{"type": "domains", "max_length": N}` (optional max_length)
- `{"type": "urls"}`
- `{"type": "dates"}`
- `{"type": "times"}`
- `{"type": "datetimes"}`
- `{"type": "ip_addresses", "version": 4|6}` (optional version)
- `{"type": "from_regex", "regex": "pattern", "fullmatch": bool}`

### Collection Protocol

Collections (arrays, maps, sets) use a server-managed sizing protocol instead
of a simple schema:

1. `{"command": "new_collection", "name": "list", "min_size": 0}` → returns collection name string
   - Optional: `"max_size": N`
2. `{"command": "collection_more", "collection": "<name>"}` → returns bool
3. `{"command": "collection_reject", "collection": "<name>"}` — reject last element (for uniqueness)

For each element, the SDK calls `collection_more`. If true, generate one element
using the element generator's schema. If false, stop.

### Span Protocol

Spans group related draws for better shrinking:

- `{"command": "start_span", "label": <u64>}` — start a span
- `{"command": "stop_span", "discard": false}` — end a span

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

- [ ] `composer.json` with autoload, dev dependencies (pest, cbor library)
- [ ] `phpunit.xml` / Pest config
- [ ] PSR-4 autoload under `Hegel\` namespace
- [ ] `.gitignore` (vendor, .hegel, composer.lock — or not lock, depending on lib conventions)
- [ ] Basic directory structure

**Decision needed: CBOR library.**
Options:
- `2tvenom/cborEncoder` — pure PHP, well-maintained, handles maps/arrays/ints/floats/bytes/text
- `spomky-labs/cbor-php` — more comprehensive, supports tags, streaming
- `ext-cbor` — C extension, fastest, but requires installation

Recommendation: Start with `spomky-labs/cbor-php` (most complete pure-PHP option).
If performance is an issue, add ext-cbor as an optional accelerator later.

**Decision needed: Namespace.**
`Hegel\` is clean and matches the project name. `HegelPHP\` adds unnecessary
verbosity. Recommend `Hegel\`.

### Phase 1: Protocol Client

The foundation. Everything else depends on this.

- [ ] `Packet.php` — read/write binary packets with CRC32 checksums
- [ ] `Connection.php` — Unix socket connection with channel multiplexing
- [ ] `Channel.php` — Request/reply abstraction with CBOR encode/decode
- [ ] Unit tests with mock socket pairs (use `stream_socket_pair()`)
- [ ] CRC32 compatibility test against a known packet from hegel-rust

Test approach: Create socket pairs, write packets from PHP, read them back,
verify checksums. Then do an integration test connecting to a real hegel-core
server and completing the handshake.

### Phase 2: Server Lifecycle

- [ ] `Hegel.php` — Server spawning, socket connection, handshake
- [ ] Auto-installation of hegel-core via `uv`
- [ ] `HEGEL_SERVER_COMMAND` env var override
- [ ] Server process monitoring (detect crashes)
- [ ] `.hegel/` directory management and server logging
- [ ] `Settings.php` — test_cases, verbosity, seed, derandomize, database, suppress_health_check
- [ ] Integration test: spawn server, handshake, send a trivial run_test, get test_done

### Phase 3: TestCase and Core Event Loop

- [ ] `TestCase.php` — draw(), drawSilent(), assume(), note()
- [ ] `Collection.php` — Server-managed collection sizing
- [ ] Test event loop in `Hegel.php`: handle test_case events, run user closure,
      report mark_complete, handle test_done
- [ ] Span tracking (start_span/stop_span) for shrinking quality
- [ ] Handle INVALID (assumption failure), INTERESTING (test failure), overflow/StopTest
- [ ] Final replay handling (re-run interesting cases for output)

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

- [ ] `Generator.php` — Interface with `doDraw(TestCase): mixed` and combinators
- [ ] `Generators.php` — Static factory class
- [ ] `IntegerGenerator.php` — integers() with minValue/maxValue
- [ ] `FloatGenerator.php` — floats() with minValue/maxValue/excludeMin/excludeMax/allowNan/allowInfinity
- [ ] `BooleanGenerator.php` — booleans()
- [ ] `TextGenerator.php` — text() with minSize/maxSize
- [ ] `BinaryGenerator.php` — binary() with minSize/maxSize
- [ ] `JustGenerator.php` — just($value)
- [ ] `SampledFromGenerator.php` — sampledFrom([...])

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

- [ ] `ArrayGenerator.php` — arrays() with minSize/maxSize/unique (analogous to vecs())
- [ ] `HashMapGenerator.php` — maps() with key/value generators, minSize/maxSize
- [ ] Integration with Collection protocol (new_collection/collection_more/collection_reject)

### Phase 6: Combinators

- [ ] `->map(callable)` — Transform generated values
- [ ] `->filter(callable)` — Keep values matching predicate (with retry limit)
- [ ] `->flatMap(callable)` — Dependent generation
- [ ] `OneOfGenerator.php` — oneOf(gen1, gen2, ...) with span labels
- [ ] `OptionalGenerator.php` — optional(gen) returning ?T
- [ ] `CompositeGenerator.php` — composite(function(TestCase $tc) { ... })

### Phase 7: Format Generators

- [ ] `EmailGenerator.php` — emails()
- [ ] `UrlGenerator.php` — urls()
- [ ] `DomainGenerator.php` — domains()
- [ ] `DateGenerator.php` — dates()
- [ ] `TimeGenerator.php` — times()
- [ ] `DateTimeGenerator.php` — datetimes()
- [ ] `IpAddressGenerator.php` — ipAddresses() with ->v4() / ->v6()
- [ ] `RegexGenerator.php` — fromRegex(pattern) with ->fullMatch()

### Phase 8: Pest Integration

**This is the phase that needs the most design discussion.**

The goal: make hegel tests feel native in Pest. Several approaches:

#### Option A: `hegel()` helper function (simplest)

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
    $result = Parser::parse($input);
    expect($result)->not->toThrow();
})->testCases(500)->seed(42);
```

Implementation: `hegel()` creates a Pest `test()` that internally runs the Hegel
lifecycle. Settings via chained methods that configure the underlying `Settings`.

Pros: Simple, no Pest internals needed, easy to understand.
Cons: Different syntax from regular `test()` / `it()`.

#### Option B: Pest plugin with `#[Hegel]` attribute

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

**Recommendation: Start with Option A (`hegel()` helper) for v0.1.** It's the
simplest, has zero Pest internal dependencies, and works today. Then explore
Option B for v0.2 if ergonomics matter enough.

### Phase 9: Output and Reporting

- [ ] Counterexample display on failure (draw values, notes)
- [ ] Health check failure messages
- [ ] Flaky test detection reporting
- [ ] Server crash error messages
- [ ] Integration with Pest's error output (format counterexamples nicely)

### Phase 10: Stateful Testing (v0.2)

- [ ] State machine runner
- [ ] Rule and invariant declaration (likely via attributes or method naming convention)
- [ ] Variables (pools) for tracking dynamic resources
- [ ] This is complex and should wait until the core is solid

---

## Open Questions

### 1. CBOR Library Performance

Property tests run 100+ test cases per test, each with multiple generate
round-trips. CBOR encode/decode is in the hot path. Need to benchmark:
- How fast is pure-PHP CBOR vs ext-cbor?
- Is it fast enough for 100 test cases with complex generators?
- If not, can we cache schemas (they're static per generator)?

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

Need a private exception class like `AssumeFailedException` that the runner
catches but users never see.

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

### Property Tests (Phase 8+, self-hosting)
- Once the Pest integration works, use hegel-php to test hegel-php
- Packet roundtrip: `decode(encode(packet)) === packet`
- Generator schema determinism: same generator config always produces same schema

---

## Dependencies

### Runtime
- PHP >= 8.2 (need enums, fibers potentially, readonly properties)
- `spomky-labs/cbor-php` or equivalent CBOR library
- `uv` on PATH (for hegel-core installation)

### Dev
- `pestphp/pest` ^3.0
- `phpstan/phpstan` (for template type checking)
