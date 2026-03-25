<?php

declare(strict_types=1);

use Hegel\HealthCheck;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\Verbosity;

it('spawns the server, negotiates the protocol, and sends run_test on the control channel', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_server.php',
            'fake-hegel',
        );

        $settings = (new Settings())
            ->testCases(12)
            ->verbosity(Verbosity::Debug)
            ->seed(42)
            ->derandomize(true)
            ->disableDatabase()
            ->suppressHealthCheck(HealthCheck::TooSlow, HealthCheck::FilterTooMuch);

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_HANDSHAKE' => null,
        ], function () use ($settings, $captureFile, $projectDirectory): void {
            $hegel = new Hegel($settings, 'db-key');

            try {
                $events = $hegel->runTest();
                $event = $events->receiveRequestCbor();

                expect($event['payload']['event'])->toBe('test_done')
                    ->and($event['payload']['results']['passed'])->toBeTrue();

                $events->writeReplyCbor($event['messageId'], ['result' => true]);
                $capture = hegelReadJsonFile($captureFile);
            } finally {
                $hegel->close();
            }

            expect($capture['argv'][1])->toBe('--verbosity')
                ->and($capture['argv'][2])->toBe('debug')
                ->and($capture['handshake']['channel_id'])->toBe(0)
                ->and($capture['handshake']['message_id'])->toBe(1)
                ->and($capture['handshake']['payload'])->toBe('hegel_handshake_start')
                ->and($capture['run_test']['channel_id'])->toBe(0)
                ->and($capture['run_test']['message_id'])->toBe(2)
                ->and($capture['run_test']['payload']['command'])->toBe('run_test')
                ->and($capture['run_test']['payload']['test_cases'])->toBe(12)
                ->and($capture['run_test']['payload']['seed'])->toBe(42)
                ->and($capture['run_test']['payload']['channel_id'])->toBe(3)
                ->and($capture['run_test']['payload']['database_key'])->toBe('db-key')
                ->and($capture['run_test']['payload']['derandomize'])->toBeTrue()
                ->and($capture['run_test']['payload']['database'])->toBeNull()
                ->and($capture['run_test']['payload']['suppress_health_check'])->toBe([
                    'too_slow',
                    'filter_too_much',
                ])
                ->and($capture['event_ack']['channel_id'])->toBe(3)
                ->and($capture['event_ack']['message_id'])->toBe(1)
                ->and($capture['event_ack']['is_reply'])->toBeTrue()
                ->and($capture['event_ack']['payload'])->toBe(['result' => true])
                ->and(file_exists($projectDirectory . '/.hegel/install.log'))->toBeFalse()
                ->and((string) file_get_contents($projectDirectory . '/.hegel/server.log'))
                ->toContain('fake-hegel-server ready')
                ->toContain('fake-hegel-server stderr');
        });
    });
});

it('installs hegel-core with uv when there is no server command override', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $binDirectory = $projectDirectory . '/bin';

        expect(mkdir($binDirectory, 0777, true))->toBeTrue();

        hegelWriteFakeUv(
            $binDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_server.php',
        );

        $originalPath = getenv('PATH');

        hegelWithEnvironment([
            'PATH' => $binDirectory . PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath),
            'HEGEL_SERVER_COMMAND' => null,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_HANDSHAKE' => null,
        ], function () use ($projectDirectory): void {
            $hegel = new Hegel((new Settings())->verbosity(Verbosity::Normal));

            try {
                $events = $hegel->runTest();
                $event = $events->receiveRequestCbor();
                $events->writeReplyCbor($event['messageId'], ['result' => true]);
            } finally {
                $hegel->close();
            }

            expect(file_get_contents($projectDirectory . '/.hegel/venv/hegel-version'))
                ->toBe('0.2.2')
                ->and(file_exists($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
                ->and((string) file_get_contents($projectDirectory . '/.hegel/install.log'))
                ->toContain('uv venv --clear --no-project --python')
                ->toContain('uv pip install --python');
        });
    });
});

it('reinstalls a cached hegel venv when the launcher points at a missing interpreter', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $binDirectory = $projectDirectory . '/bin';
        $venvDirectory = $projectDirectory . '/.hegel/venv/bin';

        expect(mkdir($binDirectory, 0777, true))->toBeTrue()
            ->and(mkdir($venvDirectory, 0777, true))->toBeTrue();

        file_put_contents($projectDirectory . '/.hegel/venv/hegel-version', '0.2.2');
        file_put_contents(
            $venvDirectory . '/hegel',
            "#!/missing/python\nexit 0\n",
        );
        chmod($venvDirectory . '/hegel', 0755);
        symlink('/missing/python', $venvDirectory . '/python');

        hegelWriteFakeUv(
            $binDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_server.php',
        );

        $originalPath = getenv('PATH');

        hegelWithEnvironment([
            'PATH' => $binDirectory . PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath),
            'HEGEL_SERVER_COMMAND' => null,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_HANDSHAKE' => null,
        ], function () use ($projectDirectory): void {
            $hegel = new Hegel();

            try {
                $events = $hegel->runTest();
                $event = $events->receiveRequestCbor();
                $events->writeReplyCbor($event['messageId'], ['result' => true]);
            } finally {
                $hegel->close();
            }

            expect((string) file_get_contents($projectDirectory . '/.hegel/install.log'))
                ->toContain('uv venv --clear --no-project --python')
                ->and((string) file_get_contents($projectDirectory . '/.hegel/venv/bin/hegel'))
                ->toContain('fake_hegel_server.php');
        });
    });
});

it('rejects unsupported protocol versions during the handshake', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_server.php',
            'fake-hegel',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => null,
            'HEGEL_FAKE_HANDSHAKE' => 'Hegel/0.8',
        ], function (): void {
            $hegel = new Hegel();

            try {
                expect(fn () => $hegel->start())
                    ->toThrow(RuntimeException::class, 'supports protocol versions 0.6 through 0.7');
            } finally {
                $hegel->close();
            }
        });
    });
});
