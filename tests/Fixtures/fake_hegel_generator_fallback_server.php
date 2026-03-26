<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fake_hegel_transport.php';

$argv = $_SERVER['argv'] ?? [];
['reader' => $reader, 'writer' => $writer, 'close' => $closeTransport] = hegelFakeOpenTransport($argv);

$capture = [
    'commands' => [],
    'generate_requests' => [],
    'start_spans' => [],
    'stop_spans' => [],
    'collection_more' => [],
];

$mode = getenv('HEGEL_FAKE_GENERATOR_FALLBACK_MODE');

if (! is_string($mode) || $mode === '') {
    $mode = 'happy';
}

$handshake = Packet::readFrom($reader);
Packet::writeTo($writer, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runTest = Packet::readFrom($reader);
$runTestPayload = CborCodec::decode($runTest->payload);
Packet::writeTo(
    $writer,
    new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
);

$eventChannelId = $runTestPayload['channel_id'];
Packet::writeTo(
    $writer,
    new Packet($eventChannelId, 1, false, CborCodec::encode([
        'event' => 'test_case',
        'channel_id' => 8,
    ])),
);

$testCaseAck = Packet::readFrom($reader);
$capture['test_case_ack'] = CborCodec::decode($testCaseAck->payload);

[$generateResponses, $collectionMoreResponses] = match ($mode) {
    'mapped' => [
        [5],
        [],
    ],
    'filter_exhaustion' => [
        [1, 1, 1],
        [],
    ],
    'flat_map_invalid_callback' => [
        [2],
        [],
    ],
    'map_collision' => [
        array_merge([2, 'dup', 1], array_fill(0, 19, 'dup')),
        [],
    ],
    'optional_none' => [
        [false],
        [],
    ],
    'parity_generators' => [
        [2, 30, 40, 7, 'xy', 60, 70, 'john', 80],
        [],
    ],
    default => [
        [
            10,
            20,
            1,
            3,
            4,
            2,
            [5, 6],
            2,
            'left',
            1,
            'right',
            2,
            1,
            9,
            true,
            'opt',
        ],
        [true, true, false],
    ],
};

while (true) {
    $packet = Packet::readFrom($reader);

    if ($packet->channelId !== 8) {
        fwrite(STDERR, sprintf("unexpected channel: %d\n", $packet->channelId));
        exit(1);
    }

    if ($packet->messageId === 0x7FFFFFFF) {
        $capture['close'] = [
            'message_id' => $packet->messageId,
            'payload' => bin2hex($packet->payload),
        ];
        break;
    }

    $payload = CborCodec::decode($packet->payload);
    $command = $payload['command'] ?? null;
    $capture['commands'][] = $payload;

    if ($command === 'generate') {
        $capture['generate_requests'][] = $payload;
        $response = array_shift($generateResponses);

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => $response])),
        );
        continue;
    }

    if ($command === 'start_span') {
        $capture['start_spans'][] = $payload;

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => null])),
        );
        continue;
    }

    if ($command === 'stop_span') {
        $capture['stop_spans'][] = $payload;

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => null])),
        );
        continue;
    }

    if ($command === 'new_collection') {
        $capture['new_collection'] = $payload;

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => 'composite-list'])),
        );
        continue;
    }

    if ($command === 'collection_more') {
        $capture['collection_more'][] = $payload;
        $response = array_shift($collectionMoreResponses);

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => $response])),
        );
        continue;
    }

    if ($command === 'mark_complete') {
        $capture['mark_complete'] = $payload;

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => null])),
        );
        continue;
    }

    fwrite(STDERR, sprintf("unexpected command: %s\n", json_encode($payload)));
    exit(1);
}

Packet::writeTo(
    $writer,
    new Packet($eventChannelId, 2, false, CborCodec::encode([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ])),
);

$testDoneAck = Packet::readFrom($reader);
$capture['test_done_ack'] = CborCodec::decode($testDoneAck->payload);

$captureFile = getenv('HEGEL_FAKE_CAPTURE_FILE');

if (is_string($captureFile) && $captureFile !== '') {
    file_put_contents($captureFile, json_encode($capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

$closeTransport();
