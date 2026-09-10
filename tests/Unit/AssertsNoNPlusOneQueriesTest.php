<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\NPlusOne;
use NPlusOne\PHPUnit\Recording\Origin;
use NPlusOne\PHPUnit\Recording\QueryEvent;
use NPlusOne\PHPUnit\Session;
use NPlusOne\PHPUnit\Support\AssertsNoNPlusOneQueries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

#[CoversClass(NPlusOne::class)]
#[CoversTrait(AssertsNoNPlusOneQueries::class)]
final class AssertsNoNPlusOneQueriesTest extends TestCase
{
    use AssertsNoNPlusOneQueries;

    protected function tearDown(): void
    {
        NPlusOne::setSession(null);
    }

    public function test_it_passes_when_the_test_did_not_repeat_itself(): void
    {
        $this->useSession();

        $this->assertNoNPlusOneQueries();
    }

    public function test_it_fails_and_points_at_the_offending_call_site(): void
    {
        $session = $this->useSession();

        for ($i = 1; $i <= 4; ++$i) {
            $session->recorder()->record($this->query($i));
        }

        try {
            $this->assertNoNPlusOneQueries();
            self::fail('The assertion should have failed.');
        } catch (ExpectationFailedException $exception) {
            self::assertStringContainsString('N+1: 4x select * from "users" where "id" = ?', $exception->getMessage());
            self::assertStringContainsString('app/Http/Controllers/OrderController.php:31', $exception->getMessage());
        }
    }

    public function test_it_skips_when_the_extension_is_not_registered(): void
    {
        NPlusOne::setSession(null);

        self::assertFalse(NPlusOne::isActive());
        self::assertFalse(NPlusOne::listen());

        $this->expectException(SkippedWithMessageException::class);

        $this->assertNoNPlusOneQueries();
    }

    private function useSession(): Session
    {
        $session = new Session(
            new Options(filter: '', basePath: '/app', colors: false),
            writer: static function (string $output): void {
            },
        );
        $session->startTest('Tests\Feature\OrderTest::test_index');

        NPlusOne::setSession($session);

        return $session;
    }

    private function query(int $id): QueryEvent
    {
        return new QueryEvent(
            'select * from "users" where "id" = ?',
            [$id],
            0.4,
            'testing',
            new Origin('/app/app/Http/Controllers/OrderController.php', 31, 'index()'),
        );
    }
}
