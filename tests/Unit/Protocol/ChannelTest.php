<?php

declare(strict_types=1);

use Hegel\Protocol\Channel;
use Hegel\Protocol\Connection;
use Hegel\Protocol\Packet;
use Hegel\Tests\Support\SocketPair;

it('sends requests with monotonically increasing message ids', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $clientConnection = new Connection($clientStream);
    $serverConnection = new Connection($serverStream);
    $client = $clientConnection->connectChannel(3);
    $server = $serverConnection->connectChannel(3);

    $messageId = $client->sendRequest('hello');
    $request = $server->receiveRequest();

    expect($messageId)->toBe(1)
        ->and($request->messageId)->toBe(1)
        ->and($request->isReply)->toBeFalse()
        ->and($request->payload)->toBe('hello');

    $clientConnection->close();
    $serverConnection->close();
});

it('buffers out-of-order replies until the requested message id arrives', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $clientConnection = new Connection($clientStream);
    $serverConnection = new Connection($serverStream);
    $client = $clientConnection->connectChannel(3);
    $server = $serverConnection->connectChannel(3);

    $firstId = $client->sendRequest('first');
    $secondId = $client->sendRequest('second');

    $server->receiveRequest();
    $server->receiveRequest();

    $server->writeReply($secondId, 'reply-two');
    $server->writeReply($firstId, 'reply-one');

    expect($client->receiveReply($firstId))->toBe('reply-one')
        ->and($client->receiveReply($secondId))->toBe('reply-two');

    $clientConnection->close();
    $serverConnection->close();
});

it('unwraps cbor result envelopes while preserving integer values', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $clientConnection = new Connection($clientStream);
    $serverConnection = new Connection($serverStream);
    $client = $clientConnection->connectChannel(3);
    $server = $serverConnection->connectChannel(3);

    $server->writeReplyCbor(1, ['result' => ['ok' => true, 'count' => 1]]);

    $result = $client->requestCbor(['command' => 'ping', 'count' => 1]);
    $request = $server->receiveRequestCbor();

    expect($result)->toBe(['ok' => true, 'count' => 1])
        ->and($request['messageId'])->toBe(1)
        ->and($request['payload'])->toBe(['command' => 'ping', 'count' => 1]);

    $clientConnection->close();
    $serverConnection->close();
});

it('surfaces cbor error envelopes as runtime exceptions', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $clientConnection = new Connection($clientStream);
    $serverConnection = new Connection($serverStream);
    $client = $clientConnection->connectChannel(3);
    $server = $serverConnection->connectChannel(3);

    $server->writeReplyCbor(1, ['error' => 'boom', 'type' => 'ServerError']);

    expect(fn (): mixed => $client->requestCbor(['command' => 'ping']))
        ->toThrow(RuntimeException::class, 'ServerError: boom');

    $server->receiveRequest();

    $clientConnection->close();
    $serverConnection->close();
});

it('sends the close sentinel and rejects further blocking operations', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $clientConnection = new Connection($clientStream);
    $serverConnection = new Connection($serverStream);
    $client = $clientConnection->connectChannel(3);
    $server = $serverConnection->connectChannel(3);

    $client->close();

    $closePacket = $server->receiveRequest();

    expect($closePacket->messageId)->toBe(Channel::CLOSE_MESSAGE_ID)
        ->and($closePacket->payload)->toBe(Channel::CLOSE_PAYLOAD)
        ->and($closePacket->isReply)->toBeFalse()
        ->and(fn (): int => $client->sendRequest('later'))
        ->toThrow(RuntimeException::class, 'channel is closed')
        ->and(fn (): Packet => $client->receiveRequest())
        ->toThrow(RuntimeException::class, 'channel is closed');

    $clientConnection->close();
    $serverConnection->close();
});
