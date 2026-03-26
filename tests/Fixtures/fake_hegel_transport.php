<?php

declare(strict_types=1);

/**
 * @param list<string> $argv
 * @return array{reader: mixed, writer: mixed, close: callable(): void}
 */
function hegelFakeOpenTransport(array $argv): array
{
    if (in_array('--stdio', $argv, true)) {
        $reader = fopen('php://stdin', 'rb');
        $writer = fopen('php://stdout', 'wb');

        if (! is_resource($reader) || ! is_resource($writer)) {
            fwrite(STDERR, "failed to open stdio transport\n");
            exit(1);
        }

        stream_set_blocking($reader, true);
        stream_set_blocking($writer, true);

        return [
            'reader' => $reader,
            'writer' => $writer,
            'close' => static function (): void {
            },
        ];
    }

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

    $connection = @stream_socket_accept($server, 5);

    if ($connection === false) {
        fwrite(STDERR, "failed to accept connection\n");
        exit(1);
    }

    stream_set_blocking($connection, true);

    return [
        'reader' => $connection,
        'writer' => $connection,
        'close' => static function () use ($connection, $server): void {
            fclose($connection);
            fclose($server);
        },
    ];
}
