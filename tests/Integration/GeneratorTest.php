<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;

it('sends the expected schemas for the basic generators', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_generator_server.php',
            'fake-hegel-generator',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $integer = $testCase->draw(Generators::integers()->minValue(1)->maxValue(10));
                $float = $testCase->draw(
                    Generators::floats()
                        ->minValue(0.5)
                        ->maxValue(4.5)
                        ->allowNan(false)
                        ->allowInfinity(false)
                        ->excludeMin()
                        ->excludeMax(),
                );
                $boolean = $testCase->draw(Generators::booleans());
                $text = $testCase->draw(Generators::text()->minSize(1)->maxSize(5));
                $binary = $testCase->draw(Generators::binary()->minSize(2)->maxSize(2));
                $just = $testCase->draw(Generators::just(['fixed' => true]));
                $sampled = $testCase->draw(Generators::sampledFrom(['red', 'green', 'blue']));
                $array = $testCase->draw(
                    Generators::arrays(Generators::integers()->minValue(1)->maxValue(3))
                        ->minSize(2)
                        ->maxSize(4)
                        ->unique(),
                );
                $map = $testCase->draw(
                    Generators::maps(
                        Generators::text()->minSize(1)->maxSize(5),
                        Generators::integers()->minValue(10)->maxValue(99),
                    )
                        ->minSize(1)
                        ->maxSize(2),
                );
                $mapped = $testCase->draw(
                    Generators::integers()
                        ->minValue(2)
                        ->maxValue(5)
                        ->map(fn (int|string $value): string => str_repeat('x', (int) $value)),
                );
                $oneOf = $testCase->draw(
                    Generators::oneOf(
                        Generators::integers()->minValue(0)->maxValue(0),
                        Generators::text()->minSize(3)->maxSize(6),
                    ),
                );
                $optionalNone = $testCase->draw(
                    Generators::optional(Generators::text()->minSize(1)->maxSize(5)),
                );
                $optionalSome = $testCase->draw(
                    Generators::optional(Generators::text()->minSize(1)->maxSize(5)),
                );
                $email = $testCase->draw(Generators::emails());
                $url = $testCase->draw(Generators::urls());
                $domain = $testCase->draw(Generators::domains()->maxLength(64));
                $date = $testCase->draw(Generators::dates());
                $time = $testCase->draw(Generators::times());
                $datetime = $testCase->draw(Generators::datetimes());
                $ipAny = $testCase->draw(Generators::ipAddresses());
                $ipV4 = $testCase->draw(Generators::ipAddresses()->v4());
                $ipV6 = $testCase->draw(Generators::ipAddresses()->v6());
                $regex = $testCase->draw(Generators::fromRegex('a+')->fullMatch());

                expect($integer)->toBe(7)
                    ->and($float)->toBe(3.5)
                    ->and($boolean)->toBeTrue()
                    ->and($text)->toBe('hello')
                    ->and($binary)->toBe("\xFF\x00")
                    ->and($just)->toBe(['fixed' => true])
                    ->and($sampled)->toBe('green')
                    ->and($array)->toBe([1, 2, 3])
                    ->and($map)->toBe(['alpha' => 11, 'beta' => 22])
                    ->and($mapped)->toBe('xxxx')
                    ->and($oneOf)->toBe('picked')
                    ->and($optionalNone)->toBeNull()
                    ->and($optionalSome)->toBe('later')
                    ->and($email)->toBe('user@example.test')
                    ->and($url)->toBe('https://example.test/path')
                    ->and($domain)->toBe('example.test')
                    ->and($date)->toBe('2025-01-02')
                    ->and($time)->toBe('03:04:05')
                    ->and($datetime)->toBe('2025-01-02T03:04:05+00:00')
                    ->and($ipAny)->toBe('2001:db8::1')
                    ->and($ipV4)->toBe('192.0.2.1')
                    ->and($ipV6)->toBe('2001:db8::2')
                    ->and($regex)->toBe('aaaa');
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];

            expect($requests)->toHaveCount(22)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 10,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'float',
                    'exclude_min' => true,
                    'exclude_max' => true,
                    'allow_nan' => false,
                    'allow_infinity' => false,
                    'width' => 64,
                    'min_value' => 0.5,
                    'max_value' => 4.5,
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'boolean',
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 1,
                    'max_size' => 5,
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'binary',
                    'min_size' => 2,
                    'max_size' => 2,
                ])
                ->and($requests[5]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($requests[6]['schema'])->toBe([
                    'type' => 'list',
                    'unique' => true,
                    'elements' => [
                        'type' => 'integer',
                        'min_value' => 1,
                        'max_value' => 3,
                    ],
                    'min_size' => 2,
                    'max_size' => 4,
                ])
                ->and($requests[7]['schema'])->toBe([
                    'type' => 'dict',
                    'keys' => [
                        'type' => 'string',
                        'min_size' => 1,
                        'max_size' => 5,
                    ],
                    'values' => [
                        'type' => 'integer',
                        'min_value' => 10,
                        'max_value' => 99,
                    ],
                    'min_size' => 1,
                    'max_size' => 2,
                ])
                ->and($requests[8]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 2,
                    'max_value' => 5,
                ])
                ->and($requests[9]['schema'])->toBe([
                    'one_of' => [
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 0],
                                [
                                    'type' => 'integer',
                                    'min_value' => 0,
                                    'max_value' => 0,
                                ],
                            ],
                        ],
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 1],
                                [
                                    'type' => 'string',
                                    'min_size' => 3,
                                    'max_size' => 6,
                                ],
                            ],
                        ],
                    ],
                ])
                ->and($requests[10]['schema'])->toBe([
                    'one_of' => [
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 0],
                                ['type' => 'null'],
                            ],
                        ],
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 1],
                                [
                                    'type' => 'string',
                                    'min_size' => 1,
                                    'max_size' => 5,
                                ],
                            ],
                        ],
                    ],
                ])
                ->and($requests[11]['schema'])->toBe([
                    'one_of' => [
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 0],
                                ['type' => 'null'],
                            ],
                        ],
                        [
                            'type' => 'tuple',
                            'elements' => [
                                ['const' => 1],
                                [
                                    'type' => 'string',
                                    'min_size' => 1,
                                    'max_size' => 5,
                                ],
                            ],
                        ],
                    ],
                ])
                ->and($requests[12]['schema'])->toBe([
                    'type' => 'email',
                ])
                ->and($requests[13]['schema'])->toBe([
                    'type' => 'url',
                ])
                ->and($requests[14]['schema'])->toBe([
                    'type' => 'domain',
                    'max_length' => 64,
                ])
                ->and($requests[15]['schema'])->toBe([
                    'type' => 'date',
                ])
                ->and($requests[16]['schema'])->toBe([
                    'type' => 'time',
                ])
                ->and($requests[17]['schema'])->toBe([
                    'type' => 'datetime',
                ])
                ->and($requests[18]['schema'])->toBe([
                    'one_of' => [
                        ['type' => 'ipv4'],
                        ['type' => 'ipv6'],
                    ],
                ])
                ->and($requests[19]['schema'])->toBe([
                    'type' => 'ipv4',
                ])
                ->and($requests[20]['schema'])->toBe([
                    'type' => 'ipv6',
                ])
                ->and($requests[21]['schema'])->toBe([
                    'type' => 'regex',
                    'pattern' => 'a+',
                    'fullmatch' => true,
                ])
                ->and($capture['mark_complete'])->toBe([
                    'command' => 'mark_complete',
                    'status' => 'VALID',
                    'origin' => null,
                ])
                ->and($capture['test_case_ack'])->toBe(['result' => null])
                ->and($capture['test_done_ack'])->toBe(['result' => true])
                ->and($capture['close']['message_id'])->toBe(0x7FFFFFFF)
                ->and($capture['close']['payload'])->toBe('fe');
        });
    });
});
