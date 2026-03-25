<?php

declare(strict_types=1);

use Hegel\Protocol\Connection;
use Hegel\Protocol\Packet;
use Hegel\Tests\Support\SocketPair;

it('allocates odd client channel ids starting at three', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $connection = new Connection($clientStream);

    expect($connection->controlChannel()->channelId())->toBe(0)
        ->and($connection->newChannel()->channelId())->toBe(3)
        ->and($connection->newChannel()->channelId())->toBe(5)
        ->and($connection->connectChannel(12)->channelId())->toBe(12);

    fclose($serverStream);
    $connection->close();
});

it('routes packets for other channels into pending queues', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $connection = new Connection($clientStream);

    Packet::writeTo($serverStream, new Packet(9, 1, false, 'queued'));
    Packet::writeTo($serverStream, new Packet(3, 2, false, 'target'));

    $target = $connection->receivePacketForChannel(3);
    $queued = $connection->receivePacketForChannel(9);

    expect($target->channelId)->toBe(3)
        ->and($target->payload)->toBe('target')
        ->and($queued->channelId)->toBe(9)
        ->and($queued->payload)->toBe('queued');

    fclose($serverStream);
    $connection->close();
});

it('remaps stream failures after the server has exited', function (): void {
    [$clientStream, $serverStream] = SocketPair::create();

    $connection = new Connection($clientStream);
    $connection->markServerExited();

    fclose($serverStream);

    expect(fn (): Packet => $connection->receivePacketForChannel(0))
        ->toThrow(RuntimeException::class, 'The hegel server process exited unexpectedly.');

    $connection->close();
});
