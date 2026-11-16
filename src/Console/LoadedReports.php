<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use NPlusOne\PHPUnit\Analysis\Detection;

final class LoadedReports
{
    /**
     * @param list<string> $files
     * @param list<Detection> $detections
     */
    public function __construct(
        public readonly array $files,
        public readonly array $detections,
        public readonly int $analysedTests,
        public readonly int $recordedQueries,
        public readonly int $affectedTests,
        public readonly int $threshold,
        public readonly int $oldest = 0,
        public readonly int $newest = 0,
    ) {
    }

    /**
     * How far apart the reports were written. A wide spread means the
     * directory still holds files from an earlier run.
     */
    public function spreadInSeconds(): int
    {
        return $this->oldest > 0 && $this->newest > 0 ? $this->newest - $this->oldest : 0;
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }
}
