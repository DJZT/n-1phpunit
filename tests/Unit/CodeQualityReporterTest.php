<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Report\CodeQualityReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeQualityReporter::class)]
final class CodeQualityReporterTest extends TestCase
{
    public function test_every_issue_carries_the_fields_gitlab_requires(): void
    {
        $issues = $this->report([$this->hotspot(count: 87)]);

        self::assertCount(1, $issues);

        $issue = $issues[0];

        foreach (['description', 'fingerprint', 'severity', 'location'] as $required) {
            self::assertArrayHasKey($required, $issue);
            self::assertNotEmpty($issue[$required]);
        }

        self::assertSame('issue', $issue['type']);
        self::assertSame(CodeQualityReporter::CHECK_N_PLUS_ONE, $issue['check_name']);
        self::assertSame(['Performance'], $issue['categories']);
        self::assertSame(['begin' => 31, 'end' => 31], $issue['location']['lines']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $issue['fingerprint']);
    }

    public function test_the_location_is_a_path_without_the_line_number(): void
    {
        $issues = $this->report([$this->hotspot(count: 87)]);

        self::assertSame('app/Http/Controllers/OrderController.php', $issues[0]['location']['path']);
    }

    public function test_the_report_is_a_json_array_at_the_top_level(): void
    {
        $json = (new CodeQualityReporter(new Options(basePath: '/app')))->render([$this->hotspot(count: 4)]);

        self::assertStringStartsWith('[', trim($json));

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsList($decoded);
    }

    public function test_the_description_says_what_was_found_and_where_it_hurts_most(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_index', 87));
        $aggregator->add($this->detection('Tests\Feature\OrderTest::test_show', 9));

        $issues = $this->report($aggregator->top(10));

        self::assertStringContainsString('N+1: 87 queries of one shape in a single test', $issues[0]['description']);
        self::assertStringContainsString('Tests\Feature\OrderTest::test_index', $issues[0]['description']);
        self::assertStringContainsString('96 across 2 tests', $issues[0]['description']);
        self::assertStringContainsString('select * from "users" where "id" = ?', $issues[0]['description']);
    }

    public function test_the_framework_call_is_named_in_the_description(): void
    {
        $issues = $this->report([$this->hotspot(count: 87, via: 'HasMany->getResults()')]);

        self::assertStringContainsString('issued by HasMany->getResults()', $issues[0]['description']);
    }

    public function test_a_long_statement_is_truncated(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 't1',
            sql: 'select ' . str_repeat('"column_name", ', 40) . ' from "users"',
            normalizedSql: 'x',
            origin: new Origin('/app/app/A.php', 1, null),
            count: 4,
            distinctBindings: 4,
            totalTimeMs: 1.0,
        ));

        $issues = $this->report($aggregator->top(1));

        self::assertStringContainsString('…', $issues[0]['description']);
        self::assertLessThan(400, mb_strlen($issues[0]['description']));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function severities(): iterable
    {
        yield 'an N+1 that costs a hundred queries blocks' => [Detection::KIND_N_PLUS_ONE, 100, 'blocker'];
        yield 'fifty is critical' => [Detection::KIND_N_PLUS_ONE, 50, 'critical'];
        yield 'twenty is major' => [Detection::KIND_N_PLUS_ONE, 20, 'major'];
        yield 'a handful is minor' => [Detection::KIND_N_PLUS_ONE, 4, 'minor'];
        yield 'a duplicate sits one notch lower' => [Detection::KIND_DUPLICATE, 100, 'critical'];
        yield 'a small duplicate is only information' => [Detection::KIND_DUPLICATE, 4, 'info'];
    }

    #[DataProvider('severities')]
    public function test_the_worst_single_test_sets_the_severity(string $kind, int $count, string $expected): void
    {
        $issues = $this->report([$this->hotspot(count: $count, kind: $kind)]);

        self::assertSame($expected, $issues[0]['severity']);
    }

    public function test_the_fingerprint_survives_the_hotspot_getting_worse_or_moving(): void
    {
        $before = $this->report([$this->hotspot(count: 40)])[0]['fingerprint'];
        $worse = $this->report([$this->hotspot(count: 41)])[0]['fingerprint'];
        $moved = $this->report([$this->hotspot(count: 40, line: 512)])[0]['fingerprint'];

        self::assertSame($before, $worse, 'GitLab would otherwise call a known problem a new one');
        self::assertSame($before, $moved, 'code moves, the problem does not');
    }

    public function test_a_different_problem_gets_a_different_fingerprint(): void
    {
        $fingerprints = [
            $this->report([$this->hotspot(count: 40)])[0]['fingerprint'],
            $this->report([$this->hotspot(count: 40, sql: 'select * from "orders" where "id" = ?')])[0]['fingerprint'],
            $this->report([$this->hotspot(count: 40, file: '/app/app/Other.php')])[0]['fingerprint'],
            $this->report([$this->hotspot(count: 40, kind: Detection::KIND_DUPLICATE)])[0]['fingerprint'],
        ];

        self::assertSame($fingerprints, array_unique($fingerprints));
    }

    public function test_a_hotspot_without_a_file_is_left_out(): void
    {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 't1',
            sql: 'select 1',
            normalizedSql: 'select ?',
            origin: Origin::unknown(),
            count: 5,
            distinctBindings: 5,
            totalTimeMs: 1.0,
        ));

        self::assertSame([], $this->report($aggregator->top(10)));
    }

    public function test_an_empty_run_is_an_empty_array_rather_than_no_file(): void
    {
        self::assertSame("[]\n", (new CodeQualityReporter(new Options()))->render([]));
    }

    public function test_it_writes_the_report_creating_the_directory(): void
    {
        $path = sys_get_temp_dir() . '/n-plus-one-cq-' . bin2hex(random_bytes(6)) . '/gl-code-quality-report.json';

        (new CodeQualityReporter(new Options(basePath: '/app')))->write($path, [$this->hotspot(count: 87)]);

        self::assertFileExists($path);
        self::assertIsArray(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));

        unlink($path);
        rmdir(dirname($path));
    }

    /**
     * @param list<Hotspot> $hotspots
     *
     * @return list<array<string, mixed>>
     */
    private function report(array $hotspots): array
    {
        return (new CodeQualityReporter(new Options(basePath: '/app')))->toArray($hotspots);
    }

    private function hotspot(
        int $count,
        string $kind = Detection::KIND_N_PLUS_ONE,
        int $line = 31,
        ?string $via = null,
        ?string $sql = null,
        string $file = '/app/app/Http/Controllers/OrderController.php',
    ): Hotspot {
        $aggregator = new Aggregator();
        $aggregator->add(new Detection(
            testId: 'Tests\Feature\OrderTest::test_index',
            sql: $sql ?? 'select * from "users" where "id" = ?',
            normalizedSql: $sql ?? 'select * from "users" where "id" = ?',
            origin: new Origin($file, $line, 'OrderController->index()', $via),
            count: $count,
            distinctBindings: $kind === Detection::KIND_N_PLUS_ONE ? $count : 1,
            totalTimeMs: 1.0,
        ));

        return $aggregator->top(1)[0];
    }

    private function detection(string $testId, int $count): Detection
    {
        return new Detection(
            testId: $testId,
            sql: 'select * from "users" where "id" = ?',
            normalizedSql: 'select * from "users" where "id" = ?',
            origin: new Origin('/app/app/Http/Controllers/OrderController.php', 31, 'OrderController->index()'),
            count: $count,
            distinctBindings: $count,
            totalTimeMs: 1.0,
        );
    }
}
