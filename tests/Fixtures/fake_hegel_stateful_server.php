<?php

declare(strict_types=1);

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

$mode = getenv('HEGEL_FAKE_STATEFUL_MODE');

if (! is_string($mode) || $mode === '') {
    $mode = 'valid';
}

$handshake = Packet::readFrom($connection);
Packet::writeTo($connection, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runTest = Packet::readFrom($connection);
$runTestPayload = CborCodec::decode($runTest->payload);
Packet::writeTo(
    $connection,
    new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
);

$capture = [
    'mode' => $mode,
    'run_test' => $runTestPayload,
    'cases' => [],
];

$eventChannelId = $runTestPayload['channel_id'];
$nextEventId = 1;

$sendEvent = static function (array $payload) use ($connection, $eventChannelId, &$nextEventId): int {
    $messageId = $nextEventId;
    $nextEventId++;

    Packet::writeTo(
        $connection,
        new Packet($eventChannelId, $messageId, false, CborCodec::encode($payload)),
    );

    return $messageId;
};

$readEventAck = static function () use ($connection): array {
    $packet = Packet::readFrom($connection);

    return [
        'channel_id' => $packet->channelId,
        'message_id' => $packet->messageId,
        'payload' => CborCodec::decode($packet->payload),
    ];
};

$handleCase = static function (
    int $channelId,
    array $generateResponses,
    array $poolAddResponses,
    array $poolGenerateResponses,
    string $expectedStatus,
) use ($connection, $sendEvent, $readEventAck, &$capture): void {
    $sendEvent([
        'event' => 'test_case',
        'channel_id' => $channelId,
    ]);

    $caseCapture = [
        'channel_id' => $channelId,
        'ack' => $readEventAck(),
        'commands' => [],
    ];

    while (true) {
        $packet = Packet::readFrom($connection);

        if ($packet->channelId !== $channelId) {
            fwrite(STDERR, sprintf("unexpected channel: %d\n", $packet->channelId));
            exit(1);
        }

        if ($packet->messageId === 0x7FFFFFFF) {
            $caseCapture['close'] = [
                'message_id' => $packet->messageId,
                'payload' => bin2hex($packet->payload),
            ];
            break;
        }

        $payload = CborCodec::decode($packet->payload);
        $caseCapture['commands'][] = $payload;
        $command = $payload['command'] ?? null;

        if ($command === 'generate') {
            $response = array_shift($generateResponses);
            Packet::writeTo(
                $connection,
                new Packet($channelId, $packet->messageId, true, CborCodec::encode(['result' => $response])),
            );
            continue;
        }

        if ($command === 'new_pool') {
            Packet::writeTo(
                $connection,
                new Packet($channelId, $packet->messageId, true, CborCodec::encode(['result' => 1])),
            );
            continue;
        }

        if ($command === 'pool_add') {
            $response = array_shift($poolAddResponses);
            Packet::writeTo(
                $connection,
                new Packet($channelId, $packet->messageId, true, CborCodec::encode(['result' => $response])),
            );
            continue;
        }

        if ($command === 'pool_generate') {
            $response = array_shift($poolGenerateResponses);
            Packet::writeTo(
                $connection,
                new Packet($channelId, $packet->messageId, true, CborCodec::encode(['result' => $response])),
            );
            continue;
        }

        if ($command === 'mark_complete') {
            $caseCapture['mark_complete'] = $payload;

            if (($payload['status'] ?? null) !== $expectedStatus) {
                fwrite(STDERR, sprintf("unexpected status: %s\n", json_encode($payload)));
                exit(2);
            }

            Packet::writeTo(
                $connection,
                new Packet($channelId, $packet->messageId, true, CborCodec::encode(['result' => null])),
            );
            continue;
        }

        fwrite(STDERR, sprintf("unexpected command: %s\n", json_encode($payload)));
        exit(1);
    }

    $capture['cases'][] = $caseCapture;
};

if ($mode === 'valid') {
    $handleCase(8, [3, 0, 1, 2], [100], [100, 100], 'VALID');

    $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);

    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'interesting') {
    $handleCase(8, [1, 0], [], [], 'INTERESTING');

    $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => false,
            'interesting_test_cases' => 1,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);

    $capture['test_done_ack'] = $readEventAck();
    $handleCase(10, [1, 0], [], [], 'INTERESTING');
} else {
    fwrite(STDERR, sprintf("unknown mode: %s\n", $mode));
    exit(1);
}

$captureFile = getenv('HEGEL_FAKE_CAPTURE_FILE');

if (is_string($captureFile) && $captureFile !== '') {
    file_put_contents($captureFile, json_encode($capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

fclose($connection);
fclose($server);
