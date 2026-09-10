<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Detector;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Laravel\LaravelQueryBinder;
use NPlusOne\PHPUnit\Recording\QueryRecorder;
use NPlusOne\PHPUnit\Tests\Fixtures\MiniLaravelApplication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaravelQueryBinder::class)]
final class LaravelQueryBinderTest extends TestCase
{
    private MiniLaravelApplication $app;

    private QueryRecorder $recorder;

    private LaravelQueryBinder $binder;

    protected function setUp(): void
    {
        $this->app = new MiniLaravelApplication();
        $this->app->seedUsers(['ada', 'linus', 'rasmus']);
        $this->recorder = new QueryRecorder();
        $this->binder = new LaravelQueryBinder($this->recorder);
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function test_it_records_queries_of_a_booted_application(): void
    {
        self::assertTrue($this->binder->attach());
        self::assertTrue($this->binder->isAttached());

        $this->recorder->start('Tests\Feature\UserTest::test_index');
        $this->app->connection()->select('select * from users where id = ?', [1]);

        $queries = $this->recorder->queries();

        self::assertCount(1, $queries);
        self::assertSame('select * from users where id = ?', $queries[0]->sql);
        self::assertSame([1], $queries[0]->bindings);
        self::assertSame('default', $queries[0]->connection);
        self::assertGreaterThanOrEqual(0.0, $queries[0]->timeMs);
        self::assertSame(__FILE__, $queries[0]->origin->file);
    }

    public function test_queries_outside_a_recorded_test_are_dropped(): void
    {
        $this->binder->attach();

        $this->app->connection()->select('select * from users');

        self::assertSame(0, $this->recorder->totalQueries());
    }

    public function test_attaching_twice_does_not_record_a_query_twice(): void
    {
        $this->binder->attach();
        $this->binder->attach();
        $this->binder->attachTo($this->app->container);

        $this->recorder->start('Tests\Feature\UserTest::test_index');
        $this->app->connection()->select('select * from users');

        self::assertSame(1, $this->recorder->totalQueries());
    }

    public function test_it_does_nothing_without_a_database(): void
    {
        $container = new Container();

        self::assertFalse($this->binder->attachTo($container));
        self::assertFalse((new LaravelQueryBinder(new QueryRecorder()))->attachTo(new \stdClass()));
    }

    /**
     * Guards the one contract this package depends on across Laravel 10, 11
     * and 12: the shape of the QueryExecuted event.
     */
    public function test_it_understands_the_query_executed_event_of_the_installed_laravel(): void
    {
        $this->binder->attach();
        $this->recorder->start('Tests\Feature\UserTest::test_index');

        $this->app->container->make('events')->dispatch(
            new QueryExecuted('select * from users where id = ?', [7], 12.5, $this->app->connection()),
        );

        $queries = $this->recorder->queries();

        self::assertCount(1, $queries);
        self::assertSame('select * from users where id = ?', $queries[0]->sql);
        self::assertSame([7], $queries[0]->bindings);
        self::assertSame(12.5, $queries[0]->timeMs);
        self::assertSame('default', $queries[0]->connection);
    }

    public function test_an_unexpected_event_object_is_ignored_instead_of_breaking_the_run(): void
    {
        $this->binder->attach();
        $this->recorder->start('Tests\Feature\UserTest::test_index');

        $events = $this->app->container->make('events');
        $events->dispatch(QueryExecuted::class, [new \stdClass()]);
        $events->dispatch(QueryExecuted::class, [new class () {
            public function __get(string $name): mixed
            {
                throw new \RuntimeException('no such property: ' . $name);
            }
        }]);

        self::assertSame(0, $this->recorder->totalQueries());
    }

    public function test_it_does_not_keep_finished_applications_alive(): void
    {
        $container = new Container();
        $dispatcher = new class () {
            /** @var list<callable> */
            public array $listeners = [];

            public function listen(string $event, callable $listener): void
            {
                $this->listeners[] = $listener;
            }
        };
        $container->instance('db', new \stdClass());
        $container->instance('events', $dispatcher);

        self::assertTrue($this->binder->attachTo($container));
        self::assertCount(1, $dispatcher->listeners);

        $reference = \WeakReference::create($dispatcher);
        unset($dispatcher, $container);
        gc_collect_cycles();

        self::assertNull($reference->get(), 'the binder must not hold on to the application of every test');
    }

    public function test_a_real_loop_is_detected_as_an_n_plus_one(): void
    {
        $this->binder->attach();
        $this->recorder->start('Tests\Feature\UserTest::test_index');

        $connection = $this->app->connection();
        foreach ($connection->select('select id from users') as $row) {
            $connection->select('select * from users where id = ?', [$row->id]);
        }

        $detections = (new Detector(new Options(threshold: 3)))
            ->detect('Tests\Feature\UserTest::test_index', $this->recorder->stop());

        self::assertCount(1, $detections);
        self::assertSame(3, $detections[0]->count);
        self::assertSame(Detection::KIND_N_PLUS_ONE, $detections[0]->kind());
        self::assertSame('select * from users where id = ?', $detections[0]->sql);
        self::assertSame(__FILE__, $detections[0]->origin->file);
        self::assertStringContainsString('LaravelQueryBinderTest->', (string) $detections[0]->origin->function);
    }
}
