<?php

declare(strict_types=1);

use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;
use Hegel\Generators;

it('runs a passing property and marks the test case valid', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'valid',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $testCase->note('this only prints during final replay');
                $testCase->assume(true);
            });

            $capture = hegelReadJsonFile($captureFile);

            expect($capture['cases'])->toHaveCount(1)
                ->and($capture['cases'][0]['ack']['payload'])->toBe(['result' => null])
                ->and($capture['cases'][0]['mark_complete']['payload'])->toBe([
                    'command' => 'mark_complete',
                    'status' => 'VALID',
                    'origin' => null,
                ])
                ->and($capture['cases'][0]['close']['message_id'])->toBe(0x7FFFFFFF)
                ->and($capture['cases'][0]['close']['payload'])->toBe('fe')
                ->and($capture['test_done_ack']['payload'])->toBe(['result' => true]);
        });
    });
});

it('treats failed assumptions as invalid test cases', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'invalid',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $testCase->assume(false);
            });

            $capture = hegelReadJsonFile($captureFile);

            expect($capture['cases'][0]['mark_complete']['payload'])->toBe([
                'command' => 'mark_complete',
                'status' => 'INVALID',
                'origin' => null,
            ]);
        });
    });
});

it('replays interesting failures and then raises a property failure', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'interesting',
        ], function () use ($captureFile): void {
            $message = null;

            try {
                (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                    $testCase->draw(Generators::just('counterexample'));
                    $testCase->note('minimal failing example');
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }

            expect($message)->not->toBeNull()
                ->toContain('Property test failed')
                ->toContain('Counterexample:')
                ->toContain("Draw 1: 'counterexample'")
                ->toContain('minimal failing example')
                ->toContain('Exception: RuntimeException: boom')
                ->toContain('Origin: ');

            $capture = hegelReadJsonFile($captureFile);

            expect($capture['cases'])->toHaveCount(2)
                ->and($capture['cases'][0]['mark_complete']['payload']['status'])->toBe('INTERESTING')
                ->and($capture['cases'][1]['mark_complete']['payload']['status'])->toBe('INTERESTING')
                ->and($capture['cases'][0]['mark_complete']['payload']['origin'])->toStartWith('Panic at ')
                ->and($capture['cases'][1]['mark_complete']['payload']['origin'])->toStartWith('Panic at ')
                ->and($capture['test_done_ack']['payload'])->toBe(['result' => true]);
        });
    });
});

it('surfaces server-side errors from test_done results', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'server_error',
        ], function () use ($captureFile): void {
            expect(function (): void {
                (new Hegel(new Settings()))->run(function (): void {
                });
            })->toThrow(RuntimeException::class, "Server error:\n\nboom");

            $capture = hegelReadJsonFile($captureFile);

            expect($capture['cases'])->toHaveCount(1)
                ->and($capture['cases'][0]['mark_complete']['payload']['status'])->toBe('VALID')
                ->and($capture['test_done_ack']['payload'])->toBe(['result' => true]);
        });
    });
});

it('surfaces health check failures from test_done results', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'health_check_failure',
        ], function (): void {
            expect(function (): void {
                (new Hegel(new Settings()))->run(function (): void {
                });
            })->toThrow(RuntimeException::class, "Health check failure:\n\nfilter_too_much");
        });
    });
});

it('surfaces flaky replay messages from test_done results', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'flaky',
        ], function (): void {
            expect(function (): void {
                (new Hegel(new Settings()))->run(function (): void {
                });
            })->toThrow(RuntimeException::class, "Flaky test detected:\n\ninconsistent replay");
        });
    });
});

it('surfaces unexpected mid-run server exits with a log excerpt', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_runner_server.php',
            'fake-hegel-runner',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_RUNNER_MODE' => 'server_exit',
        ], function (): void {
            expect(function (): void {
                (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                    $testCase->assume(true);
                });
            })->toThrow(RuntimeException::class, 'runner crashed unexpectedly');
        });
    });
});
