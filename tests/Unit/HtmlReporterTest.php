<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Report\HtmlReporter;
use NPlusOne\PHPUnit\Report\Statistics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlReporter::class)]
final class HtmlReporterTest extends TestCase
{
    public function test_it_renders_one_self_contained_page(): void
    {
        $html = $this->render([$this->hotspot('Tests\Feature\OrderTest::test_index', 87)]);

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('</html>', $html);
        self::assertStringContainsString('<style>', $html);
        self::assertStringContainsString('<script>', $html);

        // A CI artefact is opened from file://, so nothing may be fetched.
        self::assertDoesNotMatchRegularExpression('/\b(src|href)\s*=/i', $html);
    }

    public function test_the_summary_is_on_the_page(): void
    {
        $html = $this->render(
            [$this->hotspot('Tests\Feature\OrderTest::test_index', 87)],
            new Statistics(analysedTests: 318, recordedQueries: 4302, affectedTests: 27, hotspots: 23),
        );

        self::assertStringContainsString('318', $html);
        self::assertStringContainsString('4,302', $html);
        self::assertStringContainsString('27', $html);
        self::assertStringContainsString('tests affected', $html);
        self::assertStringContainsString('threshold 3+ repeats in one test', $html);
    }

    public function test_a_hotspot_carries_everything_needed_to_act_on_it(): void
    {
        $html = $this->render([$this->hotspot('Tests\Feature\OrderTest::test_index', 87, via: 'HasMany->getResults()')]);

        self::assertStringContainsString('app/Http/Controllers/OrderController.php:31', $html);
        self::assertStringContainsString('OrderController-&gt;index()', $html);
        self::assertStringContainsString('via HasMany-&gt;getResults()', $html);
        self::assertStringContainsString('select * from &quot;users&quot; where &quot;id&quot; = ?', $html);
        self::assertStringContainsString('Tests\Feature\OrderTest::test_index', $html);
        self::assertStringContainsString('kind--nplusone', $html);
    }

    public function test_rows_carry_the_data_the_page_filters_and_sorts_on(): void
    {
        $html = $this->render([$this->hotspot('Tests\Feature\OrderTest::test_index', 87)]);

        self::assertStringContainsString('data-kind="N+1"', $html);
        self::assertStringContainsString('data-worst="87"', $html);
        self::assertStringContainsString('data-total="87"', $html);
        self::assertStringContainsString('data-tests="1"', $html);
        self::assertStringContainsString('data-text="app/http/controllers/ordercontroller.php:31', $html);
    }

    public function test_content_from_the_application_cannot_break_out_of_the_page(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 'Tests\Feature\X::test_<script>alert(1)</script>',
            sql: 'select * from "t" where "x" < 1 and "y" > 2 -- </script><img onerror=alert(1)>',
            normalizedSql: 'select * from "t" where "x" < ? and "y" > ?',
            origin: new Origin('/app/app/"><script>alert(1)</script>.php', 7, '<b>fn</b>()'),
            count: 4,
            distinctBindings: 4,
            totalTimeMs: 1.0,
        ));

        $html = $this->render($aggregator->top(10));

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img onerror', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&quot;x&quot; &lt; 1', $html);
    }

    public function test_a_hotspot_hit_by_hundreds_of_tests_lists_only_the_worst_of_them(): void
    {
        $aggregator = new Aggregator();

        for ($test = 1; $test <= 60; ++$test) {
            $aggregator->add(new Detection(
                testId: 'Tests\Feature\OrderTest::test_case_' . $test,
                sql: 'select * from "users" where "id" = ?',
                normalizedSql: 'select * from "users" where "id" = ?',
                origin: new Origin('/app/app/Http/Controllers/OrderController.php', 31, null),
                count: 3,
                distinctBindings: 3,
                totalTimeMs: 0.5,
            ));
        }

        $html = $this->render($aggregator->top(10));

        self::assertSame(20, substr_count($html, '<li><code>'), 'only the first twenty tests are listed');
        self::assertStringContainsString('and 40 more tests', $html);
    }

    public function test_it_says_so_when_there_is_nothing_to_show(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('No repeated query patterns found.', $html);
        self::assertStringNotContainsString('<article', $html);
    }

    public function test_it_writes_the_page_creating_the_directory(): void
    {
        $path = sys_get_temp_dir() . '/n-plus-one-html-' . bin2hex(random_bytes(6)) . '/report.html';

        (new HtmlReporter(new Options(basePath: '/app')))->write(
            $path,
            [$this->hotspot('Tests\Feature\OrderTest::test_index', 87)],
            new Statistics(1, 87, 1, 1),
        );

        self::assertFileExists($path);
        self::assertStringContainsString('N+1 query report', (string) file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function render(array $hotspots, ?Statistics $statistics = null): string
    {
        return (new HtmlReporter(new Options(basePath: '/app')))->render(
            $hotspots,
            $statistics ?? new Statistics(10, 300, 2, count($hotspots)),
        );
    }

    private function hotspot(string $testId, int $count, ?string $via = null): Hotspot
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: $testId,
            sql: 'select * from "users" where "id" = ? limit 1',
            normalizedSql: 'select * from "users" where "id" = ? limit ?',
            origin: new Origin('/app/app/Http/Controllers/OrderController.php', 31, 'OrderController->index()', $via),
            count: $count,
            distinctBindings: $count,
            totalTimeMs: 41.87,
            connection: 'mysql',
        ));

        return $aggregator->top(1)[0];
    }
}
