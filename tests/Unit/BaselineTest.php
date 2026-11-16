<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Console\Baseline;
use NPlusOne\PHPUnit\Console\LoadFailure;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Tests\Support\TemporaryReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baseline::class)]
final class BaselineTest extends TestCase
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

    public function test_an_empty_baseline_accepts_nothing(): void
    {
        $baseline = Baseline::empty();

        self::assertSame(0, $baseline->count());
        self::assertFalse($baseline->allows($this->hotspotWith(count: 3)));
        self::assertFalse($baseline->knows($this->hotspotWith(count: 3)));
        self::assertNull($baseline->limitFor($this->hotspotWith(count: 3)));
    }

    public function test_a_recorded_hotspot_is_accepted_while_it_does_not_get_worse(): void
    {
        $path = $this->path('baseline.json');
        file_put_contents($path, Baseline::render([$this->hotspotWith(count: 20)]));

        $baseline = Baseline::load($path);

        self::assertSame(1, $baseline->count());
        self::assertTrue($baseline->allows($this->hotspotWith(count: 20)));
        self::assertTrue($baseline->allows($this->hotspotWith(count: 12)), 'an improvement is fine');
        self::assertFalse($baseline->allows($this->hotspotWith(count: 21)), 'one more query is a regression');
        self::assertTrue($baseline->knows($this->hotspotWith(count: 21)));
        self::assertSame(20, $baseline->limitFor($this->hotspotWith(count: 21)));
    }

    public function test_the_same_query_from_another_place_is_a_different_entry(): void
    {
        $path = $this->path('baseline.json');
        file_put_contents($path, Baseline::render([$this->hotspotWith(count: 20, line: 31)]));

        $baseline = Baseline::load($path);

        self::assertTrue($baseline->allows($this->hotspotWith(count: 20, line: 31)));
        self::assertFalse($baseline->allows($this->hotspotWith(count: 20, line: 99)));
    }

    public function test_entries_are_written_sorted_and_readable(): void
    {
        $json = Baseline::render([
            $this->hotspotWith(count: 5, line: 90),
            $this->hotspotWith(count: 7, line: 10),
        ]);

        /** @var array{entries: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(2, $decoded['entries']);
        self::assertSame('app/Http/Controllers/OrderController.php:10', $decoded['entries'][0]['origin']);
        self::assertSame(7, $decoded['entries'][0]['max_queries_in_one_test']);
        self::assertSame(Detection::KIND_N_PLUS_ONE, $decoded['entries'][0]['kind']);
    }

    public function test_a_missing_baseline_is_an_error(): void
    {
        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('could not be read');

        Baseline::load($this->path('nope.json'));
    }

    public function test_a_baseline_without_entries_is_an_error(): void
    {
        $path = $this->path('baseline.json');
        file_put_contents($path, '{"something": "else"}');

        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('no "entries" list');

        Baseline::load($path);
    }

    public function test_a_malformed_entry_is_an_error(): void
    {
        $path = $this->path('baseline.json');
        file_put_contents($path, '{"entries": [{"origin": "app/A.php:1"}]}');

        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('malformed entry');

        Baseline::load($path);
    }

    private function hotspotWith(int $count, int $line = 31): Hotspot
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
