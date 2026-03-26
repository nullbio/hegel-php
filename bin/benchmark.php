#!/usr/bin/env php
<?php

declare(strict_types=1);

use CBOR\Decoder;
use CBOR\Encoder;
use CBOR\StringStream;
use Hegel\Generators;
use Hegel\Protocol\CborCodec;

require dirname(__DIR__) . '/vendor/autoload.php';

const VALID_MODES = ['all', 'cbor', 'generator'];
const CBOR_ITERATIONS = 100_000;
const GENERATOR_ITERATIONS = 500_000;

$mode = $argv[1] ?? 'all';

if (! in_array($mode, VALID_MODES, true)) {
    fwrite(STDERR, sprintf(
        "Unknown benchmark mode %s. Expected one of: %s.\n",
        $mode,
        implode(', ', VALID_MODES),
    ));
    exit(1);
}

/** @var array<string, array{iterations: int, milliseconds: float}> $results */
$results = [];

if ($mode === 'all' || $mode === 'cbor') {
    $payload = [
        'type' => 'dict',
        'keys' => [
            'type' => 'string',
            'min_size' => 1,
            'max_size' => 16,
        ],
        'values' => [
            'type' => 'list',
            'min_size' => 1,
            'max_size' => 8,
            'elements' => [
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 10,
            ],
        ],
        'min_size' => 1,
        'max_size' => 8,
    ];

    $results['cbor_fresh_raw'] = benchmark(CBOR_ITERATIONS, static function () use ($payload): void {
        $encodedPayload = (new Encoder())->encode($payload);
        Decoder::create()->decode(StringStream::create($encodedPayload));
    });

    $encoder = new Encoder();
    $decoder = Decoder::create();
    $results['cbor_reused_raw'] = benchmark(CBOR_ITERATIONS, static function () use ($encoder, $decoder, $payload): void {
        $encodedPayload = $encoder->encode($payload);
        $decoder->decode(StringStream::create($encodedPayload));
    });

    $results['cbor_sdk_codec'] = benchmark(CBOR_ITERATIONS, static function () use ($payload): void {
        $encodedPayload = CborCodec::encode($payload);
        CborCodec::decode($encodedPayload);
    });
}

if ($mode === 'all' || $mode === 'generator') {
    $generator = Generators::arrays(
        Generators::integers()->minValue(1)->maxValue(10),
    )
        ->minSize(1)
        ->maxSize(5)
        ->unique();

    $results['generator_basic_schema'] = benchmark(GENERATOR_ITERATIONS, static function () use ($generator): void {
        $generator->basic()?->schema();
    });
}

foreach ($results as $name => $result) {
    $milliseconds = number_format($result['milliseconds'], 3, '.', '');
    $opsPerSecond = $result['milliseconds'] === 0.0
        ? 'inf'
        : number_format(($result['iterations'] / $result['milliseconds']) * 1000, 0, '.', ',');

    fwrite(STDOUT, sprintf(
        "%s: %s ms (%d iterations, %s ops/s)\n",
        $name,
        $milliseconds,
        $result['iterations'],
        $opsPerSecond,
    ));
}

/**
 * @return array{iterations: int, milliseconds: float}
 */
function benchmark(int $iterations, callable $callback): array
{
    $start = hrtime(true);

    for ($index = 0; $index < $iterations; $index++) {
        $callback();
    }

    return [
        'iterations' => $iterations,
        'milliseconds' => (hrtime(true) - $start) / 1_000_000,
    ];
}
