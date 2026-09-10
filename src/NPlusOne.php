<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit;

/**
 * Process-wide handle on the session created by the PHPUnit extension.
 *
 * Only needed by code that wants to talk to the detector directly, e.g. the
 * AssertsNoNPlusOneQueries trait or a base TestCase that wires the listener
 * itself.
 */
final class NPlusOne
{
    private static ?Session $session = null;

    public static function setSession(?Session $session): void
    {
        self::$session = $session;
    }

    public static function session(): ?Session
    {
        return self::$session;
    }

    public static function isActive(): bool
    {
        return self::$session !== null;
    }

    /**
     * Attach the query listener to the given container (or the current one).
     * The extension does this automatically; call it manually only if your
     * application boots after setUp().
     */
    public static function listen(?object $container = null): bool
    {
        $session = self::$session;

        if ($session === null) {
            return false;
        }

        return $container === null
            ? $session->binder()->attach()
            : $session->binder()->attachTo($container);
    }
}
