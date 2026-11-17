<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Recording\QueryEvent;
use NPlusOne\PHPUnit\Recording\QueryRecorder;
use NPlusOne\PHPUnit\Report\ReportDestination;
use NPlusOne\PHPUnit\Report\Shard;
use NPlusOne\PHPUnit\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Session::class)]
#[CoversClass(QueryRecorder::class)]
final class SessionTest extends TestCase
{
    private string $output = '';

    public function test_it_reports_the_hotspots_of_the_whole_run(): void
    {
        $session = $this->session();

        $session->startTest('Tests\Feature\OrderTest::test_index');
        $this->recordLoop($session, times: 6);
        $session->endTest();

        $session->startTest('Tests\Feature\OrderTest::test_show');
        $this->recordLoop($session, times: 3);
        $session->endTest();

        $session->finish();

        self::assertStringContainsString('Top 1 of 1 hotspot', $this->output);
        self::assertStringContainsString('6x', $this->output);
        self::assertStringContainsString('9 queries recorded in 2 tests; 2 tests affected.', $this->output);
    }

    /**
     * The threshold is a per-test rule. The same statement issued once by each
     * of many tests is normal (that is just the suite exercising the same
     * endpoint), so it must never add up into a finding.
     */
    public function test_one_query_per_test_is_never_a_finding_however_many_tests_run_it(): void
    {
        $session = $this->session();

        for ($test = 1; $test <= 50; ++$test) {
            $session->startTest('Tests\\Feature\\OrderTest::test_case_' . $test);
            $this->recordLoop($session, times: 1);
            $session->endTest();
        }

        $session->finish();

        self::assertStringContainsString('No repeated query patterns found.', $this->output);
        self::assertStringContainsString('50 queries recorded in 50 tests; 0 tests affected.', $this->output);
    }

    public function test_only_the_tests_that_loop_are_counted_into_a_hotspot(): void
    {
        $session = $this->session();

        // One test loops, the other two merely touch the same query once.
        $session->startTest('Tests\\Feature\\OrderTest::test_index');
        $this->recordLoop($session, times: 8);
        $session->endTest();

        foreach (['test_show', 'test_edit'] as $name) {
            $session->startTest('Tests\\Feature\\OrderTest::' . $name);
            $this->recordLoop($session, times: 1);
            $session->endTest();
        }

        $session->finish();

        self::assertStringContainsString('Top 1 of 1 hotspot', $this->output);
        self::assertStringContainsString('8x', $this->output);
        self::assertStringContainsString('Tests\\Feature\\OrderTest::test_index (8x)', $this->output);
        self::assertStringNotContainsString('more test', $this->output, 'the two single-query tests are not part of the hotspot');
        self::assertStringContainsString('10 queries recorded in 3 tests; 1 test affected.', $this->output);
    }

    public function test_tests_outside_the_filter_are_not_recorded(): void
    {
        $session = $this->session();

        $session->startTest('Tests\Unit\OrderTest::test_total');
        self::assertFalse($session->recorder()->isRecording());

        $this->recordLoop($session, times: 6);
        $session->endTest();
        $session->finish();

        self::assertSame('', $this->output, 'nothing was analysed, so nothing is printed');
    }

    public function test_finish_closes_a_test_that_never_reported_back(): void
    {
        $session = $this->session();

        $session->startTest('Tests\Feature\OrderTest::test_index');
        $this->recordLoop($session, times: 4);

        $session->finish();

        self::assertStringContainsString('4x', $this->output);
    }

    public function test_a_running_test_can_inspect_its_own_detections(): void
    {
        $session = $this->session();

        $session->startTest('Tests\Feature\OrderTest::test_index');
        self::assertSame([], $session->currentDetections());

        $this->recordLoop($session, times: 3);

        $detections = $session->currentDetections();
        self::assertCount(1, $detections);
        self::assertSame(3, $detections[0]->count);

        $session->endTest();
        self::assertSame([], $session->currentDetections());
    }

    public function test_it_writes_a_json_report_when_asked(): void
    {
        $path = sys_get_temp_dir() . '/n-plus-one-' . uniqid('', true) . '/report.json';

        $session = $this->session(new Options(filter: '', reportFile: $path, basePath: '/app', colors: false));
        $session->startTest('Tests\Feature\OrderTest::test_index');
        $this->recordLoop($session, times: 5);
        $session->finish();

        self::assertFileExists($path);

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $report */
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(5, $report['summary']['recorded_queries']);
        self::assertSame(1, $report['summary']['hotspots']);
        self::assertSame('N+1', $report['hotspots'][0]['kind']);
        self::assertSame(5, $report['hotspots'][0]['max_queries_in_one_test']);
        self::assertSame('app/Http/Controllers/OrderController.php:31', $report['hotspots'][0]['origin']);

        unlink($path);
        rmdir(dirname($path));
    }

    /**
     * `limit` is about what a human reads. The file is merged with the other
     * workers' files afterwards, so trimming it there would lose hotspots.
     */
    public function test_the_file_report_keeps_every_hotspot_while_the_console_shows_only_the_top(): void
    {
        $path = sys_get_temp_dir() . '/n-plus-one-' . uniqid('', true) . '/report.json';

        $session = new Session(
            new Options(limit: 3, filter: '', reportFile: $path, basePath: '/app', colors: false),
            writer: function (string $output): void {
                $this->output .= $output;
            },
        );

        $session->startTest('Tests\\Feature\\OrderTest::test_index');

        foreach (range(1, 5) as $place) {
            $this->recordLoop($session, times: 3 + $place, place: 'Place' . $place . '.php');
        }

        $session->finish();

        self::assertStringContainsString('Top 3 of 5 hotspots', $this->output);
        self::assertSame(3, substr_count($this->output, 'app/Place'), 'the console is trimmed to the limit');

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $report
         */
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(5, $report['summary']['hotspots']);
        self::assertCount(5, $report['hotspots'], 'the file keeps every hotspot for the merge');
        self::assertSame(8, $report['hotspots'][0]['max_queries_in_one_test'], 'still ranked worst first');

        unlink($path);
        rmdir(dirname($path));
    }

    public function test_each_process_writes_its_own_report_under_a_parallel_runner(): void
    {
        $directory = sys_get_temp_dir() . '/n-plus-one-' . uniqid('', true);

        $session = new Session(
            new Options(filter: '', reportDir: $directory, console: false, basePath: '/app', colors: false),
            writer: function (string $output): void {
                $this->output .= $output;
            },
            destination: new ReportDestination(null, $directory, new Shard('7', 1234)),
        );

        $session->startTest('Tests\\Feature\\OrderTest::test_index');
        $this->recordLoop($session, times: 4);
        $session->finish();

        $path = $directory . '/n-plus-one.7.json';

        self::assertSame('', $this->output, 'console output is off, so workers do not interleave');
        self::assertFileExists($path);

        /** @var array{hotspots: list<array<string, mixed>>} $report */
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(4, $report['hotspots'][0]['max_queries_in_one_test']);

        unlink($path);
        rmdir($directory);
    }

    public function test_it_can_write_a_browsable_page_next_to_the_json(): void
    {
        $directory = sys_get_temp_dir() . '/n-plus-one-' . uniqid('', true);

        $session = new Session(
            new Options(
                filter: '',
                reportFile: $directory . '/report.json',
                reportHtml: $directory . '/report.html',
                console: false,
                basePath: '/app',
                colors: false,
            ),
            writer: function (string $output): void {
                $this->output .= $output;
            },
        );

        $session->startTest('Tests\\Feature\\OrderTest::test_index');
        $this->recordLoop($session, times: 4);
        $session->finish();

        self::assertFileExists($directory . '/report.json');
        self::assertFileExists($directory . '/report.html');
        self::assertStringContainsString(
            'app/Http/Controllers/OrderController.php:31',
            (string) file_get_contents($directory . '/report.html'),
        );

        unlink($directory . '/report.json');
        unlink($directory . '/report.html');
        rmdir($directory);
    }

    public function test_a_failing_json_report_does_not_break_the_run(): void
    {
        $session = $this->session(new Options(filter: '', reportFile: '/proc/definitely/not/writable/report.json', colors: false));
        $session->startTest('Tests\Feature\OrderTest::test_index');
        $this->recordLoop($session, times: 3);
        $session->finish();

        self::assertStringContainsString('N+1 report could not be written', $this->output);
    }

    private function session(?Options $options = null): Session
    {
        $this->output = '';

        return new Session(
            $options ?? new Options(basePath: '/app', colors: false),
            writer: function (string $output): void {
                $this->output .= $output;
            },
        );
    }

    private function recordLoop(Session $session, int $times, string $place = 'Http/Controllers/OrderController.php'): void
    {
        for ($i = 1; $i <= $times; ++$i) {
            $session->recorder()->record(new QueryEvent(
                'select * from "users" where "id" = ?',
                [$i],
                0.5,
                'testing',
                new Origin('/app/app/' . $place, 31, 'index()'),
            ));
        }
    }
}
