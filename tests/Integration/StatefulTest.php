<?php

declare(strict_types=1);

use Hegel\Hegel;
use Hegel\Settings;
use Hegel\Stateful\Invariant;
use Hegel\Stateful\Rule;
use Hegel\Stateful\StateMachine;
use Hegel\TestCase;
use function Hegel\Stateful\run as runStateMachine;
use function Hegel\Stateful\variables;

it('runs state machines and variable pools through the protocol', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_stateful_server.php',
            'fake-hegel-stateful',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_STATEFUL_MODE' => 'valid',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $machine = new class ($testCase) implements StateMachine {
                    private \Hegel\Stateful\Variables $pool;
                    private int $checks = 0;

                    public function __construct(TestCase $testCase)
                    {
                        $this->pool = variables($testCase);
                    }

                    public function rules(): array
                    {
                        return [
                            Rule::new('add', function (TestCase $testCase): void {
                                $testCase->note('adding alpha');
                                $this->pool->add('alpha');
                            }),
                            Rule::new('draw', function (TestCase $testCase): void {
                                $testCase->note('drawing alpha');
                                expect($this->pool->draw())->toBe('alpha');
                            }),
                            Rule::new('consume', function (TestCase $testCase): void {
                                $testCase->note('consuming alpha');
                                expect($this->pool->consume())->toBe('alpha');
                            }),
                        ];
                    }

                    public function invariants(): array
                    {
                        return [
                            Invariant::new('checks stay nonnegative', function (): void {
                                $this->checks++;
                                expect($this->checks)->toBeGreaterThan(0);
                            }),
                        ];
                    }
                };

                runStateMachine($machine, $testCase);
            });

            $capture = hegelReadJsonFile($captureFile);
            $commands = $capture['cases'][0]['commands'];

            expect($capture['cases'])->toHaveCount(1)
                ->and($capture['cases'][0]['mark_complete'])->toBe([
                    'command' => 'mark_complete',
                    'status' => 'VALID',
                    'origin' => null,
                ])
                ->and($commands[0])->toBe([
                    'command' => 'new_pool',
                ])
                ->and($commands[1]['command'])->toBe('generate')
                ->and($commands[1]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 1,
                    'max_value' => PHP_INT_MAX,
                ])
                ->and($commands[2]['command'])->toBe('generate')
                ->and($commands[2]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($commands[3])->toBe([
                    'command' => 'pool_add',
                    'pool_id' => 1,
                ])
                ->and($commands[4]['command'])->toBe('generate')
                ->and($commands[4]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($commands[5])->toBe([
                    'command' => 'pool_generate',
                    'pool_id' => 1,
                    'consume' => false,
                ])
                ->and($commands[6]['command'])->toBe('generate')
                ->and($commands[6]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 2,
                ])
                ->and($commands[7])->toBe([
                    'command' => 'pool_generate',
                    'pool_id' => 1,
                    'consume' => true,
                ])
                ->and($capture['test_done_ack']['payload'])->toBe(['result' => true]);
        });
    });
});

it('replays failing state machines as property failures with stateful steps', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_stateful_server.php',
            'fake-hegel-stateful',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_STATEFUL_MODE' => 'interesting',
        ], function () use ($captureFile): void {
            $message = null;

            try {
                (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                    $machine = new class () implements StateMachine {
                        private int $count = 0;

                        public function rules(): array
                        {
                            return [
                                Rule::new('increment', function (TestCase $testCase): void {
                                    $this->count++;
                                    $testCase->note(sprintf('count after increment = %d', $this->count));
                                }),
                            ];
                        }

                        public function invariants(): array
                        {
                            return [
                                Invariant::new('count stays below one', function (TestCase $testCase): void {
                                    $testCase->note(sprintf('checking count = %d', $this->count));

                                    if ($this->count >= 1) {
                                        throw new RuntimeException('count must stay below one');
                                    }
                                }),
                            ];
                        }
                    };

                    runStateMachine($machine, $testCase);
                });
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }

            $capture = hegelReadJsonFile($captureFile);

            expect($message)->not->toBeNull()
                ->toContain('Property test failed')
                ->toContain('Counterexample:')
                ->toContain('Initial invariant check.')
                ->toContain('Step 1: increment')
                ->toContain('count after increment = 1')
                ->toContain('checking count = 1')
                ->toContain('Exception: RuntimeException: count must stay below one')
                ->and($capture['cases'])->toHaveCount(2)
                ->and($capture['cases'][0]['mark_complete']['status'])->toBe('INTERESTING')
                ->and($capture['cases'][1]['mark_complete']['status'])->toBe('INTERESTING')
                ->and($capture['test_done_ack']['payload'])->toBe(['result' => true]);
        });
    });
});
