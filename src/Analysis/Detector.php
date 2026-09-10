<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Analysis;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\QueryEvent;

/**
 * Turns the query log of one test into detections.
 *
 * A detection is a query shape that was executed at least `threshold` times
 * from the same call site during that test.
 */
final class Detector
{
    public function __construct(
        private readonly Options $options,
        private readonly SqlNormalizer $normalizer = new SqlNormalizer(),
    ) {
    }

    /**
     * @param list<QueryEvent> $queries
     *
     * @return list<Detection>
     */
    public function detect(string $testId, array $queries): array
    {
        /** @var array<string, array{sql: string, normalized: string, origin: \NPlusOne\PHPUnit\Recording\Origin, count: int, bindings: array<string, true>, time: float, connection: ?string}> $groups */
        $groups = [];

        foreach ($queries as $query) {
            $normalized = $this->normalizer->normalize($query->sql);

            if ($this->options->isIgnoredSql($normalized)) {
                continue;
            }

            $key = $normalized . '@' . $query->origin->key();

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sql' => $query->sql,
                    'normalized' => $normalized,
                    'origin' => $query->origin,
                    'count' => 0,
                    'bindings' => [],
                    'time' => 0.0,
                    'connection' => $query->connection,
                ];
            }

            ++$groups[$key]['count'];
            $groups[$key]['bindings'][$query->bindingsKey()] = true;
            $groups[$key]['time'] += $query->timeMs;
        }

        $detections = [];

        foreach ($groups as $group) {
            if ($group['count'] < $this->options->threshold) {
                continue;
            }

            $detection = new Detection(
                testId: $testId,
                sql: $group['sql'],
                normalizedSql: $group['normalized'],
                origin: $group['origin'],
                count: $group['count'],
                distinctBindings: count($group['bindings']),
                totalTimeMs: $group['time'],
                connection: $group['connection'],
            );

            if (!$this->options->includeDuplicates && $detection->kind() === Detection::KIND_DUPLICATE) {
                continue;
            }

            $detections[] = $detection;
        }

        return $detections;
    }
}
