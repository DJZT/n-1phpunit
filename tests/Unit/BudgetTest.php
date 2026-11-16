<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Console\Baseline;
use NPlusOne\PHPUnit\Console\Budget;
use NPlusOne\PHPUnit\Console\Violation;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Tests\Support\TemporaryReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Budget::class)]
#[CoversClass(Violation::class)]
final class BudgetTest extends TestCase
{
    use TemporaryReports;

    protected function setUp(): void
    {
        $this->setUpTemporaryReports();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryReports();
    }

    public function test_without_a_rule_nothing_can_fail(): void
    {
        $budget = new Budget();

        self::assertFalse($budget->isActive());
        self::assertSame([], $budget->violations([$this->hotspot(90)]));
    }

    public function test_the_query_budget_names_the_offending_hotspot(): void
    {
        $violations = (new Budget(maxQueries: 20))->violations([$this->hotspot(87), $this->hotspot(5, line: 44)]);

        self::assertCount(1, $violations);
        self::assertSame('max-queries', $violations[0]->rule);
        self::assertStringContainsString('87x in one test', $violations[0]->message);
        self::assertStringContainsString('budget of 20', $violations[0]->message);
        self::assertSame(87, $violations[0]->hotspot?->maxCount());
    }

    public function test_the_hotspot_budget_counts_places_not_queries(): void
    {
        $hotspots = [$this->hotspot(4, line: 1), $this->hotspot(4, line: 2), $this->hotspot(4, line: 3)];

        self::assertSame([], (new Budget(maxHotspots: 3))->violations($hotspots));

        $violations = (new Budget(maxHotspots: 2))->violations($hotspots);

        self::assertCount(1, $violations);
        self::assertSame('max-hotspots', $violations[0]->rule);
        self::assertNull($violations[0]->hotspot);
    }

    public function test_fail_on_any_reports_every_hotspot(): void
    {
        $violations = (new Budget(failOnAny: true))->violations([$this->hotspot(4), $this->hotspot(9, line: 44)]);

        self::assertCount(2, $violations);
        self::assertSame(['fail-on-any', 'fail-on-any'], array_column($violations, 'rule'));
    }

    public function test_a_baseline_hides_what_it_already_knows(): void
    {
        $budget = new Budget(baseline: $this->baselineOf([$this->hotspot(40)]));

        self::assertTrue($budget->isActive());
        self::assertSame([], $budget->violations([$this->hotspot(40)]));
        self::assertSame([], $budget->unaccepted([$this->hotspot(40)]));
    }

    public function test_a_new_hotspot_breaks_the_baseline(): void
    {
        $budget = new Budget(baseline: $this->baselineOf([$this->hotspot(40)]));

        $violations = $budget->violations([$this->hotspot(40), $this->hotspot(3, line: 77)]);

        self::assertCount(1, $violations);
        self::assertSame('baseline', $violations[0]->rule);
        self::assertStringContainsString('New N+1 hotspot', $violations[0]->message);
        self::assertStringContainsString(':77', $violations[0]->message);
    }

    public function test_a_known_hotspot_that_grows_breaks_the_baseline(): void
    {
        $budget = new Budget(baseline: $this->baselineOf([$this->hotspot(40)]));

        $violations = $budget->violations([$this->hotspot(41)]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('got worse: 41x', $violations[0]->message);
        self::assertStringContainsString('baseline allowed 40x', $violations[0]->message);
    }

    public function test_other_rules_only_look_at_what_the_baseline_does_not_cover(): void
    {
        $budget = new Budget(
            maxQueries: 10,
            failOnAny: true,
            baseline: $this->baselineOf([$this->hotspot(40)]),
        );

        self::assertSame([], $budget->violations([$this->hotspot(40)]), 'a grandfathered hotspot stays silent');

        $violations = $budget->violations([$this->hotspot(40), $this->hotspot(11, line: 55)]);

        self::assertSame(['baseline', 'max-queries', 'fail-on-any'], array_column($violations, 'rule'));

        foreach ($violations as $violation) {
            self::assertSame(55, $violation->hotspot?->origin->line);
        }
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function baselineOf(array $hotspots): Baseline
    {
        $path = $this->path('baseline.json');
        file_put_contents($path, Baseline::render($hotspots));

        return Baseline::load($path);
    }

    private function hotspot(int $count, int $line = 31): Hotspot
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 'Tests\Feature\OrderTest::test_index',
            sql: 'select * from "users" where "id" = ?',
            normalizedSql: 'select * from "users" where "id" = ?',
            origin: new Origin('app/Http/Controllers/OrderController.php', $line, null),
            count: $count,
            distinctBindings: $count,
            totalTimeMs: 1.0,
        ));

        return $aggregator->top(1)[0];
    }
}
