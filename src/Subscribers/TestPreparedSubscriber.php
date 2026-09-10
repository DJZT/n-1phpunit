<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Subscribers;

use NPlusOne\PHPUnit\Session;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Prepared is emitted after setUp() has run, which is exactly when the Laravel
 * application under test exists and can be listened to.
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private readonly Session $session)
    {
    }

    public function notify(Prepared $event): void
    {
        $this->session->startTest($event->test()->id());
    }
}
