<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use NPlusOne\PHPUnit\NPlusOneExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs PHPUnit in a child process against tests/Fixtures, i.e. exactly the way
 * a host project would use the extension.
 */
#[CoversClass(NPlusOneExtension::class)]
final class EndToEndTest extends TestCase
{
    private static ?string $output = null;

    private static string $reportFile = '';

    public static function setUpBeforeClass(): void
    {
        self::$reportFile = sys_get_temp_dir() . '/n-plus-one-e2e-' . getmypid() . '.json';

        $command = sprintf(
            '%s %s -c %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../../vendor/phpunit/phpunit/phpunit'),
            escapeshellarg(__DIR__ . '/../Fixtures/phpunit.xml'),
        );

        putenv('NPLUSONE_REPORT_FILE=' . self::$reportFile);
        self::$output = (string) shell_exec($command);
        putenv('NPLUSONE_REPORT_FILE');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$reportFile !== '' && file_exists(self::$reportFile)) {
            unlink(self::$reportFile);
        }
    }

    public function test_the_fixture_suite_still_passes(): void
    {
        self::assertStringContainsString('OK (2 tests', $this->runOutput());
    }

    public function test_the_report_is_printed_after_the_run(): void
    {
        $output = $this->runOutput();

        self::assertStringContainsString('N+1 query report', $output);
        self::assertStringContainsString('Top 1 of 1 hotspot', $output);
        self::assertStringContainsString('N+1', $output);
        self::assertStringContainsString('select * from users where id = ?', $output);
        self::assertStringContainsString('tests/Fixtures/Feature/OrderListingTest.php', $output);
        self::assertStringContainsString('test_listing_loads_every_user_separately', $output);
    }

    public function test_the_loop_of_five_queries_is_counted(): void
    {
        self::assertMatchesRegularExpression('/#1\s+5x/', $this->runOutput());
    }

    public function test_queries_from_set_up_are_not_reported(): void
    {
        $output = $this->runOutput();

        self::assertStringNotContainsString('insert into users', $output, 'seeding happens in setUp() and must stay out of the report');
        self::assertStringNotContainsString('create table users', $output);
    }

    public function test_the_eager_loaded_test_is_not_reported(): void
    {
        self::assertStringNotContainsString('test_listing_is_eager_loaded', $this->runOutput());
    }

    public function test_the_json_report_is_written(): void
    {
        self::assertFileExists(self::$reportFile, $this->runOutput());

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $report */
        $report = json_decode((string) file_get_contents(self::$reportFile), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(2, $report['summary']['analysed_tests']);
        self::assertSame(1, $report['summary']['hotspots']);
        self::assertSame('N+1', $report['hotspots'][0]['kind']);
        self::assertSame(5, $report['hotspots'][0]['max_queries_in_one_test']);
        self::assertStringContainsString('OrderListingTest.php:', (string) $report['hotspots'][0]['origin']);
    }

    private function runOutput(): string
    {
        return (string) self::$output;
    }
}
