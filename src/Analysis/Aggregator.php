<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Analysis;

/**
 * Collects detections from every test and ranks them.
 */
final class Aggregator
{
    /** @var array<string, Hotspot> */
    private array $hotspots = [];

    /** @var array<string, true> */
    private array $affectedTests = [];

    public function add(Detection $detection): void
    {
        $key = $detection->key();

        if (isset($this->hotspots[$key])) {
            $this->hotspots[$key]->merge($detection);
        } else {
            $this->hotspots[$key] = Hotspot::from($detection);
        }

        $this->affectedTests[$detection->testId] = true;
    }

    /**
     * @param list<Detection> $detections
     */
    public function addAll(array $detections): void
    {
        foreach ($detections as $detection) {
            $this->add($detection);
        }
    }

    public function isEmpty(): bool
    {
        return $this->hotspots === [];
    }

    public function count(): int
    {
        return count($this->hotspots);
    }

    public function affectedTestCount(): int
    {
        return count($this->affectedTests);
    }

    /**
     * Worst offenders first: the biggest burst inside a single test wins, then
     * the overall volume, then the time spent.
     *
     * @return list<Hotspot>
     */
    public function top(int $limit): array
    {
        $hotspots = array_values($this->hotspots);

        usort($hotspots, static function (Hotspot $a, Hotspot $b): int {
            return [$b->maxCount(), $b->totalCount(), $b->totalTimeMs()]
                <=> [$a->maxCount(), $a->totalCount(), $a->totalTimeMs()];
        });

        return array_slice($hotspots, 0, $limit);
    }
}
