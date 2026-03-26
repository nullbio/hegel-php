<?php

declare(strict_types=1);

use Hegel\Generators;
use Hegel\Testing\InteractsWithHegel;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class InteractsWithHegelHarness extends PhpUnitTestCase
{
    use InteractsWithHegel;

    public function exposeDraw(): mixed
    {
        return $this->draw(Generators::integers());
    }
}

it('guards class-based helper methods outside an active property run', function (): void {
    $harness = new InteractsWithHegelHarness('testGuardsClassBasedHelperMethodsOutsideAnActivePropertyRun');

    expect(fn (): mixed => $harness->exposeDraw())
        ->toThrow(LogicException::class, 'Hegel helpers are only available while a hegel() property is running.');
});
