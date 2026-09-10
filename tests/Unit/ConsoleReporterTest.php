<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Report\ConsoleReporter;
use NPlusOne\PHPUnit\Report\Statistics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleReporter::class)]
#[CoversClass(Statistics::class)]
final class ConsoleReporterTest extends TestCase
{
    public function test_it_renders_the_ranked_hotspots(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_index', count: 42, line: 31));
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_show', count: 7, line: 31));
        $aggregator->add($this->detection('Tests\Feature\CartTest::test_totals', count: 4, line: 88, distinct: 1));

        $output = $this->reporter()->render(
            $aggregator->top(10),
            new Statistics(analysedTests: 12, recordedQueries: 400, affectedTests: 3, hotspots: $aggregator->count()),
        );

        self::assertStringContainsString('N+1 query report', $output);
        self::assertStringContainsString('Top 2 of 2 hotspots', $output);
        self::assertStringContainsString('#1', $output);
        self::assertStringContainsString('42x', $output);
        self::assertStringContainsString('Http/Controllers/OrderController.php:31', $output);
        self::assertStringContainsString('select * from "users" where "id" = ?', $output);
        self::assertStringContainsString('Tests\Feature\OrderTest::test_index (42x) +1 more test', $output);
        self::assertStringContainsString('49 queries overall', $output);
        self::assertStringContainsString('duplicate', $output);
        self::assertStringContainsString('400 queries recorded in 12 tests; 3 tests affected.', $output);
    }

    public function test_the_worst_hotspot_comes_first(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('t1', count: 3, line: 10));
        $aggregator->add($this->detection('t1', count: 90, line: 20));

        $output = $this->reporter()->render($aggregator->top(10), new Statistics(1, 93, 1, 2));

        self::assertLessThan(strpos($output, '3x'), (int) strpos($output, '90x'));
    }

    public function test_long_statements_are_truncated(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 't1',
            sql: 'select ' . str_repeat('"column_name", ', 40) . ' from "users"',
            normalizedSql: 'x',
            origin: new Origin('/app/A.php', 1, null),
            count: 3,
            distinctBindings: 3,
            totalTimeMs: 1.0,
        ));

        $reporter = new ConsoleReporter(new Options(sqlMaxLength: 60, basePath: '/app', colors: false));
        $output = $reporter->render($aggregator->top(10), new Statistics(1, 3, 1, 1));

        self::assertStringContainsString('…', $output);
        foreach (explode(PHP_EOL, $output) as $line) {
            self::assertLessThanOrEqual(78, mb_strlen($line), 'the report must stay inside a terminal width');
        }
    }

    public function test_it_says_so_when_nothing_was_found(): void
    {
        $output = $this->reporter()->render([], new Statistics(analysedTests: 12, recordedQueries: 40, affectedTests: 0, hotspots: 0));

        self::assertStringContainsString('No repeated query patterns found.', $output);
        self::assertStringContainsString('40 queries recorded in 12 tests', $output);
    }

    public function test_it_hints_when_no_listener_could_be_attached(): void
    {
        $output = $this->reporter()->render([], new Statistics(12, 0, 0, 0, listenerAttached: false));

        self::assertStringContainsString('No query listener could be attached', $output);
        self::assertStringContainsString('NPlusOne::listen($this->app)', $output);
    }

    public function test_it_does_not_hint_when_the_listener_did_attach(): void
    {
        $output = $this->reporter()->render([], new Statistics(12, 40, 0, 0));

        self::assertStringNotContainsString('No query listener', $output);
    }

    public function test_it_prints_nothing_when_no_test_was_analysed(): void
    {
        self::assertSame('', $this->reporter()->render([], new Statistics(0, 0, 0, 0)));
    }

    public function test_colours_are_emitted_only_when_enabled(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('t1', count: 3, line: 10));

        $plain = $this->reporter()->render($aggregator->top(1), new Statistics(1, 3, 1, 1));
        $coloured = (new ConsoleReporter(new Options(basePath: '/app', colors: true)))
            ->render($aggregator->top(1), new Statistics(1, 3, 1, 1));

        self::assertStringNotContainsString("\033[", $plain);
        self::assertStringContainsString("\033[", $coloured);
    }

    private function reporter(): ConsoleReporter
    {
        return new ConsoleReporter(new Options(basePath: '/app', colors: false));
    }

    private function detection(string $testId, int $count, int $line, ?int $distinct = null): Detection
    {
        return new Detection(
            testId: $testId,
            sql: 'select * from "users" where "id" = ?',
            normalizedSql: 'select * from "users" where "id" = ?',
            origin: new Origin('/app/Http/Controllers/OrderController.php', $line, null),
            count: $count,
            distinctBindings: $distinct ?? $count,
            totalTimeMs: $count * 0.5,
            connection: 'testing',
        );
    }
}
