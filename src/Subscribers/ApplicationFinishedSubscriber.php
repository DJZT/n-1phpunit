<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Subscribers;

use NPlusOne\PHPUnit\NPlusOne;
use NPlusOne\PHPUnit\Session;
use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;

final class ApplicationFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly Session $session)
    {
    }

    public function notify(Finished $event): void
    {
        $this->session->finish();

        NPlusOne::setSession(null);
    }
}
