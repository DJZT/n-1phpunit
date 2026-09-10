<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Support;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\NPlusOne;
use PHPUnit\Framework\Assert;

/**
 * Optional: fail an individual test as soon as it produces an N+1 pattern,
 * instead of only seeing it in the report at the end of the run.
 *
 *     use AssertsNoNPlusOneQueries;
 *
 *     public function test_order_index_is_eager_loaded(): void
 *     {
 *         $this->get('/orders')->assertOk();
 *
 *         $this->assertNoNPlusOneQueries();
 *     }
 */
trait AssertsNoNPlusOneQueries
{
    public function assertNoNPlusOneQueries(?string $message = null): void
    {
        $session = NPlusOne::session();

        if ($session === null) {
            Assert::markTestSkipped('The N+1 PHPUnit extension is not registered in phpunit.xml.');
        }

        $summaries = array_map(
            static fn (Detection $detection): string => sprintf(
                '%s: %dx %s at %s',
                $detection->kind(),
                $detection->count,
                trim((string) preg_replace('/\s+/', ' ', $detection->sql)),
                $detection->origin->relative($session->options()->basePath),
            ),
            $session->currentDetections(),
        );

        $message ??= 'Repeated queries detected during this test.';

        if ($summaries !== []) {
            $message .= PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $summaries);
        }

        Assert::assertSame([], $summaries, $message);
    }
}
