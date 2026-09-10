<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit;

use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Subscribers\ApplicationFinishedSubscriber;
use NPlusOne\PHPUnit\Subscribers\TestFinishedSubscriber;
use NPlusOne\PHPUnit\Subscribers\TestPreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Register in phpunit.xml:
 *
 * <extensions>
 *     <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
 *         <parameter name="threshold" value="3"/>
 *         <parameter name="limit" value="10"/>
 *     </bootstrap>
 * </extensions>
 */
final class NPlusOneExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $options = Options::fromParameters($parameters);

        if (!$options->enabled) {
            return;
        }

        $session = new Session($options);
        NPlusOne::setSession($session);

        $facade->registerSubscribers(
            new TestPreparedSubscriber($session),
            new TestFinishedSubscriber($session),
            new ApplicationFinishedSubscriber($session),
        );
    }
}
