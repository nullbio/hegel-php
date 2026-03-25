<?php

declare(strict_types=1);

namespace Hegel\Protocol;

use RuntimeException;

final class Channel
{
    public const int CLOSE_MESSAGE_ID = 0x7FFFFFFF;
    public const string CLOSE_PAYLOAD = "\xFE";

    private int $nextMessageId = 1;
    /** @var array<int, string> */
    private array $responses = [];
    /** @var list<Packet> */
    private array $requests = [];
    private bool $closed = false;

    public function __construct(
        private Connection $connection,
        private int $channelId,
    ) {
    }

    public function channelId(): int
    {
        return $this->channelId;
    }

    public function sendRequest(string $payload): int
    {
        if ($this->closed) {
            throw new RuntimeException('channel is closed');
        }

        $messageId = $this->nextMessageId;
        $this->nextMessageId++;

        $this->connection->sendPacket(
            new Packet($this->channelId, $messageId, false, $payload),
        );

        return $messageId;
    }

    public function writeReply(int $messageId, string $payload): void
    {
        $this->connection->sendPacket(
            new Packet($this->channelId, $messageId, true, $payload),
        );
    }

    public function writeReplyCbor(int $messageId, mixed $payload): void
    {
        $this->writeReply($messageId, CborCodec::encode($payload));
    }

    public function receiveReply(int $messageId): string
    {
        if (array_key_exists($messageId, $this->responses)) {
            $payload = $this->responses[$messageId];
            unset($this->responses[$messageId]);

            return $payload;
        }

        if ($this->closed) {
            throw new RuntimeException('channel is closed');
        }

        while (true) {
            $packet = $this->connection->receivePacketForChannel($this->channelId);

            if (! $packet->isReply) {
                $this->requests[] = $packet;
                continue;
            }

            if ($packet->messageId === $messageId) {
                return $packet->payload;
            }

            $this->responses[$packet->messageId] = $packet->payload;
        }
    }

    public function receiveReplyCbor(int $messageId): mixed
    {
        return CborCodec::decode($this->receiveReply($messageId));
    }

    public function requestCbor(mixed $request): mixed
    {
        $messageId = $this->sendRequest(CborCodec::encode($request));
        $reply = $this->receiveReplyCbor($messageId);

        if (is_array($reply) && array_key_exists('error', $reply)) {
            throw new RuntimeException(self::formatProtocolError($reply['error'], $reply['type'] ?? null));
        }

        if (is_array($reply) && array_key_exists('result', $reply)) {
            return $reply['result'];
        }

        return $reply;
    }

    public function receiveRequest(): Packet
    {
        if ($this->requests !== []) {
            return array_shift($this->requests);
        }

        if ($this->closed) {
            throw new RuntimeException('channel is closed');
        }

        while (true) {
            $packet = $this->connection->receivePacketForChannel($this->channelId);

            if ($packet->isReply) {
                $this->responses[$packet->messageId] = $packet->payload;
                continue;
            }

            return $packet;
        }
    }

    /**
     * @return array{messageId: int, payload: mixed}
     */
    public function receiveRequestCbor(): array
    {
        $packet = $this->receiveRequest();

        return [
            'messageId' => $packet->messageId,
            'payload' => CborCodec::decode($packet->payload),
        ];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->connection->sendPacket(
            new Packet($this->channelId, self::CLOSE_MESSAGE_ID, false, self::CLOSE_PAYLOAD),
        );
    }

    public function markClosed(): void
    {
        $this->closed = true;
    }

    private static function formatProtocolError(mixed $error, mixed $type): string
    {
        $message = self::stringify($error);

        if (! is_string($type) || $type === '') {
            return $message;
        }

        return sprintf('%s: %s', $type, $message);
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return var_export($value, true);
        }

        $json = json_encode($value);

        if ($json !== false) {
            return $json;
        }

        return 'unknown protocol error';
    }
}
