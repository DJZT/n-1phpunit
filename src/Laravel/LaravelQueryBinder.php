<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use NPlusOne\PHPUnit\Recording\BacktraceOriginResolver;
use NPlusOne\PHPUnit\Recording\QueryEvent;
use NPlusOne\PHPUnit\Recording\QueryRecorder;
use Throwable;
use WeakMap;

/**
 * Hooks the recorder into the Laravel application that the test just booted.
 *
 * Nothing in the application under test has to change: by the time PHPUnit
 * emits Test\Prepared, setUp() (and therefore createApplication()) has already
 * run, so the container is there and `db` can be listened to.
 */
final class LaravelQueryBinder
{
    private const QUERY_EXECUTED = 'Illuminate\\Database\\Events\\QueryExecuted';

    /**
     * Laravel builds a fresh application (and therefore a fresh event
     * dispatcher) for every test, so this must not keep those objects alive:
     * a WeakMap lets each finished application be collected as usual.
     *
     * @var WeakMap<object, true>
     */
    private WeakMap $bound;

    private bool $everAttached = false;

    public function __construct(
        private readonly QueryRecorder $recorder,
        private readonly BacktraceOriginResolver $origins = new BacktraceOriginResolver(),
    ) {
        $this->bound = new WeakMap();
    }

    /**
     * Attach to whatever application is currently active.
     */
    public function attach(): bool
    {
        $container = $this->currentContainer();

        return $container === null ? false : $this->attachTo($container);
    }

    /**
     * Attach to an explicit container, for projects that prefer to wire the
     * listener from their base TestCase.
     */
    public function attachTo(object $container): bool
    {
        try {
            if (!method_exists($container, 'bound') || !$container->bound('db')) {
                return false;
            }

            $listener = function (object $query): void {
                $this->onQuery($query);
            };

            // Preferred: the event dispatcher covers every connection, not just
            // the default one. DatabaseManager::listen() only exists on some
            // Laravel versions (elsewhere it is proxied to the connection).
            if ($container->bound('events')) {
                /** @var object $events */
                $events = $container->make('events');

                if ($this->remember($events)) {
                    $events->listen(self::QUERY_EXECUTED, $listener);
                }

                return true;
            }

            /** @var object $manager */
            $manager = $container->make('db');

            if (!is_callable([$manager, 'listen'])) {
                return false;
            }

            if ($this->remember($manager)) {
                $manager->listen($listener);
            }

            return true;
        } catch (Throwable) {
            // A broken or half-booted container must never break the test run.
            return false;
        }
    }

    /**
     * @return bool true when this object has not been hooked before, so the
     *              caller still has to register the listener on it
     */
    private function remember(object $target): bool
    {
        if (isset($this->bound[$target])) {
            return false;
        }

        $this->bound[$target] = true;
        $this->everAttached = true;

        return true;
    }

    public function isAttached(): bool
    {
        return $this->everAttached;
    }

    /**
     * Handles an Illuminate\Database\Events\QueryExecuted instance without
     * depending on the class being present.
     */
    private function onQuery(object $query): void
    {
        if (!$this->recorder->isRecording()) {
            return;
        }

        try {
            $sql = $query->sql ?? null;
            if (!is_string($sql) || $sql === '') {
                return;
            }

            /** @var list<mixed> $bindings */
            $bindings = is_array($query->bindings ?? null) ? array_values($query->bindings) : [];
            $time = $query->time ?? 0;
            $connection = $query->connectionName ?? null;

            $this->recorder->record(new QueryEvent(
                sql: $sql,
                bindings: $bindings,
                timeMs: is_numeric($time) ? (float) $time : 0.0,
                connection: is_string($connection) ? $connection : null,
                origin: $this->origins->capture(),
            ));
        } catch (Throwable) {
            // Observing queries must never turn into a test failure.
        }
    }

    private function currentContainer(): ?object
    {
        if (class_exists(Container::class)) {
            $container = Container::getInstance();

            if ($container instanceof Container && $container->bound('db')) {
                return $container;
            }
        }

        if (class_exists(Facade::class)) {
            $application = Facade::getFacadeApplication();

            if (is_object($application) && method_exists($application, 'bound') && $application->bound('db')) {
                return $application;
            }
        }

        return null;
    }
}
