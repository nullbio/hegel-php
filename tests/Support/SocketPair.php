<?php

declare(strict_types=1);

namespace Hegel\Tests\Support;

use RuntimeException;

final class SocketPair
{
    /**
     * @return array{0: resource, 1: resource}
     */
    public static function create(): array
    {
        $pair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );

        if ($pair === false) {
            throw new RuntimeException('Failed to create a Unix socket pair.');
        }

        if (count($pair) !== 2 || ! isset($pair[0], $pair[1])) {
            throw new RuntimeException('Expected exactly two sockets from stream_socket_pair().');
        }

        foreach ($pair as $stream) {
            stream_set_blocking($stream, true);
        }

        return [$pair[0], $pair[1]];
    }
}
