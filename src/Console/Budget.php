<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use NPlusOne\PHPUnit\Analysis\Hotspot;

/**
 * The rules CI fails on. With no rule given the command only reports, so
 * adding it to a pipeline can never break a build by surprise.
 */
final class Budget
{
    public function __construct(
        public readonly ?int $maxQueries = null,
        public readonly ?int $maxHotspots = null,
        public readonly bool $failOnAny = false,
        public readonly ?Baseline $baseline = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->maxQueries !== null
            || $this->maxHotspots !== null
            || $this->failOnAny
            || $this->baseline !== null;
    }

    /**
     * Hotspots the baseline already accounts for; everything else is new or
     * worse than it was.
     *
     * @param list<Hotspot> $hotspots
     *
     * @return list<Hotspot>
     */
    public function unaccepted(array $hotspots): array
    {
        if ($this->baseline === null) {
            return $hotspots;
        }

        return array_values(array_filter(
            $hotspots,
            fn (Hotspot $hotspot): bool => !$this->baseline->allows($hotspot),
        ));
    }

    /**
     * @param list<Hotspot> $hotspots every hotspot of the run, ranked
     *
     * @return list<Violation>
     */
    public function violations(array $hotspots): array
    {
        $considered = $this->unaccepted($hotspots);
        $violations = [];

        if ($this->baseline !== null) {
            foreach ($considered as $hotspot) {
                $limit = $this->baseline->limitFor($hotspot);

                $violations[] = new Violation(
                    'baseline',
                    $limit === null
                        ? sprintf('New %s hotspot: %dx at %s', $hotspot->kind(), $hotspot->maxCount(), $hotspot->origin->relative(''))
                        : sprintf(
                            'Hotspot got worse: %dx at %s (baseline allowed %dx)',
                            $hotspot->maxCount(),
                            $hotspot->origin->relative(''),
                            $limit,
                        ),
                    $hotspot,
                );
            }
        }

        if ($this->maxQueries !== null) {
            foreach ($considered as $hotspot) {
                if ($hotspot->maxCount() > $this->maxQueries) {
                    $violations[] = new Violation(
                        'max-queries',
                        sprintf(
                            '%dx in one test at %s exceeds the budget of %d',
                            $hotspot->maxCount(),
                            $hotspot->origin->relative(''),
                            $this->maxQueries,
                        ),
                        $hotspot,
                    );
                }
            }
        }

        if ($this->failOnAny) {
            foreach ($considered as $hotspot) {
                $violations[] = new Violation(
                    'fail-on-any',
                    sprintf('%s hotspot: %dx at %s', $hotspot->kind(), $hotspot->maxCount(), $hotspot->origin->relative('')),
                    $hotspot,
                );
            }
        }

        if ($this->maxHotspots !== null && count($considered) > $this->maxHotspots) {
            $violations[] = new Violation(
                'max-hotspots',
                sprintf('%d hotspots exceed the budget of %d', count($considered), $this->maxHotspots),
            );
        }

        return $violations;
    }
}
