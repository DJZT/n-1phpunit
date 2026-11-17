<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Session;
use NPlusOne\PHPUnit\Tests\Fixtures\Eloquent\PostBoard;
use NPlusOne\PHPUnit\Tests\Fixtures\MiniLaravelApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The case the package exists for: one request walks 100 models and touches a
 * relation on each, so Eloquent fires 100 more queries from deep inside the
 * framework. The report has to blame the loop in application code, count the
 * burst of a single request, and stay silent about the eager-loaded screen.
 */
#[CoversNothing]
final class EloquentLazyLoadingTest extends TestCase
{
    private const POSTS = 100;

    private MiniLaravelApplication $app;

    private string $reportFile = '';

    protected function setUp(): void
    {
        $this->app = new MiniLaravelApplication();
        $this->app->seedPostsWithComments(posts: self::POSTS, commentsEach: 3);
        $this->reportFile = sys_get_temp_dir() . '/n-plus-one-eloquent-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        $this->app->destroy();

        if (is_file($this->reportFile)) {
            unlink($this->reportFile);
        }
    }

    public function test_one_request_that_lazy_loads_a_relation_is_blamed_on_the_loop(): void
    {
        $report = $this->record(static function (Session $session): void {
            $session->startTest('Tests\Feature\PostBoardTest::test_index');
            (new PostBoard())->withLazyComments();
            $session->endTest();
        });

        self::assertCount(1, $report['hotspots'], 'the single query for the posts is not a repeat');

        $hotspot = $report['hotspots'][0];

        self::assertSame(Detection::KIND_N_PLUS_ONE, $hotspot['kind']);
        self::assertSame(self::POSTS, $hotspot['max_queries_in_one_test'], 'one request, one hundred extra queries');
        self::assertStringContainsString('from "comments" where "comments"."post_id" = ?', (string) $hotspot['sql']);

        // Not the test, not the framework: the foreach in the application code.
        self::assertSame(
            'tests/Fixtures/Eloquent/PostBoard.php:' . $this->lineOfLazyLoop(),
            $hotspot['origin'],
        );
        self::assertStringContainsString('PostBoard->withLazyComments()', (string) $hotspot['function']);
    }

    /**
     * The example in the README: one screen, three problems, each blamed on
     * its own line.
     */
    public function test_a_whole_screen_reports_one_row_per_problem(): void
    {
        $report = $this->record(static function (Session $session): void {
            foreach (['test_board_is_rendered', 'test_board_shows_authors'] as $name) {
                $session->startTest('Tests\Feature\PostBoardTest::' . $name);
                (new PostBoard())->render();
                $session->endTest();
            }

            $session->startTest('Tests\Feature\PostBoardTest::test_board_is_eager_loaded');
            (new PostBoard())->withEagerComments();
            $session->endTest();
        });

        self::assertCount(3, $report['hotspots']);
        self::assertSame(604, $report['summary']['recorded_queries']);
        self::assertSame(2, $report['summary']['affected_tests'], 'the eager-loaded request is clean');

        $rows = array_map(static fn (array $hotspot): array => [
            'kind' => $hotspot['kind'],
            'origin' => $hotspot['origin'],
            'worst' => $hotspot['max_queries_in_one_test'],
            'total' => $hotspot['total_queries'],
            'tests' => count($hotspot['tests']),
        ], $report['hotspots']);

        $line = $this->lineOfRenderLoop();

        self::assertSame([
            // The setting is read with the same parameter every time, so it is
            // a duplicate rather than an N+1.
            ['kind' => Detection::KIND_DUPLICATE, 'origin' => $this->at($line + 1), 'worst' => self::POSTS, 'total' => self::POSTS * 2, 'tests' => 2],
            ['kind' => Detection::KIND_N_PLUS_ONE, 'origin' => $this->at($line + 5), 'worst' => self::POSTS, 'total' => self::POSTS * 2, 'tests' => 2],
            ['kind' => Detection::KIND_N_PLUS_ONE, 'origin' => $this->at($line + 6), 'worst' => self::POSTS, 'total' => self::POSTS * 2, 'tests' => 2],
        ], $rows);
    }

    public function test_the_eager_loaded_screen_produces_no_finding(): void
    {
        $report = $this->record(static function (Session $session): void {
            $session->startTest('Tests\Feature\PostBoardTest::test_index_eager');
            (new PostBoard())->withEagerComments();
            $session->endTest();
        });

        self::assertSame([], $report['hotspots']);
        self::assertSame(2, $report['summary']['recorded_queries'], 'posts plus one eager load for all of them');
    }

    public function test_two_tests_hitting_the_same_screen_stay_one_row_with_a_per_request_count(): void
    {
        $report = $this->record(static function (Session $session): void {
            foreach (['test_index', 'test_index_again'] as $name) {
                $session->startTest('Tests\Feature\PostBoardTest::' . $name);
                (new PostBoard())->withLazyComments();
                $session->endTest();
            }
        });

        self::assertCount(1, $report['hotspots'], 'the same call site is one hotspot, however many tests reach it');

        $hotspot = $report['hotspots'][0];

        self::assertSame(self::POSTS, $hotspot['max_queries_in_one_test'], 'still per request, not summed');
        self::assertSame(self::POSTS * 2, $hotspot['total_queries'], 'the total says how much the suite spends here');
        self::assertSame([
            'Tests\Feature\PostBoardTest::test_index' => self::POSTS,
            'Tests\Feature\PostBoardTest::test_index_again' => self::POSTS,
        ], $hotspot['tests']);
    }

    public function test_a_lazy_and_an_eager_screen_are_told_apart_in_one_run(): void
    {
        $report = $this->record(static function (Session $session): void {
            $session->startTest('Tests\Feature\PostBoardTest::test_index_eager');
            (new PostBoard())->withEagerComments();
            $session->endTest();

            $session->startTest('Tests\Feature\PostBoardTest::test_index_lazy');
            (new PostBoard())->withLazyComments();
            $session->endTest();
        });

        self::assertCount(1, $report['hotspots']);
        self::assertSame(
            ['Tests\Feature\PostBoardTest::test_index_lazy' => self::POSTS],
            $report['hotspots'][0]['tests'],
            'only the lazy request is named',
        );
    }

    /**
     * @param callable(Session): void $run
     *
     * @return array{summary: array<string, int>, hotspots: list<array<string, mixed>>}
     */
    private function record(callable $run): array
    {
        $session = new Session(
            new Options(
                filter: '',
                reportFile: $this->reportFile,
                console: false,
                basePath: dirname(__DIR__, 2),
                colors: false,
            ),
            writer: static fn (string $text) => null,
        );

        self::assertTrue($session->binder()->attach(), 'the listener must find the booted application');

        $run($session);
        $session->finish();

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $report */
        $report = json_decode((string) file_get_contents($this->reportFile), true, flags: JSON_THROW_ON_ERROR);

        return $report;
    }

    private function at(int $line): string
    {
        return 'tests/Fixtures/Eloquent/PostBoard.php:' . $line;
    }

    private function lineOfRenderLoop(): int
    {
        $file = dirname(__DIR__) . '/Fixtures/Eloquent/PostBoard.php';
        $lines = file($file) ?: [];

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'foreach (Post::all()') && str_contains($lines[$index + 1] ?? '', 'Setting::query()')) {
                return $index + 1;
            }
        }

        self::fail('The render() loop was not found in the fixture.');
    }

    /**
     * The fixture's loop, found rather than hard-coded, so editing the fixture
     * cannot turn this into a false failure.
     */
    private function lineOfLazyLoop(): int
    {
        $file = dirname(__DIR__) . '/Fixtures/Eloquent/PostBoard.php';

        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, 'foreach (Post::all()')) {
                return $index + 2; // the relation is touched on the next line
            }
        }

        self::fail('The lazy-loading loop was not found in the fixture.');
    }
}
