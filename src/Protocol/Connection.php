<?php

declare(strict_types=1);

namespace Hegel\Protocol;

use RuntimeException;

final class Connection
{
    public const string SERVER_EXITED_MESSAGE = 'The hegel server process exited unexpectedly. See .hegel/server.log for diagnostic information.';

    private int $nextChannelId = 1;
    /** @var array<int, list<Packet>> */
    private array $pendingPackets = [];
    private bool $serverExited = false;
    private bool $transportClosed = false;
    private mixed $serverExitChecker = null;

    public function __construct(
        private mixed $stream,
    ) {
    }

    public function controlChannel(): Channel
    {
        return new Channel($this, 0);
    }

    public function newChannel(): Channel
    {
        $channelId = ($this->nextChannelId << 1) | 1;
        $this->nextChannelId++;

        return new Channel($this, $channelId);
    }

    public function connectChannel(int $channelId): Channel
    {
        return new Channel($this, $channelId);
    }

    public function sendPacket(Packet $packet): void
    {
        try {
            Packet::writeTo($this->stream, $packet);
        } catch (RuntimeException $exception) {
            throw $this->remapStreamError($exception);
        }
    }

    public function receivePacketForChannel(int $channelId): Packet
    {
        if (isset($this->pendingPackets[$channelId]) && $this->pendingPackets[$channelId] !== []) {
            $packet = array_shift($this->pendingPackets[$channelId]);

            if ($this->pendingPackets[$channelId] === []) {
                unset($this->pendingPackets[$channelId]);
            }

            return $packet;
        }

        try {
            while (true) {
                $packet = Packet::readFrom($this->stream);

                if ($packet->channelId === $channelId) {
                    return $packet;
                }

                $this->pendingPackets[$packet->channelId][] = $packet;
            }
        } catch (RuntimeException $exception) {
            throw $this->remapStreamError($exception);
        }
    }

    public function markServerExited(): void
    {
        $this->serverExited = true;
    }

    public function serverHasExited(): bool
    {
        return $this->serverExited;
    }

    public function setServerExitChecker(callable $checker): void
    {
        $this->serverExitChecker = $checker;
    }

    public function close(): void
    {
        if ($this->transportClosed) {
            return;
        }

        $this->transportClosed = true;

        if (! is_resource($this->stream)) {
            return;
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        fclose($this->stream);
    }

    private function remapStreamError(RuntimeException $exception): RuntimeException
    {
        if ($exception->getMessage() === 'Unexpected end of packet stream.') {
            $this->serverExited = true;

            return new RuntimeException(self::SERVER_EXITED_MESSAGE, 0, $exception);
        }

        if (! $this->serverExited && is_callable($this->serverExitChecker) && ($this->serverExitChecker)()) {
            $this->serverExited = true;
        }

        if (! $this->serverExited) {
            return $exception;
        }

        return new RuntimeException(self::SERVER_EXITED_MESSAGE, 0, $exception);
    }
}
