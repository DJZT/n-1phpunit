<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;

/**
 * The smallest thing that still looks like a booted Laravel app to the
 * extension: a container instance with `events` and `db` bound.
 */
final class MiniLaravelApplication
{
    public readonly Container $container;

    private readonly Capsule $capsule;

    public function __construct()
    {
        $this->container = new Container();
        Container::setInstance($this->container);

        $this->container->singleton('events', static fn (Container $container): Dispatcher => new Dispatcher($container));

        $this->capsule = new Capsule($this->container);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setEventDispatcher($this->container->make('events'));
        $this->capsule->setAsGlobal();

        $this->container->instance('db', $this->capsule->getDatabaseManager());
        $this->container->instance('db.connection', $this->connection());

        // Eloquent models need the connection resolver, which is what lets a
        // fixture reproduce real lazy loading.
        $this->capsule->bootEloquent();
    }

    /**
     * A board of posts, each with an author and comments, plus a settings
     * table — enough to reproduce what a single HTTP request does to a
     * database when nothing is eager loaded.
     */
    public function seedPostsWithComments(int $posts, int $commentsEach): void
    {
        $connection = $this->connection();
        $connection->statement('create table users (id integer primary key autoincrement, name varchar not null)');
        $connection->statement('create table posts (id integer primary key autoincrement, user_id integer not null, title varchar not null)');
        $connection->statement('create table comments (id integer primary key autoincrement, post_id integer not null, body varchar not null)');
        $connection->statement('create table settings (id integer primary key autoincrement, key varchar not null, value varchar not null)');

        $connection->insert('insert into settings (key, value) values (?, ?)', ['locale', 'uk']);

        foreach (['ada', 'linus', 'rasmus'] as $name) {
            $connection->insert('insert into users (name) values (?)', [$name]);
        }

        for ($post = 1; $post <= $posts; ++$post) {
            $connection->insert('insert into posts (user_id, title) values (?, ?)', [($post % 3) + 1, 'post ' . $post]);

            for ($comment = 1; $comment <= $commentsEach; ++$comment) {
                $connection->insert('insert into comments (post_id, body) values (?, ?)', [$post, 'comment ' . $comment]);
            }
        }
    }

    public function connection(): Connection
    {
        return $this->capsule->getConnection();
    }

    /**
     * @param list<string> $names
     */
    public function seedUsers(array $names): void
    {
        $connection = $this->connection();
        $connection->statement('create table users (id integer primary key autoincrement, name varchar not null)');

        foreach ($names as $name) {
            $connection->insert('insert into users (name) values (?)', [$name]);
        }
    }

    public function destroy(): void
    {
        $this->capsule->getDatabaseManager()->purge();
        Container::setInstance(null);
    }
}
