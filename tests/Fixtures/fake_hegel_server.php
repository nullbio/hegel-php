<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fake_hegel_transport.php';

$argv = $_SERVER['argv'] ?? [];
['reader' => $reader, 'writer' => $writer, 'close' => $closeTransport] = hegelFakeOpenTransport($argv);

fwrite(STDERR, "fake-hegel-server stderr\n");
fwrite(STDERR, "fake-hegel-server ready\n");

$capture = [
    'argv' => array_slice($argv, 1),
];

$handshakeRequest = Packet::readFrom($reader);
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
    $writer,
    new Packet(0, $handshakeRequest->messageId, true, $handshakeResponse),
);

$runTestRequest = Packet::readFrom($reader);
$runTestPayload = CborCodec::decode($runTestRequest->payload);

$capture['run_test'] = [
    'channel_id' => $runTestRequest->channelId,
    'message_id' => $runTestRequest->messageId,
    'payload' => $runTestPayload,
];

Packet::writeTo(
    $writer,
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
    $writer,
    new Packet(
        $runTestPayload['channel_id'],
        $eventMessageId,
        false,
        CborCodec::encode($eventPayload),
    ),
);

$ackPacket = Packet::readFrom($reader);
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

$closeTransport();
