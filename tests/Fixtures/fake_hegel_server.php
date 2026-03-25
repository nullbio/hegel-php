<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
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

fwrite(STDOUT, "fake-hegel-server ready\n");
fwrite(STDERR, "fake-hegel-server stderr\n");

$connection = @stream_socket_accept($server, 5);

if ($connection === false) {
    fwrite(STDERR, "failed to accept connection\n");
    exit(1);
}

$capture = [
    'argv' => array_slice($argv, 1),
];

$handshakeRequest = Packet::readFrom($connection);
$capture['handshake'] = [
    'channel_id' => $handshakeRequest->channelId,
    'message_id' => $handshakeRequest->messageId,
    'payload' => $handshakeRequest->payload,
];

$handshakeResponse = getenv('HEGEL_FAKE_HANDSHAKE');

if ($handshakeResponse === false) {
    $handshakeResponse = 'Hegel/0.7';
}

Packet::writeTo(
    $connection,
    new Packet(0, $handshakeRequest->messageId, true, $handshakeResponse),
);

$runTestRequest = Packet::readFrom($connection);
$runTestPayload = CborCodec::decode($runTestRequest->payload);

$capture['run_test'] = [
    'channel_id' => $runTestRequest->channelId,
    'message_id' => $runTestRequest->messageId,
    'payload' => $runTestPayload,
];

Packet::writeTo(
    $connection,
    new Packet(0, $runTestRequest->messageId, true, CborCodec::encode(['result' => null])),
);

$eventMessageId = 1;
$eventPayload = [
    'event' => 'test_done',
    'results' => [
        'passed' => true,
        'interesting_test_cases' => 0,
        'error' => null,
        'health_check_failure' => null,
        'flaky' => null,
    ],
];

Packet::writeTo(
    $connection,
    new Packet(
        $runTestPayload['channel_id'],
        $eventMessageId,
        false,
        CborCodec::encode($eventPayload),
    ),
);

$ackPacket = Packet::readFrom($connection);
$capture['event_ack'] = [
    'channel_id' => $ackPacket->channelId,
    'message_id' => $ackPacket->messageId,
    'is_reply' => $ackPacket->isReply,
    'payload' => CborCodec::decode($ackPacket->payload),
];

$captureFile = getenv('HEGEL_FAKE_CAPTURE_FILE');

if (is_string($captureFile) && $captureFile !== '') {
    file_put_contents($captureFile, json_encode($capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

fclose($connection);
fclose($server);
