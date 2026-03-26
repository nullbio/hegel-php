<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

function hegelWithRealCorePestFile(string $fileName, string $testContents, callable $assertions): void
{
    hegelWithTemporaryProject(function (string $projectDirectory) use ($fileName, $testContents, $assertions): void {
        $testFile = $projectDirectory . '/' . $fileName;

        file_put_contents($testFile, $testContents);

        $path = getenv('PATH');
        $home = getenv('HOME');

        $result = hegelRunProcess(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/vendor/bin/pest',
                '--bootstrap',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                '--colors=never',
                $testFile,
            ],
            $projectDirectory,
            [
                'PATH' => is_string($path) ? $path : '',
                'HOME' => is_string($home) ? $home : $projectDirectory,
            ],
        );

        $assertions($projectDirectory, $result);
    });
}

it('validates format generators against the real hegel-core server', function (): void {
    $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

hegel('real hegel-core format generators satisfy PHP validators', function (TestCase $tc): void {
    $email = $tc->draw(Generators::emails());
    $url = $tc->draw(Generators::urls());
    $domain = $tc->draw(Generators::domains()->maxLength(64));
    $date = $tc->draw(Generators::dates());
    $time = $tc->draw(Generators::times());
    $datetime = $tc->draw(Generators::datetimes());
    $ipAny = $tc->draw(Generators::ipAddresses());
    $ipV4 = $tc->draw(Generators::ipAddresses()->v4());
    $ipV6 = $tc->draw(Generators::ipAddresses()->v6());
    $regex = $tc->draw(Generators::fromRegex('a+')->fullMatch());

    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $timeObject = DateTimeImmutable::createFromFormat('!H:i:s.u', $time)
        ?: DateTimeImmutable::createFromFormat('!H:i:s', $time);
    $datetimeValid = false;

    try {
        new DateTimeImmutable($datetime);
        $datetimeValid = true;
    } catch (Throwable) {
        $datetimeValid = false;
    }

    $record = [
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        'url' => filter_var($url, FILTER_VALIDATE_URL) !== false,
        'domain' => filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
        'domain_length' => strlen($domain) <= 64,
        'date' => $dateObject instanceof DateTimeImmutable && $dateObject->format('Y-m-d') === $date,
        'time' => $timeObject instanceof DateTimeImmutable
            && preg_match('/^\d{2}:\d{2}:\d{2}(?:\.\d+)?$/', $time) === 1,
        'datetime' => $datetimeValid
            && preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/',
                $datetime,
            ) === 1,
        'ip_any' => filter_var($ipAny, FILTER_VALIDATE_IP) !== false,
        'ip_v4' => filter_var($ipV4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        'ip_v6' => filter_var($ipV6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
        'regex' => preg_match('/^a+$/', $regex) === 1,
    ];

    expect($record)->toBe([
        'email' => true,
        'url' => true,
        'domain' => true,
        'domain_length' => true,
        'date' => true,
        'time' => true,
        'datetime' => true,
        'ip_any' => true,
        'ip_v4' => true,
        'ip_v6' => true,
        'regex' => true,
    ]);

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(10)->seed(321)->verbosity('quiet')->disableDatabase();
PHP;

    hegelWithRealCorePestFile(
        'RealFormatGeneratorTest.php',
        $testContents,
        function (string $projectDirectory, array $result): void {
            $traceFile = $projectDirectory . '/trace.jsonl';

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    "Real hegel-core format run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $result['stdout'],
                    $result['stderr'],
                ));
            }

            expect(is_file($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
                ->and(is_file($projectDirectory . '/.hegel/server.log'))->toBeTrue()
                ->and(is_file($traceFile))->toBeTrue();

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($lines)->not->toBeFalse();
            assert(is_array($lines));
            expect($lines)->toHaveCount(10);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($decoded)->toBe([
                    'email' => true,
                    'url' => true,
                    'domain' => true,
                    'domain_length' => true,
                    'date' => true,
                    'time' => true,
                    'datetime' => true,
                    'ip_any' => true,
                    'ip_v4' => true,
                    'ip_v6' => true,
                    'regex' => true,
                ]);
            }
        },
    );
});

it('validates bounded basic generators against the real hegel-core server', function (): void {
    $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

hegel('real hegel-core bounded generators honor their contracts', function (TestCase $tc): void {
    $integer = $tc->draw(Generators::integers()->minValue(-10)->maxValue(10));
    $float = $tc->draw(
        Generators::floats()
            ->minValue(0.5)
            ->maxValue(4.5)
            ->allowNan(false)
            ->allowInfinity(false)
            ->excludeMin()
            ->excludeMax(),
    );
    $boolean = $tc->draw(Generators::booleans());
    $binary = $tc->draw(Generators::binary()->minSize(2)->maxSize(4));
    $array = $tc->draw(
        Generators::arrays(Generators::integers()->minValue(1)->maxValue(3))
            ->minSize(3)
            ->maxSize(3)
            ->unique(),
    );
    $map = $tc->draw(
        Generators::maps(
            Generators::text()->minSize(1)->maxSize(3),
            Generators::integers()->minValue(10)->maxValue(20),
        )
            ->minSize(1)
            ->maxSize(2),
    );
    $sampled = $tc->draw(Generators::sampledFrom(['red', 'green', 'blue']));
    $optional = $tc->draw(Generators::optional(Generators::just(5)));
    $float32 = $tc->draw(
        Generators::floats()
            ->width(32)
            ->allowNan(false)
            ->allowInfinity(false),
    );

    $record = [
        'integer' => is_int($integer) && $integer >= -10 && $integer <= 10,
        'float' => is_float($float) && is_finite($float) && $float > 0.5 && $float < 4.5,
        'boolean' => is_bool($boolean),
        'binary' => is_string($binary) && strlen($binary) >= 2 && strlen($binary) <= 4,
        'array_size' => count($array) === 3,
        'array_unique' => count(array_unique($array, SORT_REGULAR)) === 3,
        'map_size' => count($map) >= 1 && count($map) <= 2,
        'map_keys' => array_reduce(
            array_keys($map),
            static fn (bool $carry, mixed $key): bool => $carry && (is_int($key) || is_string($key)),
            true,
        ),
        'map_values' => array_reduce(
            array_values($map),
            static fn (bool $carry, mixed $value): bool => $carry && is_int($value) && $value >= 10 && $value <= 20,
            true,
        ),
        'sampled' => in_array($sampled, ['red', 'green', 'blue'], true),
        'optional' => $optional === null || $optional === 5,
        'float32' => is_float($float32) && is_finite($float32),
    ];

    expect($record)->toBe([
        'integer' => true,
        'float' => true,
        'boolean' => true,
        'binary' => true,
        'array_size' => true,
        'array_unique' => true,
        'map_size' => true,
        'map_keys' => true,
        'map_values' => true,
        'sampled' => true,
        'optional' => true,
        'float32' => true,
    ]);

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(10)->seed(654)->verbosity('quiet')->disableDatabase();
PHP;

    hegelWithRealCorePestFile(
        'RealBoundedGeneratorTest.php',
        $testContents,
        function (string $projectDirectory, array $result): void {
            $traceFile = $projectDirectory . '/trace.jsonl';

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    "Real hegel-core bounded run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $result['stdout'],
                    $result['stderr'],
                ));
            }

            expect(is_file($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
                ->and(is_file($projectDirectory . '/.hegel/server.log'))->toBeTrue()
                ->and(is_file($traceFile))->toBeTrue();

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($lines)->not->toBeFalse();
            assert(is_array($lines));
            expect($lines)->toHaveCount(10);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($decoded)->toBe([
                    'integer' => true,
                    'float' => true,
                    'boolean' => true,
                    'binary' => true,
                    'array_size' => true,
                    'array_unique' => true,
                    'map_size' => true,
                    'map_keys' => true,
                    'map_values' => true,
                    'sampled' => true,
                    'optional' => true,
                    'float32' => true,
                ]);
            }
        },
    );
});

it('validates default and object generators against the real hegel-core server', function (): void {
    $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

enum RealCoreDefaultStatus
{
    case Draft;
    case Paid;
}

final readonly class RealCoreDefaultProfile
{
    public function __construct(
        public string $name,
        public bool $active,
    ) {
    }
}

final readonly class RealCoreEmailProfile
{
    public function __construct(
        public string $email,
        public int $age,
    ) {
    }
}

final readonly class RealCoreMoney
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}

hegel('real hegel-core default and object generators satisfy php-level contracts', function (TestCase $tc): void {
    $numbers = $tc->draw(
        Generators::default('array', Generators::default('int'))
            ->minSize(2)
            ->maxSize(2),
    );
    $status = $tc->draw(Generators::default(RealCoreDefaultStatus::class));
    $profile = $tc->draw(Generators::default(RealCoreDefaultProfile::class));
    $emailProfile = $tc->draw(
        Generators::object(RealCoreEmailProfile::class)
            ->with('email', Generators::emails())
            ->with('age', Generators::integers()->minValue(18)->maxValue(99)),
    );
    $money = $tc->draw(
        Generators::fixedDicts()
            ->field('amount', Generators::integers()->minValue(1)->maxValue(9))
            ->field('currency', Generators::sampledFrom(['USD', 'EUR']))
            ->into(RealCoreMoney::class),
    );

    $record = [
        'numbers' => count($numbers) === 2 && array_reduce(
            $numbers,
            static fn (bool $carry, mixed $value): bool => $carry && (
                is_int($value)
                || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
            ),
            true,
        ),
        'status' => $status instanceof RealCoreDefaultStatus,
        'profile' => $profile instanceof RealCoreDefaultProfile
            && is_string($profile->name)
            && is_bool($profile->active),
        'email_profile' => $emailProfile instanceof RealCoreEmailProfile
            && filter_var($emailProfile->email, FILTER_VALIDATE_EMAIL) !== false
            && $emailProfile->age >= 18
            && $emailProfile->age <= 99,
        'money' => $money instanceof RealCoreMoney
            && $money->amount >= 1
            && $money->amount <= 9
            && in_array($money->currency, ['USD', 'EUR'], true),
    ];

    expect($record)->toBe([
        'numbers' => true,
        'status' => true,
        'profile' => true,
        'email_profile' => true,
        'money' => true,
    ]);

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(10)->seed(777)->verbosity('quiet')->disableDatabase();
PHP;

    hegelWithRealCorePestFile(
        'RealDefaultObjectGeneratorTest.php',
        $testContents,
        function (string $projectDirectory, array $result): void {
            $traceFile = $projectDirectory . '/trace.jsonl';

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    "Real hegel-core default/object run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $result['stdout'],
                    $result['stderr'],
                ));
            }

            expect(is_file($traceFile))->toBeTrue();

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($lines)->not->toBeFalse();
            assert(is_array($lines));
            expect($lines)->toHaveCount(10);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($decoded)->toBe([
                    'numbers' => true,
                    'status' => true,
                    'profile' => true,
                    'email_profile' => true,
                    'money' => true,
                ]);
            }
        },
    );
});

it('validates set tuple fixed dict fixed array and unit generators against the real hegel-core server', function (): void {
    $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

hegel('real hegel-core tuple and collection parity generators honor their contracts', function (TestCase $tc): void {
    $set = $tc->draw(
        Generators::hashSets(Generators::integers()->minValue(1)->maxValue(20))
            ->minSize(3)
            ->maxSize(3),
    );
    $tuple = $tc->draw(
        Generators::tuples(
            Generators::integers()->minValue(1)->maxValue(3),
            Generators::text()->minSize(2)->maxSize(4),
            Generators::booleans(),
        ),
    );
    $fixedArray = $tc->draw(
        Generators::fixedArrays(
            Generators::integers()->minValue(4)->maxValue(5),
            2,
        ),
    );
    $fixedDict = $tc->draw(
        Generators::fixedDicts()
            ->field('name', Generators::text()->minSize(1)->maxSize(5))
            ->field('age', Generators::integers()->minValue(18)->maxValue(99))
            ->build(),
    );
    $unit = $tc->draw(Generators::unit());
    $emptyTuple = $tc->draw(Generators::tuples());

    $record = [
        'set_list' => is_array($set) && array_is_list($set),
        'set_size' => count($set) === 3,
        'set_unique' => count(array_unique($set, SORT_REGULAR)) === 3,
        'set_bounds' => array_reduce(
            $set,
            static fn (bool $carry, mixed $value): bool => $carry
                && is_int($value)
                && $value >= 1
                && $value <= 20,
            true,
        ),
        'tuple_shape' => is_array($tuple)
            && array_is_list($tuple)
            && count($tuple) === 3
            && is_int($tuple[0])
            && $tuple[0] >= 1
            && $tuple[0] <= 3
            && is_string($tuple[1])
            && preg_match_all('/./us', $tuple[1]) >= 2
            && preg_match_all('/./us', $tuple[1]) <= 4
            && is_bool($tuple[2]),
        'fixed_array' => is_array($fixedArray)
            && array_is_list($fixedArray)
            && count($fixedArray) === 2
            && array_reduce(
                $fixedArray,
                static fn (bool $carry, mixed $value): bool => $carry
                    && is_int($value)
                    && $value >= 4
                    && $value <= 5,
                true,
            ),
        'fixed_dict' => is_array($fixedDict)
            && array_key_exists('name', $fixedDict)
            && array_key_exists('age', $fixedDict)
            && count($fixedDict) === 2
            && is_string($fixedDict['name'])
            && preg_match_all('/./us', $fixedDict['name']) >= 1
            && preg_match_all('/./us', $fixedDict['name']) <= 5
            && is_int($fixedDict['age'])
            && $fixedDict['age'] >= 18
            && $fixedDict['age'] <= 99,
        'unit' => $unit === null,
        'empty_tuple' => $emptyTuple === [],
    ];

    expect($record)->toBe([
        'set_list' => true,
        'set_size' => true,
        'set_unique' => true,
        'set_bounds' => true,
        'tuple_shape' => true,
        'fixed_array' => true,
        'fixed_dict' => true,
        'unit' => true,
        'empty_tuple' => true,
    ]);

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(10)->seed(777)->verbosity('quiet')->disableDatabase();
PHP;

    hegelWithRealCorePestFile(
        'RealCollectionParityGeneratorTest.php',
        $testContents,
        function (string $projectDirectory, array $result): void {
            $traceFile = $projectDirectory . '/trace.jsonl';

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    "Real hegel-core collection parity run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $result['stdout'],
                    $result['stderr'],
                ));
            }

            expect(is_file($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
                ->and(is_file($projectDirectory . '/.hegel/server.log'))->toBeTrue()
                ->and(is_file($traceFile))->toBeTrue();

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($lines)->not->toBeFalse();
            assert(is_array($lines));
            expect($lines)->toHaveCount(10);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($decoded)->toBe([
                    'set_list' => true,
                    'set_size' => true,
                    'set_unique' => true,
                    'set_bounds' => true,
                    'tuple_shape' => true,
                    'fixed_array' => true,
                    'fixed_dict' => true,
                    'unit' => true,
                    'empty_tuple' => true,
                ]);
            }
        },
    );
});

it('validates the php randomizer helper against the real hegel-core server', function (): void {
    $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\TestCase;

hegel('real hegel-core randomizer helper honors php-native contracts', function (TestCase $tc): void {
    $random = $tc->randomizer();
    $trueRandom = $tc->randomizer(true);

    $integer = $random->getInt(10, 20);
    $float = $random->getFloat(1.0, 2.0);
    $bytes = $random->getBytes(4);
    $sampled = $random->getBytesFromString('abcd', 3);
    $shuffled = $random->shuffleArray([1, 2, 3]);
    $picked = $random->pickArrayKeys([
        'left' => 1,
        'right' => 2,
        'up' => 3,
    ], 2);
    $nextInt = $random->nextInt();
    $nextFloat = $random->nextFloat();
    $trueInteger = $trueRandom->getInt(10, 20);
    $trueBytes = $trueRandom->getBytes(4);

    $sorted = $shuffled;
    sort($sorted);

    $record = [
        'integer' => is_int($integer) && $integer >= 10 && $integer <= 20,
        'float' => is_float($float) && $float >= 1.0 && $float < 2.0,
        'bytes' => is_string($bytes) && strlen($bytes) === 4,
        'sampled' => is_string($sampled) && strlen($sampled) === 3 && preg_match('/^[abcd]{3}$/', $sampled) === 1,
        'shuffled' => $sorted === [1, 2, 3],
        'picked' => count($picked) === 2
            && count(array_unique($picked, SORT_REGULAR)) === 2
            && array_reduce(
                $picked,
                static fn (bool $carry, mixed $key): bool => $carry && in_array($key, ['left', 'right', 'up'], true),
                true,
            ),
        'next_int' => is_int($nextInt),
        'next_float' => is_float($nextFloat) && $nextFloat >= 0.0 && $nextFloat < 1.0,
        'true_integer' => is_int($trueInteger) && $trueInteger >= 10 && $trueInteger <= 20,
        'true_bytes' => is_string($trueBytes) && strlen($trueBytes) === 4,
    ];

    expect($record)->toBe([
        'integer' => true,
        'float' => true,
        'bytes' => true,
        'sampled' => true,
        'shuffled' => true,
        'picked' => true,
        'next_int' => true,
        'next_float' => true,
        'true_integer' => true,
        'true_bytes' => true,
    ]);

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(10)->seed(888)->verbosity('quiet')->disableDatabase();
PHP;

    hegelWithRealCorePestFile(
        'RealRandomizerTest.php',
        $testContents,
        function (string $projectDirectory, array $result): void {
            $traceFile = $projectDirectory . '/trace.jsonl';

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    "Real hegel-core randomizer run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $result['stdout'],
                    $result['stderr'],
                ));
            }

            expect(is_file($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
                ->and(is_file($projectDirectory . '/.hegel/server.log'))->toBeTrue()
                ->and(is_file($traceFile))->toBeTrue();

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($lines)->not->toBeFalse();
            assert(is_array($lines));
            expect($lines)->toHaveCount(10);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($decoded)->toBe([
                    'integer' => true,
                    'float' => true,
                    'bytes' => true,
                    'sampled' => true,
                    'shuffled' => true,
                    'picked' => true,
                    'next_int' => true,
                    'next_float' => true,
                    'true_integer' => true,
                    'true_bytes' => true,
                ]);
            }
        },
    );
});
