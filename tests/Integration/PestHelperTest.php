<?php

declare(strict_types=1);

it('provides a hegel helper that stays chainable with Pest', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_pest_helper_server.php',
            'fake-hegel-pest-helper',
        );

        expect(mkdir($projectDirectory . '/tests/Integration', 0777, true))->toBeTrue();

        file_put_contents(
            $projectDirectory . '/phpunit.xml',
            <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit
                bootstrap="/home/pat/projects/hegel-php/vendor/autoload.php"
                cacheDirectory=".phpunit.cache"
                colors="true"
                executionOrder="depends,defects"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xsi:noNamespaceSchemaLocation="/home/pat/projects/hegel-php/vendor/phpunit/phpunit/phpunit.xsd"
            >
                <testsuites>
                    <testsuite name="Integration">
                        <directory>tests/Integration</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML,
        );

        file_put_contents(
            $projectDirectory . '/tests/Pest.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            pest()->in('Integration');
            PHP,
        );

        file_put_contents(
            $projectDirectory . '/tests/Integration/HelperTest.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Hegel\Generators;
            use Hegel\HealthCheck;
            use Hegel\TestCase;

            hegel('runs via the hegel helper', function (TestCase $testCase): void {
                $value = $testCase->draw(Generators::integers()->minValue(1)->maxValue(3));
                expect($value)->toBe(2);
            })
                ->group('hegel-helper')
                ->testCases(12)
                ->seed(42)
                ->verbosity('debug')
                ->derandomize(true)
                ->suppressHealthCheck(HealthCheck::TooSlow, 'filter_too_much');

            test('plain failing test outside the filtered group', function (): void {
                expect(true)->toBeFalse();
            });
            PHP,
        );

        $result = hegelRunProcess(
            [
                PHP_BINARY,
                '/home/pat/projects/hegel-php/vendor/bin/pest',
                '--configuration',
                $projectDirectory . '/phpunit.xml',
                '--group',
                'hegel-helper',
            ],
            $projectDirectory,
            [
                'HEGEL_SERVER_COMMAND' => $serverCommand,
                'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            ],
        );

        $capture = hegelReadJsonFile($captureFile);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('1 passed')
            ->and($capture['argv'][0])->toBe('--stdio')
            ->and($capture['argv'][1])->toBe('--verbosity')
            ->and($capture['argv'][2])->toBe('debug')
            ->and($capture['run_test']['payload']['test_cases'])->toBe(12)
            ->and($capture['run_test']['payload']['seed'])->toBe(42)
            ->and($capture['run_test']['payload']['derandomize'])->toBeTrue()
            ->and($capture['run_test']['payload']['suppress_health_check'])->toBe([
                'too_slow',
                'filter_too_much',
            ])
            ->and($capture['run_test']['payload']['database_key'])->toBe(
                'tests/Integration/HelperTest.php::runs via the hegel helper',
            )
            ->and($capture['generate_requests'])->toBe([
                [
                    'command' => 'generate',
                    'schema' => [
                        'type' => 'integer',
                        'min_value' => 1,
                        'max_value' => 3,
                    ],
                ],
            ])
            ->and($capture['mark_complete'])->toBe([
                'command' => 'mark_complete',
                'status' => 'VALID',
                'origin' => null,
            ])
            ->and($capture['test_case_ack'])->toBe(['result' => null])
            ->and($capture['test_done_ack'])->toBe(['result' => true]);
    });
});

it('reuses one server for multiple hegel helper tests in the same pest process', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_shared_pest_helper_server.php',
            'fake-hegel-shared-pest-helper',
        );

        expect(mkdir($projectDirectory . '/tests/Integration', 0777, true))->toBeTrue();

        file_put_contents(
            $projectDirectory . '/phpunit.xml',
            <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit
                bootstrap="/home/pat/projects/hegel-php/vendor/autoload.php"
                cacheDirectory=".phpunit.cache"
                colors="true"
                executionOrder="depends,defects"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xsi:noNamespaceSchemaLocation="/home/pat/projects/hegel-php/vendor/phpunit/phpunit/phpunit.xsd"
            >
                <testsuites>
                    <testsuite name="Integration">
                        <directory>tests/Integration</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML,
        );

        file_put_contents(
            $projectDirectory . '/tests/Pest.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            pest()->in('Integration');
            PHP,
        );

        file_put_contents(
            $projectDirectory . '/tests/Integration/SharedHelperTest.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Hegel\Generators;
            use Hegel\TestCase;

            hegel('first shared property', function (TestCase $testCase): void {
                $value = $testCase->draw(Generators::integers()->minValue(1)->maxValue(3));
                expect($value)->toBe(2);
            })->group('hegel-shared')->testCases(5)->seed(11);

            hegel('second shared property', function (TestCase $testCase): void {
                $value = $testCase->draw(Generators::integers()->minValue(1)->maxValue(3));
                expect($value)->toBe(2);
            })->group('hegel-shared')->testCases(7)->seed(22);
            PHP,
        );

        $result = hegelRunProcess(
            [
                PHP_BINARY,
                '/home/pat/projects/hegel-php/vendor/bin/pest',
                '--configuration',
                $projectDirectory . '/phpunit.xml',
                '--group',
                'hegel-shared',
            ],
            $projectDirectory,
            [
                'HEGEL_SERVER_COMMAND' => $serverCommand,
                'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            ],
        );

        $capture = hegelReadJsonFile($captureFile);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('2 passed')
            ->and($capture['starts'])->toBe(1)
            ->and($capture['handshakes'])->toHaveCount(1)
            ->and($capture['run_tests'])->toHaveCount(2)
            ->and($capture['run_tests'][0]['payload']['test_cases'])->toBe(5)
            ->and($capture['run_tests'][0]['payload']['seed'])->toBe(11)
            ->and($capture['run_tests'][1]['payload']['test_cases'])->toBe(7)
            ->and($capture['run_tests'][1]['payload']['seed'])->toBe(22)
            ->and($capture['run_tests'][0]['payload']['database_key'])->toBe(
                'tests/Integration/SharedHelperTest.php::first shared property',
            )
            ->and($capture['run_tests'][1]['payload']['database_key'])->toBe(
                'tests/Integration/SharedHelperTest.php::second shared property',
            );
    });
});
