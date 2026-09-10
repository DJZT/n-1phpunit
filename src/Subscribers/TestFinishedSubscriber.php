<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Subscribers;

use NPlusOne\PHPUnit\Session;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly Session $session)
    {
    }

    public function notify(Finished $event): void
    {
        $this->session->endTest();
    }
}
