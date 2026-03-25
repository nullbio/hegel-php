<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\SpanLabel;
use Hegel\TestCase;

it('falls back to local composition when generators are not basic', function (): void {
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
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $array = $testCase->draw(
                    Generators::arrays(
                        Generators::composite(
                            fn (TestCase $inner): int|string => $inner->draw(
                                Generators::integers()->minValue(10)->maxValue(20),
                            ),
                        ),
                    )
                        ->minSize(1)
                        ->maxSize(2),
                );
                $filtered = $testCase->draw(
                    Generators::integers()
                        ->minValue(1)
                        ->maxValue(4)
                        ->filter(fn (int|string $value): bool => ((int) $value % 2) === 0),
                );
                $flatMapped = $testCase->draw(
                    Generators::integers()
                        ->minValue(2)
                        ->maxValue(2)
                        ->flatMap(
                            fn (int|string $value) => Generators::arrays(
                                Generators::integers()->minValue(5)->maxValue(6),
                            )
                                ->minSize((int) $value)
                                ->maxSize((int) $value),
                        ),
                );
                $map = $testCase->draw(
                    Generators::maps(
                        Generators::composite(
                            fn (TestCase $inner): string => $inner->draw(
                                Generators::text()->minSize(4)->maxSize(5),
                            ),
                        ),
                        Generators::integers()->minValue(1)->maxValue(2),
                    )
                        ->minSize(2)
                        ->maxSize(2),
                );
                $oneOf = $testCase->draw(
                    Generators::oneOf(
                        Generators::just('skip'),
                        Generators::composite(
                            fn (TestCase $inner): int|string => $inner->draw(
                                Generators::integers()->minValue(9)->maxValue(9),
                            ),
                        ),
                    ),
                );
                $optional = $testCase->draw(
                    Generators::optional(
                        Generators::composite(
                            fn (TestCase $inner): string => $inner->draw(
                                Generators::text()->minSize(3)->maxSize(3),
                            ),
                        ),
                    ),
                );

                expect($array)->toBe([10, 20])
                    ->and($filtered)->toBe(4)
                    ->and($flatMapped)->toBe([5, 6])
                    ->and($map)->toBe(['left' => 1, 'right' => 2])
                    ->and($oneOf)->toBe(9)
                    ->and($optional)->toBe('opt');
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];
            $labels = array_column($capture['start_spans'], 'label');
            $discardedStops = array_values(
                array_filter(
                    $capture['stop_spans'],
                    static fn (array $payload): bool => $payload['discard'] === true,
                ),
            );

            expect($capture['new_collection'])->toBe([
                'command' => 'new_collection',
                'name' => 'composite_list',
                'min_size' => 1,
                'max_size' => 2,
            ])
                ->and($capture['collection_more'])->toBe([
                    ['command' => 'collection_more', 'collection' => 'composite-list'],
                    ['command' => 'collection_more', 'collection' => 'composite-list'],
                    ['command' => 'collection_more', 'collection' => 'composite-list'],
                ])
                ->and($requests)->toHaveCount(16)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 10,
                    'max_value' => 20,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 10,
                    'max_value' => 20,
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 4,
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 4,
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 4,
                ])
                ->and($requests[5]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 2,
                    'max_value' => 2,
                ])
                ->and($requests[6]['schema'])->toBe([
                    'type' => 'list',
                    'unique' => false,
                    'elements' => [
                        'type' => 'integer',
                        'min_value' => 5,
                        'max_value' => 6,
                    ],
                    'min_size' => 2,
                    'max_size' => 2,
                ])
                ->and($requests[7]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 2,
                    'max_value' => 2,
                ])
                ->and($requests[8]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 4,
                    'max_size' => 5,
                ])
                ->and($requests[9]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 2,
                ])
                ->and($requests[10]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 4,
                    'max_size' => 5,
                ])
                ->and($requests[11]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => 2,
                ])
                ->and($requests[12]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 1,
                ])
                ->and($requests[13]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 9,
                    'max_value' => 9,
                ])
                ->and($requests[14]['schema'])->toBe([
                    'type' => 'boolean',
                ])
                ->and($requests[15]['schema'])->toBe([
                    'type' => 'string',
                    'min_size' => 3,
                    'max_size' => 3,
                ])
                ->and($labels)->toContain(SpanLabel::LIST)
                ->and($labels)->toContain(SpanLabel::FILTER)
                ->and($labels)->toContain(SpanLabel::FLAT_MAP)
                ->and($labels)->toContain(SpanLabel::MAP)
                ->and($labels)->toContain(SpanLabel::MAP_ENTRY)
                ->and($labels)->toContain(SpanLabel::ONE_OF)
                ->and($labels)->toContain(SpanLabel::OPTIONAL)
                ->and($discardedStops)->toHaveCount(2)
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
