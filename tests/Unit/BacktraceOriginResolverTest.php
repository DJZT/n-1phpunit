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
