<?php

declare(strict_types=1);

use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;
use Random\Engine\Mt19937;

it('uses shrinkable randomizer draws through primitive schemas', function (): void {
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
            'HEGEL_FAKE_GENERATOR_SERVER_MODE' => 'randomizer',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $random = $testCase->randomizer();

                $int = $random->getInt(1, 3);
                $float = $random->getFloat(1.0, 2.0);
                $bytes = $random->getBytes(2);
                $sampled = $random->getBytesFromString('wxyz', 3);
                $shuffled = $random->shuffleArray(['a', 'b', 'c']);
                $picked = $random->pickArrayKeys([
                    'left' => 1,
                    'right' => 2,
                    'up' => 3,
                ], 2);

                expect($int)->toBe(2)
                    ->and($float)->toBe(1.5)
                    ->and($bytes)->toBe("\xAA\xBB")
                    ->and($sampled)->toBe('ywz')
                    ->and($shuffled)->toBe(['c', 'b', 'a'])
                    ->and($picked)->toBe(['up', 'left']);
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];

            expect($requests)->toHaveCount(10)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 3,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'float',
                    'exclude_min' => false,
                    'exclude_max' => true,
                    'allow_nan' => false,
                    'allow_infinity' => false,
                    'width' => 64,
                    'min_value' => 1,
                    'max_value' => 2,
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'binary',
                    'min_size' => 2,
                    'max_size' => 2,
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 3,
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 3,
                ])
                ->and($requests[5]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 3,
                ])
                ->and($requests[6]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($requests[7]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 1,
                ])
                ->and($requests[8]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($requests[9]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 1,
                ]);
        });
    });
});

it('can produce a seeded native randomizer from one hegel draw', function (): void {
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
            'HEGEL_FAKE_GENERATOR_SERVER_MODE' => 'randomizer_true',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $random = $testCase->randomizer(true);
                $expected = new \Random\Randomizer(new Mt19937(123));

                expect($random->getInt(1, 10))->toBe($expected->getInt(1, 10))
                    ->and($random->getBytes(4))->toBe($expected->getBytes(4))
                    ->and($random->shuffleArray(['a', 'b', 'c']))->toBe($expected->shuffleArray(['a', 'b', 'c']));
            });

            $capture = hegelReadJsonFile($captureFile);

            expect($capture['generate_requests'])->toBe([
                [
                    'command' => 'generate',
                    'schema' => [
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => PHP_INT_MAX,
                    ],
                ],
            ]);
        });
    });
});
