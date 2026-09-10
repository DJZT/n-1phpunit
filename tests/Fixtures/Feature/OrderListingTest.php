<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures\Feature;

use NPlusOne\PHPUnit\Tests\Fixtures\MiniLaravelApplication;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for a real feature test: it boots an application in setUp() and
 * then hits the database in a loop, the way a controller without eager
 * loading would.
 */
final class OrderListingTest extends TestCase
{
    private MiniLaravelApplication $app;

    protected function setUp(): void
    {
        $this->app = new MiniLaravelApplication();
        // Queries from setUp() (migrations, factories) are deliberately not recorded.
        $this->app->seedUsers(['ada', 'linus', 'rasmus', 'grace', 'alan']);
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function test_listing_loads_every_user_separately(): void
    {
        $connection = $this->app->connection();
        $ids = array_column($connection->select('select id from users'), 'id');

        $names = [];
        foreach ($ids as $id) {
            $names[] = $connection->select('select * from users where id = ?', [$id])[0]->name;
        }

        self::assertCount(5, $names);
    }

    public function test_listing_is_eager_loaded(): void
    {
        $connection = $this->app->connection();
        $users = $connection->select('select * from users');

        self::assertCount(5, $users);
    }
}
