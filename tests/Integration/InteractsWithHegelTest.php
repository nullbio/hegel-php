<?php

declare(strict_types=1);

it('supports class-based tests with the InteractsWithHegel trait', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_pest_helper_server.php',
            'fake-hegel-interacts-with-hegel',
        );

        expect(mkdir($projectDirectory . '/tests/Feature', 0777, true))->toBeTrue();

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
                    <testsuite name="Feature">
                        <directory>tests/Feature</directory>
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

            pest()->in('Feature');
            PHP,
        );

        file_put_contents(
            $projectDirectory . '/tests/Feature/TraitBackedTest.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Hegel\Generators;
            use Hegel\Settings;
            use Hegel\Testing\InteractsWithHegel;
            use Hegel\Verbosity;

            final class TraitBackedTest extends \PHPUnit\Framework\TestCase
            {
                use InteractsWithHegel;

                public function test_runs_a_property_inside_a_class_based_test(): void
                {
                    $settings = (new Settings())
                        ->testCases(12)
                        ->seed(42)
                        ->verbosity(Verbosity::Debug)
                        ->derandomize(true);

                    $this->hegel(function (): void {
                        $value = $this->draw(Generators::integers()->minValue(1)->maxValue(3));
                        expect($value)->toBe(2);
                    }, $settings);
                }
            }
            PHP,
        );

        $result = hegelRunProcess(
            [
                PHP_BINARY,
                '/home/pat/projects/hegel-php/vendor/bin/pest',
                '--configuration',
                $projectDirectory . '/phpunit.xml',
                $projectDirectory . '/tests/Feature/TraitBackedTest.php',
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
            ->and($capture['run_test']['payload']['test_cases'])->toBe(12)
            ->and($capture['run_test']['payload']['seed'])->toBe(42)
            ->and($capture['run_test']['payload']['derandomize'])->toBeTrue()
            ->and($capture['run_test']['payload']['database_key'])->toBe(
                'TraitBackedTest::test_runs_a_property_inside_a_class_based_test',
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
            ]);
    });
});
