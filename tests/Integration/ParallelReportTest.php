<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use NPlusOne\PHPUnit\Console\AnalyzeCommand;
use NPlusOne\PHPUnit\Report\ReportDestination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The whole parallel story, end to end: two workers run the fixture suite the
 * way `php artisan test --parallel` runs them (one process each, TEST_TOKEN
 * exported), then the real bin/n-plus-one merges their reports and judges them.
 */
#[CoversNothing]
final class ParallelReportTest extends TestCase
{
    private static string $directory = '';

    /** @var array<string, string> */
    private static array $workerOutput = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/n-plus-one-parallel-' . bin2hex(random_bytes(6));

        foreach (['1', '2'] as $token) {
            $command = sprintf(
                'TEST_TOKEN=%s NPLUSONE_REPORT_DIR=%s NPLUSONE_CONSOLE=0 %s %s -c %s 2>&1',
                escapeshellarg($token),
                escapeshellarg(self::$directory),
                escapeshellarg(PHP_BINARY),
                escapeshellarg(self::root() . '/vendor/phpunit/phpunit/phpunit'),
                escapeshellarg(self::root() . '/tests/Fixtures/phpunit.xml'),
            );

            self::$workerOutput[$token] = (string) shell_exec($command);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir(self::$directory)) {
            rmdir(self::$directory);
        }

        self::$directory = '';
        self::$workerOutput = [];
    }

    public function test_each_worker_writes_its_own_report(): void
    {
        foreach (['1', '2'] as $token) {
            self::assertStringContainsString('OK (2 tests', self::$workerOutput[$token], 'the fixture suite must pass');
            self::assertFileExists(self::$directory . '/' . ReportDestination::FILE_PREFIX . $token . '.json');
        }

        self::assertCount(2, glob(self::$directory . '/*.json') ?: []);
    }

    public function test_workers_can_be_told_to_keep_the_console_quiet(): void
    {
        self::assertStringNotContainsString('N+1 query report', self::$workerOutput['1']);
    }

    public function test_the_cli_merges_the_workers_and_keeps_the_per_test_count(): void
    {
        [$exit, $output] = $this->analyze([self::$directory, '--no-color']);

        self::assertSame(AnalyzeCommand::EXIT_OK, $exit, $output);
        self::assertStringContainsString('Analysed 2 report files', $output);
        self::assertStringContainsString('5x', $output, 'the worst single test is still 5 queries');
        self::assertStringContainsString('10 queries overall', $output, 'both workers are counted');
        self::assertStringContainsString('No budget set', $output);
    }

    public function test_the_cli_fails_the_build_when_the_budget_is_exceeded(): void
    {
        [$exit, $output] = $this->analyze([self::$directory, '--max-queries=4', '--no-color']);

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $exit);
        self::assertStringContainsString('[max-queries] 5x in one test', $output);
        self::assertStringContainsString('OrderListingTest.php:38', $output);
    }

    public function test_the_cli_passes_the_build_inside_the_budget(): void
    {
        [$exit, $output] = $this->analyze([self::$directory, '--max-queries=5', '--no-color']);

        self::assertSame(AnalyzeCommand::EXIT_OK, $exit);
        self::assertStringContainsString('Within budget.', $output);
    }

    public function test_the_cli_speaks_json_for_other_tools(): void
    {
        [$exit, $output] = $this->analyze([self::$directory, '--format=json', '--fail-on-any']);

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $exit);

        /** @var array{status: string, summary: array<string, int>, hotspots: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('violations', $decoded['status']);
        self::assertSame(4, $decoded['summary']['analysed_tests'], 'two workers of two tests each');
        self::assertSame(5, $decoded['summary']['worst_queries_in_one_test']);
        self::assertSame(10, $decoded['hotspots'][0]['total_queries']);
        self::assertStringContainsString('OrderListingTest.php:38', (string) $decoded['hotspots'][0]['origin']);
    }

    public function test_the_cli_reports_nothing_to_do_without_reports(): void
    {
        [$exit, $output] = $this->analyze([self::$directory . '/missing', '--allow-empty']);

        self::assertSame(AnalyzeCommand::EXIT_OK, $exit);
        self::assertStringContainsString('No N+1 reports to analyse', $output);

        [$exit] = $this->analyze([self::$directory . '/missing']);

        self::assertSame(AnalyzeCommand::EXIT_USAGE, $exit);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function analyze(array $arguments): array
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::root() . '/bin/n-plus-one'),
            implode(' ', array_map('escapeshellarg', $arguments)),
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return [$exit, implode(PHP_EOL, $output)];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
