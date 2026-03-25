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

$mode = getenv('HEGEL_FAKE_RUNNER_MODE');

if (! is_string($mode) || $mode === '') {
    $mode = 'valid';
}

$capture = [
    'mode' => $mode,
    'cases' => [],
];

$handshake = Packet::readFrom($connection);
$capture['handshake'] = [
    'channel_id' => $handshake->channelId,
    'message_id' => $handshake->messageId,
    'payload' => $handshake->payload,
];

Packet::writeTo($connection, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runTest = Packet::readFrom($connection);
$runTestPayload = CborCodec::decode($runTest->payload);
$capture['run_test'] = [
    'channel_id' => $runTest->channelId,
    'message_id' => $runTest->messageId,
    'payload' => $runTestPayload,
];

Packet::writeTo(
    $connection,
    new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
);

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
        'is_reply' => $packet->isReply,
        'payload' => CborCodec::decode($packet->payload),
    ];
};

$handleCase = static function (int $channelId, string $expectedStatus) use ($connection, $sendEvent, $readEventAck, &$capture): void {
    $eventId = $sendEvent([
        'event' => 'test_case',
        'channel_id' => $channelId,
    ]);

    $caseCapture = [
        'event_id' => $eventId,
        'channel_id' => $channelId,
        'ack' => $readEventAck(),
    ];

    $markComplete = Packet::readFrom($connection);
    $markCompletePayload = CborCodec::decode($markComplete->payload);
    $caseCapture['mark_complete'] = [
        'channel_id' => $markComplete->channelId,
        'message_id' => $markComplete->messageId,
        'payload' => $markCompletePayload,
    ];

    Packet::writeTo(
        $connection,
        new Packet($channelId, $markComplete->messageId, true, CborCodec::encode(['result' => null])),
    );

    $closePacket = Packet::readFrom($connection);
    $caseCapture['close'] = [
        'channel_id' => $closePacket->channelId,
        'message_id' => $closePacket->messageId,
        'payload' => bin2hex($closePacket->payload),
    ];

    if (($markCompletePayload['status'] ?? null) !== $expectedStatus) {
        fwrite(STDERR, sprintf("unexpected status: %s\n", json_encode($markCompletePayload)));
        exit(2);
    }

    $capture['cases'][] = $caseCapture;
};

if ($mode === 'valid') {
    $handleCase(8, 'VALID');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'invalid') {
    $handleCase(8, 'INVALID');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'interesting') {
    $handleCase(8, 'INTERESTING');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => false,
            'interesting_test_cases' => 1,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
    $handleCase(10, 'INTERESTING');
} elseif ($mode === 'server_error') {
    $handleCase(8, 'VALID');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => 'boom',
            'health_check_failure' => null,
            'flaky' => null,
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'health_check_failure') {
    $handleCase(8, 'VALID');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => 'filter_too_much',
            'flaky' => null,
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'flaky') {
    $handleCase(8, 'VALID');
    $testDoneEventId = $sendEvent([
        'event' => 'test_done',
        'results' => [
            'passed' => true,
            'interesting_test_cases' => 0,
            'error' => null,
            'health_check_failure' => null,
            'flaky' => 'inconsistent replay',
        ],
    ]);
    $capture['test_done_event_id'] = $testDoneEventId;
    $capture['test_done_ack'] = $readEventAck();
} elseif ($mode === 'server_exit') {
    $eventId = $sendEvent([
        'event' => 'test_case',
        'channel_id' => 8,
    ]);
    $capture['cases'][] = [
        'event_id' => $eventId,
        'channel_id' => 8,
        'ack' => $readEventAck(),
    ];
    fwrite(STDERR, "runner crashed unexpectedly\n");
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
