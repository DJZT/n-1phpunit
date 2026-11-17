<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures\Split;

use NPlusOne\PHPUnit\Tests\Fixtures\MiniLaravelApplication;
use PHPUnit\Framework\TestCase;

/**
 * One of two interchangeable suites: run together in one process, or one per
 * worker, the merged report has to come out the same.
 */
final class AlphaListingTest extends TestCase
{
    private MiniLaravelApplication $app;

    protected function setUp(): void
    {
        $this->app = new MiniLaravelApplication();
        $this->app->seedUsers(['ada', 'linus', 'rasmus', 'grace', 'alan', 'barbara']);
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function test_listing_loads_every_user_separately(): void
    {
        $connection = $this->app->connection();
        $ids = array_column($connection->select('select id from users'), 'id');

        foreach ($ids as $id) {
            $connection->select('select * from users where id = ?', [$id]);
        }

        self::assertCount(6, $ids);
    }

    public function test_settings_are_read_again_and_again(): void
    {
        $connection = $this->app->connection();

        for ($i = 0; $i < 4; ++$i) {
            $connection->select('select name from users where id = ?', [1]);
        }

        self::assertTrue(true);
    }
}
