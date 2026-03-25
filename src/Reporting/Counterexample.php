<?php

declare(strict_types=1);

namespace Hegel\Reporting;

use Throwable;

final class Counterexample
{
    /**
     * @param list<string> $outputLines
     */
    public function __construct(
        private array $outputLines,
        private ?string $exceptionClass = null,
        private ?string $exceptionMessage = null,
        private ?string $location = null,
        private ?string $trace = null,
    ) {
    }

    /**
     * @param list<string> $outputLines
     */
    public static function fromThrowable(array $outputLines, Throwable $throwable, bool $includeTrace): self
    {
        return new self(
            $outputLines,
            $throwable::class,
            $throwable->getMessage(),
            sprintf('%s:%d', $throwable->getFile(), $throwable->getLine()),
            $includeTrace ? $throwable->getTraceAsString() : null,
        );
    }

    public function format(int $index, bool $numbered): string
    {
        $lines = [
            $numbered ? sprintf('Counterexample %d:', $index) : 'Counterexample:',
        ];

        foreach ($this->outputLines as $outputLine) {
            $lines[] = $outputLine;
        }

        if ($this->exceptionClass !== null) {
            $summary = $this->exceptionClass;

            if ($this->exceptionMessage !== null && $this->exceptionMessage !== '') {
                $summary .= ': ' . $this->exceptionMessage;
            }

            $lines[] = 'Exception: ' . $summary;
        }

        if ($this->location !== null) {
            $lines[] = 'Origin: ' . $this->location;
        }

        if ($this->trace !== null && $this->trace !== '') {
            $lines[] = 'Stack trace:';
            $lines[] = $this->trace;
        }

        return implode("\n", $lines);
    }
}
