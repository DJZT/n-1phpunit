<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Console\LoadFailure;
use NPlusOne\PHPUnit\Console\ReportLoader;
use NPlusOne\PHPUnit\Tests\Support\TemporaryReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportLoader::class)]
final class ReportLoaderTest extends TestCase
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

    public function test_it_merges_the_reports_of_every_worker(): void
    {
        $this->writeReport('n-plus-one.1.json', [
            'summary' => ['analysed_tests' => 10, 'recorded_queries' => 300, 'affected_tests' => 2, 'hotspots' => 1],
            'hotspots' => [$this->hotspot(tests: ['Tests\Feature\OrderTest::test_index' => 40])],
        ]);
        $this->writeReport('n-plus-one.2.json', [
            'summary' => ['analysed_tests' => 8, 'recorded_queries' => 120, 'affected_tests' => 1, 'hotspots' => 1],
            'hotspots' => [$this->hotspot(tests: ['Tests\Feature\OrderTest::test_show' => 7])],
        ]);

        $reports = (new ReportLoader())->load([$this->directory]);

        self::assertCount(2, $reports->files);
        self::assertSame(18, $reports->analysedTests);
        self::assertSame(420, $reports->recordedQueries);
        self::assertSame(3, $reports->affectedTests);
        self::assertSame(3, $reports->threshold);

        $aggregator = new Aggregator();
        $aggregator->addAll($reports->detections);

        self::assertSame(1, $aggregator->count(), 'the same call site from two workers is one hotspot');

        $hotspot = $aggregator->top(1)[0];

        self::assertSame(40, $hotspot->maxCount(), 'the per-test rule survives the merge');
        self::assertSame(47, $hotspot->totalCount());
        self::assertSame(2, $hotspot->testCount());
        self::assertSame(Detection::KIND_N_PLUS_ONE, $hotspot->kind());
    }

    public function test_the_time_of_a_hotspot_survives_the_round_trip(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [$this->hotspot(
                tests: ['a' => 6, 'b' => 4],
                overrides: ['total_queries' => 10, 'total_time_ms' => 25.0],
            )],
        ]);

        $aggregator = new Aggregator();
        $aggregator->addAll((new ReportLoader())->load([$this->directory])->detections);

        self::assertEqualsWithDelta(25.0, $aggregator->top(1)[0]->totalTimeMs(), 0.0001);
    }

    public function test_a_duplicate_stays_a_duplicate(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [$this->hotspot(tests: ['a' => 5], overrides: ['kind' => Detection::KIND_DUPLICATE])],
        ]);

        $aggregator = new Aggregator();
        $aggregator->addAll((new ReportLoader())->load([$this->directory])->detections);

        self::assertSame(Detection::KIND_DUPLICATE, $aggregator->top(1)[0]->kind());
    }

    public function test_it_reads_a_single_file_a_glob_and_a_directory(): void
    {
        $this->writeReport('n-plus-one.1.json', ['hotspots' => []]);
        $this->writeReport('n-plus-one.2.json', ['hotspots' => []]);

        self::assertCount(1, (new ReportLoader())->load([$this->path('n-plus-one.1.json')])->files);
        self::assertCount(2, (new ReportLoader())->load([$this->path('n-plus-one.*.json')])->files);
        self::assertCount(2, (new ReportLoader())->load([$this->directory])->files);
    }

    public function test_the_same_file_named_twice_is_read_once(): void
    {
        $this->writeReport('one.json', ['hotspots' => [$this->hotspot(tests: ['a' => 4])]]);

        $reports = (new ReportLoader())->load([$this->directory, $this->path('one.json')]);

        self::assertCount(1, $reports->files);
        self::assertCount(1, $reports->detections);
    }

    public function test_the_origin_is_parsed_back_into_a_file_and_a_line(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [$this->hotspot(tests: ['a' => 4], overrides: ['origin' => 'app/Http/Kernel.php:120'])],
        ]);

        $origin = (new ReportLoader())->load([$this->directory])->detections[0]->origin;

        self::assertSame('app/Http/Kernel.php', $origin->file);
        self::assertSame(120, $origin->line);
    }

    public function test_an_unknown_origin_stays_unknown(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [$this->hotspot(tests: ['a' => 4], overrides: ['origin' => 'unknown location'])],
        ]);

        self::assertTrue((new ReportLoader())->load([$this->directory])->detections[0]->origin->isUnknown());
    }

    public function test_a_hotspot_without_a_tests_map_still_counts(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [$this->hotspot(tests: [], overrides: ['max_queries_in_one_test' => 9])],
        ]);

        $detections = (new ReportLoader())->load([$this->directory])->detections;

        self::assertCount(1, $detections);
        self::assertSame(9, $detections[0]->count);
    }

    public function test_a_missing_path_is_an_error(): void
    {
        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('No report found at');

        (new ReportLoader())->load([$this->path('nope.json')]);
    }

    public function test_broken_json_is_an_error_rather_than_a_silent_pass(): void
    {
        file_put_contents($this->path('broken.json'), '{"hotspots": [');

        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('is not valid JSON');

        (new ReportLoader())->load([$this->directory]);
    }

    public function test_a_malformed_hotspot_section_is_an_error(): void
    {
        file_put_contents($this->path('bad.json'), '{"hotspots": "nope"}');

        $this->expectException(LoadFailure::class);
        $this->expectExceptionMessage('malformed "hotspots" section');

        (new ReportLoader())->load([$this->directory]);
    }

    public function test_an_empty_directory_yields_no_files(): void
    {
        self::assertTrue((new ReportLoader())->load([$this->directory])->isEmpty());
    }
}
