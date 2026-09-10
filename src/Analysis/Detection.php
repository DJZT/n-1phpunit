<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Analysis;

use NPlusOne\PHPUnit\Recording\Origin;

/**
 * One repeated query shape found inside a single test.
 */
final class Detection
{
    public const KIND_N_PLUS_ONE = 'N+1';
    public const KIND_DUPLICATE = 'duplicate';

    public function __construct(
        public readonly string $testId,
        public readonly string $sql,
        public readonly string $normalizedSql,
        public readonly Origin $origin,
        public readonly int $count,
        public readonly int $distinctBindings,
        public readonly float $totalTimeMs,
        public readonly ?string $connection = null,
    ) {
    }

    public function kind(): string
    {
        return $this->distinctBindings > 1 ? self::KIND_N_PLUS_ONE : self::KIND_DUPLICATE;
    }

    public function key(): string
    {
        return md5($this->normalizedSql . '@' . $this->origin->key());
    }
}
