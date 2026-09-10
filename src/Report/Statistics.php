<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

final class Statistics
{
    public function __construct(
        public readonly int $analysedTests,
        public readonly int $recordedQueries,
        public readonly int $affectedTests,
        public readonly int $hotspots,
        public readonly bool $listenerAttached = true,
    ) {
    }
}
