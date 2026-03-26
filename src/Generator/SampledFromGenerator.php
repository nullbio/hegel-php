<?php

declare(strict_types=1);

namespace Hegel\Generator;

use Hegel\Exception\GenerationException;
use Hegel\TestCase;
use InvalidArgumentException;

final class SampledFromGenerator extends AbstractGenerator
{
    /**
     * @var list<mixed>
     */
    private array $values;

    /**
     * @param array<int|string, mixed> $values
     */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new InvalidArgumentException('sampledFrom requires at least one element.');
        }

        $this->values = array_values($values);
    }

    public function draw(TestCase $testCase): mixed
    {
        return $this->basic()->draw($testCase);
    }

    public function basic(): BasicGeneratorDefinition
    {
        return new BasicGeneratorDefinition(
            [
                'type' => 'integer',
                'min_value' => 0,
                'max_value' => count($this->values) - 1,
            ],
            function (mixed $value): mixed {
                $index = GeneratorValue::toInteger($value, 'sampledFrom index');

                if (! array_key_exists($index, $this->values)) {
                    throw new GenerationException(sprintf('Generated sampledFrom index %d is out of range.', $index));
                }

                return GeneratorValue::copy($this->values[$index]);
            },
        );
    }
}
