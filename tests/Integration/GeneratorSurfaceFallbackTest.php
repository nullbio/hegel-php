<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\SpanLabel;
use Hegel\TestCase;

it('falls back to local composition for set tuple fixed dict and fixed array generators', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_generator_fallback_server.php',
            'fake-hegel-generator-fallback',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_GENERATOR_FALLBACK_MODE' => 'parity_generators',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $set = $testCase->draw(
                    Generators::hashSets(
                        Generators::composite(
                            fn (TestCase $inner): int|string => $inner->draw(
                                Generators::integers()->minValue(30)->maxValue(40),
                            ),
                        ),
                    )
                        ->minSize(2)
                        ->maxSize(2),
                );
                $tuple = $testCase->draw(
                    Generators::tuples(
                        Generators::composite(
                            fn (TestCase $inner): int|string => $inner->draw(
                                Generators::integers()->minValue(7)->maxValue(7),
                            ),
                        ),
                        Generators::composite(
                            fn (TestCase $inner): string => $inner->draw(
                                Generators::text()->minSize(2)->maxSize(2),
                            ),
                        ),
                    ),
                );
                $fixedArray = $testCase->draw(
                    Generators::fixedArrays(
                        Generators::composite(
                            fn (TestCase $inner): int|string => $inner->draw(
                                Generators::integers()->minValue(60)->maxValue(70),
                            ),
                        ),
                        2,
                    ),
                );
                $fixedDict = $testCase->draw(
                    Generators::fixedDicts()
                        ->field(
                            'name',
                            Generators::composite(
                                fn (TestCase $inner): string => $inner->draw(
                                    Generators::text()->minSize(4)->maxSize(4),
                                ),
                            ),
                        )
                        ->field(
                            'age',
                            Generators::composite(
                                fn (TestCase $inner): int|string => $inner->draw(
                                    Generators::integers()->minValue(80)->maxValue(80),
                                ),
                            ),
                        )
                        ->build(),
                );
                $unit = $testCase->draw(Generators::unit());

                expect($set)->toBe([30, 40])
                    ->and($tuple)->toBe([7, 'xy'])
                    ->and($fixedArray)->toBe([60, 70])
                    ->and($fixedDict)->toBe([
                        'name' => 'john',
                        'age' => 80,
                    ])
                    ->and($unit)->toBeNull();
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];
            $labels = array_column($capture['start_spans'], 'label');

            expect($requests)->toHaveCount(9)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 2,
                    'max_value' => 2,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 30,
                    'max_value' => 40,
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 30,
                    'max_value' => 40,
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 7,
                    'max_value' => 7,
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 2,
                    'max_size' => 2,
                ])
                ->and($requests[5]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 60,
                    'max_value' => 70,
                ])
                ->and($requests[6]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 60,
                    'max_value' => 70,
                ])
                ->and($requests[7]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 4,
                    'max_size' => 4,
                ])
                ->and($requests[8]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 80,
                    'max_value' => 80,
                ])
                ->and($labels)->toContain(SpanLabel::SET)
                ->and($labels)->toContain(SpanLabel::SET_ELEMENT)
                ->and($labels)->toContain(SpanLabel::TUPLE)
                ->and($labels)->toContain(SpanLabel::FIXED_DICT)
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
