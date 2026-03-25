<?php

declare(strict_types=1);

use CBOR\ByteStringObject;
use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$socketPath = $argv[1] ?? null;

if (! is_string($socketPath) || $socketPath === '') {
    fwrite(STDERR, "missing socket path\n");
    exit(1);
}

$server = @stream_socket_server('unix://' . $socketPath, $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, sprintf("failed to create socket: %s\n", $errorMessage));
    exit(1);
}

$connection = @stream_socket_accept($server, 5);

if ($connection === false) {
    fwrite(STDERR, "failed to accept connection\n");
    exit(1);
}

$capture = [
    'generate_requests' => [],
];

$handshake = Packet::readFrom($connection);
Packet::writeTo($connection, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runTest = Packet::readFrom($connection);
$runTestPayload = CborCodec::decode($runTest->payload);
Packet::writeTo(
    $connection,
    new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
);

$eventChannelId = $runTestPayload['channel_id'];
Packet::writeTo(
    $connection,
    new Packet($eventChannelId, 1, false, CborCodec::encode([
        'event' => 'test_case',
        'channel_id' => 8,
    ])),
);

$testCaseAck = Packet::readFrom($connection);
$capture['test_case_ack'] = CborCodec::decode($testCaseAck->payload);

$responses = [
    7,
    3.5,
    true,
    'hello',
    ByteStringObject::create("\xFF\x00"),
    1,
    [1, 2, 3],
    [
        ['alpha', 11],
        ['beta', 22],
    ],
    4,
    [1, 'picked'],
    [0, null],
    [1, 'later'],
    'user@example.test',
    'https://example.test/path',
    'example.test',
    '2025-01-02',
    '03:04:05',
    '2025-01-02T03:04:05+00:00',
    '2001:db8::1',
    '192.0.2.1',
    '2001:db8::2',
    'aaaa',
];

while (true) {
    $packet = Packet::readFrom($connection);

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
        $response = array_shift($responses);

        Packet::writeTo(
            $connection,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => $response])),
        );
        continue;
    }

    if ($command === 'mark_complete') {
        $capture['mark_complete'] = $payload;

        Packet::writeTo(
            $connection,
            new Packet(8, $packet->messageId, true, CborCodec::encode(['result' => null])),
        );
        continue;
    }

    fwrite(STDERR, sprintf("unexpected command: %s\n", json_encode($payload)));
    exit(1);
}

Packet::writeTo(
    $connection,
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

$testDoneAck = Packet::readFrom($connection);
$capture['test_done_ack'] = CborCodec::decode($testDoneAck->payload);

$captureFile = getenv('HEGEL_FAKE_CAPTURE_FILE');

if (is_string($captureFile) && $captureFile !== '') {
    file_put_contents($captureFile, json_encode($capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

fclose($connection);
fclose($server);
