<?php

declare(strict_types=1);

it('runs a real Pest property against hegel-core', function (): void {
    hegelWithTemporaryProject(function (string $projectDirectory): void {
        $traceFile = $projectDirectory . '/trace.jsonl';
        $testFile = $projectDirectory . '/RealPropertyTest.php';

        $testContents = <<<'PHP'
<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\TestCase;

hegel('real hegel-core property', function (TestCase $tc): void {
    $integer = $tc->draw(Generators::integers());
    $text = $tc->draw(Generators::text());
    $bytes = $tc->draw(Generators::binary());
    $items = $tc->draw(Generators::arrays(Generators::integers()));
    $choice = $tc->draw(Generators::oneOf(
        Generators::integers(),
        Generators::just(123),
    ));

    expect($integer)->toBeInt();
    expect($text)->toBeString();
    expect($bytes)->toBeString();
    expect($items)->toBeArray();
    expect($choice)->toBeInt();

    file_put_contents(
        __DIR__ . '/trace.jsonl',
        json_encode([
            'integer' => is_int($integer),
            'text' => is_string($text),
            'bytes' => is_string($bytes),
            'items' => is_array($items),
            'choice' => is_int($choice),
        ], JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND,
    );
})->testCases(5)->seed(123)->verbosity('quiet')->disableDatabase();
PHP;

        file_put_contents($testFile, $testContents);

        $path = getenv('PATH');
        $home = getenv('HOME');

        $result = hegelRunProcess(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/vendor/bin/pest',
                '--bootstrap',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                '--colors=never',
                $testFile,
            ],
            $projectDirectory,
            [
                'PATH' => is_string($path) ? $path : '',
                'HOME' => is_string($home) ? $home : $projectDirectory,
            ],
        );

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(sprintf(
                "Real hegel-core Pest run failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                $result['stdout'],
                $result['stderr'],
            ));
        }

        expect(is_file($projectDirectory . '/.hegel/venv/bin/hegel'))->toBeTrue()
            ->and(is_file($projectDirectory . '/.hegel/server.log'))->toBeTrue()
            ->and(is_file($traceFile))->toBeTrue();

        $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        expect($lines)->not->toBeFalse();
        assert(is_array($lines));
        expect($lines)->toHaveCount(5);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            expect($decoded)->toBe([
                'integer' => true,
                'text' => true,
                'bytes' => true,
                'items' => true,
                'choice' => true,
            ]);
        }
    });
});
