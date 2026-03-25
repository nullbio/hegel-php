<?php

declare(strict_types=1);

namespace Hegel\Protocol;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

final readonly class Packet
{
    public const int MAGIC = 0x4845474C;
    public const int HEADER_SIZE = 20;
    public const int TERMINATOR = 0x0A;
    public const int REPLY_BIT = 0x80000000;
    private const int MAX_UINT32 = 0xFFFFFFFF;
    private const int MAX_MESSAGE_ID = 0x7FFFFFFF;

    public function __construct(
        public int $channelId,
        public int $messageId,
        public bool $isReply,
        public string $payload,
    ) {
        self::assertUint32($this->channelId, 'channelId');
        self::assertMessageId($this->messageId);

        if (strlen($this->payload) > self::MAX_UINT32) {
            throw new InvalidArgumentException('Packet payload is too large.');
        }
    }

    public static function writeTo(mixed $stream, self $packet): void
    {
        $rawMessageId = $packet->isReply
            ? ($packet->messageId | self::REPLY_BIT)
            : $packet->messageId;

        $header = pack(
            'N5',
            self::MAGIC,
            0,
            $packet->channelId,
            $rawMessageId,
            strlen($packet->payload),
        );

        $checksum = self::checksum($header, $packet->payload);
        $header = substr_replace($header, $checksum, 4, 4);

        self::writeExact($stream, $header);
        self::writeExact($stream, $packet->payload);
        self::writeExact($stream, chr(self::TERMINATOR));

        if (! self::suppressStreamWarnings(static fn (): bool => fflush($stream))) {
            throw new RuntimeException('Failed to flush the packet stream.');
        }
    }

    public static function readFrom(mixed $stream): self
    {
        $header = self::readExact($stream, self::HEADER_SIZE);
        $fields = unpack(
            'Nmagic/Nchecksum/Nchannel_id/Nraw_message_id/Npayload_length',
            $header,
        );

        if ($fields === false) {
            throw new RuntimeException('Failed to unpack the packet header.');
        }

        if ($fields['magic'] !== self::MAGIC) {
            throw new UnexpectedValueException('Invalid packet magic number.');
        }

        $payload = self::readExact($stream, $fields['payload_length']);
        $terminator = self::readExact($stream, 1);

        if (ord($terminator) !== self::TERMINATOR) {
            throw new UnexpectedValueException('Invalid packet terminator.');
        }

        $zeroedHeader = substr_replace($header, "\x00\x00\x00\x00", 4, 4);
        $checksum = self::checksum($zeroedHeader, $payload);

        if (! hash_equals(substr($header, 4, 4), $checksum)) {
            throw new UnexpectedValueException('Invalid packet checksum.');
        }

        return new self(
            channelId: $fields['channel_id'],
            messageId: $fields['raw_message_id'] & self::MAX_MESSAGE_ID,
            isReply: ($fields['raw_message_id'] & self::REPLY_BIT) !== 0,
            payload: $payload,
        );
    }

    private static function checksum(string $zeroedHeader, string $payload): string
    {
        return hash('crc32b', $zeroedHeader . $payload, true);
    }

    private static function readExact(mixed $stream, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);

            if ($remaining <= 0) {
                break;
            }

            $chunk = self::suppressStreamWarnings(
                static fn (): string|false => fread($stream, $remaining),
            );

            if ($chunk === false) {
                throw new RuntimeException('Failed to read from the packet stream.');
            }

            if ($chunk === '') {
                throw new RuntimeException('Unexpected end of packet stream.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private static function writeExact(mixed $stream, string $data): void
    {
        $written = 0;
        $length = strlen($data);

        while ($written < $length) {
            $result = self::suppressStreamWarnings(
                static fn (): int|false => fwrite($stream, substr($data, $written)),
            );

            if ($result === false || $result === 0) {
                throw new RuntimeException('Failed to write to the packet stream.');
            }

            $written += $result;
        }
    }

    private static function assertUint32(int $value, string $name): void
    {
        if ($value < 0 || $value > self::MAX_UINT32) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 0xFFFFFFFF.', $name));
        }
    }

    private static function assertMessageId(int $messageId): void
    {
        if ($messageId < 0 || $messageId > self::MAX_MESSAGE_ID) {
            throw new InvalidArgumentException('messageId must be between 0 and 0x7FFFFFFF.');
        }
    }

    private static function suppressStreamWarnings(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
