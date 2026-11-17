<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Recording;

/**
 * Walks a backtrace and returns the innermost frame that belongs to the
 * application under test, i.e. the loop that actually fires the queries.
 */
final class BacktraceOriginResolver
{
    private const DEPTH = 60;

    /**
     * Framework classes that only carry a query to the database. They say
     * nothing about why it was issued, so they are skipped when looking for
     * the framework call to blame.
     */
    private const PLUMBING = [
        'Illuminate\\Events\\Dispatcher',
        'Illuminate\\Database\\Connection',
        'Illuminate\\Database\\DatabaseManager',
        'Illuminate\\Database\\Query\\',
        'Illuminate\\Database\\Concerns\\',
        'Illuminate\\Database\\Eloquent\\Builder',
        'Illuminate\\Support\\Facades\\',
        'Illuminate\\Support\\Traits\\ForwardsCalls',
    ];

    /** @var list<string> */
    private array $ignoredFragments;

    /**
     * @param list<string> $extraIgnoredFragments
     */
    public function __construct(array $extraIgnoredFragments = [])
    {
        $this->ignoredFragments = array_merge(
            [
                DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
                dirname(__DIR__) . DIRECTORY_SEPARATOR, // this package, when used from a path repository
            ],
            $extraIgnoredFragments,
        );
    }

    public function capture(): Origin
    {
        return $this->resolve(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH));
    }

    /**
     * @param list<array<string, mixed>> $frames
     */
    public function resolve(array $frames): Origin
    {
        $firstWithFile = null;
        $via = null;

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }

            $ignored = $this->isIgnored($file);

            if ($ignored) {
                // Deepest first, so the innermost meaningful framework call wins:
                // HasMany->getResults() rather than Connection->select().
                $via ??= $this->frameworkCall($frame);
            }

            $origin = new Origin(
                $file,
                (int) ($frame['line'] ?? 0),
                $this->enclosingFunction($frames, $index),
                $via,
            );
            $firstWithFile ??= $origin;

            if (!$ignored) {
                return $origin;
            }
        }

        return $firstWithFile ?? Origin::unknown();
    }

    /**
     * The call a framework frame is making, unless it is plain plumbing.
     *
     * @param array<string, mixed> $frame
     */
    private function frameworkCall(array $frame): ?string
    {
        $class = $frame['class'] ?? null;
        $function = $frame['function'] ?? null;

        if (!is_string($class) || !is_string($function) || str_contains($function, '{closure}')) {
            return null;
        }

        // This package's own listener sits between the framework and us.
        if (str_starts_with($class, self::packageNamespace())) {
            return null;
        }

        foreach (self::PLUMBING as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return null;
            }
        }

        return $this->shortName($class) . (string) ($frame['type'] ?? '::') . $function . '()';
    }

    /**
     * A backtrace frame stores the location a call was made *from*, so the
     * function that contains it is described by the next frame.
     *
     * @param list<array<string, mixed>> $frames
     */
    private function enclosingFunction(array $frames, int $index): ?string
    {
        $frame = $frames[$index + 1] ?? $frames[$index] ?? null;
        if ($frame === null || !isset($frame['function'])) {
            return null;
        }

        $class = isset($frame['class']) ? $this->shortName((string) $frame['class']) . (string) ($frame['type'] ?? '::') : '';

        return $class . (string) $frame['function'] . '()';
    }

    /**
     * "NPlusOne\\PHPUnit\\", derived rather than written down so a rename
     * cannot leave this behind.
     */
    private static function packageNamespace(): string
    {
        $position = strrpos(__NAMESPACE__, '\\');

        return ($position === false ? __NAMESPACE__ : substr(__NAMESPACE__, 0, $position)) . '\\';
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function isIgnored(string $file): bool
    {
        foreach ($this->ignoredFragments as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
