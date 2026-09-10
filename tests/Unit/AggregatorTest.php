<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Recording\Origin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Aggregator::class)]
#[CoversClass(Hotspot::class)]
final class AggregatorTest extends TestCase
{
    public function test_the_same_hotspot_seen_in_several_tests_is_merged(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_index', count: 5));
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_show', count: 3));

        self::assertSame(1, $aggregator->count());
        self::assertSame(2, $aggregator->affectedTestCount());

        $hotspot = $aggregator->top(10)[0];

        self::assertSame(5, $hotspot->maxCount());
        self::assertSame(8, $hotspot->totalCount());
        self::assertSame(2, $hotspot->testCount());
        self::assertSame(
            ['Tests\Feature\OrderTest::test_index' => 5, 'Tests\Feature\OrderTest::test_show' => 3],
            $hotspot->tests(),
        );
    }

    public function test_hotspots_are_ranked_by_the_worst_single_test_then_by_volume(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('t1', count: 4, line: 10));
        $aggregator->add($this->detection('t1', count: 12, line: 20));
        $aggregator->add($this->detection('t1', count: 4, line: 30));
        $aggregator->add($this->detection('t2', count: 4, line: 30));

        $ranked = $aggregator->top(10);

        self::assertSame(12, $ranked[0]->maxCount());
        self::assertSame(30, $ranked[1]->origin->line, 'ties are broken by the total number of queries');
        self::assertSame(10, $ranked[2]->origin->line);
    }

    public function test_it_returns_at_most_the_requested_number_of_hotspots(): void
    {
        $aggregator = new Aggregator();

        for ($line = 1; $line <= 25; ++$line) {
            $aggregator->add($this->detection('t1', count: $line + 2, line: $line));
        }

        $top = $aggregator->top(10);

        self::assertCount(10, $top);
        self::assertSame(27, $top[0]->maxCount());
        self::assertSame(18, $top[9]->maxCount());
        self::assertSame(25, $aggregator->count());
    }

    public function test_a_hotspot_serialises_to_an_array(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_index', count: 5));

        $array = $aggregator->top(1)[0]->toArray('/app');

        self::assertSame('N+1', $array['kind']);
        self::assertSame('Http/Controllers/OrderController.php:20', $array['origin']);
        self::assertSame(5, $array['max_queries_in_one_test']);
        self::assertSame(['Tests\Feature\OrderTest::test_index' => 5], $array['tests']);
    }

    public function test_an_empty_aggregator_has_nothing_to_report(): void
    {
        $aggregator = new Aggregator();

        self::assertTrue($aggregator->isEmpty());
        self::assertSame([], $aggregator->top(10));
    }

    private function detection(string $testId, int $count, int $line = 20): Detection
    {
        return new Detection(
            testId: $testId,
            sql: 'select * from "users" where "id" = ?',
            normalizedSql: 'select * from "users" where "id" = ?',
            origin: new Origin('/app/Http/Controllers/OrderController.php', $line, 'render()'),
            count: $count,
            distinctBindings: $count,
            totalTimeMs: $count * 0.5,
            connection: 'testing',
        );
    }
}
