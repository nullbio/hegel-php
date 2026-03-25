<?php

declare(strict_types=1);

namespace Hegel;

use Hegel\Protocol\Channel;
use Hegel\Protocol\Connection;
use Hegel\Reporting\Counterexample;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type HegelEvent array{messageId: int, payload: array<string, mixed>}
 * @phpstan-type ProcessStatus array{
 *     command: string,
 *     pid: int,
 *     running: bool,
 *     signaled: bool,
 *     stopped: bool,
 *     exitcode: int,
 *     termsig: int,
 *     stopsig: int
 * }
 */
final class Hegel
{
    private const float SUPPORTED_PROTOCOL_MIN = 0.6;
    private const float SUPPORTED_PROTOCOL_MAX = 0.7;
    private const string HANDSHAKE_STRING = 'hegel_handshake_start';
    private const string SERVER_VERSION = '0.2.2';
    private const string SERVER_COMMAND_ENV = 'HEGEL_SERVER_COMMAND';
    private const string SERVER_DIRECTORY = '.hegel';
    private const int SOCKET_CONNECT_ATTEMPTS = 50;
    private const int SOCKET_CONNECT_DELAY_US = 100_000;
    private const string UV_NOT_FOUND_MESSAGE = "You are seeing this error message because hegel-php tried to use `uv` to install hegel-core, but could not find uv on the PATH.\n\nHegel uses a Python server component called `hegel-core` to share core property-based testing functionality across languages. There are two ways for Hegel to get hegel-core:\n\n* By default, Hegel looks for uv (https://docs.astral.sh/uv/) on the PATH, and uses uv to install hegel-core to a local `.hegel/venv` directory. We recommend this option. To continue, install uv: https://docs.astral.sh/uv/getting-started/installation/.\n* Alternatively, you can manage the installation of hegel-core yourself. After installing, setting the HEGEL_SERVER_COMMAND environment variable to your hegel-core binary path tells hegel-php to use that hegel-core instead.\n\nSee https://hegel.dev/reference/installation for more details.";

    private Settings $settings;
    private ?string $databaseKey;
    private ?Connection $connection = null;
    private ?Channel $controlChannel = null;
    private mixed $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private ?string $socketDirectory = null;

    public function __construct(?Settings $settings = null, ?string $databaseKey = null)
    {
        $this->settings = $settings ?? new Settings();
        $this->databaseKey = $databaseKey;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function start(): void
    {
        if ($this->connection !== null && $this->controlChannel !== null) {
            return;
        }

        $this->socketDirectory = $this->createSocketDirectory();
        $socketPath = $this->socketDirectory . DIRECTORY_SEPARATOR . 'hegel.sock';

        try {
            $commandPath = self::findHegelCommand();
            $this->spawnServer($commandPath, $socketPath);

            $stream = $this->connectToServer($socketPath);
            stream_set_timeout($stream, 120);

            $this->connection = new Connection($stream);
            $this->connection->setServerExitChecker(fn (): bool => $this->hasServerExited());
            $this->controlChannel = $this->connection->controlChannel();

            $this->performHandshake();
        } catch (Throwable $throwable) {
            $this->close();
            throw $throwable;
        }
    }

    public function connection(): Connection
    {
        $this->start();
        $this->refreshServerStatus();

        if ($this->connection === null) {
            throw new RuntimeException('Hegel connection is not available.');
        }

        return $this->connection;
    }

    public function controlChannel(): Channel
    {
        $this->start();
        $this->refreshServerStatus();

        if ($this->controlChannel === null) {
            throw new RuntimeException('Hegel control channel is not available.');
        }

        return $this->controlChannel;
    }

    public function runTest(): Channel
    {
        $control = $this->controlChannel();
        $testChannel = $this->connection()->newChannel();

        $control->requestCbor(
            $this->settings->toRunTestPayload($testChannel->channelId(), $this->databaseKey),
        );

        return $testChannel;
    }

    public function run(callable $test): void
    {
        $gotInteresting = false;
        /** @var array<string, mixed> $results */
        $results = [];
        /** @var list<Counterexample> $counterexamples */
        $counterexamples = [];

        try {
            $eventChannel = $this->runTest();

            while (true) {
                $event = $eventChannel->receiveRequestCbor();
                $eventType = $event['payload']['event'] ?? null;

                if (! is_string($eventType)) {
                    throw new RuntimeException('Expected event in payload.');
                }

                if ($eventType === 'test_case') {
                    $result = $this->handleTestCaseEvent($eventChannel, $event, $test, false);
                    $gotInteresting = $result->interesting || $gotInteresting;
                    continue;
                }

                if ($eventType === 'test_done') {
                    $eventChannel->writeReplyCbor($event['messageId'], ['result' => true]);
                    $payloadResults = $event['payload']['results'] ?? [];
                    $results = is_array($payloadResults) ? $payloadResults : [];
                    break;
                }

                throw new RuntimeException(sprintf('unknown event: %s', $eventType));
            }

            $error = $results['error'] ?? null;

            if (is_string($error) && $error !== '') {
                throw new RuntimeException(self::formatNamedFailure('Server error', $error));
            }

            $healthCheckFailure = $results['health_check_failure'] ?? null;

            if (is_string($healthCheckFailure) && $healthCheckFailure !== '') {
                throw new RuntimeException(self::formatNamedFailure('Health check failure', $healthCheckFailure));
            }

            $flaky = $results['flaky'] ?? null;

            if (is_string($flaky) && $flaky !== '') {
                throw new RuntimeException(self::formatNamedFailure('Flaky test detected', $flaky));
            }

            $interestingCount = $results['interesting_test_cases'] ?? 0;

            if (! is_int($interestingCount)) {
                $interestingCount = 0;
            }

            for ($index = 0; $index < $interestingCount; $index++) {
                $event = $eventChannel->receiveRequestCbor();
                $eventType = $event['payload']['event'] ?? null;

                if ($eventType !== 'test_case') {
                    throw new RuntimeException('Expected final test_case event.');
                }

                $result = $this->handleTestCaseEvent($eventChannel, $event, $test, true);
                $gotInteresting = $result->interesting || $gotInteresting;

                if ($result->counterexample !== null) {
                    $counterexamples[] = $result->counterexample;
                }
            }

            $passed = $results['passed'] ?? true;

            if (! is_bool($passed)) {
                $passed = true;
            }

            if (! $passed || $gotInteresting) {
                throw new RuntimeException(self::formatPropertyFailure($counterexamples));
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === Connection::SERVER_EXITED_MESSAGE) {
                throw new RuntimeException($this->withServerLog($exception->getMessage()), 0, $exception);
            }

            throw $exception;
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }

        $this->controlChannel = null;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if ($status['running']) {
                $deadline = microtime(true) + 1.0;

                do {
                    usleep(50_000);
                    $status = proc_get_status($this->process);
                } while ($status['running'] && microtime(true) < $deadline);
            }

            if ($status['running']) {
                @proc_terminate($this->process);
                usleep(50_000);
                $status = proc_get_status($this->process);

                if ($status['running']) {
                    @proc_terminate($this->process, 9);
                }
            }

            @proc_close($this->process);
        }

        $this->process = null;

        if ($this->socketDirectory !== null) {
            self::deleteDirectory($this->socketDirectory);
            $this->socketDirectory = null;
        }
    }

    private function performHandshake(): void
    {
        $control = $this->controlChannel;

        if ($control === null) {
            throw new RuntimeException('Hegel control channel is not available.');
        }

        $messageId = $control->sendRequest(self::HANDSHAKE_STRING);
        $this->refreshServerStatus();
        $response = $control->receiveReply($messageId);
        $this->refreshServerStatus();

        if (! str_starts_with($response, 'Hegel/')) {
            throw new RuntimeException(sprintf('Bad handshake response: %s', var_export($response, true)));
        }

        $versionString = substr($response, strlen('Hegel/'));
        $version = filter_var($versionString, FILTER_VALIDATE_FLOAT);

        if ($version === false) {
            throw new RuntimeException(sprintf('Bad version number: %s', $versionString));
        }

        if ($version < self::SUPPORTED_PROTOCOL_MIN || $version > self::SUPPORTED_PROTOCOL_MAX) {
            throw new RuntimeException(sprintf(
                'hegel-php supports protocol versions %s through %s, but the connected server is using protocol version %s. Upgrading hegel-php or downgrading hegel-core might help.',
                self::SUPPORTED_PROTOCOL_MIN,
                self::SUPPORTED_PROTOCOL_MAX,
                $versionString,
            ));
        }
    }

    /**
     * @return resource
     */
    private function connectToServer(string $socketPath): mixed
    {
        $attempts = 0;

        while (true) {
            $status = $this->processStatus();

            if ($status !== false && ! $status['running']) {
                throw new RuntimeException($this->withServerLog(sprintf(
                    'The hegel server process exited immediately (%s). See .hegel/server.log for diagnostic information.',
                    self::formatProcessStatus($status),
                )));
            }

            if (file_exists($socketPath)) {
                $errorCode = 0;
                $errorMessage = '';
                $stream = @stream_socket_client(
                    'unix://' . $socketPath,
                    $errorCode,
                    $errorMessage,
                    0.1,
                );

                if ($stream !== false) {
                    return $stream;
                }

                if ($attempts >= self::SOCKET_CONNECT_ATTEMPTS) {
                    throw new RuntimeException(sprintf(
                        'Failed to connect to hegel server socket: %s',
                        $errorMessage !== '' ? $errorMessage : 'unknown error',
                    ));
                }
            } elseif ($attempts >= self::SOCKET_CONNECT_ATTEMPTS) {
                throw new RuntimeException('Timeout waiting for hegel server to create socket');
            }

            usleep(self::SOCKET_CONNECT_DELAY_US);
            $attempts++;
        }
    }

    private function spawnServer(string $commandPath, string $socketPath): void
    {
        self::ensureServerDirectory();

        $environment = self::environment();

        $environment['PYTHONUNBUFFERED'] = '1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::serverLogPath(), 'a'],
            2 => ['file', self::serverLogPath(), 'a'],
        ];

        $this->process = @proc_open(
            [$commandPath, $socketPath, '--verbosity', $this->settings->verbosityLevel()->value],
            $descriptors,
            $this->pipes,
            null,
            $environment,
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException(sprintf('Failed to spawn hegel at path %s', $commandPath));
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];
    }

    private function refreshServerStatus(): void
    {
        if (! $this->hasServerExited()) {
            return;
        }

        if ($this->connection !== null) {
            $this->connection->markServerExited();
        }
    }

    private function hasServerExited(): bool
    {
        $status = $this->processStatus();

        return $status !== false && ! $status['running'];
    }

    /**
     * @return ProcessStatus|false
     */
    private function processStatus(): array|false
    {
        if (! is_resource($this->process)) {
            return false;
        }

        return proc_get_status($this->process);
    }

    private static function findHegelCommand(): string
    {
        $override = getenv(self::SERVER_COMMAND_ENV);

        if ($override !== false) {
            return self::resolveExecutablePath($override);
        }

        return self::ensureHegelInstalled();
    }

    private static function ensureHegelInstalled(): string
    {
        $venvDirectory = self::serverDirectoryPath() . DIRECTORY_SEPARATOR . 'venv';
        $versionFile = $venvDirectory . DIRECTORY_SEPARATOR . 'hegel-version';
        $hegelBinary = $venvDirectory . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'hegel';

        if (is_file($versionFile) && is_file($hegelBinary)) {
            $cachedVersion = file_get_contents($versionFile);

            if ($cachedVersion !== false && trim($cachedVersion) === self::SERVER_VERSION) {
                return $hegelBinary;
            }
        }

        self::ensureServerDirectory();

        $installLogPath = self::installLogPath();

        if (file_put_contents($installLogPath, '') === false) {
            throw new RuntimeException('Failed to create install log.');
        }

        $uvPath = self::findExecutableOnPath('uv');

        if ($uvPath === null) {
            throw new RuntimeException(self::UV_NOT_FOUND_MESSAGE);
        }

        $venvExitCode = self::runLoggedCommand(
            [$uvPath, 'venv', '--clear', $venvDirectory],
            $installLogPath,
        );

        if ($venvExitCode !== 0) {
            throw new RuntimeException(sprintf(
                "uv venv failed. Install log:\n%s",
                self::readLog($installLogPath),
            ));
        }

        $pythonPath = $venvDirectory . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';

        $installExitCode = self::runLoggedCommand(
            [$uvPath, 'pip', 'install', '--python', $pythonPath, 'hegel-core==' . self::SERVER_VERSION],
            $installLogPath,
        );

        if ($installExitCode !== 0) {
            throw new RuntimeException(sprintf(
                "Failed to install hegel-core (version: %s). Set %s to a hegel binary path to skip installation.\nInstall log:\n%s",
                self::SERVER_VERSION,
                self::SERVER_COMMAND_ENV,
                self::readLog($installLogPath),
            ));
        }

        if (! is_file($hegelBinary)) {
            throw new RuntimeException(sprintf('hegel not found at %s after installation', $hegelBinary));
        }

        if (file_put_contents($versionFile, self::SERVER_VERSION) === false) {
            throw new RuntimeException('Failed to write version file.');
        }

        return $hegelBinary;
    }

    /**
     * @param list<string> $command
     */
    private static function runLoggedCommand(array $command, string $logPath): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];

        $environment = self::environment();

        $process = @proc_open($command, $descriptors, $pipes, null, $environment);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to run `%s`.', basename($command[0])));
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return proc_close($process);
    }

    private function createSocketDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'hegel-php-'
            . bin2hex(random_bytes(8));

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $directory));
        }

        return $directory;
    }

    private static function ensureServerDirectory(): void
    {
        $directory = self::serverDirectoryPath();

        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create %s', self::SERVER_DIRECTORY));
        }
    }

    private static function serverDirectoryPath(): string
    {
        $currentDirectory = getcwd();

        if ($currentDirectory === false) {
            throw new RuntimeException('Failed to resolve the current working directory.');
        }

        return $currentDirectory . DIRECTORY_SEPARATOR . self::SERVER_DIRECTORY;
    }

    private static function serverLogPath(): string
    {
        return self::serverDirectoryPath() . DIRECTORY_SEPARATOR . 'server.log';
    }

    private static function installLogPath(): string
    {
        return self::serverDirectoryPath() . DIRECTORY_SEPARATOR . 'install.log';
    }

    private static function readLog(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return '';
        }

        return $contents;
    }

    private static function resolveExecutablePath(string $command): string
    {
        if ($command === '') {
            return $command;
        }

        if (str_contains($command, DIRECTORY_SEPARATOR)) {
            return $command;
        }

        return self::findExecutableOnPath($command) ?? $command;
    }

    private static function findExecutableOnPath(string $name): ?string
    {
        $path = getenv('PATH');

        if ($path === false || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param ProcessStatus $status
     */
    private static function formatProcessStatus(array $status): string
    {
        if ($status['signaled']) {
            return sprintf('signal %d', $status['termsig']);
        }

        if ($status['exitcode'] >= 0) {
            return sprintf('exit code %d', $status['exitcode']);
        }

        return 'unknown status';
    }

    private static function deleteDirectory(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (! is_dir($path)) {
            @unlink($path);
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::deleteDirectory($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }

    /**
     * @param HegelEvent $event
     */
    private function handleTestCaseEvent(Channel $eventChannel, array $event, callable $test, bool $isFinal): TestCaseResult
    {
        $channelId = $event['payload']['channel_id'] ?? null;

        if (! is_int($channelId)) {
            throw new RuntimeException('Missing channel id');
        }

        $testCaseChannel = $this->connection()->connectChannel($channelId);
        $eventChannel->writeReplyCbor($event['messageId'], ['result' => null]);

        $interesting = $this->runTestCase($testCaseChannel, $test, $isFinal);
        $this->refreshServerStatus();

        if ($this->connection()->serverHasExited()) {
            throw new RuntimeException($this->withServerLog(Connection::SERVER_EXITED_MESSAGE));
        }

        return $interesting;
    }

    private function runTestCase(Channel $channel, callable $test, bool $isFinal): TestCaseResult
    {
        $testCase = TestCase::create(
            $this->connection(),
            $channel,
            $this->settings->verbosityLevel(),
            $isFinal,
        );

        $status = 'VALID';
        $origin = null;
        $counterexample = null;

        try {
            $test(clone $testCase);
        } catch (StopTestException) {
            $status = 'INVALID';
        } catch (TestCaseControlFlow $exception) {
            if (
                $exception->getMessage() === TestCase::ASSUME_FAIL_STRING
                || $exception->getMessage() === TestCase::STOP_TEST_STRING
            ) {
                $status = 'INVALID';
            } else {
                $status = 'INTERESTING';
                $origin = sprintf('Panic at %s:%d', $exception->getFile(), $exception->getLine());

                if ($isFinal) {
                    $counterexample = Counterexample::fromThrowable(
                        $testCase->outputLines(),
                        $exception,
                        $this->settings->verbosityLevel() === Verbosity::Debug,
                    );
                }
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === Connection::SERVER_EXITED_MESSAGE) {
                throw $exception;
            }

            $status = 'INTERESTING';
            $origin = sprintf('Panic at %s:%d', $exception->getFile(), $exception->getLine());

            if ($isFinal) {
                $counterexample = Counterexample::fromThrowable(
                    $testCase->outputLines(),
                    $exception,
                    $this->settings->verbosityLevel() === Verbosity::Debug,
                );
            }
        } catch (Throwable $exception) {
            $status = 'INTERESTING';
            $origin = sprintf('Panic at %s:%d', $exception->getFile(), $exception->getLine());

            if ($isFinal) {
                $counterexample = Counterexample::fromThrowable(
                    $testCase->outputLines(),
                    $exception,
                    $this->settings->verbosityLevel() === Verbosity::Debug,
                );
            }
        }

        if (! $testCase->testAborted()) {
            $testCase->sendMarkComplete($status, $origin);
        }

        return new TestCaseResult($status === 'INTERESTING', $counterexample);
    }

    /**
     * @param list<Counterexample> $counterexamples
     */
    private static function formatPropertyFailure(array $counterexamples): string
    {
        if ($counterexamples === []) {
            return 'Property test failed';
        }

        $sections = ['Property test failed'];
        $numbered = count($counterexamples) > 1;

        foreach ($counterexamples as $index => $counterexample) {
            $sections[] = $counterexample->format($index + 1, $numbered);
        }

        return implode("\n\n", $sections);
    }

    private static function formatNamedFailure(string $title, string $details): string
    {
        return sprintf("%s:\n\n%s", $title, $details);
    }

    private function withServerLog(string $message): string
    {
        $excerpt = self::recentLogExcerpt(self::serverLogPath());

        if ($excerpt === '') {
            return $message;
        }

        return sprintf("%s\n\nRecent .hegel/server.log output:\n%s", $message, $excerpt);
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        /** @var array<string, string> $environment */
        $environment = getenv();

        return $environment;
    }

    private static function recentLogExcerpt(string $path, int $maxLines = 20): string
    {
        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", rtrim($contents));

        if ($lines === false || $lines === []) {
            return '';
        }

        return implode("\n", array_slice($lines, -$maxLines));
    }
}

final class TestCaseResult
{
    public function __construct(
        public readonly bool $interesting,
        public readonly ?Counterexample $counterexample,
    ) {
    }
}
