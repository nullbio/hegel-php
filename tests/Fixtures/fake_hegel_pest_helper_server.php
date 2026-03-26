<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fake_hegel_transport.php';

$argv = $_SERVER['argv'] ?? [];
['reader' => $reader, 'writer' => $writer, 'close' => $closeTransport] = hegelFakeOpenTransport($argv);

$capture = [
    'argv' => array_slice($argv, 1),
    'generate_requests' => [],
];

$handshake = Packet::readFrom($reader);
$capture['handshake'] = [
    'channel_id' => $handshake->channelId,
    'message_id' => $handshake->messageId,
    'payload' => $handshake->payload,
];
Packet::writeTo($writer, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runTest = Packet::readFrom($reader);
$capture['run_test'] = [
    'channel_id' => $runTest->channelId,
    'message_id' => $runTest->messageId,
    'payload' => CborCodec::decode($runTest->payload),
];
Packet::writeTo(
    $writer,
    new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
);

$eventChannelId = $capture['run_test']['payload']['channel_id'];
Packet::writeTo(
    $writer,
    new Packet($eventChannelId, 1, false, CborCodec::encode([
        'event' => 'test_case',
        'channel_id' => 8,
    ])),
);

$testCaseAck = Packet::readFrom($reader);
$capture['test_case_ack'] = CborCodec::decode($testCaseAck->payload);

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

    if ($command === 'generate') {
        $capture['generate_requests'][] = $payload;

        Packet::writeTo(
            $writer,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => 2])),
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
