<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Recording\BacktraceOriginResolver;
use NPlusOne\PHPUnit\Recording\Origin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BacktraceOriginResolver::class)]
#[CoversClass(Origin::class)]
final class BacktraceOriginResolverTest extends TestCase
{
    public function test_it_skips_framework_frames_and_stops_at_application_code(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 400, 'function' => 'run'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 'line' => 20, 'function' => 'get'],
            ['file' => '/app/app/Http/Controllers/OrderController.php', 'line' => 31, 'function' => 'get'],
            ['file' => '/app/tests/Feature/OrderTest.php', 'line' => 12, 'function' => 'index', 'class' => 'App\Http\Controllers\OrderController', 'type' => '->'],
        ]);

        self::assertSame('/app/app/Http/Controllers/OrderController.php', $origin->file);
        self::assertSame(31, $origin->line);
        self::assertSame('OrderController->index()', $origin->function);
    }

    public function test_it_names_the_framework_call_that_issued_the_query(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php', 'line' => 492, 'function' => '{closure}'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 874, 'function' => 'logQuery', 'class' => 'Illuminate\Database\Connection', 'type' => '->'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php', 'line' => 3494, 'function' => 'runSelect', 'class' => 'Illuminate\Database\Query\Builder', 'type' => '->'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/HasMany.php', 'line' => 41, 'function' => 'get', 'class' => 'Illuminate\Database\Eloquent\Builder', 'type' => '->'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', 'line' => 637, 'function' => 'getResults', 'class' => 'Illuminate\Database\Eloquent\Relations\HasMany', 'type' => '->'],
            ['file' => '/app/app/Http/Controllers/OrderController.php', 'line' => 31, 'function' => '__get', 'class' => 'Illuminate\Database\Eloquent\Model', 'type' => '->'],
            ['file' => '/app/tests/Feature/OrderTest.php', 'line' => 12, 'function' => 'index', 'class' => 'App\Http\Controllers\OrderController', 'type' => '->'],
        ]);

        self::assertSame('/app/app/Http/Controllers/OrderController.php', $origin->file);
        // Connection and the query builders only carry the query; the relation
        // is what explains it.
        self::assertSame('HasMany->getResults()', $origin->via);
    }

    public function test_there_is_no_framework_call_to_name_when_the_app_queries_directly(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 874, 'function' => 'logQuery', 'class' => 'Illuminate\Database\Connection', 'type' => '->'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php', 'line' => 3494, 'function' => 'runSelect', 'class' => 'Illuminate\Database\Query\Builder', 'type' => '->'],
            ['file' => '/app/app/Support/Settings.php', 'line' => 44, 'function' => 'first', 'class' => 'Illuminate\Database\Query\Builder', 'type' => '->'],
        ]);

        self::assertSame('/app/app/Support/Settings.php', $origin->file);
        self::assertNull($origin->via);
    }

    public function test_this_packages_own_frames_are_never_named(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([
            ['file' => '/app/vendor/djzt/phpunit-n-plus-one/src/Laravel/LaravelQueryBinder.php', 'line' => 120, 'function' => 'capture', 'class' => 'NPlusOne\PHPUnit\Recording\BacktraceOriginResolver', 'type' => '->'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 874, 'function' => 'logQuery', 'class' => 'Illuminate\Database\Connection', 'type' => '->'],
            ['file' => '/app/app/Support/Settings.php', 'line' => 44, 'function' => 'first', 'class' => 'Illuminate\Database\Query\Builder', 'type' => '->'],
        ]);

        self::assertNull($origin->via);
    }

    public function test_it_falls_back_to_the_innermost_known_frame(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([
            ['function' => 'call_user_func'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 400, 'function' => 'run'],
        ]);

        self::assertSame('/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', $origin->file);
        self::assertSame(400, $origin->line);
    }

    public function test_an_empty_backtrace_yields_an_unknown_origin(): void
    {
        $origin = (new BacktraceOriginResolver())->resolve([]);

        self::assertTrue($origin->isUnknown());
        self::assertSame('unknown', $origin->key());
        self::assertSame('unknown location', $origin->relative('/app'));
    }

    public function test_extra_fragments_can_be_ignored(): void
    {
        $resolver = new BacktraceOriginResolver(['/app/app/Support/']);

        $origin = $resolver->resolve([
            ['file' => '/app/app/Support/QueryHelper.php', 'line' => 5, 'function' => 'fetch'],
            ['file' => '/app/app/Http/Controllers/OrderController.php', 'line' => 31, 'function' => 'fetch'],
        ]);

        self::assertSame(31, $origin->line);
    }

    public function test_it_captures_the_call_site_of_a_live_backtrace(): void
    {
        [$origin, $expectedLine] = $this->capture();

        self::assertSame(__FILE__, $origin->file);
        self::assertSame($expectedLine, $origin->line);
        self::assertSame('BacktraceOriginResolverTest->capture()', $origin->function);
    }

    public function test_paths_are_relative_to_the_base_path(): void
    {
        $origin = new Origin('/app/app/Http/Controllers/OrderController.php', 31, 'index()');

        self::assertSame('app/Http/Controllers/OrderController.php:31', $origin->relative('/app'));
        self::assertSame('app/Http/Controllers/OrderController.php:31 (index())', $origin->describe('/app'));
        self::assertSame('/app/app/Http/Controllers/OrderController.php:31', $origin->relative('/other'));
    }

    /**
     * @return array{Origin, int}
     */
    private function capture(): array
    {
        $expectedLine = __LINE__ + 2;

        return [(new BacktraceOriginResolver())->capture(), $expectedLine];
    }
}
