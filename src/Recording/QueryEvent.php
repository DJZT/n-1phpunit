<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Recording;

/**
 * A single executed SQL statement, as observed during a test.
 */
final class QueryEvent
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly float $timeMs,
        public readonly ?string $connection,
        public readonly Origin $origin,
    ) {
    }

    /**
     * Stable fingerprint of the bindings, used to tell a real N+1 (same query,
     * different parameters) apart from a plain duplicated query.
     */
    public function bindingsKey(): string
    {
        $scalars = array_map(static function (mixed $binding): string {
            if (is_object($binding)) {
                return method_exists($binding, '__toString') ? (string) $binding : $binding::class;
            }

            if (is_array($binding)) {
                return md5(serialize($binding));
            }

            return var_export($binding, true);
        }, $this->bindings);

        return md5(implode('|', $scalars));
    }
}
