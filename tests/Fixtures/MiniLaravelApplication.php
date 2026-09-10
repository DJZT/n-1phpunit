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
