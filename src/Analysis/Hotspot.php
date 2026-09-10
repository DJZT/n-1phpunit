<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Analysis;

use NPlusOne\PHPUnit\Recording\Origin;

/**
 * The same query shape and call site, merged across every test that hit it.
 */
final class Hotspot
{
    /** @var array<string, int> test id => number of executions in that test */
    private array $tests = [];

    private int $maxCount = 0;

    private int $totalCount = 0;

    private float $totalTimeMs = 0.0;

    private bool $nPlusOne = false;

    private function __construct(
        public readonly string $normalizedSql,
        public readonly string $sql,
        public readonly Origin $origin,
        public readonly ?string $connection,
    ) {
    }

    public static function from(Detection $detection): self
    {
        $hotspot = new self(
            $detection->normalizedSql,
            $detection->sql,
            $detection->origin,
            $detection->connection,
        );
        $hotspot->merge($detection);

        return $hotspot;
    }

    public function merge(Detection $detection): void
    {
        $this->tests[$detection->testId] = ($this->tests[$detection->testId] ?? 0) + $detection->count;
        $this->totalCount += $detection->count;
        $this->totalTimeMs += $detection->totalTimeMs;
        $this->maxCount = max($this->maxCount, $detection->count);
        $this->nPlusOne = $this->nPlusOne || $detection->kind() === Detection::KIND_N_PLUS_ONE;
    }

    public function kind(): string
    {
        return $this->nPlusOne ? Detection::KIND_N_PLUS_ONE : Detection::KIND_DUPLICATE;
    }

    public function maxCount(): int
    {
        return $this->maxCount;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    public function totalTimeMs(): float
    {
        return $this->totalTimeMs;
    }

    /**
     * @return array<string, int>
     */
    public function tests(): array
    {
        arsort($this->tests);

        return $this->tests;
    }

    public function testCount(): int
    {
        return count($this->tests);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $basePath = ''): array
    {
        return [
            'kind' => $this->kind(),
            'sql' => $this->sql,
            'normalized_sql' => $this->normalizedSql,
            'origin' => $this->origin->relative($basePath),
            'function' => $this->origin->function,
            'connection' => $this->connection,
            'max_queries_in_one_test' => $this->maxCount,
            'total_queries' => $this->totalCount,
            'total_time_ms' => round($this->totalTimeMs, 3),
            'tests' => $this->tests(),
        ];
    }
}
