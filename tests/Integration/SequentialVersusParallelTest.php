<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use NPlusOne\PHPUnit\Console\AnalyzeCommand;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The promise of the parallel support: splitting a suite across workers must
 * not change the verdict. The same two fixture classes are run once in a
 * single process and once one-per-worker, and the merged reports are compared.
 */
#[CoversNothing]
final class SequentialVersusParallelTest extends TestCase
{
    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $sequential = [];

    /** @var array<string, mixed> */
    private static array $parallel = [];

    private static int $sequentialExit = -1;

    private static int $parallelExit = -1;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/n-plus-one-equivalence-' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/sequential', 0o777, true);
        mkdir(self::$root . '/parallel', 0o777, true);

        // One process runs both classes.
        self::runSuite(self::$root . '/sequential', null, null);

        // Two workers, one class each, exactly as a parallel runner would split them.
        self::runSuite(self::$root . '/parallel', '1', '/AlphaListingTest/');
        self::runSuite(self::$root . '/parallel', '2', '/BetaListingTest/');

        [self::$sequentialExit, self::$sequential] = self::analyse(self::$root . '/sequential');
        [self::$parallelExit, self::$parallel] = self::analyse(self::$root . '/parallel');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['sequential', 'parallel'] as $directory) {
            foreach (glob(self::$root . '/' . $directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir(self::$root . '/' . $directory)) {
                rmdir(self::$root . '/' . $directory);
            }
        }

        if (self::$root !== '' && is_dir(self::$root)) {
            rmdir(self::$root);
        }

        self::$root = '';
        self::$sequential = [];
        self::$parallel = [];
    }

    public function test_the_workers_wrote_one_report_each(): void
    {
        self::assertCount(1, glob(self::$root . '/sequential/*.json') ?: []);
        self::assertCount(2, glob(self::$root . '/parallel/*.json') ?: []);
    }

    public function test_both_runs_find_the_same_hotspots_in_the_same_order(): void
    {
        self::assertSame($this->comparable(self::$sequential), $this->comparable(self::$parallel));
    }

    public function test_both_runs_agree_on_the_summary(): void
    {
        self::assertSame(self::$sequential['summary'], self::$parallel['summary']);
        self::assertSame(4, self::$sequential['summary']['analysed_tests']);
        self::assertSame(6, self::$sequential['summary']['worst_queries_in_one_test']);
        self::assertSame(4, self::$sequential['summary']['hotspots'], 'two classes, two hotspots each');
    }

    public function test_both_runs_reach_the_same_verdict(): void
    {
        self::assertSame(self::$sequentialExit, self::$parallelExit);
        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, self::$parallelExit);
        self::assertCount(4, self::$parallel['violations']);
    }

    public function test_hotspots_with_equal_counts_keep_a_stable_order(): void
    {
        $files = array_map(
            static fn (array $hotspot): string => strtok(basename((string) $hotspot['origin']), ':'),
            self::$parallel['hotspots'],
        );

        // The two classes produce the same counts, so the order can only come
        // from the call site — not from whichever worker was a millisecond
        // slower, which would reshuffle the report between runs.
        self::assertSame([
            'AlphaListingTest.php',
            'BetaListingTest.php',
            'AlphaListingTest.php',
            'BetaListingTest.php',
        ], $files);
    }

    /**
     * Timings legitimately differ between runs, and the file names are the one
     * thing that is meant to differ.
     *
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function comparable(array $report): array
    {
        unset($report['files']);

        $report['hotspots'] = array_map(static function (array $hotspot): array {
            unset($hotspot['total_time_ms']);

            return $hotspot;
        }, $report['hotspots']);

        return $report;
    }

    private static function runSuite(string $reportDirectory, ?string $token, ?string $filter): void
    {
        $command = sprintf(
            '%sNPLUSONE_REPORT_DIR=%s %s %s -c %s %s > /dev/null 2>&1',
            $token === null ? '' : 'TEST_TOKEN=' . escapeshellarg($token) . ' ',
            escapeshellarg($reportDirectory),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::packageRoot() . '/vendor/phpunit/phpunit/phpunit'),
            escapeshellarg(self::packageRoot() . '/tests/Fixtures/phpunit-split.xml'),
            $filter === null ? '' : '--filter ' . escapeshellarg($filter),
        );

        shell_exec($command);
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private static function analyse(string $directory): array
    {
        $command = sprintf(
            '%s %s %s --format=json --fail-on-any 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::packageRoot() . '/bin/n-plus-one'),
            escapeshellarg($directory),
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(implode(PHP_EOL, $output), true, flags: JSON_THROW_ON_ERROR);

        return [$exit, $decoded];
    }

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
