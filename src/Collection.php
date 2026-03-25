<?php

declare(strict_types=1);

namespace Hegel;

use RuntimeException;

final class Collection
{
    private ?string $serverName = null;
    private bool $finished = false;

    public function __construct(
        private TestCase $testCase,
        private string $name,
        private int $minSize,
        private ?int $maxSize = null,
    ) {
    }

    public function more(): bool
    {
        if ($this->finished) {
            return false;
        }

        try {
            $response = $this->testCase->sendRequest('collection_more', [
                'collection' => $this->ensureInitialized(),
            ]);
        } catch (StopTestException) {
            $this->finished = true;
            throw new TestCaseControlFlow(TestCase::STOP_TEST_STRING);
        }

        if (! is_bool($response)) {
            throw new RuntimeException(sprintf(
                'Expected bool from collection_more, got %s.',
                get_debug_type($response),
            ));
        }

        if (! $response) {
            $this->finished = true;
        }

        return $response;
    }

    public function reject(?string $why = null): void
    {
        if ($this->finished) {
            return;
        }

        try {
            $payload = ['collection' => $this->ensureInitialized()];

            if ($why !== null) {
                $payload['why'] = $why;
            }

            $this->testCase->sendRequest('collection_reject', $payload);
        } catch (StopTestException) {
        }
    }

    private function ensureInitialized(): string
    {
        if ($this->serverName !== null) {
            return $this->serverName;
        }

        $payload = [
            'name' => $this->name,
            'min_size' => $this->minSize,
        ];

        if ($this->maxSize !== null) {
            $payload['max_size'] = $this->maxSize;
        }

        try {
            $response = $this->testCase->sendRequest('new_collection', $payload);
        } catch (StopTestException) {
            throw new TestCaseControlFlow(TestCase::STOP_TEST_STRING);
        }

        if (! is_string($response)) {
            throw new RuntimeException(sprintf(
                'Expected text response from new_collection, got %s.',
                get_debug_type($response),
            ));
        }

        $this->serverName = $response;

        return $this->serverName;
    }
}
