<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\SpanLabel;
use Hegel\TestCase;

/**
 * @return array<string, mixed>
 */
function hegelRunGeneratorFallbackScenario(string $mode, callable $property): array
{
    $capture = null;

    hegelWithTemporaryProject(function (string $projectDirectory) use ($mode, $property, &$capture): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_generator_fallback_server.php',
            'fake-hegel-generator-fallback',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_GENERATOR_FALLBACK_MODE' => $mode,
        ], function () use ($property, $captureFile, &$capture): void {
            (new Hegel(new Settings()))->run($property);
            $capture = hegelReadJsonFile($captureFile);
        });
    });

    expect($capture)->toBeArray();
    assert(is_array($capture));

    return $capture;
}

it('falls back to local map composition for non-basic generators', function (): void {
    $capture = hegelRunGeneratorFallbackScenario(
        'mapped',
        function (TestCase $testCase): void {
            $mapped = $testCase->draw(
                Generators::composite(
                    fn (TestCase $inner): int|string => $inner->draw(
                        Generators::integers()->minValue(5)->maxValue(5),
                    ),
                )->map(
                    fn (int|string $value): string => str_repeat('x', (int) $value),
                ),
            );

            expect($mapped)->toBe('xxxxx');
        },
    );

    expect($capture['generate_requests'])->toBe([
        [
            'command' => 'generate',
            'schema' => [
                'type' => 'integer',
                'min_value' => 5,
                'max_value' => 5,
            ],
        ],
    ])
        ->and(array_column($capture['start_spans'], 'label'))->toContain(SpanLabel::MAPPED)
        ->and($capture['mark_complete'])->toBe([
            'command' => 'mark_complete',
            'status' => 'VALID',
            'origin' => null,
        ]);
});

it('marks the test case invalid after filter rejects three values', function (): void {
    $capture = hegelRunGeneratorFallbackScenario(
        'filter_exhaustion',
        function (TestCase $testCase): void {
            $testCase->draw(
                Generators::integers()
                    ->minValue(1)
                    ->maxValue(1)
                    ->filter(static fn (): bool => false),
            );
        },
    );

    $filterSpans = array_values(
        array_filter(
            $capture['start_spans'],
            static fn (array $payload): bool => $payload['label'] === SpanLabel::FILTER,
        ),
    );
    $discardedStops = array_values(
        array_filter(
            $capture['stop_spans'],
            static fn (array $payload): bool => $payload['discard'] === true,
        ),
    );

    expect($capture['generate_requests'])->toHaveCount(3)
        ->and($filterSpans)->toHaveCount(3)
        ->and($discardedStops)->toHaveCount(3)
        ->and($capture['mark_complete'])->toBe([
            'command' => 'mark_complete',
            'status' => 'INVALID',
            'origin' => null,
        ]);
});

it('surfaces invalid flatMap callbacks from local composition', function (): void {
    $capture = hegelRunGeneratorFallbackScenario(
        'flat_map_invalid_callback',
        function (TestCase $testCase): void {
            expect(fn (): mixed => $testCase->draw(
                Generators::integers()
                    ->minValue(2)
                    ->maxValue(2)
                    ->flatMap(static fn (): int => 123),
            ))->toThrow(RuntimeException::class, 'flatMap callback must return a generator, got int.');
        },
    );

    expect($capture['generate_requests'])->toBe([
        [
            'command' => 'generate',
            'schema' => [
                'type' => 'integer',
                'min_value' => 2,
                'max_value' => 2,
            ],
        ],
    ])
        ->and(array_column($capture['start_spans'], 'label'))->toContain(SpanLabel::FLAT_MAP)
        ->and($capture['mark_complete'])->toBe([
            'command' => 'mark_complete',
            'status' => 'VALID',
            'origin' => null,
        ]);
});

it('fails when fallback map generation cannot produce enough unique keys', function (): void {
    $capture = hegelRunGeneratorFallbackScenario(
        'map_collision',
        function (TestCase $testCase): void {
            expect(fn (): mixed => $testCase->draw(
                Generators::maps(
                    Generators::composite(
                        fn (TestCase $inner): string => $inner->draw(
                            Generators::text()->minSize(3)->maxSize(3),
                        ),
                    ),
                    Generators::integers()->minValue(1)->maxValue(1),
                )
                    ->minSize(2)
                    ->maxSize(2),
            ))->toThrow(RuntimeException::class, 'Failed to generate enough unique map keys.');
        },
    );

    $mapEntrySpans = array_values(
        array_filter(
            $capture['start_spans'],
            static fn (array $payload): bool => $payload['label'] === SpanLabel::MAP_ENTRY,
        ),
    );

    expect($capture['generate_requests'])->toHaveCount(22)
        ->and($capture['generate_requests'][0]['schema'])->toBe([
            'type' => 'integer',
            'min_value' => 2,
            'max_value' => 2,
        ])
        ->and($mapEntrySpans)->toHaveCount(20)
        ->and($capture['mark_complete'])->toBe([
            'command' => 'mark_complete',
            'status' => 'VALID',
            'origin' => null,
        ]);
});

it('returns null from optional fallback without drawing the inner generator', function (): void {
    $capture = hegelRunGeneratorFallbackScenario(
        'optional_none',
        function (TestCase $testCase): void {
            $value = $testCase->draw(
                Generators::optional(
                    Generators::composite(
                        fn (TestCase $inner): string => $inner->draw(
                            Generators::text()->minSize(3)->maxSize(3),
                        ),
                    ),
                ),
            );

            expect($value)->toBeNull();
        },
    );

    expect($capture['generate_requests'])->toBe([
        [
            'command' => 'generate',
            'schema' => [
                'type' => 'boolean',
            ],
        ],
    ])
        ->and($capture['start_spans'])->toBe([
            ['command' => 'start_span', 'label' => SpanLabel::OPTIONAL],
        ])
        ->and($capture['stop_spans'])->toBe([
            ['command' => 'stop_span', 'discard' => false],
        ])
        ->and($capture['mark_complete'])->toBe([
            'command' => 'mark_complete',
            'status' => 'VALID',
            'origin' => null,
        ]);
});
