<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Detector;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Recording\QueryEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Detector::class)]
#[CoversClass(Detection::class)]
final class DetectorTest extends TestCase
{
    private const TEST_ID = 'Tests\Feature\OrderTest::test_index';

    public function test_it_reports_a_query_shape_repeated_from_one_call_site(): void
    {
        $detections = $this->detect([
            $this->query('select * from "orders"', [], line: 10),
            $this->query('select * from "users" where "id" = ?', [1], line: 20),
            $this->query('select * from "users" where "id" = ?', [2], line: 20),
            $this->query('select * from "users" where "id" = ?', [3], line: 20),
        ]);

        self::assertCount(1, $detections);
        self::assertSame(3, $detections[0]->count);
        self::assertSame(3, $detections[0]->distinctBindings);
        self::assertSame(Detection::KIND_N_PLUS_ONE, $detections[0]->kind());
        self::assertSame(20, $detections[0]->origin->line);
        self::assertSame(self::TEST_ID, $detections[0]->testId);
    }

    public function test_it_stays_quiet_below_the_threshold(): void
    {
        $detections = $this->detect([
            $this->query('select * from "users" where "id" = ?', [1], line: 20),
            $this->query('select * from "users" where "id" = ?', [2], line: 20),
        ]);

        self::assertSame([], $detections);
    }

    public function test_the_same_shape_from_different_call_sites_is_not_merged(): void
    {
        $detections = $this->detect([
            $this->query('select * from "users" where "id" = ?', [1], line: 20),
            $this->query('select * from "users" where "id" = ?', [2], line: 20),
            $this->query('select * from "users" where "id" = ?', [3], line: 55),
        ]);

        self::assertSame([], $detections);
    }

    public function test_identical_bindings_are_reported_as_duplicates(): void
    {
        $detections = $this->detect([
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
        ]);

        self::assertCount(1, $detections);
        self::assertSame(1, $detections[0]->distinctBindings);
        self::assertSame(Detection::KIND_DUPLICATE, $detections[0]->kind());
    }

    public function test_duplicates_can_be_excluded(): void
    {
        $queries = [
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
            $this->query('select * from "settings" where "key" = ?', ['locale'], line: 12),
        ];

        self::assertSame([], $this->detect($queries, new Options(includeDuplicates: false)));
    }

    public function test_ignored_statements_never_produce_detections(): void
    {
        $options = new Options(ignoreSql: ['~^select \* from "migrations~']);

        $detections = $this->detect([
            $this->query('select * from "migrations" where "batch" = ?', [1], line: 3),
            $this->query('select * from "migrations" where "batch" = ?', [2], line: 3),
            $this->query('select * from "migrations" where "batch" = ?', [3], line: 3),
        ], $options);

        self::assertSame([], $detections);
    }

    public function test_it_sums_the_time_spent_in_a_hotspot(): void
    {
        $detections = $this->detect([
            $this->query('select * from "users" where "id" = ?', [1], line: 20, timeMs: 1.5),
            $this->query('select * from "users" where "id" = ?', [2], line: 20, timeMs: 2.0),
            $this->query('select * from "users" where "id" = ?', [3], line: 20, timeMs: 0.5),
        ]);

        self::assertEqualsWithDelta(4.0, $detections[0]->totalTimeMs, 0.0001);
    }

    /**
     * @param list<QueryEvent> $queries
     *
     * @return list<Detection>
     */
    private function detect(array $queries, ?Options $options = null): array
    {
        return (new Detector($options ?? new Options()))->detect(self::TEST_ID, $queries);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function query(string $sql, array $bindings, int $line, float $timeMs = 0.1): QueryEvent
    {
        return new QueryEvent($sql, $bindings, $timeMs, 'testing', new Origin('/app/Http/Controllers/OrderController.php', $line, 'render()'));
    }
}
