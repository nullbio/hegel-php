<?php

declare(strict_types=1);

use Hegel\Generator\ProvidesGenerator;
use Hegel\Generators;
use Hegel\Hegel;
use Hegel\Settings;
use Hegel\TestCase;

enum DefaultProtocolStatus
{
    case Draft;
    case Paid;
}

final readonly class DefaultProtocolProfile
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}

final readonly class DefaultProtocolEmailProfile
{
    public function __construct(
        public string $email,
        public int $age,
    ) {
    }
}

final readonly class DefaultProtocolMoney
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}

final class DefaultProvidedValue implements ProvidesGenerator
{
    public function __construct(
        public string $label,
    ) {
    }

    public static function generator(): \Hegel\Generator\Generator
    {
        return Generators::just(new self('provided'));
    }
}

it('sends expected schemas for default object and typed builder generators', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $captureFile = $projectDirectory . '/capture.json';
        $serverCommand = hegelWritePhpWrapper(
            $projectDirectory,
            __DIR__ . '/../Fixtures/fake_hegel_generator_server.php',
            'fake-hegel-default-object-generator',
        );

        hegelWithEnvironment([
            'HEGEL_SERVER_COMMAND' => $serverCommand,
            'HEGEL_FAKE_CAPTURE_FILE' => $captureFile,
            'HEGEL_FAKE_GENERATOR_SERVER_MODE' => 'default_object_generators',
        ], function () use ($captureFile): void {
            (new Hegel(new Settings()))->run(function (TestCase $testCase): void {
                /** @var \Hegel\Generator\ArrayGenerator $numbersGenerator */
                $numbersGenerator = Generators::default('array', Generators::default('int'));
                $numbers = $testCase->draw($numbersGenerator->minSize(2)->maxSize(2));
                $status = $testCase->draw(Generators::default(DefaultProtocolStatus::class));
                $profile = $testCase->draw(Generators::default(DefaultProtocolProfile::class));
                $emailProfile = $testCase->draw(
                    Generators::object(DefaultProtocolEmailProfile::class)
                        ->with('email', Generators::emails())
                        ->with('age', Generators::integers()->minValue(18)->maxValue(99)),
                );
                $money = $testCase->draw(
                    Generators::fixedDicts()
                        ->field('amount', Generators::integers()->minValue(1)->maxValue(9))
                        ->field('currency', Generators::sampledFrom(['USD', 'EUR']))
                        ->into(DefaultProtocolMoney::class),
                );
                $provided = $testCase->draw(Generators::default(DefaultProvidedValue::class));

                expect($numbers)->toBe([1, 2])
                    ->and($status)->toBe(DefaultProtocolStatus::Paid)
                    ->and($profile)->toEqual(new DefaultProtocolProfile('Ada', 25))
                    ->and($emailProfile)->toEqual(new DefaultProtocolEmailProfile('user@example.test', 30))
                    ->and($money)->toEqual(new DefaultProtocolMoney(7, 'EUR'))
                    ->and($provided)->toEqual(new DefaultProvidedValue('provided'));
            });

            $capture = hegelReadJsonFile($captureFile);
            $requests = $capture['generate_requests'];

            expect($requests)->toHaveCount(5)
                ->and($requests[0]['schema'])->toBe([
                    'type' => 'list',
                    'unique' => false,
                    'elements' => [
                        'type' => 'integer',
                        'min_value' => PHP_INT_MIN,
                        'max_value' => PHP_INT_MAX,
                    ],
                    'min_size' => 2,
                    'max_size' => 2,
                ])
                ->and($requests[1]['schema'])->toBe([
                    'type' => 'integer',
                    'min_value' => 0,
                    'max_value' => 1,
                ])
                ->and($requests[2]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'string',
                            'min_size' => 0,
                        ],
                        [
                            'type' => 'integer',
                            'min_value' => PHP_INT_MIN,
                            'max_value' => PHP_INT_MAX,
                        ],
                    ],
                ])
                ->and($requests[3]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'email',
                        ],
                        [
                            'type' => 'integer',
                            'min_value' => 18,
                            'max_value' => 99,
                        ],
                    ],
                ])
                ->and($requests[4]['schema'])->toBe([
                    'type' => 'tuple',
                    'elements' => [
                        [
                            'type' => 'integer',
                            'min_value' => 1,
                            'max_value' => 9,
                        ],
                        [
                            'type' => 'integer',
                            'min_value' => 0,
                            'max_value' => 1,
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
});
