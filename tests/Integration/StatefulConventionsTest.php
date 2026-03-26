<?php

declare(strict_types=1);

use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;
use function Hegel\Stateful\run as runStateMachine;
use function Hegel\Stateful\variables;

it('runs convention-discovered state machines through the protocol', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_stateful_server.php',
            'fake-hegel-stateful-conventions',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_STATEFUL_MODE' => 'valid',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                $machine = new class ($testCase) {
                    private \Hegel\Stateful\Variables $pool;
                    private int $checks = 0;

                    public function __construct(TestCase $testCase)
                    {
                        $this->pool = variables($testCase);
                    }

                    public function ruleAdd(TestCase $testCase): void
                    {
                        $testCase->note('adding alpha');
                        $this->pool->add('alpha');
                    }

                    public function ruleDraw(): void
                    {
                        expect($this->pool->draw())->toBe('alpha');
                    }

                    public function ruleConsume(): void
                    {
                        expect($this->pool->consume())->toBe('alpha');
                    }

                    public function invariantChecksStayNonnegative(): void
                    {
                        $this->checks++;
                        expect($this->checks)->toBeGreaterThan(0);
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
                ->and($commands[2]['command'])->toBe('generate')
                ->and($commands[3])->toBe([
                    'command' => 'pool_add',
                    'pool_id' => 1,
                ])
                ->and($commands[5])->toBe([
                    'command' => 'pool_generate',
                    'pool_id' => 1,
                    'consume' => false,
                ])
                ->and($commands[7])->toBe([
                    'command' => 'pool_generate',
                    'pool_id' => 1,
                    'consume' => true,
                ]);
        });
    });
});
