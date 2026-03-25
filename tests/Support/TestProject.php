<?php

declare(strict_types=1);

function hegelWithTemporaryProject(callable $callback): void
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'hegel-php-test-'
        . bin2hex(random_bytes(8));

    expect(mkdir($directory, 0777, true))->toBeTrue();

    $originalDirectory = getcwd();

    if (! is_string($originalDirectory)) {
        throw new RuntimeException('Failed to resolve the current working directory.');
    }

    chdir($directory);

    try {
        $callback($directory);
    } finally {
        chdir($originalDirectory);
        hegelDeleteDirectory($directory);
    }
}

/**
 * @param array<string, string|null> $variables
 */
function hegelWithEnvironment(array $variables, callable $callback): void
{
    $originals = [];

    foreach ($variables as $name => $value) {
        $originals[$name] = getenv($name);

        if ($value === null) {
            putenv($name);
            continue;
        }

        putenv($name . '=' . $value);
    }

    try {
        $callback();
    } finally {
        foreach ($originals as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $value);
        }
    }
}

function hegelWritePhpWrapper(string $directory, string $scriptPath, string $name): string
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $contents = "#!/bin/sh\nexec "
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($scriptPath)
        . " \"$@\"\n";

    file_put_contents($path, $contents);
    chmod($path, 0755);

    return $path;
}

function hegelWriteFakeUv(string $binDirectory, string $serverScriptPath): void
{
    $uvPath = $binDirectory . DIRECTORY_SEPARATOR . 'uv';

    $contents = "#!/bin/sh\n"
        . "set -eu\n"
        . "if [ \"\$1\" = \"venv\" ] && [ \"\$2\" = \"--clear\" ]; then\n"
        . "  venv_dir=\"\$3\"\n"
        . "  echo \"uv venv --clear \$venv_dir\"\n"
        . "  rm -rf \"\$venv_dir\"\n"
        . "  mkdir -p \"\$venv_dir/bin\"\n"
        . "  cat > \"\$venv_dir/bin/python\" <<'EOF'\n"
        . "#!/bin/sh\n"
        . "exit 0\n"
        . "EOF\n"
        . "  chmod +x \"\$venv_dir/bin/python\"\n"
        . "  exit 0\n"
        . "fi\n"
        . "if [ \"\$1\" = \"pip\" ] && [ \"\$2\" = \"install\" ] && [ \"\$3\" = \"--python\" ]; then\n"
        . "  python_path=\"\$4\"\n"
        . "  package=\"\$5\"\n"
        . "  venv_dir=\$(dirname \"\$(dirname \"\$python_path\")\")\n"
        . "  echo \"uv pip install --python \$python_path \$package\"\n"
        . "  cat > \"\$venv_dir/bin/hegel\" <<'EOF'\n"
        . "#!/bin/sh\n"
        . 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($serverScriptPath) . " \"\$@\"\n"
        . "EOF\n"
        . "  chmod +x \"\$venv_dir/bin/hegel\"\n"
        . "  exit 0\n"
        . "fi\n"
        . "echo \"unexpected uv invocation: \$*\" >&2\n"
        . "exit 1\n";

    file_put_contents($uvPath, $contents);
    chmod($uvPath, 0755);
}

function hegelDeleteDirectory(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (is_link($path) || ! is_dir($path)) {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (@unlink($path) || ! file_exists($path)) {
                return;
            }

            usleep(20_000);
            clearstatcache(true, $path);
        }

        throw new RuntimeException(sprintf('Failed to delete file: %s', $path));
    }

    $entries = scandir($path);

    expect($entries)->not->toBeFalse();

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        hegelDeleteDirectory($path . DIRECTORY_SEPARATOR . $entry);
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        if (@rmdir($path) || ! file_exists($path)) {
            return;
        }

        usleep(20_000);
        clearstatcache(true, $path);
    }

    throw new RuntimeException(sprintf('Failed to delete directory: %s', $path));
}

/**
 * @return array<string, mixed>
 */
function hegelReadJsonFile(string $path): array
{
    $deadline = microtime(true) + 2.0;

    while (microtime(true) < $deadline) {
        if (! is_file($path)) {
            usleep(20_000);
            continue;
        }

        $contents = file_get_contents($path);

        if ($contents !== false && $contents !== '') {
            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
                usleep(20_000);
                continue;
            }
        }

        usleep(20_000);
    }

    throw new RuntimeException(sprintf('Timed out waiting for JSON file: %s', $path));
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function hegelRunProcess(array $command, string $workingDirectory, array $environment = []): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $environment);

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}
