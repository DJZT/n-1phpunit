<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Recording;

/**
 * Buffers the queries of the test that is currently running.
 */
final class QueryRecorder
{
    private ?string $testId = null;

    /** @var list<QueryEvent> */
    private array $queries = [];

    private int $totalQueries = 0;

    public function start(string $testId): void
    {
        $this->testId = $testId;
        $this->queries = [];
    }

    public function isRecording(): bool
    {
        return $this->testId !== null;
    }

    public function currentTestId(): ?string
    {
        return $this->testId;
    }

    public function record(QueryEvent $query): void
    {
        if ($this->testId === null) {
            return;
        }

        $this->queries[] = $query;
        ++$this->totalQueries;
    }

    /**
     * @return list<QueryEvent>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Closes the current test and hands over everything recorded for it.
     *
     * @return list<QueryEvent>
     */
    public function stop(): array
    {
        $queries = $this->queries;

        $this->testId = null;
        $this->queries = [];

        return $queries;
    }

    public function totalQueries(): int
    {
        return $this->totalQueries;
    }
}
