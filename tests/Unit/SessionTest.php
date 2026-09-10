<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Recording\QueryEvent;
use NPlusOne\PHPUnit\Recording\QueryRecorder;
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

    private function recordLoop(Session $session, int $times): void
    {
        for ($i = 1; $i <= $times; ++$i) {
            $session->recorder()->record(new QueryEvent(
                'select * from "users" where "id" = ?',
                [$i],
                0.5,
                'testing',
                new Origin('/app/app/Http/Controllers/OrderController.php', 31, 'index()'),
            ));
        }
    }
}
