<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Integration;

use Illuminate\Validation\Factory as ValidationFactory;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Session;
use NPlusOne\PHPUnit\Tests\Fixtures\MiniLaravelApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The second shape of N+1: the loop is not in the application at all, it is
 * inside the framework. `exists` on an array of ids runs one count query per
 * element, and the only application frame in the stack is the line that kicked
 * validation off — which is why the report also names the framework call.
 */
#[CoversNothing]
final class ValidationExistsTest extends TestCase
{
    private const IDS = 5;

    private MiniLaravelApplication $app;

    private string $reportFile = '';

    protected function setUp(): void
    {
        if (!class_exists(ValidationFactory::class)) {
            self::markTestSkipped('illuminate/validation is not installed.');
        }

        $this->app = new MiniLaravelApplication();
        $this->app->seedPostsWithComments(posts: self::IDS, commentsEach: 1);
        $this->reportFile = sys_get_temp_dir() . '/n-plus-one-exists-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        $this->app->destroy();

        if (is_file($this->reportFile)) {
            unlink($this->reportFile);
        }
    }

    public function test_exists_on_an_array_is_one_query_per_element(): void
    {
        $report = $this->validate([
            'applications' => ['required', 'array'],
            'applications.*.id' => ['required', 'integer', 'exists:posts,id'],
        ]);

        self::assertCount(1, $report['hotspots']);

        $hotspot = $report['hotspots'][0];

        self::assertSame(Detection::KIND_N_PLUS_ONE, $hotspot['kind']);
        self::assertSame(self::IDS, $hotspot['max_queries_in_one_test']);
        self::assertSame('select count(*) as aggregate from "posts" where "id" = ?', $hotspot['sql']);

        // The whole loop lives in vendor, so without this the report would only
        // point at the line that called the validator.
        self::assertSame('DatabasePresenceVerifier->getCount()', $hotspot['via']);
    }

    public function test_a_rejected_payload_still_pays_for_every_element(): void
    {
        $report = $this->validate([
            // The array is already too long, and Laravel still runs the nested
            // rules for every element.
            'applications' => ['required', 'array', 'max:3'],
            'applications.*.id' => ['required', 'integer', 'exists:posts,id'],
        ]);

        self::assertSame(self::IDS, $report['summary']['recorded_queries']);
        self::assertSame(self::IDS, $report['hotspots'][0]['max_queries_in_one_test']);
    }

    public function test_checking_the_whole_array_at_once_costs_one_query(): void
    {
        $report = $this->validate([
            'applications' => ['required', 'array'],
            'applications.*.id' => ['required', 'integer'],
        ], static function (MiniLaravelApplication $app, array $payload): void {
            // What the batched rule does instead: one query for every id.
            $ids = array_column($payload['applications'], 'id');
            $app->connection()->table('posts')->whereIn('id', $ids)->pluck('id');
        });

        self::assertSame([], $report['hotspots'], 'one query cannot be a repeat');
        self::assertSame(1, $report['summary']['recorded_queries']);
    }

    /**
     * @param array<string, list<string>> $rules
     * @param null|callable(MiniLaravelApplication, array<string, mixed>): void $also
     *
     * @return array{summary: array<string, int>, hotspots: list<array<string, mixed>>}
     */
    private function validate(array $rules, ?callable $also = null): array
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

        self::assertTrue($session->binder()->attach());

        $payload = [
            'applications' => array_map(
                static fn (int $id): array => ['id' => $id],
                range(1, self::IDS),
            ),
        ];

        $session->startTest('Tests\Feature\BulkUpdateApplicationTest::testBulkUpdateApplicationsWithInvalidData');
        $this->app->validation()->make($payload, $rules)->fails();

        if ($also !== null) {
            $also($this->app, $payload);
        }

        $session->endTest();
        $session->finish();

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $report */
        $report = json_decode((string) file_get_contents($this->reportFile), true, flags: JSON_THROW_ON_ERROR);

        return $report;
    }
}
