<?php

declare(strict_types=1);

use Hegel\Protocol\Packet;

it('round-trips packet framing including the reply bit', function (): void {
    $stream = fopen('php://temp', 'w+b');

    expect(is_resource($stream))->toBeTrue();
    assert(is_resource($stream));

    Packet::writeTo($stream, new Packet(3, 7, true, "\x00hello"));

    rewind($stream);

    $packet = Packet::readFrom($stream);

    expect($packet->channelId)->toBe(3)
        ->and($packet->messageId)->toBe(7)
        ->and($packet->isReply)->toBeTrue()
        ->and($packet->payload)->toBe("\x00hello");

    fclose($stream);
});

it('rejects packets with an invalid checksum', function (): void {
    $stream = fopen('php://temp', 'w+b');

    expect(is_resource($stream))->toBeTrue();
    assert(is_resource($stream));

    Packet::writeTo($stream, new Packet(5, 1, false, 'payload'));

    rewind($stream);

    $bytes = stream_get_contents($stream);

    expect($bytes)->not->toBeFalse();

    $tampered = $bytes;
    $tampered[4] = chr((ord($tampered[4]) ^ 0xFF) & 0xFF);

    $tamperedStream = fopen('php://temp', 'w+b');

    expect(is_resource($tamperedStream))->toBeTrue();
    assert(is_resource($tamperedStream));

    fwrite($tamperedStream, $tampered);
    rewind($tamperedStream);

    expect(fn (): Packet => Packet::readFrom($tamperedStream))
        ->toThrow(UnexpectedValueException::class, 'Invalid packet checksum.');

    fclose($stream);
    fclose($tamperedStream);
});

it('matches the rust packet encoding for a known vector', function (): void {
    $expectedHex = '4845474cb768d95d000000010000002a0000000b68656c6c6f20776f726c640a';
    $expectedBytes = hex2bin($expectedHex);

    expect($expectedBytes)->not->toBeFalse();
    assert(is_string($expectedBytes));

    $readStream = fopen('php://temp', 'w+b');
    expect(is_resource($readStream))->toBeTrue();
    assert(is_resource($readStream));

    fwrite($readStream, $expectedBytes);
    rewind($readStream);

    $packet = Packet::readFrom($readStream);

    expect($packet->channelId)->toBe(1)
        ->and($packet->messageId)->toBe(42)
        ->and($packet->isReply)->toBeFalse()
        ->and($packet->payload)->toBe('hello world');

    $writeStream = fopen('php://temp', 'w+b');
    expect(is_resource($writeStream))->toBeTrue();
    assert(is_resource($writeStream));

    Packet::writeTo($writeStream, new Packet(1, 42, false, 'hello world'));
    rewind($writeStream);

    $written = stream_get_contents($writeStream);

    expect($written)->not->toBeFalse()
        ->and(bin2hex($written))->toBe($expectedHex);

    fclose($readStream);
    fclose($writeStream);
});
