<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Report\Shard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Shard::class)]
final class ShardTest extends TestCase
{
    private const VARS = [
        'UNIQUE_TEST_TOKEN',
        'TEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING_TOKEN',
        'PARATEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING',
        'PARATEST',
    ];

    protected function setUp(): void
    {
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
    }

    public function test_a_plain_run_is_not_parallel(): void
    {
        $shard = Shard::current();

        self::assertFalse($shard->isParallel());
        self::assertSame((string) getmypid(), $shard->id());
    }

    public function test_the_runner_token_names_the_shard(): void
    {
        putenv('TEST_TOKEN=7');

        $shard = Shard::current();

        self::assertTrue($shard->isParallel());
        self::assertSame('7', $shard->id());
        self::assertSame(getmypid(), $shard->pid());
    }

    public function test_the_unique_token_wins_over_the_plain_one(): void
    {
        putenv('TEST_TOKEN=2');
        putenv('UNIQUE_TEST_TOKEN=abc123');

        self::assertSame('abc123', Shard::current()->id());
    }

    public function test_a_parallel_marker_without_a_token_falls_back_to_the_pid(): void
    {
        putenv('LARAVEL_PARALLEL_TESTING=1');

        $shard = Shard::current();

        self::assertTrue($shard->isParallel());
        self::assertSame((string) getmypid(), $shard->id());
    }

    public function test_a_switched_off_marker_is_not_a_parallel_run(): void
    {
        putenv('PARATEST=0');

        self::assertFalse(Shard::current()->isParallel());
    }

    public function test_a_token_is_reduced_to_characters_that_are_safe_in_a_file_name(): void
    {
        putenv('TEST_TOKEN=../../etc/passwd');

        self::assertSame('etcpasswd', Shard::current()->id());
    }

    public function test_an_empty_token_is_ignored(): void
    {
        putenv('TEST_TOKEN=');

        self::assertFalse(Shard::current()->isParallel());
    }

    private function clearEnvironment(): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
        }
    }
}
