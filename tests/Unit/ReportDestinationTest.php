<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Report\ReportDestination;
use NPlusOne\PHPUnit\Report\Shard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportDestination::class)]
final class ReportDestinationTest extends TestCase
{
    public function test_without_a_report_setting_nothing_is_written(): void
    {
        self::assertNull((new ReportDestination(null, null, $this->worker('3')))->path());
        self::assertNull((new ReportDestination('', '', $this->worker('3')))->path());
    }

    public function test_a_directory_gets_one_file_per_process(): void
    {
        self::assertSame(
            'build/n-plus-one/n-plus-one.3.json',
            (new ReportDestination(null, 'build/n-plus-one/', $this->worker('3')))->path(),
        );

        self::assertSame(
            'build/n-plus-one/n-plus-one.' . getmypid() . '.json',
            (new ReportDestination(null, 'build/n-plus-one', $this->sequential()))->path(),
        );
    }

    public function test_a_sequential_run_writes_exactly_the_file_it_was_given(): void
    {
        self::assertSame(
            'build/n-plus-one.json',
            (new ReportDestination('build/n-plus-one.json', null, $this->sequential()))->path(),
        );
    }

    public function test_a_worker_makes_a_plain_file_name_unique(): void
    {
        self::assertSame(
            'build/n-plus-one.4.json',
            (new ReportDestination('build/n-plus-one.json', null, $this->worker('4')))->path(),
        );

        self::assertSame(
            'build/report.4',
            (new ReportDestination('build/report', null, $this->worker('4')))->path(),
        );
    }

    public function test_placeholders_are_filled_in(): void
    {
        $destination = new ReportDestination('build/run-{token}-{pid}.json', null, new Shard('9', 4242));

        self::assertSame('build/run-9-4242.json', $destination->path());
    }

    public function test_a_placeholder_is_honoured_even_in_a_sequential_run(): void
    {
        $destination = new ReportDestination('build/run-{token}.json', null, new Shard(null, 4242));

        self::assertSame('build/run-4242.json', $destination->path());
    }

    public function test_the_directory_wins_over_the_file(): void
    {
        $destination = new ReportDestination('build/n-plus-one.json', 'build/reports', $this->worker('1'));

        self::assertSame('build/reports/n-plus-one.1.json', $destination->path());
    }

    public function test_it_reads_both_settings_from_the_options(): void
    {
        $destination = ReportDestination::fromOptions(
            new Options(reportFile: 'build/a.json', reportDir: 'build/b'),
            $this->worker('2'),
        );

        self::assertSame('build/b/n-plus-one.2.json', $destination->path());
    }

    private function worker(string $token): Shard
    {
        return new Shard($token, getmypid() ?: 0);
    }

    private function sequential(): Shard
    {
        return new Shard(null, getmypid() ?: 0);
    }
}
