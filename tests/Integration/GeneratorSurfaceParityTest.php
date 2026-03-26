<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;

it('sends the expected schemas for set tuple fixed dict fixed array and unit generators', function (): void {
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
            'HEGEL_FAKE_GENERATOR_SERVER_MODE' => 'parity_generators',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $set = $testCase->draw(
                    Generators::hashSets(Generators::integers()->minValue(1)->maxValue(10))
                        ->minSize(2)
                        ->maxSize(3),
                );
                $tuple = $testCase->draw(
                    Generators::tuples(
                        Generators::integers()->minValue(7)->maxValue(7),
                        Generators::text()->minSize(4)->maxSize(4),
                        Generators::booleans(),
                    ),
                );
                $fixedArray = $testCase->draw(
                    Generators::fixedArrays(
                        Generators::integers()->minValue(8)->maxValue(9),
                        2,
                    ),
                );
                $fixedDict = $testCase->draw(
                    Generators::fixedDicts()
                        ->field('name', Generators::text()->minSize(1)->maxSize(5))
                        ->field('age', Generators::integers()->minValue(18)->maxValue(99))
                        ->build(),
                );
                $unit = $testCase->draw(Generators::unit());
                $emptyTuple = $testCase->draw(Generators::tuples());

                expect($set)->toBe([1, 2, 3])
                    ->and($tuple)->toBe([7, 'pair', true])
                    ->and($fixedArray)->toBe([9, 8])
                    ->and($fixedDict)->toBe([
                        'name' => 'Ada',
                        'age' => 37,
                    ])
                    ->and($unit)->toBeNull()
                    ->and($emptyTuple)->toBe([]);
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];

            expect($requests)->toHaveCount(5)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'list',
                    'unique' => true,
                    'elements' => [
                        'type' => 'integer',
                        'min_value' => 1,
                        'max_value' => 10,
                    ],
                    'min_size' => 2,
                    'max_size' => 3,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'integer',
                            'min_value' => 7,
                            'max_value' => 7,
                        ],
                        [
                            'type' => 'string',
                            'min_size' => 4,
                            'max_size' => 4,
                        ],
                        [
                            'type' => 'boolean',
                        ],
                    ],
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'integer',
                            'min_value' => 8,
                            'max_value' => 9,
                        ],
                        [
                            'type' => 'integer',
                            'min_value' => 8,
                            'max_value' => 9,
                        ],
                    ],
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'string',
                            'min_size' => 1,
                            'max_size' => 5,
                        ],
                        [
                            'type' => 'integer',
                            'min_value' => 18,
                            'max_value' => 99,
                        ],
                    ],
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [],
                ])
                ->and($capture['mark_complete'])->toBe([
                    'command' => 'mark_complete',
                    'status' => 'VALID',
                    'origin' => null,
                ])
                ->and($capture['test_case_ack'])->toBe(['result' => null])
                ->and($capture['test_done_ack'])->toBe(['result' => true]);
        });
    });
});
