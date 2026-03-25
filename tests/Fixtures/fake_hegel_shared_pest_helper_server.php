<?php

declare(strict_types=1);

use Hegel\Protocol\CborCodec;
use Hegel\Protocol\Packet;
use RuntimeException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$socketPath = $argv[1] ?? null;

if (! is_string($socketPath) || $socketPath === '') {
    fwrite(STDERR, "missing socket path\n");
    exit(1);
}

$captureFile = getenv('HEGEL_FAKE_CAPTURE_FILE');
$capture = [
    'starts' => 1,
    'argv' => array_slice($argv, 1),
    'handshakes' => [],
    'run_tests' => [],
];

if (is_string($captureFile) && $captureFile !== '' && is_file($captureFile)) {
    $existing = json_decode((string) file_get_contents($captureFile), true);

    if (is_array($existing)) {
        $capture = [
            'starts' => (int) ($existing['starts'] ?? 0) + 1,
            'argv' => $capture['argv'],
            'handshakes' => is_array($existing['handshakes'] ?? null) ? $existing['handshakes'] : [],
            'run_tests' => is_array($existing['run_tests'] ?? null) ? $existing['run_tests'] : [],
        ];
    }
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

$handshake = Packet::readFrom($connection);
$capture['handshakes'][] = [
    'channel_id' => $handshake->channelId,
    'message_id' => $handshake->messageId,
    'payload' => $handshake->payload,
];
Packet::writeTo($connection, new Packet(0, $handshake->messageId, true, 'Hegel/0.7'));

$runIndex = 0;

while (true) {
    try {
        $runTest = Packet::readFrom($connection);
    } catch (RuntimeException) {
        break;
    }

    if ($runTest->messageId === 0x7FFFFFFF) {
        continue;
    }

    $payload = CborCodec::decode($runTest->payload);

    if (! is_array($payload) || ($payload['command'] ?? null) !== 'run_test') {
        break;
    }

    $capture['run_tests'][] = [
        'channel_id' => $runTest->channelId,
        'message_id' => $runTest->messageId,
        'payload' => $payload,
    ];

    Packet::writeTo(
        $connection,
        new Packet(0, $runTest->messageId, true, CborCodec::encode(['result' => null])),
    );

    $eventChannelId = $payload['channel_id'];
    $caseChannelId = 10 + $runIndex;

    Packet::writeTo(
        $connection,
        new Packet($eventChannelId, 1, false, CborCodec::encode([
            'event' => 'test_case',
            'channel_id' => $caseChannelId,
        ])),
    );

    $testCaseAck = Packet::readFrom($connection);
    $capture['run_tests'][$runIndex]['test_case_ack'] = CborCodec::decode($testCaseAck->payload);

    while (true) {
        $packet = Packet::readFrom($connection);

        if ($packet->channelId === $eventChannelId && $packet->messageId === 0x7FFFFFFF) {
            $capture['run_tests'][$runIndex]['event_channel_closed'] = true;
            continue;
        }

        if ($packet->channelId !== $caseChannelId) {
            fwrite(STDERR, sprintf("unexpected channel: %d\n", $packet->channelId));
            exit(1);
        }

        if ($packet->messageId === 0x7FFFFFFF) {
            $capture['run_tests'][$runIndex]['case_channel_closed'] = true;
            break;
        }

        $casePayload = CborCodec::decode($packet->payload);
        $command = $casePayload['command'] ?? null;

        if ($command === 'generate') {
            $capture['run_tests'][$runIndex]['generate_requests'][] = $casePayload;

            Packet::writeTo(
                $connection,
                new Packet($caseChannelId, $packet->messageId, true, CborCodec::encode(['result' => 2])),
            );
            continue;
        }

        if ($command === 'mark_complete') {
            $capture['run_tests'][$runIndex]['mark_complete'] = $casePayload;

            Packet::writeTo(
                $connection,
                new Packet($caseChannelId, $packet->messageId, true, CborCodec::encode(['result' => null])),
            );
            continue;
        }

        fwrite(STDERR, sprintf("unexpected command: %s\n", json_encode($casePayload)));
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
    $capture['run_tests'][$runIndex]['test_done_ack'] = CborCodec::decode($testDoneAck->payload);
    $runIndex++;
}

if (is_string($captureFile) && $captureFile !== '') {
    file_put_contents($captureFile, json_encode($capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

fclose($connection);
fclose($server);
